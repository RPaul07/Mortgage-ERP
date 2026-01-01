<?php
/**
 * Document Cleanup Script
 * Marks duplicate doc_names and invalid documents (wrong MIME type or empty) as 'failed' in download_queue
 * Does not delete documents from database, only marks them in download_queue
 */

chdir(__DIR__ . '/..');

require_once 'DatabaseManager.php';
require_once 'FileProcessor.php';

$config = include 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 0);
set_time_limit(0);

// Configuration
$dryRun = false; 
$batchSize = 200; 
$limitFiles = 0; 

echo "======================================" . PHP_EOL;
echo "Document Cleanup Script" . PHP_EOL;
echo "======================================" . PHP_EOL;
echo "Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE") . PHP_EOL;
if ($limitFiles > 0) {
    echo "File Limit: First $limitFiles files only" . PHP_EOL;
} else {
    echo "File Limit: None (processing all files)" . PHP_EOL;
}
echo "Starting at: " . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    $connection = $dbManager->getConnection();
    
    // Statistics
    $stats = [
        'duplicates_found' => 0,
        'duplicates_marked' => 0,
        'invalid_empty' => 0,
        'invalid_mime' => 0,
        'invalid_marked' => 0,
        'affected_loans' => [],
        'queue_updated' => 0,
        'queue_inserted' => 0
    ];
    
    
    echo "======================================" . PHP_EOL;
    echo "PHASE 1: Duplicate doc_name Marking" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    
    // Step 1.1: Find all duplicate doc_names
    echo "Step 1.1: Identifying duplicate doc_names..." . PHP_EOL;
    $duplicateQuery = "
        SELECT doc_name, COUNT(*) as count, 
               GROUP_CONCAT(doc_id ORDER BY uploaded_at ASC, doc_id ASC SEPARATOR ',') as doc_ids,
               MIN(uploaded_at) as oldest_uploaded_at
        FROM documents 
        WHERE is_current = 1
        GROUP BY doc_name 
        HAVING COUNT(*) > 1
        ORDER BY doc_name
    ";
    if ($limitFiles > 0) {
        $duplicateQuery .= " LIMIT " . (int)$limitFiles;
    }
    
    $result = $connection->query($duplicateQuery);
    if (!$result) {
        throw new Exception("Query failed: " . $connection->error);
    }
    
    $duplicateGroups = [];
    while ($row = $result->fetch_assoc()) {
        $docIds = explode(',', $row['doc_ids']);
        $duplicateGroups[] = [
            'doc_name' => $row['doc_name'],
            'count' => (int)$row['count'],
            'doc_ids' => array_map('intval', $docIds),
            'keep_doc_id' => (int)$docIds[0], // Oldest (first in sorted list)
            'delete_doc_ids' => array_slice($docIds, 1), // All others
            'oldest_uploaded_at' => $row['oldest_uploaded_at']
        ];
        $stats['duplicates_found'] += (int)$row['count'] - 1; // Exclude the one we keep
    }
    $result->free();
    
    echo "Found " . count($duplicateGroups) . " duplicate doc_name groups" . PHP_EOL;
    echo "Total duplicate documents to mark: {$stats['duplicates_found']}" . PHP_EOL;
    echo PHP_EOL;
    
    // Step 1.2: Mark duplicate documents in download_queue (keep oldest, mark newer)
    if (!$dryRun && !empty($duplicateGroups)) {
        echo "Step 1.2: Marking duplicate documents in download_queue (keeping oldest)..." . PHP_EOL;
        
        // Prepare statements for checking existence and updating/inserting
        $checkStmt = $connection->prepare("SELECT queue_id FROM download_queue WHERE file_id = ?");
        $updateStmt = $connection->prepare("
            UPDATE download_queue 
            SET status = 'failed',
                error_message = ?,
                completed_at = NOW()
            WHERE file_id = ?
        ");
        $insertStmt = $connection->prepare("
            INSERT INTO download_queue (file_id, status, error_message, priority, created_at, completed_at)
            VALUES (?, 'failed', ?, 0, ?, NOW())
        ");
        
        foreach ($duplicateGroups as $group) {
            $docName = $group['doc_name'];
            $errorMessage = "Document marked for deletion: Duplicate doc_name (kept older version)";
            $createdAt = $group['oldest_uploaded_at'] ?: date('Y-m-d H:i:s');
            
            foreach ($group['delete_doc_ids'] as $docId) {
                // Get loan_id for statistics
                $infoStmt = $connection->prepare("SELECT loan_id FROM documents WHERE doc_id = ?");
                $infoStmt->bind_param("i", $docId);
                $infoStmt->execute();
                $infoResult = $infoStmt->get_result();
                $infoRow = $infoResult->fetch_assoc();
                $infoStmt->close();
                
                if (!$infoRow) {
                    continue; // Document doesn't exist
                }
                
                $loanId = (int)$infoRow['loan_id'];
                $stats['affected_loans'][$loanId] = true;
                
                // Check if entry exists in download_queue
                $checkStmt->bind_param("s", $docName);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $exists = $checkResult->fetch_assoc() !== null;
                $checkStmt->reset();
                
                if ($exists) {
                    // Update existing entry
                    $updateStmt->bind_param("ss", $errorMessage, $docName);
                    $updateStmt->execute();
                    if ($updateStmt->affected_rows > 0) {
                        $stats['queue_updated']++;
                        $stats['duplicates_marked']++;
                    }
                    $updateStmt->reset();
                } else {
                    // Insert new entry
                    $insertStmt->bind_param("sss", $docName, $errorMessage, $createdAt);
                    $insertStmt->execute();
                    if ($insertStmt->affected_rows > 0) {
                        $stats['queue_inserted']++;
                        $stats['duplicates_marked']++;
                    }
                    $insertStmt->reset();
                }
                
                if ($stats['duplicates_marked'] % 100 == 0) {
                    echo "  Marked {$stats['duplicates_marked']}/{$stats['duplicates_found']} duplicate documents..." . PHP_EOL;
                }
            }
        }
        
        $checkStmt->close();
        $updateStmt->close();
        $insertStmt->close();
        
        echo "Marked {$stats['duplicates_marked']} duplicate documents in download_queue" . PHP_EOL;
        echo "  - Updated existing entries: {$stats['queue_updated']}" . PHP_EOL;
        echo "  - Inserted new entries: {$stats['queue_inserted']}" . PHP_EOL;
        echo PHP_EOL;
    } else {
        echo "Step 1.2: Skipped (dry run mode)" . PHP_EOL;
        echo PHP_EOL;
    }
    
    // Reset queue counters for Phase 2
    $phase1Updated = $stats['queue_updated'];
    $phase1Inserted = $stats['queue_inserted'];
    $stats['queue_updated'] = 0;
    $stats['queue_inserted'] = 0;
    

    echo "======================================" . PHP_EOL;
    echo "PHASE 2: MIME Type and Empty File Validation" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    
    
    $connection = $dbManager->getConnection();
    
    
    $countStmt = $connection->prepare("
        SELECT COUNT(*) as total 
        FROM documents d
        INNER JOIN document_blobs db ON d.doc_id = db.doc_id
        WHERE d.is_current = 1
    ");
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalDocsAvailable = (int)$countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    $totalDocs = ($limitFiles > 0) ? min($limitFiles, $totalDocsAvailable) : $totalDocsAvailable;
    
    echo "Step 2.1: Found $totalDocsAvailable total documents" . PHP_EOL;
    if ($limitFiles > 0) {
        echo "Processing first $limitFiles documents (limited)" . PHP_EOL;
    }
    echo PHP_EOL;
    
    
    $offset = 0;
    $processed = 0;
    $invalidDocIds = [];
    
    while ($offset < $totalDocs) {
        
        $connection = $dbManager->getConnection();
        
        $currentBatchSize = min($batchSize, $totalDocs - $offset);
        echo "Step 2.2: Processing batch (offset: $offset, batch size: $currentBatchSize)..." . PHP_EOL;
        
        // Get batch of documents with content
        $batchStmt = $connection->prepare("
            SELECT d.doc_id, d.loan_id, d.doc_name, d.file_size_bytes, d.uploaded_at, db.doc_content
            FROM documents d
            INNER JOIN document_blobs db ON d.doc_id = db.doc_id
            WHERE d.is_current = 1
            ORDER BY d.doc_id
            LIMIT ? OFFSET ?
        ");
        $batchStmt->bind_param("ii", $currentBatchSize, $offset);
        $batchStmt->execute();
        $batchResult = $batchStmt->get_result();
        
        $batchDocs = [];
        while ($row = $batchResult->fetch_assoc()) {
            $batchDocs[] = $row;
        }
        $batchStmt->close();
        
        if (empty($batchDocs)) {
            break;
        }
        
        // Validate each document in batch
        foreach ($batchDocs as $doc) {
            $docId = (int)$doc['doc_id'];
            $loanId = (int)$doc['loan_id'];
            $docName = $doc['doc_name'];
            $content = $doc['doc_content'];
            $fileSize = (int)$doc['file_size_bytes'];
            $uploadedAt = $doc['uploaded_at'];
            $isInvalid = false;
            $invalidReason = '';
            $errorMessage = '';
            
            // Check for empty file
            if ($fileSize == 0 || strlen($content) == 0) {
                $isInvalid = true;
                $invalidReason = 'Empty file';
                $errorMessage = "Document marked for deletion: File content is empty";
                $stats['invalid_empty']++;
            } else {
                // Check MIME type
                $mimeCheck = FileProcessor::checkMimeType($content);
                if (!$mimeCheck['is_pdf']) {
                    $isInvalid = true;
                    $invalidReason = $mimeCheck['message'];
                    $errorMessage = "Document marked for deletion: " . $mimeCheck['message'];
                    $stats['invalid_mime']++;
                }
            }
            
            if ($isInvalid) {
                $invalidDocIds[] = [
                    'doc_id' => $docId,
                    'loan_id' => $loanId,
                    'doc_name' => $docName,
                    'reason' => $invalidReason,
                    'error_message' => $errorMessage,
                    'uploaded_at' => $uploadedAt
                ];
                $stats['affected_loans'][$loanId] = true;
            }
            
            $processed++;
            if ($processed % 100 == 0) {
                echo "  Validated $processed/$totalDocs documents..." . PHP_EOL;
            }
            
            // Free memory
            unset($content);
        }
        
        $offset += $currentBatchSize; // Fixed: use currentBatchSize instead of batchSize
        unset($batchDocs);
        
        // Pause between batches to prevent overwhelming the database
        if ($offset < $totalDocs) {
            echo "  Pausing 3 seconds before next batch..." . PHP_EOL;
            sleep(3);
        }
    }
    
    echo "Validation complete. Found " . count($invalidDocIds) . " invalid documents" . PHP_EOL;
    echo "  - Empty files: {$stats['invalid_empty']}" . PHP_EOL;
    echo "  - Wrong MIME type: {$stats['invalid_mime']}" . PHP_EOL;
    echo PHP_EOL;
    
    // Step 2.3: Mark invalid documents in download_queue
    if (!$dryRun && !empty($invalidDocIds)) {
        echo "Step 2.3: Marking invalid documents in download_queue..." . PHP_EOL;
        
        // Prepare statements for checking existence and updating/inserting
        $checkStmt = $connection->prepare("SELECT queue_id FROM download_queue WHERE file_id = ?");
        $updateStmt = $connection->prepare("
            UPDATE download_queue 
            SET status = 'failed',
                error_message = ?,
                completed_at = NOW()
            WHERE file_id = ?
        ");
        $insertStmt = $connection->prepare("
            INSERT INTO download_queue (file_id, status, error_message, priority, created_at, completed_at)
            VALUES (?, 'failed', ?, 0, ?, NOW())
        ");
        
        foreach ($invalidDocIds as $invalid) {
            $docName = $invalid['doc_name'];
            $errorMessage = $invalid['error_message'];
            $createdAt = $invalid['uploaded_at'] ?: date('Y-m-d H:i:s');
            
            // Check if entry exists in download_queue
            $checkStmt->bind_param("s", $docName);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $exists = $checkResult->fetch_assoc() !== null;
            $checkStmt->reset();
            
            if ($exists) {
                // Update existing entry
                $updateStmt->bind_param("ss", $errorMessage, $docName);
                $updateStmt->execute();
                if ($updateStmt->affected_rows > 0) {
                    $stats['queue_updated']++;
                    $stats['invalid_marked']++;
                }
                $updateStmt->reset();
            } else {
                // Insert new entry
                $insertStmt->bind_param("sss", $docName, $errorMessage, $createdAt);
                $insertStmt->execute();
                if ($insertStmt->affected_rows > 0) {
                    $stats['queue_inserted']++;
                    $stats['invalid_marked']++;
                }
                $insertStmt->reset();
            }
            
            if ($stats['invalid_marked'] % 100 == 0) {
                echo "  Marked {$stats['invalid_marked']}/" . count($invalidDocIds) . " invalid documents..." . PHP_EOL;
            }
        }
        
        $checkStmt->close();
        $updateStmt->close();
        $insertStmt->close();
        
        echo "Marked {$stats['invalid_marked']} invalid documents in download_queue" . PHP_EOL;
        echo "  - Updated existing entries: {$stats['queue_updated']}" . PHP_EOL;
        echo "  - Inserted new entries: {$stats['queue_inserted']}" . PHP_EOL;
        echo PHP_EOL;
    } else {
        echo "Step 2.3: Skipped (dry run mode)" . PHP_EOL;
        echo PHP_EOL;
    }
    
    
    if (!$dryRun && !empty($stats['affected_loans'])) {
        echo "======================================" . PHP_EOL;
        echo "PHASE 3: Updating Loan Completion Status" . PHP_EOL;
        echo "======================================" . PHP_EOL;
        
        // Ensure connection is still alive before Phase 3
        $connection = $dbManager->getConnection();
        
        $affectedLoanIds = array_keys($stats['affected_loans']);
        $totalLoans = count($affectedLoanIds);
        echo "Updating completion status for $totalLoans affected loan(s)..." . PHP_EOL;
        
        $updated = 0;
        foreach ($affectedLoanIds as $loanId) {
            try {
                
                $dbManager->updateLoanCompletionStatus($loanId);
                
                $updated++;
                if ($updated % 50 == 0) {
                    echo "  Updated $updated/$totalLoans loans..." . PHP_EOL;
                }
            } catch (Exception $e) {
                error_log("Failed to update loan_id $loanId: " . $e->getMessage());
            }
        }
        
        echo "Updated completion status for $updated loans" . PHP_EOL;
        echo PHP_EOL;
    }
    
    
    echo "======================================" . PHP_EOL;
    echo "FINAL SUMMARY" . PHP_EOL;
    echo "======================================" . PHP_EOL;
    echo "Duplicate doc_names found: {$stats['duplicates_found']}" . PHP_EOL;
    echo "Duplicate documents marked: {$stats['duplicates_marked']}" . PHP_EOL;
    echo "Invalid documents found:" . PHP_EOL;
    echo "  - Empty files: {$stats['invalid_empty']}" . PHP_EOL;
    echo "  - Wrong MIME type: {$stats['invalid_mime']}" . PHP_EOL;
    echo "Invalid documents marked: {$stats['invalid_marked']}" . PHP_EOL;
    echo "Total documents marked: " . ($stats['duplicates_marked'] + $stats['invalid_marked']) . PHP_EOL;
    echo "Download queue entries:" . PHP_EOL;
    echo "  - Updated (Phase 1): $phase1Updated" . PHP_EOL;
    echo "  - Inserted (Phase 1): $phase1Inserted" . PHP_EOL;
    echo "  - Updated (Phase 2): {$stats['queue_updated']}" . PHP_EOL;
    echo "  - Inserted (Phase 2): {$stats['queue_inserted']}" . PHP_EOL;
    echo "  - Total updated: " . ($phase1Updated + $stats['queue_updated']) . PHP_EOL;
    echo "  - Total inserted: " . ($phase1Inserted + $stats['queue_inserted']) . PHP_EOL;
    echo "Affected loans: " . count($stats['affected_loans']) . PHP_EOL;
    echo PHP_EOL;
    echo "Script completed at: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "======================================" . PHP_EOL;
    
    $dbManager->close();
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Document cleanup ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo "FATAL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
?>
