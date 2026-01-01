<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$globalAverageSize = 0;
$globalAverageCount = 0;
$loanDetails = [];

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get global average size (from report_document_size.php logic)
    $globalSizeStmt = $dbManager->getConnection()->prepare("
        SELECT ROUND(AVG(file_size_bytes), 2) as average_size_bytes
        FROM documents
        WHERE is_current = 1
    ");
    
    $globalSizeStmt->execute();
    $globalSizeResult = $globalSizeStmt->get_result();
    $globalSizeRow = $globalSizeResult->fetch_assoc();
    $globalAverageSize = (float)$globalSizeRow['average_size_bytes'];
    $globalSizeStmt->close();
    
    // Get global average document count per loan (from report_document_count.php logic)
    $globalCountStmt = $dbManager->getConnection()->prepare("
        SELECT ROUND(AVG(doc_count), 2) as average_documents_per_loan
        FROM (
            SELECT loan_id, COUNT(*) as doc_count
            FROM documents
            WHERE is_current = 1
            GROUP BY loan_id
        ) as loan_counts
    ");
    
    $globalCountStmt->execute();
    $globalCountResult = $globalCountStmt->get_result();
    $globalCountRow = $globalCountResult->fetch_assoc();
    $globalAverageCount = (float)$globalCountRow['average_documents_per_loan'];
    $globalCountStmt->close();
    
    // Get details for each loan
    $loanStmt = $dbManager->getConnection()->prepare("
        SELECT 
            l.loan_number,
            l.loan_id,
            COUNT(d.doc_id) as total_documents,
            ROUND(AVG(d.file_size_bytes), 2) as average_size_bytes
        FROM loans l
        LEFT JOIN documents d ON l.loan_id = d.loan_id AND d.is_current = 1
        GROUP BY l.loan_id, l.loan_number
        ORDER BY l.loan_number
    ");
    
    $loanStmt->execute();
    $loanResult = $loanStmt->get_result();
    
    while ($row = $loanResult->fetch_assoc()) {
        $totalDocs = (int)$row['total_documents'];
        $avgSize = (float)$row['average_size_bytes'];
        
        // Compare to global averages
        $sizeComparison = '';
        if ($avgSize > $globalAverageSize) {
            $sizeComparison = 'ABOVE';
        } elseif ($avgSize < $globalAverageSize) {
            $sizeComparison = 'BELOW';
        } else {
            $sizeComparison = 'EQUAL';
        }
        
        $countComparison = '';
        if ($totalDocs > $globalAverageCount) {
            $countComparison = 'ABOVE';
        } elseif ($totalDocs < $globalAverageCount) {
            $countComparison = 'BELOW';
        } else {
            $countComparison = 'EQUAL';
        }
        
        $loanDetails[] = [
            'loan_number' => $row['loan_number'],
            'loan_id' => (int)$row['loan_id'],
            'total_documents' => $totalDocs,
            'average_size_bytes' => $avgSize,
            'size_comparison' => $sizeComparison,
            'count_comparison' => $countComparison
        ];
    }
    
    $loanStmt->close();
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading loan details: " . $e->getMessage();
    error_log("Report loan details error: " . $e->getMessage());
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
.comparison-above {
    color: #000000;
}
.comparison-below {
    color: #000000;
}
.comparison-equal {
    color: #6c757d;
    font-weight: bold;
}
.global-avg-info {
    padding: 10px;
    margin-bottom: 15px;
    font-size: 14px;
}
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">Loan Details</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <div class="global-avg-info">
                            <strong>Global Averages:</strong><br>
                            Average Document Size: <?php echo number_format($globalAverageSize, 2); ?> bytes<br>
                            Average Documents per Loan: <?php echo number_format($globalAverageCount, 2); ?>
                        </div>
                        
                        <table class="table table-striped table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Loan Number</th>
                                    <th>Total Documents</th>
                                    <th>Average Size (bytes)</th>
                                    <th>Size Comparison</th>
                                    <th>Count Comparison</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loanDetails as $loan): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($loan['loan_number']); ?></td>
                                        <td><?php echo number_format($loan['total_documents']); ?></td>
                                        <td><?php echo number_format($loan['average_size_bytes'], 2); ?></td>
                                        <td>
                                            <span class="comparison-<?php echo strtolower($loan['size_comparison']); ?>">
                                                <?php echo $loan['size_comparison']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="comparison-<?php echo strtolower($loan['count_comparison']); ?>">
                                                <?php echo $loan['count_comparison']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
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

