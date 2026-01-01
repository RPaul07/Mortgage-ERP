<?php
// Search by Date - search documents by upload date range
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';
$errorMessage = '';

// Get date range for helper info
try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get earliest and latest document dates for reference
    $rangeStmt = $dbManager->getConnection()->prepare("
        SELECT 
            DATE(MIN(uploaded_at)) as earliest_date,
            DATE(MAX(uploaded_at)) as latest_date,
            COUNT(*) as total_docs
        FROM documents
    ");
    
    $rangeStmt->execute();
    $rangeResult = $rangeStmt->get_result();
    $dateRange = $rangeResult->fetch_assoc();
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading date information: " . $e->getMessage();
    error_log("Search date error: " . $e->getMessage());
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
.date-info {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
    <div class="panel panel-primary">
        <div class="panel-heading">Search by Upload Date</div>
        <div class="panel-body">
            <form method="post" action="search_date_view.php">
                <div class="form-group">
                    <label class="control-label">From Date:</label>
                    <input type="date" name="fromDate" class="form-control" 
                           <?php if (isset($dateRange['earliest_date'])): ?>
                               min="<?php echo $dateRange['earliest_date']; ?>"
                               max="<?php echo $dateRange['latest_date']; ?>"
                           <?php endif; ?>>
                </div>
                
                <div class="form-group">
                    <label class="control-label">To Date:</label>
                    <input type="date" name="toDate" class="form-control"
                           <?php if (isset($dateRange['earliest_date'])): ?>
                               min="<?php echo $dateRange['earliest_date']; ?>"
                               max="<?php echo $dateRange['latest_date']; ?>"
                           <?php endif; ?>>
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
