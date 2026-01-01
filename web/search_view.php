<?php
// Search View - Display search results for selected loan ID
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

// Input validation
$loanId = filter_var($_POST['loanId'] ?? 0, FILTER_VALIDATE_INT);
$errorMessage = '';
$searchResults = [];
$loanInfo = null;

if (!$loanId) {
    $errorMessage = "Invalid loan ID provided.";
} else {
    try {
        $dbManager = new DatabaseManager(
            $config['database']['host'],
            $config['database']['username'],
            $config['database']['password'],
            $config['database']['database'],
            $config['database']['charset'],
            $config['database']['port']
        );
        
        // Get loan information
        $loanStmt = $dbManager->getConnection()->prepare("
            SELECT loan_number FROM loans WHERE loan_id = ?
        ");
        $loanStmt->bind_param("i", $loanId);
        $loanStmt->execute();
        $loanResult = $loanStmt->get_result();
        
        if ($loanResult->num_rows === 0) {
            $errorMessage = "Loan not found.";
        } else {
            $loanInfo = $loanResult->fetch_assoc();
            
            // Get documents for this loan (lightweight query - no content BLOB)
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
                WHERE d.loan_id = ?
                ORDER BY d.uploaded_at DESC
                LIMIT 50
            ");
            
            $stmt->bind_param("i", $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $searchResults[] = $row;
            }
        }
        
        $dbManager->close();
        
    } catch (Exception $e) {
        $errorMessage = "Error searching documents: " . $e->getMessage();
        error_log("Search view error: " . $e->getMessage());
    }
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
                    Search Results
                    <?php if ($loanInfo): ?>
                        - Loan: <?php echo htmlspecialchars($loanInfo['loan_number']); ?>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                        <a href="search_loanId.php" class="btn btn-default">Back to Search</a>
                    <?php elseif (empty($searchResults)): ?>
                        <div class="alert alert-info">No documents found for this loan.</div>
                        <a href="search_loanId.php" class="btn btn-default">Back to Search</a>
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
