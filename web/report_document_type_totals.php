<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$documentTypeTotals = [];

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get total number of each document type
    $stmt = $dbManager->getConnection()->prepare("
        SELECT 
            dt.type_name,
            dt.doc_type_id,
            COUNT(d.doc_id) as total_count
        FROM document_type dt
        LEFT JOIN documents d ON dt.doc_type_id = d.doc_type_id AND d.is_current = 1
        GROUP BY dt.doc_type_id, dt.type_name
        ORDER BY total_count DESC, dt.type_name
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $documentTypeTotals[] = [
            'type_name' => $row['type_name'],
            'doc_type_id' => (int)$row['doc_type_id'],
            'total_count' => (int)$row['total_count']
        ];
    }
    
    $stmt->close();
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading document type totals: " . $e->getMessage();
    error_log("Report document type totals error: " . $e->getMessage());
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
                <div class="panel-heading">Document Type Totals</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <table class="table table-striped table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Document Type</th>
                                    <th>Total Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentTypeTotals as $docType): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($docType['type_name']); ?></td>
                                        <td><?php echo number_format($docType['total_count']); ?></td>
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

