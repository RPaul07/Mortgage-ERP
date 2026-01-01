<?php
/**
 * Cron Job 4: Close API Session (Cleanup)
 * Closes old sessions and performs cleanup
 * Runs: Every hour at :00
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
error_log("[" . date('Y-m-d H:i:s') . "] Starting session cleanup cron job");

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
    $logger = new ApiCallLogger($dbManager, ['cron_job' => '04_close_session']);
    $apiClient->setLogger($logger);
    
    // Initialize session manager
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['cron_job' => '04_close_session']);
    
    // Cleanup expired sessions
    $cleaned = $sessionManager->cleanupExpiredSessions();
    error_log("[" . date('Y-m-d H:i:s') . "] Cleaned up $cleaned expired session(s)");
    
    // Close sessions older than 50 minutes (safety margin before 1 hour expiration)
    $connection = $dbManager->getConnection();
    
    $stmt = $connection->prepare("
        SELECT session_id FROM api_session_state
        WHERE is_active = 1
        AND created_at < DATE_SUB(NOW(), INTERVAL 50 MINUTE)
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $oldSessions = [];
    while ($row = $result->fetch_assoc()) {
        $oldSessions[] = $row['session_id'];
    }
    $stmt->close();
    
    $closedCount = 0;
    foreach ($oldSessions as $sessionId) {
        if ($sessionManager->closeSession($sessionId)) {
            $closedCount++;
            error_log("[" . date('Y-m-d H:i:s') . "] Closed old session: $sessionId");
        }
    }
    
    if ($closedCount > 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Closed $closedCount old session(s)");
    }
    
    $dbManager->close();
    
    echo "Cleanup completed - Expired: $cleaned, Closed: $closedCount\n";
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

