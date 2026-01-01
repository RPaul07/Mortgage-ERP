<?php
/**
 * Cron Job 2: Query Files and Add to Queue
 * Queries API for available files and adds them to download queue
 * Runs: Every hour at :10
 */

// Set working directory
chdir(__DIR__ . '/..');

//require_once 'config.php';
require_once 'ApiClient.php';
require_once 'DatabaseManager.php';
require_once 'session/SessionManager.php';
require_once 'queue/QueueManager.php';
require_once 'logging/ApiCallLogger.php';

// Load configuration
$config = include 'config.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("[" . date('Y-m-d H:i:s') . "] Starting file query cron job");

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
    $logger = new ApiCallLogger($dbManager, ['cron_job' => '02_query_files']);
    $apiClient->setLogger($logger);
    
    // Initialize session manager
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['cron_job' => '02_query_files']);
    
    // Get active session (will create if needed)
    $sessionId = $sessionManager->getOrCreateSession();
    
    if (!$sessionId) {
        throw new Exception("Failed to get or create API session");
    }
    
    // Set session ID in ApiClient (required for API calls)
    $apiClient->setSessionId($sessionId);
    
    // Query files from API
    error_log("[" . date('Y-m-d H:i:s') . "] Querying files from API...");
    $filesResponse = $sessionManager->executeWithContext(
        [
            'cron_job' => '02_query_files',
            'operation' => 'query_files'
        ],
        static function ($client) {
            return $client->queryFiles();
        }
    );
    
    // Parse files from response
    if (!isset($filesResponse['files']) || !is_array($filesResponse['files'])) {
        throw new Exception("API response missing decoded file list.");
    }
    
    $files = $filesResponse['files'];
    
    $fileCount = count($files);
    error_log("[" . date('Y-m-d H:i:s') . "] Found $fileCount file(s) from API");
    
    // Initialize queue manager
    $queueManager = new QueueManager($dbManager);
    
    // Add files to queue
    $addedCount = $queueManager->addBatchToQueue($files, 5);
    
    error_log("[" . date('Y-m-d H:i:s') . "] Added $addedCount file(s) to download queue");
    
    // Get queue statistics
    $stats = $queueManager->getQueueStats();
    error_log("[" . date('Y-m-d H:i:s') . "] Queue stats - Pending: {$stats['pending']}, Processing: {$stats['processing']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}, Retry: {$stats['retry']}");
    
    $dbManager->close();
    
    echo "Query completed: $fileCount files found, $addedCount added to queue\n";
    echo "Queue status: Pending: {$stats['pending']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}\n";
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


