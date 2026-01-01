<?php
/**
 * Backfill script to identify and store missing required documents for all existing loans
 * Required document types are identified by is_required_by_default = 1 flag
 * Stores doc_type_id values in JSON array for efficiency
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
    
    // Verify missing_doc_types column exists
    echo "Verifying missing_doc_types column exists...\n";
    $checkColumn = $connection->query("SHOW COLUMNS FROM loans LIKE 'missing_doc_types'");
    
    if ($checkColumn->num_rows == 0) {
        throw new Exception("missing_doc_types column does not exist. Please add it first using: ALTER TABLE loans ADD COLUMN missing_doc_types JSON DEFAULT NULL COMMENT 'JSON array of missing required document type IDs';");
    }
    
    echo "missing_doc_types column verified.\n\n";
    
    // Get all loans
    echo "\nFetching all loans...\n";
    $loansResult = $connection->query("SELECT loan_id, loan_number FROM loans ORDER BY loan_id");
    $totalLoans = $loansResult->num_rows;
    echo "Found $totalLoans loans to process.\n\n";
    
    // Get required document type IDs (where is_required_by_default = 1)
    $requiredTypesResult = $connection->query("
        SELECT doc_type_id, type_name 
        FROM document_type 
        WHERE is_required_by_default = 1
        ORDER BY doc_type_id
    ");
    
    $requiredTypeIds = [];
    $requiredTypeNames = [];
    while ($row = $requiredTypesResult->fetch_assoc()) {
        $requiredTypeIds[] = (int)$row['doc_type_id'];
        $requiredTypeNames[(int)$row['doc_type_id']] = $row['type_name'];
    }
    
    echo "Found " . count($requiredTypeIds) . " required document types:\n";
    foreach ($requiredTypeNames as $id => $name) {
        echo "  - $name (ID: $id)\n";
    }
    echo "\n";
    
    if (empty($requiredTypeIds)) {
        throw new Exception("No required document types found in database. Please check document_type table.");
    }
    
    // Prepare update statement
    $updateStmt = $connection->prepare("
        UPDATE loans 
        SET completion_status = ?, 
            missing_doc_count = ?, 
            missing_doc_types = ? 
        WHERE loan_id = ?
    ");
    
    if (!$updateStmt) {
        throw new Exception("Prepare failed: " . $connection->error);
    }
    
    // Process each loan
    $processed = 0;
    $completeLoans = 0;
    $incompleteLoans = 0;
    $startTime = microtime(true);
    
    while ($loan = $loansResult->fetch_assoc()) {
        $loanId = $loan['loan_id'];
        $loanNumber = $loan['loan_number'];
        
        // Get documents that exist for this loan with required types
        $placeholders = implode(',', array_fill(0, count($requiredTypeIds), '?'));
        $docStmt = $connection->prepare("
            SELECT DISTINCT doc_type_id 
            FROM documents 
            WHERE loan_id = ? 
              AND doc_type_id IN ($placeholders)
              AND is_current = 1
        ");
        
        if (!$docStmt) {
            throw new Exception("Prepare failed: " . $connection->error);
        }
        
        $docTypes = 'i' . str_repeat('i', count($requiredTypeIds));
        $docParams = array_merge([$loanId], $requiredTypeIds);
        $docStmt->bind_param($docTypes, ...$docParams);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        
        $existingTypeIds = [];
        while ($row = $docResult->fetch_assoc()) {
            $existingTypeIds[] = (int)$row['doc_type_id'];
        }
        $docStmt->close();
        
        // Find missing document type IDs
        $missingTypeIds = array_values(array_diff($requiredTypeIds, $existingTypeIds));
        
        // Calculate missing count
        $missingCount = count($missingTypeIds);
        
        // Determine completion status (loan is complete only if it has all required document types)
        // completion_status = 1 if and only if missing_doc_count = 0
        $isComplete = ($missingCount == 0);
        
        // Store missing type IDs as JSON array (null if none missing)
        $missingTypesJson = !empty($missingTypeIds) ? json_encode($missingTypeIds) : null;
        
        // Update loan record
        $updateStmt->bind_param("iisi", $isComplete, $missingCount, $missingTypesJson, $loanId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Execute failed for loan $loanId: " . $updateStmt->error);
        }
        
        $processed++;
        if ($isComplete) {
            $completeLoans++;
        } else {
            $incompleteLoans++;
        }
        
        // Progress indicator
        if ($processed % 100 == 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $processed / $elapsed;
            $remaining = ($totalLoans - $processed) / $rate;
            echo "Processed $processed/$totalLoans loans... (ETA: " . round($remaining, 1) . "s)\n";
        }
    }
    
    $updateStmt->close();
    
    $totalTime = microtime(true) - $startTime;
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "BACKFILL COMPLETE\n";
    echo str_repeat("=", 60) . "\n";
    echo "Total loans processed: $processed\n";
    echo "Complete loans (has all required docs): $completeLoans\n";
    echo "Incomplete loans (missing at least one required doc): $incompleteLoans\n";
    echo "Processing time: " . round($totalTime, 2) . " seconds\n";
    echo "Average rate: " . round($processed / $totalTime, 2) . " loans/second\n";
    echo "\n";
    
    // Summary statistics
    $summaryResult = $connection->query("
        SELECT 
            COUNT(*) as total_loans,
            SUM(completion_status) as complete_count,
            SUM(CASE WHEN completion_status = 0 THEN 1 ELSE 0 END) as incomplete_count,
            AVG(missing_doc_count) as avg_missing_docs,
            MIN(missing_doc_count) as min_missing_docs,
            MAX(missing_doc_count) as max_missing_docs,
            SUM(CASE WHEN missing_doc_count = 0 THEN 1 ELSE 0 END) as loans_with_all_docs
        FROM loans
    ");
    
    if ($summaryRow = $summaryResult->fetch_assoc()) {
        echo "SUMMARY STATISTICS:\n";
        echo "  Total loans: " . $summaryRow['total_loans'] . "\n";
        echo "  Complete loans: " . $summaryRow['complete_count'] . "\n";
        echo "  Incomplete loans: " . $summaryRow['incomplete_count'] . "\n";
        echo "  Loans with all required docs: " . $summaryRow['loans_with_all_docs'] . "\n";
        echo "  Average missing documents per loan: " . round($summaryRow['avg_missing_docs'], 2) . "\n";
        echo "  Minimum missing documents: " . $summaryRow['min_missing_docs'] . "\n";
        echo "  Maximum missing documents: " . $summaryRow['max_missing_docs'] . "\n";
    }
    
    // Sample query to show loans with missing documents
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "SAMPLE: Loans with missing documents (first 5):\n";
    echo str_repeat("=", 60) . "\n";
    
    $sampleResult = $connection->query("
        SELECT 
            loan_id,
            loan_number,
            missing_doc_count,
            missing_doc_types
        FROM loans
        WHERE missing_doc_count > 0
        ORDER BY missing_doc_count DESC, loan_number ASC
        LIMIT 5
    ");
    
    while ($sample = $sampleResult->fetch_assoc()) {
        $missingIds = json_decode($sample['missing_doc_types'], true);
        $missingNames = [];
        if ($missingIds) {
            foreach ($missingIds as $id) {
                if (isset($requiredTypeNames[$id])) {
                    $missingNames[] = $requiredTypeNames[$id];
                }
            }
        }
        echo "Loan: {$sample['loan_number']} - Missing {$sample['missing_doc_count']} docs: " . implode(', ', $missingNames) . "\n";
    }
    
    // Don't manually close connection - let DatabaseManager destructor handle it
    // $connection->close();
    
    echo "\nBackfill completed successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

