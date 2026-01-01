<?php
/**
 * Manual Download Files Script
 * Replicates the download cron job process with 90-second pauses between batches
 * Processes files continuously until no more pending files are available
 */

// Set working directory
chdir(__DIR__ . '/..');

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
ini_set('max_execution_time', 0); // No time limit for manual script
set_time_limit(0);

echo "======================================" . PHP_EOL;
echo "Manual Download Files Script" . PHP_EOL;
echo "======================================" . PHP_EOL;
echo "Starting at: " . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

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
    $logger = new ApiCallLogger($dbManager, ['tool' => 'manual_download_files']);
    $apiClient->setLogger($logger);
    
    // Initialize session manager
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['tool' => 'manual_download_files']);
    
    // Initialize queue manager
    $queueManager = new QueueManager($dbManager);
    
    // Configuration
    $batchSize = 30;
    $fileDelay = $config['file_processing']['file_delay'] ?? 1;
    $checkInterval = 15;
    $pauseOnHighUsage = $config['resource_monitoring']['pause_on_high_usage'] ?? 5;
    $batchPauseSeconds = 90; // 90 seconds between batches
    
    // Statistics across all batches
    $totalBatches = 0;
    $totalProcessed = 0;
    $totalSuccess = 0;
    $totalErrors = 0;
    
    // Main processing loop - continue until no more files
    while (true) {
        // Get active session (will create if needed)
        $sessionId = $sessionManager->getActiveSessionId();
        
        if (!$sessionId) {
            echo "No active session found, attempting to create one..." . PHP_EOL;
            $sessionId = $sessionManager->getOrCreateSession();
        }
        
        if (!$sessionId) {
            throw new Exception("Failed to get or create API session");
        }
        
        // Set session ID in ApiClient
        $apiClient->setSessionId($sessionId);
        
        // Reset stuck processing items
        $resetCount = $queueManager->resetStuckItems();
        if ($resetCount > 0) {
            echo "Reset $resetCount stuck processing item(s)" . PHP_EOL;
        }
        
        // Get next batch of files to process
        $queueItems = $queueManager->getNextBatch($batchSize, ['pending']);
        
        if (empty($queueItems)) {
            echo PHP_EOL;
            echo "======================================" . PHP_EOL;
            echo "No more files to process!" . PHP_EOL;
            echo "======================================" . PHP_EOL;
            break; // Exit loop - no more files
        }
        
        $totalBatches++;
        $processedCount = count($queueItems);
        $totalProcessed += $processedCount;
        
        echo PHP_EOL;
        echo "======================================" . PHP_EOL;
        echo "Batch #$totalBatches - Processing $processedCount file(s)" . PHP_EOL;
        echo "======================================" . PHP_EOL;
        echo "Started at: " . date('Y-m-d H:i:s') . PHP_EOL;
        echo PHP_EOL;
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($queueItems as $index => $item) {
            $queueId = $item['queue_id'];
            $fileId = $item['file_id'];
            
            // Retry logic: up to 3 attempts per file
            $maxAttempts = 3;
            $attempt = 0;
            $fileProcessed = false;
            $lastError = null;
            
            while ($attempt < $maxAttempts && !$fileProcessed) {
                $attempt++;
                
                try {
                    // Mark as processing (only on first attempt)
                    if ($attempt === 1) {
                        $queueManager->markProcessing($queueId);
                    }
                    
                    // Resource monitoring check (only on first attempt per file)
                    if ($attempt === 1 && $index > 0 && ($index + 1) % $checkInterval === 0) {
                        $resourceStatus = FileProcessor::checkSystemResources($config['resource_monitoring']);
                        
                        if ($resourceStatus['should_pause']) {
                            echo "  [Pause] High resource usage detected. Pausing for $pauseOnHighUsage seconds..." . PHP_EOL;
                            sleep($pauseOnHighUsage);
                        }
                    }
                    
                    // Log retry attempt if not first attempt
                    if ($attempt > 1) {
                        echo "  [Retry $attempt/$maxAttempts] $fileId (previous error: $lastError)" . PHP_EOL;
                    }
                    
                    // Download file
                    $fileResponse = $sessionManager->executeWithContext(
                        [
                            'tool' => 'manual_download_files',
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
                        echo "  [SKIP] $fileId - " . $mimeCheck['message'] . PHP_EOL;
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
                    echo "  [OK] $fileId$duplicateNote$attemptNote - Loan: $loanNumber, Type: $documentType, Size: " . FileProcessor::formatBytes($fileSize) . PHP_EOL;
                    
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
                        echo "  [FAIL] $fileId after $maxAttempts attempts: $lastError" . PHP_EOL;
                        $fileProcessed = true; // Exit retry loop
                    } else {
                        // Not last attempt - will retry immediately
                        echo "  [ERROR] Attempt $attempt/$maxAttempts for $fileId: $lastError (retrying...)" . PHP_EOL;
                        // Exponential backoff delay: 1s, 2s
                        $retryDelay = $attempt;
                        sleep($retryDelay);
                    }
                }
            }
        }
        
        $totalSuccess += $successCount;
        $totalErrors += $errorCount;
        
        // Get updated queue statistics
        $stats = $queueManager->getQueueStats();
        
        echo PHP_EOL;
        echo "Batch #$totalBatches completed at: " . date('Y-m-d H:i:s') . PHP_EOL;
        echo "  Success: $successCount, Errors: $errorCount" . PHP_EOL;
        echo "  Queue stats - Pending: {$stats['pending']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}, Retry: {$stats['retry']}" . PHP_EOL;
        
        // Check if there are more files to process
        $remainingPending = $stats['pending'];
        
        if ($remainingPending > 0) {
            echo PHP_EOL;
            echo "======================================" . PHP_EOL;
            echo "Pausing for $batchPauseSeconds seconds before next batch..." . PHP_EOL;
            echo "Remaining pending files: $remainingPending" . PHP_EOL;
            echo "======================================" . PHP_EOL;
            sleep($batchPauseSeconds);
        } else {
            // No more pending files
            echo PHP_EOL;
            echo "No more pending files. Exiting..." . PHP_EOL;
            break;
        }
    }
    
    // Final summary
    echo PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "FINAL SUMMARY" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "Total batches processed: $totalBatches" . PHP_EOL;
    echo "Total files processed: $totalProcessed" . PHP_EOL;
    echo "Total successful: $totalSuccess" . PHP_EOL;
    echo "Total errors: $totalErrors" . PHP_EOL;
    echo PHP_EOL;
    
    // Get final queue statistics
    $finalStats = $queueManager->getQueueStats();
    echo "Final queue statistics:" . PHP_EOL;
    echo "  Pending: {$finalStats['pending']}" . PHP_EOL;
    echo "  Processing: {$finalStats['processing']}" . PHP_EOL;
    echo "  Completed: {$finalStats['completed']}" . PHP_EOL;
    echo "  Failed: {$finalStats['failed']}" . PHP_EOL;
    echo "  Retry: {$finalStats['retry']}" . PHP_EOL;
    echo PHP_EOL;
    echo "Script completed at: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "======================================" . PHP_EOL;
    
    $dbManager->close();
    
} catch (Exception $e) {
    echo PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "FATAL ERROR" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "Error: " . $e->getMessage() . PHP_EOL;
    echo "Time: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo PHP_EOL;
    error_log("[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage());
    error_log("[" . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString());
    exit(1);
}

