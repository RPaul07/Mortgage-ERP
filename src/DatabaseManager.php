<?php
/**
 * DatabaseManager class for handling all database operations
 * Provides secure database connection and document storage functionality
 * Automatically creates loans, document types, and users as needed
 */

class DatabaseManager {
    private $connection;
    private $host;
    private $username;
    private $password;
    private $database;
    private $charset;
    private $port;
    
    /**
     * Constructor
     * @param string $host Database host
     * @param string $username Database username
     * @param string $password Database password
     * @param string $database Database name
     * @param string $charset Character set
     * @param int $port Database port
     */
    public function __construct($host, $username, $password, $database, $charset = 'utf8mb4', $port = 3306) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->charset = $charset;
        $this->port = $port;
        
        $this->connect();
    }
    
    /**
     * Establish database connection
     * @throws Exception if connection fails
     */
    private function connect() {
        $this->connection = new mysqli($this->host, $this->username, $this->password, $this->database, $this->port);
        
        if ($this->connection->connect_error) {
            throw new Exception("Database connection failed: " . $this->connection->connect_error);
        }
        
        // Set charset
        if (!$this->connection->set_charset($this->charset)) {
            throw new Exception("Error setting charset: " . $this->connection->error);
        }
    }
    
    /**
     * Check if connection is alive and reconnect if necessary
     * Uses a simple query instead of ping() for PHP 8.2+ compatibility
     * @throws Exception if reconnection fails
     */
    private function ensureConnection() {
        // If no connection exists, create one
        if (!$this->connection) {
            $this->connect();
            return;
        }
        
        // Check if connection is alive by attempting a simple query
        // This works across all PHP versions and doesn't rely on deprecated ping()
        try {
            $result = $this->connection->query("SELECT 1");
            if ($result === false) {
                // Query failed, connection is dead - reconnect
                $this->connection->close();
                $this->connect();
            } else {
                // Connection is alive, free the result
                $result->free();
            }
        } catch (Exception $e) {
            // Exception occurred, connection is likely dead - reconnect
            $this->connection->close();
            $this->connect();
        }
    }
    
    /**
     * Get or create loan ID by loan number
     * @param string $loanNumber The loan number
     * @return int The loan_id
     * @throws Exception if operation fails
     */
    public function getOrCreateLoanId($loanNumber) {
        $this->ensureConnection();
        
        // First, try to get existing loan
        $stmt = $this->connection->prepare("SELECT loan_id FROM loans WHERE loan_number = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $loanNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['loan_id'];
        }
        
        $stmt->close();
        
        // Loan doesn't exist, create it
        $stmt = $this->connection->prepare("INSERT INTO loans (loan_number, created_at) VALUES (?, NOW())");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $loanNumber);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $loanId = $this->connection->insert_id;
        $stmt->close();
        
        return $loanId;
    }
    
    /**
     * Get or create document type ID by type name
     * @param string $typeName The document type name
     * @return int The doc_type_id
     * @throws Exception if operation fails
     */
    public function getOrCreateDocumentTypeId($typeName) {
        // First, try to get existing document type
        $stmt = $this->connection->prepare("SELECT doc_type_id FROM document_type WHERE type_name = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $typeName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['doc_type_id'];
        }
        
        $stmt->close();
        
        // Document type doesn't exist, create it
        $stmt = $this->connection->prepare("INSERT INTO document_type (type_name) VALUES (?)");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $typeName);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $docTypeId = $this->connection->insert_id;
        $stmt->close();
        
        return $docTypeId;
    }
    
    /**
     * Get or create system user ID
     * @return int The user_id (always 1 for system)
     * @throws Exception if operation fails
     */
    private function getOrCreateSystemUserId() {
        // First, try to get existing system user
        $stmt = $this->connection->prepare("SELECT user_id FROM users WHERE user_id = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['user_id'];
        }
        
        $stmt->close();
        
        // System user doesn't exist, create it
        $userName = 'SYSTEM';
        $firstName = 'System';
        $lastName = 'User';
        $role = 'IMPORT';
        $email = 'system@import.local';
        
        $stmt = $this->connection->prepare("INSERT INTO users (user_id, user_name, first_name, last_name, email, role) VALUES (1, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("sssss", $userName, $firstName, $lastName, $email, $role);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
        return 1;
    }
    
    /**
     * Get or create web user ID for web uploads
     * @return int The user_id (always 2 for web user)
     * @throws Exception if operation fails
     */
    private function getOrCreateWebUserId() {
        // First, try to get existing web user
        $stmt = $this->connection->prepare("SELECT user_id FROM users WHERE user_id = 2");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['user_id'];
        }
        
        $stmt->close();
        
        // Web user doesn't exist, create it
        $userName = 'WEB_USER';
        $firstName = 'Web';
        $lastName = 'User';
        $role = 'UPLOAD';
        $email = 'web@upload.local';
        
        $stmt = $this->connection->prepare("INSERT INTO users (user_id, user_name, first_name, last_name, email, role) VALUES (2, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("sssss", $userName, $firstName, $lastName, $email, $role);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
        return 2;
    }
    
    /**
     * Insert a document into the new database schema
     * Tracks duplicates in document_duplicates table with latest file marked as primary
     * @param string $filename The filename
     * @param string $loanNumber The loan number
     * @param string $documentType The document type
     * @param string $content The file content
     * @param int $userId The user ID (default: 1 for system)
     * @return int The document ID
     * @throws Exception if insertion fails
     */
    public function insertDocument($filename, $loanNumber, $documentType, $content, $userId = 1) {
        $this->ensureConnection();
        
        // Start transaction
        $this->connection->begin_transaction();
        
        try {
            // Auto-create/get user based on userId
            if ($userId == 1) {
                $userId = $this->getOrCreateSystemUserId();
            } elseif ($userId == 2) {
                $userId = $this->getOrCreateWebUserId();
            }
            
            // Get or create loan ID
            $loanId = $this->getOrCreateLoanId($loanNumber);
            
            // Extract version number from filename
            // Filename format: <loan_number>-<document_type>_<version_number>-<time_stamp>.pdf
            // OR: <loan_number>-<document_type>-<time_stamp>.pdf (version = 0)
            $versionNumber = 0;
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $parts = explode('-', $nameWithoutExt);
            
            if (isset($parts[1])) {
                // Check if document_type part has version number suffix: <type>_<number>
                if (preg_match('/^(.+)_(\d+)$/', $parts[1], $matches)) {
                    $versionNumber = (int)$matches[2];
                } else {
                    $versionNumber = 0;
                }
            }
            
            // Get or create document type ID
            $docTypeId = $this->getOrCreateDocumentTypeId($documentType);
            
            // Calculate file size
            $fileSize = strlen($content);
            
            // Calculate SHA256 hash for duplicate detection
            $sha256Hash = hash('sha256', $content);
            
            // Check if a document with the same filename already exists
            $isDuplicate = $this->documentExists($filename);
            
            // Get or create duplicate_group_id if this is a duplicate
            $duplicateGroupId = null;
            if ($isDuplicate) {
                // Check if this hash already exists in document_duplicates
                $existingGroupId = $this->getDuplicateGroupIdByHash($sha256Hash);
                
                if ($existingGroupId) {
                    // Use existing duplicate group
                    $duplicateGroupId = $existingGroupId;
                } else {
                    // Check if there's an existing duplicate group for this filename
                    $existingFilenameGroupId = $this->getDuplicateGroupIdByFilename($filename);
                    
                    if ($existingFilenameGroupId) {
                        // Use existing group for this filename
                        $duplicateGroupId = $existingFilenameGroupId;
                    } else {
                        // Create new duplicate group (UUID)
                        $duplicateGroupId = $this->generateUUID();
                    }
                }
                
                // Ensure all existing documents with this filename are in document_duplicates
                $this->ensureExistingDuplicatesTracked($filename, $duplicateGroupId);
                
                // Mark all existing duplicates with this filename as non-primary
                $this->markDuplicatesAsNonPrimary($filename);
            }
            
            // Insert into documents table
            $stmt = $this->connection->prepare("INSERT INTO documents (loan_id, doc_name, doc_type_id, uploaded_at, user_id, file_size_bytes, version_number, is_current) VALUES (?, ?, ?, NOW(), ?, ?, ?, 1)");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->connection->error);
            }
            
            $stmt->bind_param("isiiii", $loanId, $filename, $docTypeId, $userId, $fileSize, $versionNumber);
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $docId = $this->connection->insert_id;
            $stmt->close();
            
            // Insert into document_blobs table
            $stmt = $this->connection->prepare("INSERT INTO document_blobs (doc_id, doc_content) VALUES (?, ?)");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->connection->error);
            }
            
            $null = null;
            $stmt->bind_param("ib", $docId, $null);
            $stmt->send_long_data(1, $content);
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $stmt->close();
            
            // Insert into document_duplicates table if this is a duplicate
            if ($isDuplicate && $duplicateGroupId) {
                $this->insertDuplicateRecord($docId, $duplicateGroupId, $sha256Hash, true);
            }
            
            // Update loan statistics
            $this->updateLoanStatistics($loanId);
            
            // Update loan completion status (missing documents tracking)
            $this->updateLoanCompletionStatus($loanId);
            
            // Commit transaction
            $this->connection->commit();
            
            return $docId;
            
        } catch (Exception $e) {
            $this->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * Update loan statistics after document insertion
     * @param int $loanId The loan ID
     * @throws Exception if update fails
     */
    private function updateLoanStatistics($loanId) {
        // Get current document count and total size for this loan
        $stmt = $this->connection->prepare("
            SELECT COUNT(*) as doc_count, SUM(file_size_bytes) as total_size 
            FROM documents 
            WHERE loan_id = ? AND is_current = 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("i", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $docCount = $row['doc_count'] ?? 0;
        $totalSize = $row['total_size'] ?? 0;
        
        // Update loans table
        $stmt = $this->connection->prepare("UPDATE loans SET doc_count = ?, total_size_bytes = ?, last_accessed = NOW() WHERE loan_id = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("iii", $docCount, $totalSize, $loanId);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Update loan completion status and missing document information
     * Uses doc_type_id values stored in JSON array for efficiency
     * @param int $loanId The loan ID
     * @throws Exception if operation fails
     */
    public function updateLoanCompletionStatus($loanId) {
        $this->ensureConnection();
        
        // Get all required document type IDs (where is_required_by_default = 1)
        $stmt = $this->connection->prepare("
            SELECT doc_type_id 
            FROM document_type 
            WHERE is_required_by_default = 1
            ORDER BY doc_type_id
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requiredTypeIds = [];
        while ($row = $result->fetch_assoc()) {
            $requiredTypeIds[] = (int)$row['doc_type_id'];
        }
        $stmt->close();
        
        if (empty($requiredTypeIds)) {
            return; // No required types found, skip update
        }
        
        // Get existing documents for this loan with required types
        $placeholders = implode(',', array_fill(0, count($requiredTypeIds), '?'));
        $docStmt = $this->connection->prepare("
            SELECT DISTINCT doc_type_id 
            FROM documents 
            WHERE loan_id = ? 
              AND doc_type_id IN ($placeholders)
              AND is_current = 1
        ");
        
        if (!$docStmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $types = 'i' . str_repeat('i', count($requiredTypeIds));
        $params = array_merge([$loanId], $requiredTypeIds);
        $docStmt->bind_param($types, ...$params);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        
        $existingTypeIds = [];
        while ($row = $docResult->fetch_assoc()) {
            $existingTypeIds[] = (int)$row['doc_type_id'];
        }
        $docStmt->close();
        
        // Find missing document type IDs
        $missingTypeIds = array_values(array_diff($requiredTypeIds, $existingTypeIds));
        
        // Calculate missing count
        $missingCount = count($missingTypeIds);
        
        // Determine completion status (loan is complete only if it has all required document types)
        // completion_status = 1 if and only if missing_doc_count = 0
        $isComplete = ($missingCount == 0);
        
        // Store missing type IDs as JSON array (empty array if none missing)
        $missingTypesJson = !empty($missingTypeIds) ? json_encode($missingTypeIds) : null;
        
        // Update loans table
        $updateStmt = $this->connection->prepare("
            UPDATE loans 
            SET completion_status = ?, 
                missing_doc_count = ?, 
                missing_doc_types = ? 
            WHERE loan_id = ?
        ");
        
        if (!$updateStmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $updateStmt->bind_param("iisi", $isComplete, $missingCount, $missingTypesJson, $loanId);
        
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new Exception("Execute failed: " . $updateStmt->error);
        }
        
        $updateStmt->close();
    }
    
    /**
     * Check if a document already exists
     * @param string $filename The filename to check
     * @return bool True if document exists
     * @throws Exception if query fails
     */
    public function documentExists($filename) {
        $this->ensureConnection();
        
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM documents WHERE doc_name = ? AND is_current = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        
        return $count > 0;
    }
    
    /**
     * Check which documents from a list already exist in the database (bulk check)
     * @param array $filenames Array of filenames to check
     * @param int $chunkSize Number of filenames to check per query (default 1000)
     * @return array Array of existing document names
     * @throws Exception if query fails
     */
    public function checkDocumentsExistBulk($filenames, $chunkSize = 1000) {
        if (empty($filenames)) {
            return [];
        }
        
        $this->ensureConnection();
        
        $existingNames = [];
        $chunks = array_chunk($filenames, $chunkSize);
        $totalChunks = count($chunks);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            if (empty($chunk)) {
                continue;
            }
            
            // Ensure connection is still alive before each chunk (important for long-running operations)
            $this->ensureConnection();
            
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->connection->prepare("
                SELECT doc_name 
                FROM documents 
                WHERE doc_name IN ($placeholders) 
                  AND is_current = 1
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->connection->error);
            }
            
            $types = str_repeat('s', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $existingNames[] = $row['doc_name'];
            }
            
            $stmt->close();
            
            // Add delay between chunks to reduce database load (0.05-0.1 second)
            if ($chunkIndex < $totalChunks - 1) {
                usleep(75000); // 0.075 second delay
            }
        }
        
        return $existingNames;
    }
    
    /**
     * Get document count for a specific loan ID
     * @param string $loanId The loan ID
     * @return int Number of documents for the loan
     * @throws Exception if query fails
     */
    public function getDocumentCountByLoanId($loanId) {
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM `documents` WHERE `loan_id` = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        
        return $count;
    }
    
    /**
     * Get all documents for a specific loan ID
     * @param string $loanId The loan ID
     * @return array Array of document records
     * @throws Exception if query fails
     */
    public function getDocumentsByLoanId($loanId) {
        $stmt = $this->connection->prepare("SELECT `file_name`, `loan_id`, `content` FROM `documents` WHERE `loan_id` = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $documents = [];
        while ($row = $result->fetch_assoc()) {
            $documents[] = $row;
        }
        
        $stmt->close();
        return $documents;
    }
    
    /**
     * Get duplicate group ID by SHA256 hash
     * @param string $sha256Hash The SHA256 hash
     * @return string|null The duplicate_group_id or null if not found
     * @throws Exception if query fails
     */
    private function getDuplicateGroupIdByHash($sha256Hash) {
        $stmt = $this->connection->prepare("SELECT duplicate_group_id FROM document_duplicates WHERE sha256_hash = ? LIMIT 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $sha256Hash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? $row['duplicate_group_id'] : null;
    }
    
    /**
     * Get duplicate group ID by filename
     * @param string $filename The filename
     * @return string|null The duplicate_group_id or null if not found
     * @throws Exception if query fails
     */
    private function getDuplicateGroupIdByFilename($filename) {
        $stmt = $this->connection->prepare("
            SELECT dd.duplicate_group_id 
            FROM document_duplicates dd
            INNER JOIN documents d ON dd.doc_id = d.doc_id
            WHERE d.doc_name = ? AND d.is_current = 1
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? $row['duplicate_group_id'] : null;
    }
    
    /**
     * Generate a UUID v4 for duplicate group ID
     * @return string UUID string
     */
    private function generateUUID() {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } else {
            $data = openssl_random_pseudo_bytes(16);
        }
        
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
        
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
    
    /**
     * Ensure all existing documents with a filename are tracked in document_duplicates
     * @param string $filename The filename
     * @param string $duplicateGroupId The duplicate group ID to use
     * @throws Exception if operation fails
     */
    private function ensureExistingDuplicatesTracked($filename, $duplicateGroupId) {
        // Get all existing documents with this filename that aren't in document_duplicates yet
        $stmt = $this->connection->prepare("
            SELECT d.doc_id, db.doc_content
            FROM documents d
            INNER JOIN document_blobs db ON d.doc_id = db.doc_id
            LEFT JOIN document_duplicates dd ON d.doc_id = dd.doc_id
            WHERE d.doc_name = ? 
            AND d.is_current = 1
            AND dd.doc_id IS NULL
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Calculate hash for existing document
            $existingHash = hash('sha256', $row['doc_content']);
            
            // Insert into document_duplicates as non-primary
            $this->insertDuplicateRecord($row['doc_id'], $duplicateGroupId, $existingHash, false);
        }
        
        $stmt->close();
    }
    
    /**
     * Mark all existing duplicate records for a filename as non-primary
     * @param string $filename The filename
     * @throws Exception if update fails
     */
    private function markDuplicatesAsNonPrimary($filename) {
        $stmt = $this->connection->prepare("
            UPDATE document_duplicates dd
            INNER JOIN documents d ON dd.doc_id = d.doc_id
            SET dd.is_primary = 0
            WHERE d.doc_name = ? AND d.is_current = 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Insert a record into document_duplicates table
     * @param int $docId The document ID
     * @param string $duplicateGroupId The duplicate group ID (UUID)
     * @param string $sha256Hash The SHA256 hash of the content
     * @param bool $isPrimary Whether this is the primary document
     * @throws Exception if insertion fails
     */
    private function insertDuplicateRecord($docId, $duplicateGroupId, $sha256Hash, $isPrimary = false) {
        $stmt = $this->connection->prepare("
            INSERT INTO document_duplicates (duplicate_group_id, sha256_hash, doc_id, is_primary)
            VALUES (?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $isPrimaryInt = $isPrimary ? 1 : 0;
        $stmt->bind_param("ssii", $duplicateGroupId, $sha256Hash, $docId, $isPrimaryInt);
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    /**
     * Get database connection status
     * @return bool True if connected
     */
    public function isConnected() {
        return $this->connection && !$this->connection->connect_error;
    }
    
    /**
     * Get last error message
     * @return string Last error message
     */
    public function getLastError() {
        return $this->connection ? $this->connection->error : 'No connection';
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    
    /**
     * Get database connection (for use by QueueManager and SessionManager)
     * Automatically checks connection health and reconnects if necessary
     * @return mysqli Database connection
     * @throws Exception if connection check/reconnect fails
     */
    public function getConnection() {
        $this->ensureConnection();
        return $this->connection;
    }
    
    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct() {
        $this->close();
    }
}
?>
