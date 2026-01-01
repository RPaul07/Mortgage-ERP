<?php
// Search View - Display search results for selected document type
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

// Input validation
$docTypeId = filter_var($_POST['docTypeId'] ?? 0, FILTER_VALIDATE_INT);
$loanId = filter_var($_POST['loanId'] ?? 0, FILTER_VALIDATE_INT);
$errorMessage = '';
$searchResults = [];
$searchInfo = null;

if (!$docTypeId) {
    $errorMessage = "Invalid document type provided.";
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
        
        // Get document type information
        $docTypeStmt = $dbManager->getConnection()->prepare("
            SELECT type_name FROM document_type WHERE doc_type_id = ?
        ");
        $docTypeStmt->bind_param("i", $docTypeId);
        $docTypeStmt->execute();
        $docTypeResult = $docTypeStmt->get_result();
        
        if ($docTypeResult->num_rows === 0) {
            $errorMessage = "Document type not found.";
        } else {
            $docTypeInfo = $docTypeResult->fetch_assoc();
            
            // Build search info
            $searchInfo = ['type_name' => $docTypeInfo['type_name']];
            
            // If loan filter is specified, get loan info
            if ($loanId) {
                $loanStmt = $dbManager->getConnection()->prepare("
                    SELECT loan_number FROM loans WHERE loan_id = ?
                ");
                $loanStmt->bind_param("i", $loanId);
                $loanStmt->execute();
                $loanResult = $loanStmt->get_result();
                
                if ($loanResult->num_rows > 0) {
                    $loanInfo = $loanResult->fetch_assoc();
                    $searchInfo['loan_number'] = $loanInfo['loan_number'];
                }
            }
            
            // Build the main search query
            $baseQuery = "
                SELECT 
                    d.doc_id as document_id,
                    d.doc_name,
                    l.loan_number,
                    d.file_size_bytes,
                    d.last_accessed,
                    d.uploaded_at as created_at
                FROM documents d
                JOIN loans l ON d.loan_id = l.loan_id
                JOIN document_type dt ON d.doc_type_id = dt.doc_type_id
                WHERE d.doc_type_id = ?
            ";
            
            $params = [$docTypeId];
            $paramTypes = "i";
            
            // Add loan filter if specified
            if ($loanId) {
                $baseQuery .= " AND d.loan_id = ?";
                $params[] = $loanId;
                $paramTypes .= "i";
            }
            
            $baseQuery .= " ORDER BY d.uploaded_at DESC LIMIT 50";
            
            $stmt = $dbManager->getConnection()->prepare($baseQuery);
            $stmt->bind_param($paramTypes, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $searchResults[] = $row;
            }
        }
        
        $dbManager->close();
        
    } catch (Exception $e) {
        $errorMessage = "Error searching documents: " . $e->getMessage();
        error_log("Search doc type view error: " . $e->getMessage());
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
                    Search Results - Document Type: 
                    <?php if ($searchInfo): ?>
                        <?php echo htmlspecialchars($searchInfo['type_name']); ?>
                        <?php if (isset($searchInfo['loan_number'])): ?>
                            (Loan: <?php echo htmlspecialchars($searchInfo['loan_number']); ?>)
                        <?php else: ?>
                            (All Loans)
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                        <a href="search_docType.php" class="btn btn-default">Back to Search</a>
                    <?php elseif (empty($searchResults)): ?>
                        <p>No documents found for this search criteria.</p>
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
