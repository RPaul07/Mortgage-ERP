<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$loansMissingDocuments = [];
$loansWithAllDocuments = [];
$loansWithZeroDocuments = [];

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get all document type names for lookup
    $typeNameStmt = $dbManager->getConnection()->prepare("SELECT doc_type_id, type_name FROM document_type");
    $typeNameStmt->execute();
    $typeNameResult = $typeNameStmt->get_result();
    
    $typeNameLookup = [];
    while ($typeRow = $typeNameResult->fetch_assoc()) {
        $typeNameLookup[(int)$typeRow['doc_type_id']] = $typeRow['type_name'];
    }
    $typeNameStmt->close();
    
    // Get loans missing at least one required document (using stored columns)
    $missingStmt = $dbManager->getConnection()->prepare("
        SELECT 
            l.loan_number,
            l.loan_id,
            l.missing_doc_count,
            l.missing_doc_types
        FROM loans l
        WHERE l.missing_doc_count > 0
        ORDER BY l.missing_doc_count DESC, l.loan_number
    ");
    
    $missingStmt->execute();
    $missingResult = $missingStmt->get_result();
    
    while ($row = $missingResult->fetch_assoc()) {
        $missingDocCount = (int)$row['missing_doc_count'];
        $missingDocTypesJson = $row['missing_doc_types'];
        
        // Decode JSON array of doc_type_id values
        $missingTypeIds = [];
        if (!empty($missingDocTypesJson)) {
            $missingTypeIds = json_decode($missingDocTypesJson, true);
            if (!is_array($missingTypeIds)) {
                $missingTypeIds = [];
            }
        }
        
        // Convert doc_type_id values to type names
        $missingTypeNames = [];
        foreach ($missingTypeIds as $typeId) {
            if (isset($typeNameLookup[(int)$typeId])) {
                $missingTypeNames[] = $typeNameLookup[(int)$typeId];
            }
        }
        
        // Sort type names alphabetically
        sort($missingTypeNames);
        
        $loansMissingDocuments[] = [
            'loan_number' => $row['loan_number'],
            'loan_id' => (int)$row['loan_id'],
            'missing_doc_count' => $missingDocCount,
            'missing_document_types' => implode(', ', $missingTypeNames)
        ];
    }
    $missingStmt->close();
    
    // Get loans with all required documents (using stored completion_status)
    $completeStmt = $dbManager->getConnection()->prepare("
        SELECT 
            l.loan_number,
            l.loan_id
        FROM loans l
        WHERE l.completion_status = 1
        ORDER BY l.loan_number
    ");
    
    $completeStmt->execute();
    $completeResult = $completeStmt->get_result();
    
    while ($row = $completeResult->fetch_assoc()) {
        $loansWithAllDocuments[] = [
            'loan_number' => $row['loan_number'],
            'loan_id' => (int)$row['loan_id']
        ];
    }
    $completeStmt->close();
    
    // Get loans with zero documents
    $zeroStmt = $dbManager->getConnection()->prepare("
        SELECT 
            l.loan_number,
            l.loan_id,
            COALESCE(doc_counts.doc_count, 0) as document_count
        FROM loans l
        LEFT JOIN (
            SELECT loan_id, COUNT(*) as doc_count
            FROM documents
            WHERE is_current = 1
            GROUP BY loan_id
        ) doc_counts ON l.loan_id = doc_counts.loan_id
        WHERE COALESCE(doc_counts.doc_count, 0) = 0
        ORDER BY l.loan_number
    ");
    
    $zeroStmt->execute();
    $zeroResult = $zeroStmt->get_result();
    
    while ($row = $zeroResult->fetch_assoc()) {
        $loansWithZeroDocuments[] = [
            'loan_number' => $row['loan_number'],
            'loan_id' => (int)$row['loan_id']
        ];
    }
    $zeroStmt->close();
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading loan completion status: " . $e->getMessage();
    error_log("Report loan completion error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<title>Document Management Web Front End</title>
<link href="assets/css/bootstrap.css" rel="stylesheet">
<style>
.main-box {
    text-align:center;
    padding:20px;
    border-radius:5px;
    -moz-border-radius:5px ;
    -webkit-border-radius:5px;
    margin-bottom:40px;
}
.section-box {
    margin: 20px 0;
    padding: 15px;
    background-color: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.section-title {
    font-size: 18px;
    font-weight: bold;
    color: #333;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #007bff;
}
.count-badge {
    display: inline-block;
    background-color: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    margin-left: 10px;
}
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">Loan Completion Status</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <!-- Section 1: Loans Missing Documents -->
                        <div class="section-box">
                            <div class="section-title">
                                Loans Missing at Least One Required Document - Total: <?php echo count($loansMissingDocuments); ?>
                            </div>
                            <?php if (empty($loansMissingDocuments)): ?>
                                <p>No loans are missing required documents.</p>
                            <?php else: ?>
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Loan Number</th>
                                            <th>Missing Count</th>
                                            <th>Missing Document Types</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loansMissingDocuments as $loan): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                                <td><?php echo $loan['missing_doc_count']; ?></td>
                                                <td><?php echo htmlspecialchars($loan['missing_document_types']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Section 2: Loans With All Documents -->
                        <div class="section-box">
                            <div class="section-title">
                                Loans With All Required Documents - Total: <?php echo count($loansWithAllDocuments); ?>
                            </div>
                            <?php if (empty($loansWithAllDocuments)): ?>
                                <p>No loans have all required documents.</p>
                            <?php else: ?>
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Loan Number</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loansWithAllDocuments as $loan): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Section 3: Loans With Zero Documents -->
                        <div class="section-box">
                            <div class="section-title">
                                Loans With Zero Documents - Total: <?php echo count($loansWithZeroDocuments); ?>
                            </div>
                            <?php if (empty($loansWithZeroDocuments)): ?>
                                <p>All loans have at least one document.</p>
                            <?php else: ?>
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Loan Number</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loansWithZeroDocuments as $loan): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="form-group">
                            <a href="report_main.php" class="btn btn-primary">Back to Report Main</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</body></html>

