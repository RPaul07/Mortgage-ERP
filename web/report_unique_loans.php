<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$totalUniqueLoans = 0;
$loanNumbers = [];

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get total count
    $countStmt = $dbManager->getConnection()->prepare("
        SELECT COUNT(DISTINCT loan_number) as total_unique_loans
        FROM loans
    ");
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();
    $totalUniqueLoans = (int)$countRow['total_unique_loans'];
    $countStmt->close();
    
    // Get all loan numbers individually to avoid GROUP_CONCAT truncation
    $stmt = $dbManager->getConnection()->prepare("
        SELECT DISTINCT loan_number
        FROM loans
        ORDER BY loan_number
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loanNumbers = [];
    while ($row = $result->fetch_assoc()) {
        $loanNumbers[] = $row['loan_number'];
    }
    $stmt->close();
    
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading loan numbers: " . $e->getMessage();
    error_log("Report unique loans error: " . $e->getMessage());
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
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">Unique Loans Report</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <div>
                            <h4>Total Number of Unique Loan Numbers: <strong><?php echo number_format($totalUniqueLoans); ?></strong></h4>
                        </div>
                        
                        <hr>
                        
                        <h4>All Loan Numbers:</h4>
                        <table class="table table-striped table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Id</th>
                                    <th>Loan Number</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $id = 1; foreach ($loanNumbers as $loanNumber): ?>
                                    <tr>
                                        <td><?php echo $id++; ?></td>
                                        <td><?php echo htmlspecialchars($loanNumber); ?></td>
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

