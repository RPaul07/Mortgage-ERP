<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$totalDocuments = 0;
$averageDocumentsPerLoan = 0;
$totalLoansWithDocuments = 0;

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get total count of all documents
    $totalStmt = $dbManager->getConnection()->prepare("
        SELECT COUNT(*) as total_documents
        FROM documents
        WHERE is_current = 1
    ");
    
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalRow = $totalResult->fetch_assoc();
    $totalDocuments = (int)$totalRow['total_documents'];
    $totalStmt->close();
    
    // Get average number of documents per loan
    $avgStmt = $dbManager->getConnection()->prepare("
        SELECT 
            COUNT(DISTINCT loan_id) as total_loans_with_documents,
            ROUND(AVG(doc_count), 2) as average_documents_per_loan
        FROM (
            SELECT loan_id, COUNT(*) as doc_count
            FROM documents
            WHERE is_current = 1
            GROUP BY loan_id
        ) as loan_counts
    ");
    
    $avgStmt->execute();
    $avgResult = $avgStmt->get_result();
    $avgRow = $avgResult->fetch_assoc();
    $totalLoansWithDocuments = (int)$avgRow['total_loans_with_documents'];
    $averageDocumentsPerLoan = (float)$avgRow['average_documents_per_loan'];
    $avgStmt->close();
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading document count statistics: " . $e->getMessage();
    error_log("Report document count error: " . $e->getMessage());
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
.stats-display {
    text-align: left;
    padding: 15px;
    font-size: 25px;
}
.stat-item {
    margin: 10px 0;
    padding: 10px;
    border-bottom: 1px solid #eee;
}
.stat-item:last-child {
    border-bottom: none;
}
.stat-label {
    font-weight: bold;
    color: #333;
}
.stat-value {
    color: #666;
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
                <div class="panel-heading">Document Count Statistics</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <div class="stats-display">
                            <div class="stat-item">
                                <span class="stat-label">Total Count of All Documents:</span>
                                <span class="stat-value"><?php echo number_format($totalDocuments); ?></span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Average Number of Documents per Loan:</span>
                                <span class="stat-value"><?php echo number_format($averageDocumentsPerLoan, 2); ?></span>
                            </div>
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

