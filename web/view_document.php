<?php
// PDF Viewer - Downloads PDF from database to filesystem and serves it
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

$config = include __DIR__ . '/../src/config.php';

// Input validation
$docId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$docId) {
    http_response_code(400);
    die('Invalid document ID');
}

try {
    $dbManager = new DatabaseManager(
        $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        $config['database']['database'],
        $config['database']['charset'],
        $config['database']['port']
    );
    
    // Get document metadata first (lightweight query)
    $metaStmt = $dbManager->getConnection()->prepare(
        "SELECT doc_name as filename, file_size_bytes as file_size, uploaded_at as created_at FROM documents WHERE doc_id = ?"
    );
    $metaStmt->bind_param("i", $docId);
    $metaStmt->execute();
    $metaResult = $metaStmt->get_result();
    
    if ($metaResult->num_rows === 0) {
        http_response_code(404);
        die('Document not found');
    }
    
    $metadata = $metaResult->fetch_assoc();
    
    // Size check before loading content
    $maxSize = 15 * 1024 * 1024; // 15MB limit
    if ($metadata['file_size'] > $maxSize) {
        http_response_code(413);
        die('Document too large to display online');
    }
    
    // Check if file already exists in views directory
    $viewsDir = '/var/www/html/web/views/';
    $safeFilename = 'doc_' . $docId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $metadata['filename']);
    $filePath = $viewsDir . $safeFilename;
    
    // If file doesn't exist, download from database
    if (!file_exists($filePath)) {
        // Get PDF content from database
        $contentStmt = $dbManager->getConnection()->prepare(
            "SELECT doc_content FROM document_blobs WHERE doc_id = ?"
        );
        $contentStmt->bind_param("i", $docId);
        $contentStmt->execute();
        $contentResult = $contentStmt->get_result();
        
        if ($contentResult->num_rows === 0) {
            http_response_code(404);
            die('Document content not found');
        }
        
        $content = $contentResult->fetch_assoc()['doc_content'];
        
        // Validate it's actually a PDF
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);
        
        if ($mimeType !== 'application/pdf') {
            http_response_code(415);
            die('Document is not a valid PDF');
        }
        
        // Save to filesystem
        if (file_put_contents($filePath, $content) === false) {
            http_response_code(500);
            die('Error saving document to filesystem');
        }
        
        // Set proper permissions
        chmod($filePath, 0644);
    }
    
    // Update last_accessed timestamp
    $updateStmt = $dbManager->getConnection()->prepare(
        "UPDATE documents SET last_accessed = NOW() WHERE doc_id = ?"
    );
    $updateStmt->bind_param("i", $docId);
    $updateStmt->execute();
    
    $dbManager->close();
    
    // Serve the PDF file
    if (file_exists($filePath)) {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . basename($metadata['filename']) . '"');
        
        // Cache headers
        header('Cache-Control: private, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($metadata['created_at'])) . ' GMT');
        
        // Output the file
        readfile($filePath);
    } else {
        http_response_code(500);
        die('Error accessing document file');
    }
    
} catch (Exception $e) {
    error_log("PDF viewer error for doc_id $docId: " . $e->getMessage());
    http_response_code(500);
    die('Error loading document');
}
?>
