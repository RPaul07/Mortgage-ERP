<?php
/**
 * QueueManager class for managing download queue
 * Handles adding files to queue, retrieving batches, and updating status
 */

require_once __DIR__ . '/../DatabaseManager.php';

class QueueManager {
    private $dbManager;
    private $connection;
    
    /**
     * Constructor
     * @param DatabaseManager $dbManager Database manager instance
     */
    public function __construct($dbManager) {
        $this->dbManager = $dbManager;
        // Get connection from DatabaseManager
        $this->connection = $dbManager->getConnection();
    }
    
    /**
     * Add file to download queue
     * @param string $fileId File ID/name
     * @param int $priority Priority (1-10, default 5)
     * @return bool True if added, false if already exists
     * @throws Exception if operation fails
     */
    public function addToQueue($fileId, $priority = 5) {
        $stmt = $this->connection->prepare("
            INSERT INTO download_queue (file_id, status, priority, created_at)
            VALUES (?, 'pending', ?, NOW())
            ON DUPLICATE KEY UPDATE 
                status = IF(status = 'completed', status, 'pending'),
                created_at = IF(status = 'completed', created_at, NOW()),
                attempts = IF(status = 'completed', attempts, 0),
                error_message = IF(status = 'completed', error_message, NULL),
                started_at = IF(status = 'completed', started_at, NULL),
                completed_at = IF(status = 'completed', completed_at, NULL),
                next_retry_at = IF(status = 'completed', next_retry_at, NULL)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("si", $fileId, $priority);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    /**
     * Add multiple files to queue
     * @param array $fileIds Array of file IDs
     * @param int $priority Priority for all files
     * @return int Number of files added
     */
    public function addBatchToQueue($fileIds, $priority = 5) {
        $added = 0;
        foreach ($fileIds as $fileId) {
            try {
                if ($this->addToQueue($fileId, $priority)) {
                    $added++;
                }
            } catch (Exception $e) {
                // Log error but continue
                error_log("Failed to add $fileId to queue: " . $e->getMessage());
            }
        }
        return $added;
    }
    
    /**
     * Add multiple files to queue using bulk INSERT (more efficient for large batches)
     * @param array $fileIds Array of file IDs
     * @param int $priority Priority for all files
     * @param int $chunkSize Number of files per INSERT statement (default 500)
     * @return int Number of files added
     * @throws Exception if operation fails
     */
    public function addBulkToQueue($fileIds, $priority = 5, $chunkSize = 500) {
        if (empty($fileIds)) {
            return 0;
        }
        
        $added = 0;
        $chunks = array_chunk($fileIds, $chunkSize);
        $totalChunks = count($chunks);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            if (empty($chunk)) {
                continue;
            }
            
            $values = [];
            $types = '';
            $params = [];
            
            foreach ($chunk as $fileId) {
                $values[] = "(?, 'pending', ?, NOW())";
                $types .= 'si';
                $params[] = $fileId;
                $params[] = $priority;
            }
            
            $valuesStr = implode(', ', $values);
            $query = "
                INSERT INTO download_queue (file_id, status, priority, created_at)
                VALUES $valuesStr
                ON DUPLICATE KEY UPDATE 
                    status = IF(status = 'completed', status, 'pending'),
                    created_at = IF(status = 'completed', created_at, NOW()),
                    attempts = IF(status = 'completed', attempts, 0),
                    error_message = IF(status = 'completed', error_message, NULL),
                    started_at = IF(status = 'completed', started_at, NULL),
                    completed_at = IF(status = 'completed', completed_at, NULL),
                    next_retry_at = IF(status = 'completed', next_retry_at, NULL)
            ";
            
            $stmt = $this->connection->prepare($query);
            
            if (!$stmt) {
                error_log("Failed to prepare bulk insert: " . $this->connection->error);
                continue;
            }
            
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $added += $stmt->affected_rows;
            } else {
                error_log("Failed to execute bulk insert: " . $stmt->error);
            }
            
            $stmt->close();
            
            // Add delay between chunks to reduce database load (0.1-0.2 second)
            if ($chunkIndex < $totalChunks - 1) {
                usleep(150000); // 0.15 second delay
            }
        }
        
        return $added;
    }
    
    /**
     * Get next batch of files to process
     * @param int $limit Number of files to retrieve
     * @param string $status Status to filter (default: 'pending')
     * @return array Array of queue records
     * @throws Exception if operation fails
     */
    public function getNextBatch($limit = 15, $status = 'pending') {
        $statuses = is_array($status) ? $status : [$status];
        
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $types = str_repeat('s', count($statuses)) . 'i';
        $params = array_merge($statuses, [$limit]);
        
        $query = "
            SELECT queue_id, file_id, status, priority, attempts, max_attempts
            FROM download_queue
            WHERE status IN ($placeholders)
            AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY priority DESC, created_at ASC, queue_id ASC
            LIMIT ?
        ";
        
        $stmt = $this->connection->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $files = [];
        while ($row = $result->fetch_assoc()) {
            $files[] = $row;
        }
        
        $stmt->close();
        return $files;
    }
    
    /**
     * Mark file as processing
     * @param int $queueId Queue ID
     * @throws Exception if operation fails
     */
    public function markProcessing($queueId) {
        $stmt = $this->connection->prepare("
            UPDATE download_queue
            SET status = 'processing', started_at = NOW()
            WHERE queue_id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("i", $queueId);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Mark file as completed
     * @param int $queueId Queue ID
     * @throws Exception if operation fails
     */
    public function markCompleted($queueId) {
        $stmt = $this->connection->prepare("
            UPDATE download_queue
            SET status = 'completed', completed_at = NOW(), error_message = NULL
            WHERE queue_id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->bind_param("i", $queueId);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Mark file as failed
     * @param int $queueId Queue ID
     * @param string $error Error message
     * @param bool $retry Whether to schedule retry
     * @throws Exception if operation fails
     */
    public function markFailed($queueId, $error, $retry = true) {
        // Get current attempts
        $stmt = $this->connection->prepare("
            SELECT attempts, max_attempts FROM download_queue WHERE queue_id = ?
        ");
        $stmt->bind_param("i", $queueId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $attempts = $row['attempts'] + 1;
        $maxAttempts = $row['max_attempts'];
        
        $errorMsg = substr($error, 0, 65535); // Limit error message length
        
        if ($retry && $attempts < $maxAttempts) {
            // Schedule retry (exponential backoff: 2^attempts minutes)
            $retryMinutes = pow(2, $attempts);
            $nextRetry = date('Y-m-d H:i:s', strtotime("+$retryMinutes minutes"));
            
            $stmt = $this->connection->prepare("
                UPDATE download_queue
                SET status = 'retry', 
                    attempts = ?,
                    error_message = ?,
                    next_retry_at = ?
                WHERE queue_id = ?
            ");
            
            $stmt->bind_param("issi", $attempts, $errorMsg, $nextRetry, $queueId);
        } else {
            // Max attempts reached, mark as failed
            $stmt = $this->connection->prepare("
                UPDATE download_queue
                SET status = 'failed',
                    attempts = ?,
                    error_message = ?,
                    completed_at = NOW()
                WHERE queue_id = ?
            ");
            
            $stmt->bind_param("isi", $attempts, $errorMsg, $queueId);
        }
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Get queue statistics
     * @return array Statistics array
     */
    public function getQueueStats() {
        $stats = [];
        
        $statuses = ['pending', 'processing', 'completed', 'failed', 'retry'];
        foreach ($statuses as $status) {
            $stmt = $this->connection->prepare("
                SELECT COUNT(*) as count FROM download_queue WHERE status = ?
            ");
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats[$status] = $row['count'];
            $stmt->close();
        }
        
        return $stats;
    }
    
    /**
     * Reset stuck processing items (older than 30 minutes)
     * @return int Number of items reset
     */
    public function resetStuckItems() {
        $stmt = $this->connection->prepare("
            UPDATE download_queue
            SET status = 'pending', started_at = NULL
            WHERE status = 'processing'
            AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");
        
        $stmt->execute();
        $affected = $this->connection->affected_rows;
        $stmt->close();
        
        return $affected;
    }
}

