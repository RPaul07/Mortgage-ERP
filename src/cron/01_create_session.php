<?php
/**
 * Cron Job 1: Create API Session
 * Creates or verifies an active API session
 * Runs: Every hour at :05
 */

// Set working directory
chdir(__DIR__ . '/..');

//require_once 'config.php';
require_once 'ApiClient.php';
require_once 'DatabaseManager.php';
require_once 'session/SessionManager.php';
require_once 'logging/ApiCallLogger.php';

// Load configuration
$config = include 'config.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_log("[" . date('Y-m-d H:i:s') . "] Starting session creation cron job");

try {
    // Initialize database manager
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Initialize API client
    $apiClient = new ApiClient(
        $config['api']['base_url'],
        $config['api']['username'],
        $config['api']['password'],
        $config['api']['timeout'],
        $config['api']['user_agent']
    );
    $logger = new ApiCallLogger($dbManager, ['cron_job' => '01_create_session']);
    $apiClient->setLogger($logger);
    
    // Initialize session manager
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['cron_job' => '01_create_session']);
    
    // Get or create session
    $sessionId = $sessionManager->getOrCreateSession();
    
    error_log("[" . date('Y-m-d H:i:s') . "] Session created/verified: $sessionId");
    
    // Cleanup expired sessions
    $cleaned = $sessionManager->cleanupExpiredSessions();
    if ($cleaned > 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Cleaned up $cleaned expired session(s)");
    }
    
    $dbManager->close();
    
    echo "Session created/verified successfully: $sessionId\n";
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

