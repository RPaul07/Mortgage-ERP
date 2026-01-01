<?php
/**
 * Download Pending Files Script
 * Downloads files with pending status from download_queue and stores them in the database
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
ini_set('max_execution_time', 1800);
set_time_limit(1800);

error_log("[" . date('Y-m-d H:i:s') . "] Starting download script for pending files");

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
    
    // Query for files with non-completed status
    $connection = $dbManager->getConnection();
    
    $stmt = $connection->prepare("
        SELECT queue_id, file_id, status, error_message, attempts
        FROM download_queue
        WHERE status != 'completed'
        ORDER BY queue_id ASC
    ");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $connection->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
    
    $totalRecords = count($records);
    error_log("[" . date('Y-m-d H:i:s') . "] Found $totalRecords non-completed file(s) to download");
    
    if ($totalRecords === 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] No records found. Exiting.");
        exit(0);
    }
    
    // Initialize API client and session manager
    $apiClient = new ApiClient(
        $config['api']['base_url'],
        $config['api']['username'],
        $config['api']['password'],
        $config['api']['timeout'],
        $config['api']['user_agent']
    );
    $logger = new ApiCallLogger($dbManager, ['tool' => 'download_pending_files']);
    $apiClient->setLogger($logger);
    
    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['tool' => 'download_pending_files']);
    
    // Initialize queue manager
    $queueManager = new QueueManager($dbManager);
    
    
    error_log("[" . date('Y-m-d H:i:s') . "] Creating new API session...");
    $sessionId = null;
    
    try {
        // Create a new session (this will clear any existing session internally)
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
        
        // Validate session ID format (should be non-empty string)
        if (empty($sessionId) || !is_string($sessionId)) {
            throw new Exception("Invalid session ID format: " . var_export($sessionId, true));
        }
        
        // Session ID is already set in ApiClient by createSession(), but verify it
        if ($apiClient->getSessionId() !== $sessionId) {
            $apiClient->setSessionId($sessionId);
        }
        
        error_log("[" . date('Y-m-d H:i:s') . "] New API session created successfully: " . substr($sessionId, 0, 20) . "...");
        
        
        sleep(1);
        
        error_log("[" . date('Y-m-d H:i:s') . "] API session established and verified");
        
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] CRITICAL: Failed to establish API session: " . $e->getMessage());
        error_log("[" . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString());
        throw new Exception("Cannot proceed without valid API session: " . $e->getMessage());
    }
    
    // Batch processing configuration
    $batchSize = 25;
    $batchPauseSeconds = 180; // 3 minutes
    
    // Statistics
    $processedCount = 0;
    $successCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    $currentBatch = 0;
    $filesInCurrentBatch = 0;
    
    // Process each record
    foreach ($records as $index => $record) {
        if ($filesInCurrentBatch >= $batchSize && $filesInCurrentBatch > 0) {
            error_log("[" . date('Y-m-d H:i:s') . "] Batch $currentBatch completed ($batchSize files). Pausing for $batchPauseSeconds seconds before next batch...");
            sleep($batchPauseSeconds);
            $filesInCurrentBatch = 0;
        }
        
        // Check if we need to start a new batch
        if ($filesInCurrentBatch == 0) {
            $currentBatch++;
            error_log("[" . date('Y-m-d H:i:s') . "] ========== Starting Batch $currentBatch ==========");
        }
        
        $queueId = $record['queue_id'];
        $fileId = $record['file_id'];
        
        error_log("[" . date('Y-m-d H:i:s') . "] Processing record $queueId: $fileId (" . ($index + 1) . "/$totalRecords)");
        
        if ($index > 0) {
            $delaySeconds = 0.5; 
            usleep($delaySeconds * 1000000); 
        }
        
        try {
            // Mark as processing
            $queueManager->markProcessing($queueId);
            
            // Download file from API with retry logic for session expiration
            $maxRetries = 2;
            $fileResponse = null;
            $content = null;
            $retryCount = 0;
            
            while ($retryCount <= $maxRetries) {
                try {
                    // Download file from API
                    $fileResponse = $sessionManager->executeWithContext(
                        [
                            'tool' => 'download_pending_files',
                            'operation' => 'request_file',
                            'file_id' => $fileId,
                            'queue_id' => $queueId
                        ],
                        static function ($client) use ($fileId) {
                            return $client->requestFile($fileId);
                        }
                    );
                    
                    // Validate file content
                    if (empty($fileResponse['raw_result'])) {
                        throw new Exception("Downloaded file content is empty");
                    }
                    
                    $content = $fileResponse['raw_result'];
                    
                    // Check if response is JSON error
                    $firstChar = substr($content, 0, 1);
                    if ($firstChar === '{' || $firstChar === '[') {
                        $decodedJson = json_decode($content, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // It's a JSON error response
                            $errorMsg = is_array($decodedJson) 
                                ? ($decodedJson['error'] ?? $decodedJson['message'] ?? json_encode($decodedJson))
                                : $content;
                            
                            // Check if it's a "SID not found" error - refresh session and retry
                            $errorStr = is_array($decodedJson) ? json_encode($decodedJson) : $content;
                            if (stripos($errorStr, 'SID not found') !== false || stripos($errorStr, 'session') !== false) {
                                if ($retryCount < $maxRetries) {
                                    $retryCount++;
                                    error_log("[" . date('Y-m-d H:i:s') . "] Queue ID $queueId: Session expired (SID not found). Refreshing session and retrying (attempt $retryCount/$maxRetries)...");
                                    
                                    // Add exponential backoff delay before refreshing (1 second, 2 seconds, etc.)
                                    $delaySeconds = $retryCount;
                                    if ($delaySeconds > 0) {
                                        error_log("[" . date('Y-m-d H:i:s') . "] Waiting {$delaySeconds} second(s) before session refresh...");
                                        sleep($delaySeconds);
                                    }
                                    
                                    // Refresh session: createSession() already clears internally
                                    $sessionResponse = $apiClient->createSession();
                                    if (!isset($sessionResponse['result'][2]) || $sessionResponse['result'][0] !== "Status: OK") {
                                        throw new Exception("Failed to refresh session: " . ($sessionResponse['result'][0] ?? 'Unknown error'));
                                    }
                                    
                                    $newSessionId = $sessionResponse['result'][2];
                                    $apiClient->setSessionId($newSessionId);
                                    $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['tool' => 'download_pending_files']);
                                    
                                    // Small delay after session creation to ensure it's fully established
                                    sleep(1);
                                    
                                    error_log("[" . date('Y-m-d H:i:s') . "] Session refreshed: " . substr($newSessionId, 0, 20) . "...");
                                    continue; // Retry the download
                                } else {
                                    // Max retries reached, treat as permanent error
                                    throw new Exception("API returned JSON error (session refresh failed): " . substr($errorMsg, 0, 200));
                                }
                            } else {
                                // It's a JSON error but not session-related - don't retry
                                throw new Exception("API returned JSON error: " . substr($errorMsg, 0, 200));
                            }
                        }
                    }
                    
                    
                    break; 
                    
                } catch (Exception $e) {
                    if ($retryCount < $maxRetries && (stripos($e->getMessage(), 'session') !== false || stripos($e->getMessage(), 'SID') !== false)) {
                        $retryCount++;
                        error_log("[" . date('Y-m-d H:i:s') . "] Queue ID $queueId: Session error detected. Refreshing session and retrying (attempt $retryCount/$maxRetries)...");
                        
                        $delaySeconds = $retryCount;
                        if ($delaySeconds > 0) {
                            error_log("[" . date('Y-m-d H:i:s') . "] Waiting {$delaySeconds} second(s) before session refresh...");
                            sleep($delaySeconds);
                        }
                        
                        // Refresh session: createSession() already clears internally
                        $sessionResponse = $apiClient->createSession();
                        if (!isset($sessionResponse['result'][2]) || $sessionResponse['result'][0] !== "Status: OK") {
                            throw new Exception("Failed to refresh session: " . ($sessionResponse['result'][0] ?? 'Unknown error'));
                        }
                        
                        $newSessionId = $sessionResponse['result'][2];
                        $apiClient->setSessionId($newSessionId);
                        $sessionManager = new SessionManager($dbManager, $apiClient, $config, ['tool' => 'download_pending_files']);
                        
                        // Small delay after session creation to ensure it's fully established
                        sleep(1);
                        
                        error_log("[" . date('Y-m-d H:i:s') . "] Session refreshed: " . substr($newSessionId, 0, 20) . "...");
                        continue; // Retry the download
                    } else {
                        // Not a session error or max retries reached - rethrow
                        throw $e;
                    }
                }
            }
            
            if (empty($content)) {
                throw new Exception("Downloaded file content is empty after retries");
            }
            
            // File size validation
            $fileSize = strlen($content);
            if ($fileSize < 100) {
                throw new Exception("File too small to be a valid PDF: {$fileSize} bytes");
            }
            
            // Check MIME type (use regular check, not strict, since we want to store valid PDFs)
            $mimeCheck = FileProcessor::checkMimeType($content);
            
            if (!$mimeCheck['is_pdf']) {
                $queueManager->markFailed($queueId, $mimeCheck['message'], false); 
                $skippedCount++;
                error_log("[" . date('Y-m-d H:i:s') . "] Queue ID $queueId: Skipped - " . $mimeCheck['message']);
                $processedCount++;
                $filesInCurrentBatch++;
                continue;
            }
            
            // Parse filename
            $fileInfo = FileProcessor::parseFilename($fileId);
            $loanNumber = $fileInfo['loan_number'];
            $documentType = FileProcessor::normalizeDocumentType($fileInfo['document_type']);
            
            // Check if duplicate
            $isDuplicate = $dbManager->documentExists($fileId);
            
            // Insert into database
            $docId = $dbManager->insertDocument($fileId, $loanNumber, $documentType, $content);
            
            // Mark as completed in queue
            $queueManager->markCompleted($queueId);
            $successCount++;
            
            $duplicateNote = $isDuplicate ? " (duplicate)" : "";
            error_log("[" . date('Y-m-d H:i:s') . "] Successfully downloaded $fileId$duplicateNote - Loan: $loanNumber, Type: $documentType, Size: " . FileProcessor::formatBytes($fileSize));
            
            $processedCount++;
            $filesInCurrentBatch++;
            
        } catch (Exception $e) {
            $errorCount++;
            $errorMessage = $e->getMessage();
            error_log("[" . date('Y-m-d H:i:s') . "] Error processing queue ID $queueId: " . $errorMessage);
            
            // Update database: set status to failed and log error message
            try {
                $queueManager->markFailed($queueId, $errorMessage, true); // Retry on error
            } catch (Exception $updateEx) {
                // If database update fails, log it but don't stop processing
                error_log("[" . date('Y-m-d H:i:s') . "] Failed to update queue ID $queueId in database: " . $updateEx->getMessage());
            }
            
            $filesInCurrentBatch++;
        }
    }
    
    // Log final batch completion if there are remaining files in the last batch
    if ($filesInCurrentBatch > 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Final batch $currentBatch completed with $filesInCurrentBatch file(s)");
    }
    
    // Summary
    error_log("[" . date('Y-m-d H:i:s') . "] ========== DOWNLOAD SUMMARY ==========");
    error_log("[" . date('Y-m-d H:i:s') . "] Total non-completed files found: $totalRecords");
    error_log("[" . date('Y-m-d H:i:s') . "] Successfully processed: $processedCount");
    error_log("[" . date('Y-m-d H:i:s') . "] Successfully downloaded and stored: $successCount");
    error_log("[" . date('Y-m-d H:i:s') . "] Skipped (non-PDF): $skippedCount");
    error_log("[" . date('Y-m-d H:i:s') . "] Errors encountered: $errorCount");
    error_log("[" . date('Y-m-d H:i:s') . "] ======================================");
    error_log("[" . date('Y-m-d H:i:s') . "] Download script completed");
    
    $dbManager->close();
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Fatal error: " . $e->getMessage());
    error_log("[" . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString());
    exit(1);
}
?>

