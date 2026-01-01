<?php
processUploadForm();
?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<title>Document Management Web Front End</title>
<link href="assets/css/bootstrap.css" rel="stylesheet">
<link href="assets/css/bootstrap-fileupload.min.css" rel="stylesheet">
<script src="assets/js/jquery-1.10.2.js"></script>
<script src="assets/js/bootstrap.js"></script>
<script src="assets/js/bootstrap-fileupload.js"></script>
<style>
.main-box {
    text-align:center;
    padding:20px;
    border-radius:5px;
    -moz-border-radius:5px;
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
        <div class="panel-heading">Upload to Existing Loan</div>
        <div class="panel-body">
            <?php if (!empty($errors['form'])): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errors['form']); ?></div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <h3>Please fill out the form below.</h3>
            <hr>
            <form method="post" action="" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="MAX_FILE_SIZE" value="5000000">
                <div class="form-group <?php echo isset($errors['loanId']) ? 'has-error' : ''; ?>">
                    <label class="control-label">Loan Number:</label>
                    <select name="loanId" class="form-control">
                        <option value="">Select Loan Number</option>
                        <?php foreach ($existingLoans as $loan): ?>
                            <option value="<?php echo htmlspecialchars($loan['loan_number']); ?>" <?php echo $loan['loan_number'] === $selectedLoanNumber ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loan['loan_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['loanId'])): ?>
                        <span class="help-block text-danger"><?php echo htmlspecialchars($errors['loanId']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-group <?php echo isset($errors['docType']) ? 'has-error' : ''; ?>">
                    <label class="control-label">Document Type:</label>
                    <select name="docType" class="form-control">
                        <option value="">Select Document Type</option>
                        <?php foreach ($docTypes as $docType): ?>
                            <option value="<?php echo htmlspecialchars($docType); ?>" <?php echo $docType === $selectedDocType ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($docType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['docType'])): ?>
                        <span class="help-block text-danger"><?php echo htmlspecialchars($errors['docType']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-group <?php echo isset($errors['userfile']) ? 'has-error' : ''; ?>">
                    <label class="control-label">File Upload</label>
                    <div class="">
                        <div class="fileupload fileupload-new" data-provides="fileupload">
                            <div class="fileupload-preview thumbnail" style="width:200px; height:150px"></div>
                            <div class="row">
                                <div class="col-md-6">
                                    <span class="btn btn-file btn-primary">
                                        <span class="fileupload-new">Select File</span>
                                        <span class="fileupload-exists">Change</span>
                                        <input name="userfile" type="file">
                                    </span>
                                </div>
                                <div class="col-md-6">
                                    <a href="#" class="btn btn-danger fileupload-exists" data-dismiss="fileupload">Remove</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if (isset($errors['userfile'])): ?>
                        <span class="help-block text-danger"><?php echo htmlspecialchars($errors['userfile']); ?></span>
                    <?php endif; ?>
                </div>
                <hr>
                <div class="form-group">
                    <button type="submit" name="submit" value="submit" class="btn btn-lg btn-block btn-success">Upload File</button>
                </div>
            </form>
        </div>
    </div>
        </div>
    </div>

<?php
function processUploadForm() {
    global $docTypes, $existingLoans, $errors, $successMessage, $selectedLoanNumber, $selectedDocType;
    
    require_once __DIR__ . '/../src/config.php';
    require_once __DIR__ . '/../src/DatabaseManager.php';
    require_once __DIR__ . '/../src/FileProcessor.php';

    $config = include __DIR__ . '/../src/config.php';

    $docTypes = [];
    $existingLoans = [];
    $errors = [];
    $successMessage = null;
    $selectedLoanNumber = '';
    $selectedDocType = '';

    // Load document types and existing loans from database
    try {
        $dbManager = new DatabaseManager(
            $config['database']['host'],
            $config['database']['username'],
            $config['database']['password'],
            $config['database']['database'],
            $config['database']['charset'],
            $config['database']['port']
        );
        
        $connection = $dbManager->getConnection();
        
        // Load document types
        $stmt = $connection->prepare("
            SELECT type_name 
            FROM document_type 
            WHERE is_required_by_default = 1
            ORDER BY type_name
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $docTypes[] = $row['type_name'];
        }
        $stmt->close();
        
        // Load existing loans sorted by loan_id (smallest to largest)
        $stmt = $connection->prepare("
            SELECT loan_id, loan_number 
            FROM loans 
            ORDER BY loan_id ASC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existingLoans[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        $errors['form'] = 'Unable to load data. Please try again later.';
        $dbManager = null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedLoanNumber = trim($_POST['loanId'] ?? '');
        $selectedDocType = $_POST['docType'] ?? '';

        // Validate loan number selection
        if ($selectedLoanNumber === '') {
            $errors['loanId'] = 'Please select a loan number.';
        } else {
            // Verify the selected loan exists in the database
            $loanExists = false;
            foreach ($existingLoans as $loan) {
                if ($loan['loan_number'] === $selectedLoanNumber) {
                    $loanExists = true;
                    break;
                }
            }
            if (!$loanExists) {
                $errors['loanId'] = 'Selected loan number does not exist.';
            }
        }

        // Validate document type
        if ($selectedDocType === '') {
            $errors['docType'] = 'Please choose a document type.';
        } elseif (!in_array($selectedDocType, $docTypes, true)) {
            $errors['docType'] = 'Selected document type is not available.';
        }

        // Validate file upload
        if (!isset($_FILES['userfile']) || $_FILES['userfile']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['userfile'] = 'Please choose a file to upload.';
        } else {
            $file = $_FILES['userfile'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors['userfile'] = 'File upload failed. Please try again.';
            } else {
                // Check MIME type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if ($mimeType !== 'application/pdf') {
                    $errors['userfile'] = 'Only PDF files are allowed.';
                }
            }
        }

        // If all validations pass, store in database
        if (empty($errors) && isset($dbManager)) {
            try {
                // Read file content into memory
                $fileContent = file_get_contents($_FILES['userfile']['tmp_name']);
                
                if ($fileContent === false) {
                    throw new Exception('Failed to read uploaded file.');
                }

                // Normalize document type (same as backend system)
                $normalizedDocType = FileProcessor::normalizeDocumentType($selectedDocType);
                
                // Generate filename: loanNumber-documentType-timestamp.pdf
                $timestamp = date('YmdHis');
                $filename = $selectedLoanNumber . '-' . $normalizedDocType . '-' . $timestamp . '.pdf';
                
                // Store in database using DatabaseManager
                $docId = $dbManager->insertDocument(
                    $filename,
                    $selectedLoanNumber,
                    $normalizedDocType,
                    $fileContent,
                    2
                );
                
                $successMessage = 'Document uploaded successfully. Document ID: ' . $docId;
                $selectedLoanNumber = '';
                $selectedDocType = '';
                
            } catch (Exception $e) {
                $errors['form'] = 'Failed to store document: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // Close database connection if it exists
    if (isset($dbManager)) {
        $dbManager->close();
    }
}
?>
</body></html>

