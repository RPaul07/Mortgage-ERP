<?php
/**
 * Request All Loans Script
 * Calls the request_all_loans API endpoint and outputs the total loan count
 */

// Set working directory to project src root
chdir(__DIR__ . '/..');

require_once 'ApiClient.php';
require_once 'logging/ApiCallLogger.php';
require_once 'DatabaseManager.php';

// Load configuration
$config = include 'config.php';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Starting request_all_loans script..." . PHP_EOL;

try {
    // Initialize database manager (used for API logging)
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );

    // Initialize API client and logger
    $apiClient = new ApiClient(
        $config['api']['base_url'],
        $config['api']['username'],
        $config['api']['password'],
        $config['api']['timeout'],
        $config['api']['user_agent']
    );

    $logger = new ApiCallLogger($dbManager, ['tool' => 'request_all_loans']);
    $apiClient->setLogger($logger);

    // Always start with a fresh session (createSession clears internally)
    echo "Creating new API session..." . PHP_EOL;
    $sessionResponse = $apiClient->createSession();

    if (!isset($sessionResponse['result']) || !is_array($sessionResponse['result'])) {
        throw new Exception("Invalid session creation response structure");
    }

    if (!isset($sessionResponse['result'][0]) || $sessionResponse['result'][0] !== "Status: OK") {
        throw new Exception("Session creation failed: " . ($sessionResponse['result'][0] ?? 'Unknown error'));
    }

    if (!isset($sessionResponse['result'][2])) {
        throw new Exception("Session ID not found in response");
    }

    $sessionId = $sessionResponse['result'][2];
    if (empty($sessionId) || !is_string($sessionId)) {
        throw new Exception("Invalid session ID format: " . var_export($sessionId, true));
    }

    echo "Session created successfully: " . substr($sessionId, 0, 20) . "..." . PHP_EOL;

    // Call request_all_loans
    echo "Calling request_all_loans endpoint..." . PHP_EOL;
    $loansResponse = $apiClient->requestAllLoans();

    if (!isset($loansResponse['loans']) || !is_array($loansResponse['loans'])) {
        throw new Exception("request_all_loans response did not contain a valid 'loans' array");
    }

    $loans = $loansResponse['loans'];
    $loanCount = count($loans);

    echo "======================================" . PHP_EOL;
    echo "Total loan IDs returned: {$loanCount}" . PHP_EOL;
    echo "======================================" . PHP_EOL;

    // Optionally print a sample of loan IDs for inspection
    $sampleSize = min(20, $loanCount);
    if ($sampleSize > 0) {
        echo "First {$sampleSize} loan IDs:" . PHP_EOL;
        for ($i = 0; $i < $sampleSize; $i++) {
            echo "- " . $loans[$i] . PHP_EOL;
        }
    }

    $dbManager->close();

    echo "request_all_loans script completed successfully." . PHP_EOL;
    exit(0);

} catch (Exception $e) {
    $message = $e->getMessage();
    error_log("[" . date('Y-m-d H:i:s') . "] request_all_loans ERROR: " . $message);
    echo "ERROR: " . $message . PHP_EOL;
    exit(1);
}


