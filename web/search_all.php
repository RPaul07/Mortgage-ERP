<?php
// Search All Files - redirect to view all documents
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';
$totalFiles = 0;
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
    
    // Get total file count for display
    $countStmt = $dbManager->getConnection()->prepare("SELECT COUNT(*) as total FROM documents");
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalFiles = $countResult->fetch_assoc()['total'];
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading file count: " . $e->getMessage();
    error_log("Search all error: " . $e->getMessage());
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
        <div class="panel-heading">View All Files</div>
        <div class="panel-body">
            <?php if ($errorMessage): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php else: ?>
                <p>Total files in system: <strong><?php echo number_format($totalFiles); ?></strong></p>
                
                <form method="post" action="search_all_view.php">
                    <div class="form-group">
                        <button type="submit" name="submit" value="submit" class="btn btn-success">View All Files</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>

</body></html>
