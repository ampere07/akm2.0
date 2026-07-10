<?php
// auto_dc.php
// Purpose: Automates Disconnection, Pullout Requests, and Grace Period Billing
// Update: Includes delayed Grace Period charging (7 days after DC)
// Fixes Applied:
//   1. OPTIMIZED Session Logic (Skip if empty, Stop on Success).
//   2. DB Safety (Reconnect if MySQL goes away before transaction).
//   3. Robust DB Credential usage.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

require_once 'db_connect.php';
require_once 'functions.php';

$logName = 'Auto_DC';

writeLog($logName, "--- STARTING AUTO DC/PULLOUT CHECK ---");
set_time_limit(300);

try {
    processAutoDisconnect($pdo);
    processGracePeriodCharge($pdo); // Checks for 7-day old DCs to apply charges
    processAutoPullout($pdo);
    writeLog($logName, "--- AUTO DC/PULLOUT COMPLETE ---");
    echo "Auto DC/Pullout Run Complete.";
} catch (Exception $e) {
    writeLog($logName, "CRITICAL ERROR: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
}

function processAutoDisconnect($pdo) {
    global $logName, $host, $database, $username, $password;

    $currentDay = intval(date('d'));
    
    // Mapping: If today is [DC Day] => Target [Billing Day]
    $dcMap = [
        10 => 30,
        15 => 5,
        22 => 12,
        25 => 15,
        30 => 20
    ];

    // Check if today is one of your hardcoded Disconnection Days
    if (!array_key_exists($currentDay, $dcMap)) {
        writeLog($logName, "Today (Day $currentDay) is not a scheduled DC day. Skipping auto disconnect.");
        return;
    }

    $targetBillingDay = $dcMap[$currentDay];
    writeLog($logName, "DC Triggered: Today is Day $currentDay. Target Billing Day is $targetBillingDay.");

    // Find Active users with the target billing day who have an unpaid or partial invoice
    $stmt = $pdo->prepare("
        SELECT b.* FROM `Billing Details` b
        JOIN `Invoice` i ON b.`Account No.` = i.`Account No.`
        WHERE b.`Billing Day` = ? 
          AND b.`Billing Status` = 'Active'
          AND (i.`Invoice Status` = 'Unpaid' OR i.`Invoice Status` = 'Partial')
        GROUP BY b.`Account No.`
    ");
    $stmt->execute([$targetBillingDay]);
    $usersToDC = $stmt->fetchAll();
    
    writeLog($logName, "Users fetched for DC (Billing Day $targetBillingDay): " . count($usersToDC));

    $count = 0;

    foreach ($usersToDC as $user) {
        $acct = $user['Account No.'];
        writeLog($logName, "Processing Account: $acct");

        // DB SAFETY CHECK (Reconnect if connection timed out)
        try {
            $pdo->query("SELECT 1");
        } catch (Exception $e) {
            writeLog($logName, "[DB RECONNECT] Connection timed out for $acct. Reconnecting...");
            try {
                $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'"
                ]);
            } catch (Exception $dbErr) {
                writeLog($logName, "[FATAL] DB Reconnect Failed: " . $dbErr->getMessage());
                continue; // Skip this user if we can't reconnect
            }
        }

        // Check if already disconnected today
        $chk = $pdo->prepare("SELECT COUNT(*) FROM `Disconnected Logs` WHERE `Account No.` = ? AND `Date` LIKE ?");
        $chk->execute([$acct, date('Y-m-d') . '%']);
        if ($chk->fetchColumn() > 0) {
            writeLog($logName, "Skipped: Already disconnected today");
            continue;
        }

        // Optimized Radius Disconnect
        $radiusResult = disconnectRadiusUser($user['Username']);

        if (!$radiusResult) {
            writeLog($logName, "ABORTING $acct: Radius API failure.");
            continue;
        }

        // Ensure connection is alive before Transaction
        try {
            $pdo->query("SELECT 1");
        } catch (Exception $e) {
            $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        $pdo->beginTransaction();
        try {
            // Note: Charge is handled later by processGracePeriodCharge
            $pdo->prepare("UPDATE `Billing Details` SET `Billing Status` = 'Inactive', `Modified By` = 'System', `Modified Date` = NOW() WHERE `Account No.` = ?")->execute([$acct]);

            $logId = date('YmdHis') . rand(100,999);
            writeLog($logName, "Creating Disconnected Log ID: $logId");

            $pdo->prepare("INSERT INTO `Disconnected Logs` (`id`, `Account No.`, `Splynx ID`, `Mikrotik ID`, `Provider`, `Username`, `Date`, `Remarks`, `User Email`, `Name`, `Barangay`, `City`) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'System Auto DC', 'System', ?, ?, ?)")->execute([
                $logId, $acct, getVal($user, 'SPLYNX ID'), getVal($user, 'MIKROTIK ID'), getVal($user, 'Provider'),
                getVal($user, 'Username'), getVal($user, 'Full Name'), getVal($user, 'Barangay'), getVal($user, 'City')
            ]);

            triggerSMS($acct, 'dcTxt', []);
            $pdo->commit();
            $count++;
            writeLog($logName, "Successfully processed DC for $acct");
        } catch (Exception $ex) {
            $pdo->rollBack();
            writeLog($logName, "DB Error $acct: " . $ex->getMessage());
        }
    }
    writeLog($logName, "Auto Disconnect Complete. Processed: $count");
}

function processGracePeriodCharge($pdo) {
    global $logName, $host, $database, $username, $password;
    
    // 1. Look back exactly 7 days
    $targetDate = date('Y-m-d', strtotime("-7 days")); 
    writeLog($logName, "Checking for Grace Period Charges (DC Date was: $targetDate)");

    // DB Safety Check
    try {
        $pdo->query("SELECT 1");
    } catch (Exception $e) {
        writeLog($logName, "[DB RECONNECT] Connection timed out (Grace Period Start). Reconnecting...");
        try {
            $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'"
            ]);
        } catch (Exception $dbErr) {
            writeLog($logName, "[FATAL] Grace Period DB Reconnect Failed: " . $dbErr->getMessage());
            return;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM `Disconnected Logs` WHERE DATE(`Date`) = ? AND `Remarks` LIKE '%Auto DC%'");
    $stmt->execute([$targetDate]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $log) {
        $acct = $log['Account No.'];
        
        try { $pdo->query("SELECT 1"); } catch (Exception $e) { /* quiet reconnect */ }

        $uStmt = $pdo->prepare("SELECT `Billing Status`, `Plan`, `Account Balance` FROM `Billing Details` WHERE `Account No.` = ?");
        $uStmt->execute([$acct]);
        $user = $uStmt->fetch();

        if ($user && ($user['Billing Status'] == 'Inactive' || $user['Billing Status'] == 'Pullout')) {
            $planPrice = getPlanPrice($pdo, $user['Plan']);
            if ($planPrice > 0) {
                // RESTORED: Uses your config offset variable
                $daysToCharge = defined('CONF_DC_ACTUAL_OFFSET') ? CONF_DC_ACTUAL_OFFSET : 10;
                
                $dailyRate = $planPrice / 30; 
                $chargeAmount = round($dailyRate * $daysToCharge, 2);

                // Find the oldest Unpaid/Partial invoice to apply the charge to
                $invStmt = $pdo->prepare("SELECT `Invoice No.` FROM `Invoice` WHERE `Account No.` = ? AND (`Invoice Status` = 'Unpaid' OR `Invoice Status` = 'Partial') ORDER BY `Due Date` ASC LIMIT 1");
                $invStmt->execute([$acct]);
                $targetInvoice = $invStmt->fetchColumn();

                $pdo->beginTransaction();
                try {
                    // 1. UPDATE INVOICE (Adds to Service Charge, Total, and Inv Balance)
                    if ($targetInvoice) {
                        $updInv = "UPDATE `Invoice` 
                                   SET `Service Charge` = `Service Charge` + ?, 
                                       `Total Amount` = `Total Amount` + ?, 
                                       `Invoice Balance` = `Invoice Balance` + ?,
                                       `Remarks` = CONCAT(COALESCE(`Remarks`, ''), ' | GP Charge ($daysToCharge Days) ', NOW()),
                                       `Modified By` = 'System_GP', 
                                       `Modified Date` = NOW()
                                   WHERE `Invoice No.` = ?";
                        $pdo->prepare($updInv)->execute([$chargeAmount, $chargeAmount, $chargeAmount, $targetInvoice]);
                        writeLog($logName, "   > Added GP Charge to Invoice #$targetInvoice");
                    } else {
                        writeLog($logName, "   > Warning: No Unpaid Invoice found for $acct. Adding to Balance only.");
                    }

                    // 2. UPDATE ACCOUNT BALANCE
                    $newBal = floatval($user['Account Balance']) + $chargeAmount;
                    $pdo->prepare("UPDATE `Billing Details` SET `Account Balance` = ?, `Modified By` = 'System_GP', `Modified Date` = NOW() WHERE `Account No.` = ?")
                        ->execute([$newBal, $acct]);
                    
                    writeLog($logName, "Applied Grace Period Charge ($daysToCharge days) for $acct: +$chargeAmount");
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    writeLog($logName, "Error charging GP for $acct: " . $e->getMessage());
                }
            }
        } else {
            writeLog($logName, "Skipping GP Charge for $acct: User is Active or not found.");
        }
    }
}

function processAutoPullout($pdo) {
    global $logName, $host, $database, $username, $password;

    // DB SAFETY CHECK (Start of Pullout function)
    try {
        $pdo->query("SELECT 1");
    } catch (Exception $e) {
        writeLog($logName, "[DB RECONNECT] Connection timed out (Pullout Start). Reconnecting...");
        try {
            $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'"
            ]);
        } catch (Exception $dbErr) {
            writeLog($logName, "[FATAL] Pullout DB Reconnect Failed: " . $dbErr->getMessage());
            return;
        }
    }

    $targetDate = date('Y-m-d', strtotime("-" . CONF_PULLOUT_OFFSET . " days"));
    writeLog($logName, "Pullout Target Date: $targetDate");

    $stmt = $pdo->prepare("SELECT * FROM `Invoice` WHERE `Due Date` = ? AND (`Invoice Status` = 'Unpaid' OR `Invoice Status` = 'Partial')");
    $stmt->execute([$targetDate]);
    $rows = $stmt->fetchAll();
    writeLog($logName, "Invoices fetched for Pullout: " . count($rows));

    $count = 0;

    foreach ($rows as $inv) {
        $acct = $inv['Account No.'];
        writeLog($logName, "Processing Account: $acct");

        $chk = $pdo->prepare("SELECT COUNT(*) FROM `Service Order` WHERE `Account No.` = ? AND `Concern` = 'Pullout' AND `Support Status` != 'Closed'");
        $chk->execute([$acct]);
        if ($chk->fetchColumn() > 0) {
            writeLog($logName, "Skipped: Existing Pullout SO");
            continue;
        }

        $userStmt = $pdo->prepare("SELECT * FROM `Billing Details` WHERE `Account No.` = ?");
        $userStmt->execute([$acct]);
        $user = $userStmt->fetch();
        if (!$user) {
            writeLog($logName, "Skipped: Billing Details not found");
            continue;
        }

        try {
            $soID = date('YmdHis') . rand(100,999);
            writeLog($logName, "Creating SO ID: $soID");

            $pdo->prepare("INSERT INTO `Service Order` (`id`, `Timestamp`, `Account No.`, `Date Installed`, `Full Name`, `Contact Number`, `Email Address`, `Address`, `Location`, `Plan`, `Provider`, `Username`, `Connection Type`, `Router/Modem SN`, `LCP`, `NAP`, `PORT`, `VLAN`, `Support Status`, `Concern`, `Concern Remarks`, `Requested by`, `Modified By`, `Modified Date`, `Barangay`, `City`) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'In Progress', 'Pullout', 'System Auto Generated (Overdue " . CONF_PULLOUT_OFFSET . " Days)', 'System', 'System', NOW(), ?, ?)")->execute([
                $soID, $acct, getVal($user, 'Date Installed'), getVal($user, 'Full Name'), getVal($user, 'Contact Number'),
                getVal($user, 'Email Address'), getVal($user, 'Address'), getVal($user, 'Location'), getVal($user, 'Plan'),
                getVal($user, 'Provider'), getVal($user, 'Username'), getVal($user, 'Connection Type'), getVal($user, 'Router/Modem SN'),
                getVal($user, 'LCP'), getVal($user, 'NAP'), getVal($user, 'PORT'), getVal($user, 'VLAN'), getVal($user, 'Barangay'),
                getVal($user, 'City')
            ]);

            $count++;
            writeLog($logName, "Pullout SO Created for $acct");
        } catch (Exception $ex) {
            writeLog($logName, "SO Error $acct: " . $ex->getMessage());
        }
    }

    writeLog($logName, "Auto Pullout Complete. Created: $count");
}

function disconnectRadiusUser($username) {
    global $logName;
    writeLog($logName, "Disconnecting Radius User: $username");

    // 1. Find User ID
    $id = null;
    $endpointPath = "/rest/user-manage/user/" . urlencode($username);
    
    foreach (RADIUS_ENDPOINTS as $baseUrl) {
        $res = callApiWithRetry($baseUrl . $endpointPath, "GET", null, $logName);
        if ($res && isset($res['.id'])) {
            $id = $res['.id'];
            writeLog($logName, "Radius ID found: $id at $baseUrl");
            break; 
        }
    }

    if (!$id) {
        writeLog($logName, "Radius: User $username not found. Proceeding with DB Disconnect.");
        return true;
    }

    // 2. Patch Group
    $patchSuccess = false;
    foreach (RADIUS_ENDPOINTS as $baseUrl) {
        $url = $baseUrl . "/rest/user-manage/user/" . $id;
        if (callApiWithRetry($url, "PATCH", ["group" => "Disconnected"], $logName)) {
            writeLog($logName, "Radius group patched to Disconnected");
            $patchSuccess = true;
            break; 
        }
    }

    if (!$patchSuccess) {
        writeLog($logName, "Failed to patch group");
        return false;
    }

    // 3. Kill Sessions (OPTIMIZED: Check Empty & Stop on Success)
    $sessPath = "/rest/user-manage/session?user=" . urlencode($username);
    $activeSessions = [];

    // Get List
    foreach (RADIUS_ENDPOINTS as $baseUrl) {
        $res = callApiWithRetry($baseUrl . $sessPath, "GET", null, $logName);
        if ($res !== null) {
            // Host is reachable
            if (is_array($res) && !empty($res)) {
                $activeSessions = $res;
            }
            break; // Stop checking other hosts for the list
        }
    }

    // Skip if empty
    if (empty($activeSessions)) {
        writeLog($logName, "No active sessions found for $username. Skipping session kill.");
        return true;
    }

    // Kill Sessions
    foreach ($activeSessions as $s) {
        if (isset($s['.id'])) {
            foreach (RADIUS_ENDPOINTS as $baseUrl) {
                $delUrl = $baseUrl . "/rest/user-manage/session/" . $s['.id'];
                
                // callApiWithRetry returns not null on success (reachable)
                $delResult = callApiWithRetry($delUrl, "DELETE", null, $logName);
                
                if ($delResult !== null) {
                    // Success, break loop to stop hitting other servers for this session
                    writeLog($logName, "Session {$s['.id']} killed on $baseUrl.");
                    break; 
                }
            }
        }
    }

    return true;
}

function triggerSMS($acctNo, $type, $data) {
    global $logName;
    $url = "https://" . ($_SERVER['HTTP_HOST'] ?? 'cbms.akmiis.com') . "/unified_sms.php";
    $data['action'] = $type;
    $data['accountNumber'] = $acctNo;

    writeLog($logName, "Triggering SMS: $type for $acctNo");

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_exec($ch);
    curl_close($ch);
}

function getVal($row, $key) {
    if (isset($row[$key])) return $row[$key];
    $lowerKey = strtolower($key);
    foreach ($row as $k => $v) {
        if (strtolower($k) === $lowerKey) return $v;
    }
    return '';
}

function getPlanPrice($pdo, $planName) {
    try {
        $stmt = $pdo->prepare("SELECT `Price` FROM `Plan List` WHERE `Plan Name` = ?");
        $stmt->execute([$planName]);
        return floatval($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        return 0.0;
    }
}
?>
