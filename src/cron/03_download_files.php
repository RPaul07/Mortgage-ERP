<?php
/**
 * Cron Job 3: Download Files from Queue
 * Processes download queue and downloads files
 * Runs: Every 5 minutes (every 5 minutes)
 */

// Set working directory
chdir(__DIR__ . '/..');

//require_once 'config.php';
require_once 'ApiClient.php';
require_once 'DatabaseManager.php';
require_once 'FileProcessor.php';
require_once 'session/SessionManager.php';
require_once 'queue/QueueManager.php';
require_once 'logging/ApiCallLogger.php';

// Load configuration
$config = include 'config.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 900); // 15 minutes max
set_time_limit(900);

error_log("[" . date('Y-m-d H:i:s') . "] Starting file download cron job");

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
    $logger = new ApiCallLogger($dbManager, ['cron_job' => '03_download_files']);
    $apiClient->setLogger($logger);
    
    // Initialize session manager
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['cron_job' => '03_download_files']);
    
    // Get active session
    $sessionId = $sessionManager->getActiveSessionId();
    
    if (!$sessionId) {
        error_log("[" . date('Y-m-d H:i:s') . "] No active session found, attempting to create one...");
        $sessionId = $sessionManager->getOrCreateSession();
    }
    
    if (!$sessionId) {
        throw new Exception("Failed to get or create API session");
    }
    
    // Set session ID in ApiClient
    $apiClient->setSessionId($sessionId);
    
    // Initialize queue manager
    $queueManager = new QueueManager($dbManager);
    
    // Reset stuck processing items
    $resetCount = $queueManager->resetStuckItems();
    if ($resetCount > 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Reset $resetCount stuck processing item(s)");
    }
    
    // Get batch size from config
    $batchSize = 30;
    $fileDelay = $config['file_processing']['file_delay'] ?? 1;
    $checkInterval = 15;
    $pauseOnHighUsage = $config['resource_monitoring']['pause_on_high_usage'] ?? 5;
    
    // Get next batch of files to process
    $queueItems = $queueManager->getNextBatch($batchSize, ['pending']);
    
    if (empty($queueItems)) {
        error_log("[" . date('Y-m-d H:i:s') . "] No files in queue to process");
        $dbManager->close();
        echo "No files to process\n";
        exit(0);
    }
    
    $processedCount = count($queueItems);
    error_log("[" . date('Y-m-d H:i:s') . "] Processing $processedCount file(s) from queue");
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($queueItems as $index => $item) {
        $queueId = $item['queue_id'];
        $fileId = $item['file_id'];
        
        
        $maxAttempts = 3;
        $attempt = 0;
        $fileProcessed = false;
        $lastError = null;
        
        while ($attempt < $maxAttempts && !$fileProcessed) {
            $attempt++;
            
            try {
                
                if ($attempt === 1) {
                    $queueManager->markProcessing($queueId);
                }
                
                
                if ($attempt === 1 && $index > 0 && ($index + 1) % $checkInterval === 0) {
                    $resourceStatus = FileProcessor::checkSystemResources($config['resource_monitoring']);
                    
                    if ($resourceStatus['should_pause']) {
                        error_log("[" . date('Y-m-d H:i:s') . "] Pausing for $pauseOnHighUsage seconds due to high resource usage");
                        sleep($pauseOnHighUsage);
                    }
                }
                
                // Log retry attempt if not first attempt
                if ($attempt > 1) {
                    error_log("[" . date('Y-m-d H:i:s') . "] Retry attempt $attempt/$maxAttempts for $fileId (previous error: $lastError)");
                }
                
                // Download file
                $fileResponse = $sessionManager->executeWithContext(
                    [
                        'cron_job' => '03_download_files',
                        'operation' => 'request_file',
                        'file_id' => $fileId,
                        'queue_id' => $queueId
                    ],
                    static function ($client) use ($fileId) {
                        return $client->requestFile($fileId);
                    }
                );
                
                // Validate file content
                $validation = FileProcessor::validateFileContent($fileResponse['raw_result']);
                
                if (!$validation['valid']) {
                    throw new Exception($validation['message']);
                }
                
                // Check MIME type
                $mimeCheck = FileProcessor::checkMimeType($fileResponse['raw_result']);
                
                if (!$mimeCheck['is_pdf']) {
                    // Non-PDF files: mark as failed immediately, no retry
                    $queueManager->markFailed($queueId, $mimeCheck['message'], false);
                    $errorCount++;
                    error_log("[" . date('Y-m-d H:i:s') . "] Skipped $fileId - " . $mimeCheck['message']);
                    $fileProcessed = true; // Exit retry loop
                    continue; // Move to next file
                }
                
                // Parse filename
                $fileInfo = FileProcessor::parseFilename($fileId);
                $loanNumber = $fileInfo['loan_number'];
                $documentType = FileProcessor::normalizeDocumentType($fileInfo['document_type']);
                $fileSize = strlen($fileResponse['raw_result']);
                
                // Check if duplicate
                $isDuplicate = $dbManager->documentExists($fileId);
                
                // Insert into database
                $docId = $dbManager->insertDocument($fileId, $loanNumber, $documentType, $fileResponse['raw_result']);
                
                // Mark as completed in queue
                $queueManager->markCompleted($queueId);
                $successCount++;
                $fileProcessed = true; // Success - exit retry loop
                
                $duplicateNote = $isDuplicate ? " (duplicate)" : "";
                $attemptNote = $attempt > 1 ? " (attempt $attempt)" : "";
                error_log("[" . date('Y-m-d H:i:s') . "] Successfully downloaded $fileId$duplicateNote$attemptNote - Loan: $loanNumber, Type: $documentType, Size: " . FileProcessor::formatBytes($fileSize));
                
                // Delay between files (only after successful processing)
                if ($fileDelay > 0 && $index < count($queueItems) - 1) {
                    sleep($fileDelay);
                }
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                
                // If this is the last attempt, mark as failed permanently
                if ($attempt >= $maxAttempts) {
                    $queueManager->markFailed($queueId, $lastError, false); // No retry - all attempts exhausted
                    $errorCount++;
                    error_log("[" . date('Y-m-d H:i:s') . "] ERROR processing $fileId after $maxAttempts attempts: $lastError");
                    $fileProcessed = true; // Exit retry loop
                } else {
                    // Not last attempt - will retry immediately
                    error_log("[" . date('Y-m-d H:i:s') . "] Error on attempt $attempt/$maxAttempts for $fileId: $lastError (will retry)");
                    // Small delay before retry (exponential backoff: 1s, 2s)
                    $retryDelay = $attempt;
                    sleep($retryDelay);
                }
            }
        }
    }
    
    // Get updated queue statistics
    $stats = $queueManager->getQueueStats();
    
    error_log("[" . date('Y-m-d H:i:s') . "] Download batch completed - Success: $successCount, Errors: $errorCount");
    error_log("[" . date('Y-m-d H:i:s') . "] Queue stats - Pending: {$stats['pending']}, Processing: {$stats['processing']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}, Retry: {$stats['retry']}");
    
    $dbManager->close();
    
    echo "Processed $processedCount file(s) - Success: $successCount, Errors: $errorCount\n";
    echo "Queue status: Pending: {$stats['pending']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}, Retry: {$stats['retry']}\n";
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage());
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

