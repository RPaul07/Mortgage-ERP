<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$totalDisconnectErrors = 0;
$disconnectErrors = [];
$totalExtendedResponses = 0;
$extendedResponses = [];

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get total number of disconnect errors
    $disconnectCountStmt = $dbManager->getConnection()->prepare("
        SELECT COUNT(*) as total_disconnect_errors
        FROM api_call_logs
        WHERE success = 0 
            AND (error_message LIKE '%disconnect%' 
                 OR error_message LIKE '%connection%'
                 OR error_message LIKE '%timeout%'
                 OR http_status = 0
                 OR http_status IS NULL)
    ");
    
    $disconnectCountStmt->execute();
    $disconnectCountResult = $disconnectCountStmt->get_result();
    $disconnectCountRow = $disconnectCountResult->fetch_assoc();
    $totalDisconnectErrors = (int)$disconnectCountRow['total_disconnect_errors'];
    $disconnectCountStmt->close();
    
    // Get list of disconnect errors
    $disconnectStmt = $dbManager->getConnection()->prepare("
        SELECT 
            log_id,
            created_at as date_time,
            endpoint as api_call,
            http_status,
            LEFT(error_message, 200) as error_message_preview
        FROM api_call_logs
        WHERE success = 0 
            AND (error_message LIKE '%disconnect%' 
                 OR error_message LIKE '%connection%'
                 OR error_message LIKE '%timeout%'
                 OR http_status = 0
                 OR http_status IS NULL)
        ORDER BY created_at DESC
    ");
    
    $disconnectStmt->execute();
    $disconnectResult = $disconnectStmt->get_result();
    
    while ($row = $disconnectResult->fetch_assoc()) {
        $disconnectErrors[] = [
            'log_id' => (int)$row['log_id'],
            'date_time' => $row['date_time'],
            'api_call' => $row['api_call'],
            'http_status' => $row['http_status'],
            'error_message' => $row['error_message_preview']
        ];
    }
    $disconnectStmt->close();
    
    // Get total number of extended response times
    $extendedCountStmt = $dbManager->getConnection()->prepare("
        SELECT COUNT(*) as total_extended_responses
        FROM api_call_logs
        WHERE execution_time_seconds > 5.0
            AND success = 1
    ");
    
    $extendedCountStmt->execute();
    $extendedCountResult = $extendedCountStmt->get_result();
    $extendedCountRow = $extendedCountResult->fetch_assoc();
    $totalExtendedResponses = (int)$extendedCountRow['total_extended_responses'];
    $extendedCountStmt->close();
    
    // Get list of extended response times
    $extendedStmt = $dbManager->getConnection()->prepare("
        SELECT 
            log_id,
            created_at as date_time,
            endpoint as api_call,
            execution_time_seconds,
            http_status,
            session_id
        FROM api_call_logs
        WHERE execution_time_seconds > 5.0
            AND success = 1
        ORDER BY execution_time_seconds DESC, created_at DESC
    ");
    
    $extendedStmt->execute();
    $extendedResult = $extendedStmt->get_result();
    
    while ($row = $extendedResult->fetch_assoc()) {
        $extendedResponses[] = [
            'log_id' => (int)$row['log_id'],
            'date_time' => $row['date_time'],
            'api_call' => $row['api_call'],
            'execution_time_seconds' => (float)$row['execution_time_seconds'],
            'http_status' => $row['http_status'],
            'session_id' => $row['session_id']
        ];
    }
    $extendedStmt->close();
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading error logging data: " . $e->getMessage();
    error_log("Report error logging error: " . $e->getMessage());
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
}
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">Error Logging</div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php else: ?>
                        <!-- Section 1: Disconnect Errors -->
                        <div class="section-box">
                            <div class="section-title">
                                Disconnect Errors - Total: <?php echo number_format($totalDisconnectErrors); ?>
                            </div>
                            
                            <?php if (empty($disconnectErrors)): ?>
                                <p>No disconnect errors found.</p>
                            <?php else: ?>
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>API Call</th>
                                            <th>HTTP Status</th>
                                            <th>Error Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($disconnectErrors as $error): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($error['date_time']); ?></td>
                                                <td><?php echo htmlspecialchars($error['api_call']); ?></td>
                                                <td><?php echo $error['http_status'] !== null ? htmlspecialchars($error['http_status']) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars($error['error_message']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Section 2: Extended Response Times -->
                        <div class="section-box">
                            <div class="section-title extended-section-title">
                                Extended Response Times (over 5 seconds) - Total: <?php echo number_format($totalExtendedResponses); ?>
                            </div>
                            
                            <?php if (empty($extendedResponses)): ?>
                                <p>No extended response times found.</p>
                            <?php else: ?>
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>API Call</th>
                                            <th>Execution Time (seconds)</th>
                                            <th>HTTP Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($extendedResponses as $response): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($response['date_time']); ?></td>
                                                <td><?php echo htmlspecialchars($response['api_call']); ?></td>
                                                <td><?php echo number_format($response['execution_time_seconds'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($response['http_status']); ?></td>
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

