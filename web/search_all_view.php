<?php
// Search View - Display all documents at once
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

$errorMessage = '';
$searchResults = [];
$totalCount = 0;

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
        SELECT COUNT(*) as total FROM documents d
        JOIN loans l ON d.loan_id = l.loan_id
    ");
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    
    // Get all documents (lightweight query - no content BLOB)
    $stmt = $dbManager->getConnection()->prepare("
        SELECT 
            d.doc_id as document_id,
            d.doc_name,
            l.loan_number,
            d.file_size_bytes,
            d.last_accessed,
            d.uploaded_at as created_at
        FROM documents d
        JOIN loans l ON d.loan_id = l.loan_id
        ORDER BY d.uploaded_at DESC
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $searchResults[] = $row;
    }
    
    $dbManager->close();
    
} catch (Exception $e) {
    $errorMessage = "Error loading documents: " . $e->getMessage();
    error_log("Search all view error: " . $e->getMessage());
}

// Helper function to format file size
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB');
    
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
.results-table {
    text-align: left;
}
.document-row:hover {
    background-color: #f5f5f5;
}
</style>
</head>
<body>
    <div class="row main-box">
        <h3>Document Management System</h3>
        <hr>
        <div class="col-md-12">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    All Files - <?php echo number_format($totalCount); ?> Documents
                </div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php elseif (empty($searchResults)): ?>
                        <p>No documents found in the system.</p>
                    <?php else: ?>
                        
                        <div class="table-responsive results-table">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Document Name</th>
                                        <th>Loan Number</th>
                                        <th>File Size</th>
                                        <th>Last Accessed</th>
                                        <th>Uploaded</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($searchResults as $doc): ?>
                                        <tr class="document-row">
                                            <td><?php echo htmlspecialchars($doc['doc_name']); ?></td>
                                            <td><?php echo htmlspecialchars($doc['loan_number']); ?></td>
                                            <td><?php echo formatBytes($doc['file_size_bytes']); ?></td>
                                            <td>
                                                <?php 
                                                if ($doc['last_accessed']) {
                                                    echo date('M j, Y g:i A', strtotime($doc['last_accessed']));
                                                } else {
                                                    echo '<span class="text-muted">Never</span>';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($doc['created_at'])); ?></td>
                                            <td>
                                                <a href="view_document.php?id=<?php echo $doc['document_id']; ?>">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body></html>
