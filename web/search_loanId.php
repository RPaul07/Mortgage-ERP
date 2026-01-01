<?php
// Search by Loan ID - populate dropdown with available loans
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';
$loanOptions = '';
$errorMessage = '';

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get all unique loan IDs with document counts
    $stmt = $dbManager->getConnection()->prepare("
        SELECT l.loan_id, l.loan_number, COUNT(d.doc_id) as doc_count
        FROM loans l
        LEFT JOIN documents d ON l.loan_id = d.loan_id
        GROUP BY l.loan_id, l.loan_number
        HAVING doc_count > 0
        ORDER BY l.loan_id ASC
        LIMIT 100
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $loanOptions .= '<option value="' . $row['loan_id'] . '">' . 
                       htmlspecialchars($row['loan_number']) . '</option>';
    }
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading loan data: " . $e->getMessage();
    error_log("Search loan ID error: " . $e->getMessage());
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
        <div class="panel-heading">Search by Loan ID</div>
        <div class="panel-body">
            <form method="post" action="search_view.php">
                <div class="form-group">
                    <label class="control-label">Select Loan ID:</label>
                    <select name="loanId" class="form-control" required>
                        <option value="">-- Select a Loan ID --</option>
                        <?php echo $loanOptions; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" name="submit" value="submit" class="btn btn-success">Search Documents</button>
                </div>
            </form>
        </div>
    </div>
        </div>
    </div>

</body></html>