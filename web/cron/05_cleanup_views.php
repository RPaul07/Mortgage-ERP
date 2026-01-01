<?php
// PDF Views Cleanup Cron Job - Runs every 5 minutes
// Deletes all PDFs in /var/www/html/web/views/ and logs the operation

require_once '/var/www/html/src/DatabaseManager.php';
require_once '/var/www/html/src/config.php';

$config = include '/var/www/html/src/config.php';

$startTime = microtime(true);
$viewsDir = '/var/www/html/web/views/';
$filesDeleted = 0;
$filesFailed = 0;
$totalSizeDeleted = 0;
$errorMessage = null;

try {
    // Initialize database manager
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Check if views directory exists
    if (!is_dir($viewsDir)) {
        throw new Exception("Views directory does not exist: $viewsDir");
    }
    
    // Get all PDF files in the views directory
    $files = glob($viewsDir . '*.pdf');
    $files = array_merge($files, glob($viewsDir . 'doc_*')); // Include our custom named files
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $fileSize = filesize($file);
            
            if (unlink($file)) {
                $filesDeleted++;
                $totalSizeDeleted += $fileSize;
            } else {
                $filesFailed++;
            }
        }
    }
    
    // Calculate execution time
    $executionTime = round((microtime(true) - $startTime) * 1000); // milliseconds
    
    // Log the cleanup operation
    $stmt = $dbManager->getConnection()->prepare("
        INSERT INTO pdf_cleanup_logs 
        (files_deleted, files_failed, total_size_deleted, error_message, execution_time_ms) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("iiisi", $filesDeleted, $filesFailed, $totalSizeDeleted, $errorMessage, $executionTime);
    $stmt->execute();
    
    $dbManager->close();
    
    // Log to system log
    error_log("[" . date('Y-m-d H:i:s') . "] PDF Cleanup: Deleted $filesDeleted files, Failed $filesFailed, Size: " . formatBytes($totalSizeDeleted) . ", Time: {$executionTime}ms");
    
    // Output for cron job (if run manually)
    echo "PDF Cleanup completed:\n";
    echo "- Files deleted: $filesDeleted\n";
    echo "- Files failed: $filesFailed\n";
    echo "- Total size deleted: " . formatBytes($totalSizeDeleted) . "\n";
    echo "- Execution time: {$executionTime}ms\n";
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $executionTime = round((microtime(true) - $startTime) * 1000);
    
    // Try to log the error
    try {
        if (isset($dbManager)) {
            $stmt = $dbManager->getConnection()->prepare("
                INSERT INTO pdf_cleanup_logs 
                (files_deleted, files_failed, total_size_deleted, error_message, execution_time_ms) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("iiisi", $filesDeleted, $filesFailed, $totalSizeDeleted, $errorMessage, $executionTime);
            $stmt->execute();
            
            $dbManager->close();
        }
    } catch (Exception $logError) {
        error_log("Failed to log cleanup error: " . $logError->getMessage());
    }
    
    error_log("[" . date('Y-m-d H:i:s') . "] PDF Cleanup ERROR: " . $errorMessage);
    echo "ERROR: " . $errorMessage . "\n";
    exit(1);
}

// Helper function to format file sizes
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>
