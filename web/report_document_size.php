<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$totalDocuments = 0;
$totalSizeBytes = 0;
$averageSizeBytes = 0;

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get total size and average size of all documents
    $stmt = $dbManager->getConnection()->prepare("
        SELECT 
            COUNT(*) as total_documents,
            SUM(file_size_bytes) as total_size_bytes,
            ROUND(AVG(file_size_bytes), 2) as average_size_bytes
        FROM documents
        WHERE is_current = 1
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $totalDocuments = (int)$row['total_documents'];
    $totalSizeBytes = (int)$row['total_size_bytes'];
    $averageSizeBytes = (float)$row['average_size_bytes'];
    
    $stmt->close();
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading document size statistics: " . $e->getMessage();
    error_log("Report document size error: " . $e->getMessage());
}

// Helper function to format bytes
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
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
                <div class="panel-heading">Document Size Statistics</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <div class="stats-display">
                            <div class="stat-item">
                                <span class="stat-label">Total Size of All Documents:</span>
                                <span class="stat-value"><?php echo number_format($totalSizeBytes); ?> bytes</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Average Size per Document:</span>
                                <span class="stat-value"><?php echo number_format($averageSizeBytes, 2); ?> bytes</span>
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

