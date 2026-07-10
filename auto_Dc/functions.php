<?php
// functions.php
// Purpose: Global Configuration & Helper Functions
// Status: MASTER CONFIGURATION FILE

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

// =================================================================================
// 0. GLOBAL PATH CONFIGURATION (Absolute Paths for Hostinger)
// =================================================================================

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}

// =================================================================================
// 1. BILLING CYCLE & LOGIC SETUP (ADJUST PER DEPLOYMENT)
// =================================================================================

define('CONF_GEN_ADVANCE_DAYS', 1); // Days before billing date to generate SOA
define('CONF_DUE_DAYS_ADD', 0);     // Days after billing date for due date

// Notices: Days AFTER Due Date to trigger specific events
define('CONF_OVERDUE_OFFSET', 1);    // Overdue Notice
define('CONF_DC_NOTICE_OFFSET', 9);  // Disconnection Notice
define('CONF_DC_ACTUAL_OFFSET', 10);  // Actual Disconnection
define('CONF_PULLOUT_OFFSET', 17);   // Schedule Pullout

define('CONF_ENABLE_PRORATE', false);
define('CONF_ENABLE_PLAN_CHANGE_PRORATE', false);

define('CONF_ENABLE_NEW_ACCT_PRORATE', true);

define('CONF_DC_FEE', 0.00);       // Disconnection Fee
define('CONF_ACCOUNT_START_ID', 1001);

define('CONF_PAYMENT_LINK', 'https://portal.akmiis.com');
define('CONF_COMPANY_NAME', 'AKM');

// =================================================================================
// 2. INTEGRATION CREDENTIALS
// =================================================================================

define('INTERNAL_API_KEY', 'b85311edbbc4d4604f9123240d6e5aec1cdcf5b0abc6cd0b6a4a974c33d6980a');

define('API_USER', 'googleapi');
define('API_PASS', 'Akm2025!');
define('RADIUS_ENDPOINTS', [
    'https://163.223.115.122',
    'https://163.223.115.122'
]);

define('RESEND_API_KEY', 're_82hoxFZB_B6Jgg9ZDF7YwRwkb8e9LqgJk');
define('RESEND_SENDER_EMAIL', 'billing@akmiis.com');
define('RESEND_SENDER_NAME', 'AKM Billing');

define('CONF_ITEXMO_EMAIL', 'maningassss@gmail.com');
define('CONF_ITEXMO_PASS', 'Khenjie2005');
define('CONF_ITEXMO_CODE', 'PR-MEDIA100972_XH6UI');
define('CONF_ITEXMO_SENDER', 'MEDIACON');
// Set to 'ITEXMO' or 'SEMAPHORE'
define('CONF_SMS_PROVIDER', 'SEMAPHORE'); 

// Add this to your config file
define('CONF_SEMAPHORE_KEY', '4ec06380a03c4bcb14030fd54c8e0def');
define('CONF_SEMAPHORE_API_KEY', '4ec06380a03c4bcb14030fd54c8e0def');
define('CONF_SEMAPHORE_SENDER', 'MEDIACON'); // Or your approved sender name

define('CONF_DRIVE_FOLDERS', [
    'SOA'       => '174_VQMtoJuZu398AUMJfA6KBP_0gxrIu',
    'Overdue'   => '1RmtfYw4oWG_BDhbcqh2EMMUoxFr9x0Do',
    'DC Notice' => '1cNlDvGNtaTIRbZZ5p3bKXHzX-dYympou'
]);
define('LIBREOFFICE_TOKEN', 'O0u7Dijxptg1WH5UhjKQhLplOCYMDXuZt8j7kUdAlUYOhSLttIR5zU6ahu998AjY');
define('LIBREOFFICE_CONVERTER_URL', 'https://cbms.akmiis.com/pdf_engine/libreoffice_pdf_converter.php');
define('LIBREOFFICE_TEMPLATE_DIR', __DIR__ . '/templates/');



function generateLibreOfficePdf($data, $templateCode, $pdfName, $type = 'SOA') {
    $templateFile = LIBREOFFICE_TEMPLATE_DIR . $templateCode . '.docx';
    
    // Validate Template
    if (!file_exists($templateFile)) {
        writeLog('LibreOffice_PDF', "Template missing: $templateCode");
        return ['url' => '', 'path' => ''];
    }

    // Create Temp File for Data Injection
    $tempDocx = LIBREOFFICE_TEMPLATE_DIR . 'temp_' . uniqid() . '.docx';
    if (!copy($templateFile, $tempDocx)) {
        writeLog('LibreOffice_PDF', "Failed to copy template.");
        return ['url' => '', 'path' => ''];
    }

    // Inject Data
    $zip = new ZipArchive();
    if ($zip->open($tempDocx) === TRUE) {
        $xml = $zip->getFromName('word/document.xml');
        if ($xml) {
            // Using a simple str_replace is fast, but be careful with XML entities
            foreach ($data as $key => $value) {
                // FIXED: Added '?? ""' to handle null values safely
                $xml = str_replace('{{' . $key . '}}', htmlspecialchars($value ?? ''), $xml);
            }
            $zip->addFromString('word/document.xml', $xml);
        }
        $zip->close();
    } else {
        unlink($tempDocx); // Fail safe
        return ['url' => '', 'path' => ''];
    }

    // Call Converter
    $folderMap = ['SOA' => 'SOA/', 'DCNOTICE' => 'DC Notice/', 'OVERDUE' => 'Overdue/'];
    $outputSubdir = $folderMap[strtoupper($type)] ?? 'generated_pdfs/';
    
    $url = LIBREOFFICE_CONVERTER_URL . '?' . http_build_query([
        'template' => basename($tempDocx),
        'output'   => $outputSubdir . $pdfName . '.pdf',
        'token'    => LIBREOFFICE_TOKEN
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45, // Increased for safety
        CURLOPT_URL => $url
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // *** CRITICAL CLEANUP: Delete the temp .docx immediately ***
    if (file_exists($tempDocx)) {
        unlink($tempDocx);
    }

    $json = json_decode($response, true);
    if ($httpCode === 200 && isset($json['path']) && !empty($json['path'])) {
        return ['url' => $json['url'], 'path' => $json['path']];
    }

    writeLog('LibreOffice_PDF', "Conversion Failed [$httpCode]: " . substr($response, 0, 100));
    return ['url' => '', 'path' => ''];
}


// =================================================================================
// 3. SYSTEM LOGGING & HELPERS
// =================================================================================

function writeLog($logName, $message) {
    $logDir = ROOT_PATH . 'logs';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $file = $logDir . '/' . $logName . '_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";

    file_put_contents($file, $logEntry, FILE_APPEND);
}

function callApiWithRetry($url, $method, $payload = null, $logName = 'Global_API') {
    $auth = base64_encode(API_USER . ':' . API_PASS);
    $headers = [
        "Authorization: Basic $auth",
        "Content-Type: application/json"
    ];
    $maxRetries = 3;
    $retryInterval = 10;
    $attempt = 0;

    if ($payload !== null) {
        $jsonPayload = json_encode($payload);
        writeLog($logName, "[PAYLOAD] $jsonPayload");
    }

    do {
        $attempt++;
        writeLog($logName, "[ATTEMPT $attempt] Calling $method $url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $responseSize = strlen($response ?? '');

        if ($responseSize > 20480) {
            $sizeKb = round($responseSize / 1024, 1);
            writeLog($logName, "[HTTP $httpCode] Response: [Large payload suppressed: {$sizeKb} KB]");
        } else {
            writeLog($logName, "[HTTP $httpCode] Response: " . ($response ?: 'NULL'));
        }

        if ($error) {
            writeLog($logName, "[CURL ERROR] $error");
        }

        // --- FIX START: Handle 204 Success explicitly ---
        if ($httpCode === 204) {
            writeLog($logName, "[SUCCESS] API 204 No Content (Action Successful)");
            return true; // Return TRUE so the calling script stops retrying
        }
        // --- FIX END ---

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            writeLog($logName, "[SUCCESS] API call OK");
            // If response is empty but code is 200-299, return true to avoid null
            $decoded = json_decode($response, true);
            return $decoded ?: true;
        }

        if ($httpCode === 404) {
            writeLog($logName, "[NOT FOUND] 404 returned");
            return null;
        }

        if ($attempt < $maxRetries) {
            writeLog($logName, "[RETRY] Waiting $retryInterval seconds before next attempt");
            sleep($retryInterval);
        }

    } while ($attempt < $maxRetries);

    writeLog($logName, "[FAILURE] Gave up after $maxRetries attempts");
    return null;
}
?>
