<?php
/**
 * SessionManager class for managing API sessions across cron jobs
 * Handles session creation, retrieval, and cleanup
 */

require_once __DIR__ . '/SessionExpiredException.php';

class SessionManager {
    private $dbManager;
    private $apiClient;
    private $config;
    private $connection;
    private $defaultContext;
    
    /**
     * Constructor
     * @param DatabaseManager $dbManager Database manager instance
     * @param ApiClient $apiClient API client instance
     * @param array $config Configuration array
     * @param array $context Default logging context
     */
    public function __construct($dbManager, $apiClient, $config, array $context = []) {
        $this->dbManager = $dbManager;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->defaultContext = $context;
        
        // Get connection from DatabaseManager
        $this->connection = $dbManager->getConnection();
    }
    
    /**
     * Get or create active session
     * @return string Session ID
     * @throws Exception if session creation fails
     */
    public function getOrCreateSession() {
        // Check for active session in database
        $activeSession = $this->getActiveSession();
        
        if ($activeSession) {
            // Verify session is still valid (not expired)
            if ($activeSession['expires_at'] && strtotime($activeSession['expires_at']) > time()) {
                // Update last used time
                $this->updateLastUsed($activeSession['session_id']);
                // Set session ID in ApiClient
                $this->apiClient->setSessionId($activeSession['session_id']);
                return $activeSession['session_id'];
            } else {
                // Session expired, mark as inactive
                $this->deactivateSession($activeSession['session_id']);
            }
        }
        
        // No active session, create new one
        return $this->createSession();
    }
    
    /**
     * Get active session from database
     * @return array|false Session data or false if not found
     */
    private function getActiveSession() {
        $stmt = $this->connection->prepare("
            SELECT id, session_id, created_at, expires_at, last_used_at
            FROM api_session_state
            WHERE is_active = 1
            ORDER BY created_at DESC
            LIMIT 1
        ");
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $session = $result->fetch_assoc();
        $stmt->close();
        
        return $session ? $session : false;
    }
    
    /**
     * Create new API session
     * @return string Session ID
     * @throws Exception if creation fails
     */
    private function createSession() {
        // Create session via API
        $this->apiClient->setRequestContext($this->buildContext([
            'operation' => 'create_session'
        ]));
        $response = $this->apiClient->createSession();
        
        if (!isset($response['result'][2])) {
            throw new Exception("Failed to create session: Invalid response");
        }
        
        $sessionId = $response['result'][2];
        
        // Deactivate all existing sessions
        $this->deactivateAllSessions();
        
        // Store new session in database
        // Sessions typically expire after 1 hour, set expiration to 55 minutes for safety
        $expiresAt = date('Y-m-d H:i:s', strtotime('+55 minutes'));
        
        $stmt = $this->connection->prepare("
            INSERT INTO api_session_state (session_id, created_at, expires_at, is_active, last_used_at)
            VALUES (?, NOW(), ?, 1, NOW())
        ");
        
        if (!$stmt) {
            throw new Exception("Failed to store session: " . $this->connection->error);
        }
        
        $stmt->bind_param("ss", $sessionId, $expiresAt);
        $stmt->execute();
        $stmt->close();
        
        // Set session ID in ApiClient
        $this->apiClient->setSessionId($sessionId);
        
        return $sessionId;
    }
    
    /**
     * Get active session ID
     * @return string|false Session ID or false if not found
     */
    public function getActiveSessionId() {
        $session = $this->getActiveSession();
        return $session ? $session['session_id'] : false;
    }
    
    /**
     * Update last used timestamp
     * @param string $sessionId Session ID
     */
    private function updateLastUsed($sessionId) {
        $stmt = $this->connection->prepare("
            UPDATE api_session_state
            SET last_used_at = NOW()
            WHERE session_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    /**
     * Close session
     * @param string $sessionId Session ID (optional, uses active if not provided)
     * @return bool Success status
     */
    public function closeSession($sessionId = null) {
        if (!$sessionId) {
            $sessionId = $this->getActiveSessionId();
        }
        
        if (!$sessionId) {
            return false;
        }
        
        try {
            $this->apiClient->setRequestContext($this->buildContext([
                'operation' => 'close_session',
                'target_session_id' => $sessionId
            ]));
            // Close via API
            $this->apiClient->setSessionId($sessionId);
            $this->apiClient->closeSession();
            
            // Mark as inactive in database
            $this->deactivateSession($sessionId);
            
            return true;
        } catch (Exception $e) {
            // Even if API close fails, mark as inactive
            $this->deactivateSession($sessionId);
            return false;
        }
    }
    
    /**
     * Deactivate session in database
     * @param string $sessionId Session ID
     */
    private function deactivateSession($sessionId) {
        $stmt = $this->connection->prepare("
            UPDATE api_session_state
            SET is_active = 0
            WHERE session_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    /**
     * Deactivate all sessions
     */
    private function deactivateAllSessions() {
        $stmt = $this->connection->prepare("
            UPDATE api_session_state
            SET is_active = 0
            WHERE is_active = 1
        ");
        
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Refresh session by creating a new one immediately.
     * @return string New session ID
     * @throws Exception
     */
    public function refreshSession() {
        $this->deactivateAllSessions();
        return $this->createSession();
    }

    /**
     * Execute an operation that requires an active session, refreshing on expiration.
     * @param callable $operation
     * @return mixed
     * @throws Exception
     */
    public function withActiveSession(callable $operation) {
        try {
            return $operation();
        } catch (SessionExpiredException $e) {
            $newSession = $this->refreshSession();
            $this->apiClient->setSessionId($newSession);
            return $operation();
        }
    }

    /**
     * Set request context and execute operation with automatic session refresh.
     * @param array $context
     * @param callable $operation receives ApiClient as first argument
     * @return mixed
     * @throws Exception
     */
    public function executeWithContext(array $context, callable $operation)
    {
        return $this->withActiveSession(function () use ($context, $operation) {
            $this->apiClient->setRequestContext($this->buildContext($context));
            return $operation($this->apiClient);
        });
    }
    
    /**
     * Cleanup expired sessions
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions() {
        $stmt = $this->connection->prepare("
            UPDATE api_session_state
            SET is_active = 0
            WHERE is_active = 1
            AND (expires_at IS NOT NULL AND expires_at < NOW())
        ");
        
        $stmt->execute();
        $affected = $this->connection->affected_rows;
        $stmt->close();
        
        return $affected;
    }

    /**
     * Build logging context for API calls
     * @param array $context
     * @return array
     */
    private function buildContext(array $context = []): array
    {
        if (empty($this->defaultContext)) {
            return $context;
        }

        return array_merge($this->defaultContext, $context);
    }
}

?>

