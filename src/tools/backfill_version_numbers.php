<?php
/**
 * Backfill script to extract and update version_number for all existing documents
 * Extracts version number from filename format: <loan_number>-<document_type>_<version_number>-<time_stamp>.pdf
 * If no version number in filename, sets version_number = 0
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../DatabaseManager.php';

$config = require __DIR__ . '/../config.php';

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
    
    // Get all documents
    echo "Fetching all documents...\n";
    $documentsResult = $connection->query("SELECT doc_id, doc_name, version_number FROM documents ORDER BY doc_id");
    $totalDocuments = $documentsResult->num_rows;
    echo "Found $totalDocuments documents to process.\n\n";
    
    // Prepare update statement
    $updateStmt = $connection->prepare("
        UPDATE documents 
        SET version_number = ? 
        WHERE doc_id = ?
    ");
    
    if (!$updateStmt) {
        throw new Exception("Prepare failed: " . $connection->error);
    }
    
    // Process each document
    $processed = 0;
    $updated = 0;
    $unchanged = 0;
    $errors = 0;
    $startTime = microtime(true);
    
    while ($doc = $documentsResult->fetch_assoc()) {
        $docId = $doc['doc_id'];
        $filename = $doc['doc_name'];
        $currentVersion = (int)$doc['version_number'];
        
        // Extract version number from filename
        $versionNumber = 0;
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $parts = explode('-', $nameWithoutExt);
        
        if (isset($parts[1])) {
            // Check if document_type part has version number suffix: <type>_<number>
            if (preg_match('/^(.+)_(\d+)$/', $parts[1], $matches)) {
                $versionNumber = (int)$matches[2];
            } else {
                $versionNumber = 0;
            }
        }
        
        // Only update if version number is different
        if ($versionNumber !== $currentVersion) {
            $updateStmt->bind_param("ii", $versionNumber, $docId);
            
            if (!$updateStmt->execute()) {
                echo "ERROR: Failed to update doc_id $docId: " . $updateStmt->error . "\n";
                $errors++;
            } else {
                $updated++;
            }
        } else {
            $unchanged++;
        }
        
        $processed++;
        
        // Progress indicator
        if ($processed % 1000 == 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $processed / $elapsed;
            $remaining = ($totalDocuments - $processed) / $rate;
            echo "Processed $processed/$totalDocuments documents... (ETA: " . round($remaining, 1) . "s)\n";
        }
    }
    
    $updateStmt->close();
    
    $totalTime = microtime(true) - $startTime;
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "BACKFILL COMPLETE\n";
    echo str_repeat("=", 60) . "\n";
    echo "Total documents processed: $processed\n";
    echo "Documents updated: $updated\n";
    echo "Documents unchanged: $unchanged\n";
    echo "Errors: $errors\n";
    echo "Processing time: " . round($totalTime, 2) . " seconds\n";
    echo "Average rate: " . round($processed / $totalTime, 2) . " documents/second\n";
    echo "\n";
    
    // Summary statistics
    $summaryResult = $connection->query("
        SELECT 
            COUNT(*) as total_docs,
            SUM(CASE WHEN version_number = 0 THEN 1 ELSE 0 END) as version_0_count,
            SUM(CASE WHEN version_number > 0 THEN 1 ELSE 0 END) as versioned_count,
            MIN(version_number) as min_version,
            MAX(version_number) as max_version,
            AVG(version_number) as avg_version
        FROM documents
    ");
    
    if ($summaryRow = $summaryResult->fetch_assoc()) {
        echo "SUMMARY STATISTICS:\n";
        echo "  Total documents: " . $summaryRow['total_docs'] . "\n";
        echo "  Documents with version 0: " . $summaryRow['version_0_count'] . "\n";
        echo "  Documents with version > 0: " . $summaryRow['versioned_count'] . "\n";
        echo "  Minimum version: " . $summaryRow['min_version'] . "\n";
        echo "  Maximum version: " . $summaryRow['max_version'] . "\n";
        echo "  Average version: " . round($summaryRow['avg_version'], 2) . "\n";
    }
    
    // Sample query to show documents with different versions
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "SAMPLE: Documents with version numbers (first 10):\n";
    echo str_repeat("=", 60) . "\n";
    
    $sampleResult = $connection->query("
        SELECT 
            doc_id,
            doc_name,
            version_number
        FROM documents
        WHERE version_number > 0
        ORDER BY version_number DESC, doc_name ASC
        LIMIT 10
    ");
    
    while ($sample = $sampleResult->fetch_assoc()) {
        echo "Version {$sample['version_number']}: {$sample['doc_name']}\n";
    }
    
    echo "\nBackfill completed successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

