<?php
/**
 * Request All Documents Script
 * Calls the request_all_documents API endpoint, checks for existing documents,
 * and adds new documents to the download queue in batches
 */

// Set working directory to project src root
chdir(__DIR__ . '/..');

require_once 'ApiClient.php';
require_once 'logging/ApiCallLogger.php';
require_once 'DatabaseManager.php';
require_once 'session/SessionManager.php';
require_once 'queue/QueueManager.php';

// Load configuration
$config = include 'config.php';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 1800);
set_time_limit(1800);

echo "Starting request_all_documents script..." . PHP_EOL;

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

    // Initialize API client and logger
    $apiClient = new ApiClient(
        $config['api']['base_url'],
        $config['api']['username'],
        $config['api']['password'],
        $config['api']['timeout'],
        $config['api']['user_agent']
    );

    $logger = new ApiCallLogger($dbManager, ['tool' => 'request_all_documents']);
    $apiClient->setLogger($logger);

    // Initialize session manager for automatic session refresh
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['tool' => 'request_all_documents']);

    // Get or create active session (will create if needed)
    echo "Getting or creating API session..." . PHP_EOL;
    $sessionId = $sessionManager->getOrCreateSession();

    if (!$sessionId) {
        throw new Exception("Failed to get or create API session");
    }

    echo "Session active: " . substr($sessionId, 0, 20) . "..." . PHP_EOL;

    // Call request_all_documents with automatic session refresh on "SID not found"
    echo "Calling request_all_documents endpoint..." . PHP_EOL;
    $documentsResponse = $sessionManager->executeWithContext(
        [
            'tool' => 'request_all_documents',
            'operation' => 'request_all_documents'
        ],
        static function ($client) {
            return $client->requestAllDocuments();
        }
    );

    if (!isset($documentsResponse['documents']) || !is_array($documentsResponse['documents'])) {
        throw new Exception("request_all_documents response did not contain a valid 'documents' array");
    }

    $documents = $documentsResponse['documents'];
    $documentCount = count($documents);

    echo "======================================" . PHP_EOL;
    echo "Total document names returned: {$documentCount}" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    
    // Debug: Show document names and their lengths
    if ($documentCount > 0) {
        echo "Sample document names (first 5):" . PHP_EOL;
        $sample = array_slice($documents, 0, min(5, $documentCount));
        foreach ($sample as $doc) {
            echo "  Length: " . strlen($doc) . " - " . substr($doc, 0, 80) . (strlen($doc) > 80 ? '...' : '') . PHP_EOL;
        }
    }

    // Initialize queue manager
    $queueManager = new QueueManager($dbManager);

    // Batch processing configuration
    $existenceCheckChunkSize = 1000;
    $queueInsertChunkSize = 500;
    $processingChunkSize = 1000;

    // Process documents in batches
    $totalProcessed = 0;
    $totalExisting = 0;
    $totalAdded = 0;
    $chunks = array_chunk($documents, $processingChunkSize);
    $totalChunks = count($chunks);

    echo "Processing {$documentCount} documents in {$totalChunks} batch(es)..." . PHP_EOL;
    echo "Existence check chunk size: {$existenceCheckChunkSize}, Queue insert chunk size: {$queueInsertChunkSize}" . PHP_EOL;
    echo PHP_EOL;

    foreach ($chunks as $chunkIndex => $chunk) {
        $chunkNumber = $chunkIndex + 1;
        $chunkSize = count($chunk);
        
        echo "[Batch {$chunkNumber}/{$totalChunks}] Processing {$chunkSize} documents..." . PHP_EOL;

        // Step 1: Bulk check which documents already exist
        echo "  Checking which documents already exist in database..." . PHP_EOL;
        $existingNames = $dbManager->checkDocumentsExistBulk($chunk, $existenceCheckChunkSize);
        $existingCount = count($existingNames);
        $totalExisting += $existingCount;

        // Step 2: Filter out existing documents
        $existingLookup = array_flip($existingNames);
        $newDocuments = array_filter($chunk, function($docName) use ($existingLookup) {
            return !isset($existingLookup[$docName]);
        });
        $newCount = count($newDocuments);

        echo "  Found {$existingCount} existing, {$newCount} new documents" . PHP_EOL;

        // Step 3: Add new documents to queue (bulk insert)
        if ($newCount > 0) {
            echo "  Adding {$newCount} new document(s) to download queue..." . PHP_EOL;
            $addedInChunk = $queueManager->addBulkToQueue($newDocuments, 5, $queueInsertChunkSize);
            $totalAdded += $addedInChunk;
            echo "  Added {$addedInChunk} document(s) to queue" . PHP_EOL;
        } else {
            echo "  No new documents to add" . PHP_EOL;
        }

        $totalProcessed += $chunkSize;
        echo "  Batch {$chunkNumber} completed. Progress: {$totalProcessed}/{$documentCount}" . PHP_EOL;
        echo PHP_EOL;

        // Delay between batches to reduce database load (0.5-1 second)
        if ($chunkIndex < $totalChunks - 1) {
            usleep(750000); // 0.75 second delay
        }
    }

    // Get final queue statistics
    $stats = $queueManager->getQueueStats();

    echo "======================================" . PHP_EOL;
    echo "Processing Summary:" . PHP_EOL;
    echo "  Total documents from API: {$documentCount}" . PHP_EOL;
    echo "  Documents already in database: {$totalExisting}" . PHP_EOL;
    echo "  New documents added to queue: {$totalAdded}" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "Queue Statistics:" . PHP_EOL;
    echo "  Pending: {$stats['pending']}" . PHP_EOL;
    echo "  Processing: {$stats['processing']}" . PHP_EOL;
    echo "  Completed: {$stats['completed']}" . PHP_EOL;
    echo "  Failed: {$stats['failed']}" . PHP_EOL;
    echo "  Retry: {$stats['retry']}" . PHP_EOL;
    echo "======================================" . PHP_EOL;

    $dbManager->close();

    echo "request_all_documents script completed successfully." . PHP_EOL;
    exit(0);

} catch (Exception $e) {
    $message = $e->getMessage();
    error_log("[" . date('Y-m-d H:i:s') . "] request_all_documents ERROR: " . $message);
    echo "ERROR: " . $message . PHP_EOL;
    exit(1);
}


