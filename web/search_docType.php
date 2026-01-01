<?php
// Search by Document Type - populate dropdowns and handle filtering
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';
$docTypeOptions = '';
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
    
    // Get all document types that have documents
    $docTypeStmt = $dbManager->getConnection()->prepare("
        SELECT dt.doc_type_id, dt.type_name, COUNT(d.doc_id) as doc_count
        FROM document_type dt
        LEFT JOIN documents d ON dt.doc_type_id = d.doc_type_id
        GROUP BY dt.doc_type_id, dt.type_name
        HAVING doc_count > 0
        ORDER BY dt.type_name ASC
        LIMIT 100
    ");
    
    $docTypeStmt->execute();
    $docTypeResult = $docTypeStmt->get_result();
    
    while ($row = $docTypeResult->fetch_assoc()) {
        $docTypeOptions .= '<option value="' . $row['doc_type_id'] . '">' . 
                          htmlspecialchars($row['type_name']) . '</option>';
    }
    
    // Get all loan numbers that have documents
    $loanStmt = $dbManager->getConnection()->prepare("
        SELECT l.loan_id, l.loan_number, COUNT(d.doc_id) as doc_count
        FROM loans l
        LEFT JOIN documents d ON l.loan_id = d.loan_id
        GROUP BY l.loan_id, l.loan_number
        HAVING doc_count > 0
        ORDER BY l.loan_id ASC
        LIMIT 100
    ");
    
    $loanStmt->execute();
    $loanResult = $loanStmt->get_result();
    
    while ($row = $loanResult->fetch_assoc()) {
        $loanOptions .= '<option value="' . $row['loan_id'] . '">' . 
                       htmlspecialchars($row['loan_number']) . '</option>';
    }
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading data: " . $e->getMessage();
    error_log("Search doc type error: " . $e->getMessage());
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
        <div class="panel-heading">Search by Document Type</div>
        <div class="panel-body">
            <form method="post" action="search_docType_view.php">
                <div class="form-group">
                    <label class="control-label">Select Document Type:</label>
                    <select name="docTypeId" class="form-control" required>
                        <option value="">-- Select a Document Type --</option>
                        <?php echo $docTypeOptions; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="control-label">Filter by Loan (Optional):</label>
                    <select name="loanId" class="form-control">
                        <option value="">-- All Loans --</option>
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