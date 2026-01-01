<?php
/**
 * ApiClient class for handling all API communication
 * Manages session creation, file queries, downloads, and session cleanup
 */

require_once __DIR__ . '/session/SessionExpiredException.php';

class ApiClient {
    private $baseUrl;
    private $username;
    private $password;
    private $sessionId;
    private $timeout;
    private $userAgent;
    private $logger;
    private $requestContext;
    
    /**
     * Constructor
     * @param string $baseUrl API base URL
     * @param string $username API username
     * @param string $password API password
     * @param int $timeout Request timeout in seconds
     * @param string $userAgent User agent string
     */
    public function __construct($baseUrl, $username, $password, $timeout = 30, $userAgent = 'FileDownloader/1.0', $logger = null) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
        $this->sessionId = null;
        $this->logger = $logger;
        $this->requestContext = [];
    }
    
    /**
     * Clear any existing session (force close)
     * @return array Response data
     * @throws Exception if clear fails
     */
    public function clearSession() {
        $data = "username={$this->username}&password={$this->password}";
        $response = $this->makeApiCall('/api/clear_session', $data, 'Clearing existing session');
        
        // Clear our local session ID regardless of API response
        $this->sessionId = null;
        
        return $response;
    }
    
    /**
     * Create a new API session (clears any existing session first)
     * @return array Response data with result, execution time, and raw result
     * @throws Exception if session creation fails
     */
    public function createSession() {
        // First, try to clear any existing session
        try {
            $this->clearSession();
        } catch (Exception $e) {
            // Ignore clear errors - session might not exist
            // Just continue with creating new session
        }
        
        // Now create a new session
        $data = "username={$this->username}&password={$this->password}";
        $response = $this->makeApiCall('/api/create_session', $data, 'Creating session');
        
        if ($response['result'] && $response['result'][0] == "Status: OK") {
            $this->sessionId = $response['result'][2];
            return $response;
        }
        
        throw new Exception("Failed to create session: " . ($response['result'][0] ?? 'Unknown error'));
    }
    
    /**
     * Query available files
     * @return array Response data with file list
     * @throws Exception if session is not established or query fails
     */
    public function queryFiles() {
        if (!$this->sessionId) {
            throw new Exception("No active session. Call createSession() first.");
        }
        
        $data = "uid={$this->username}&sid={$this->sessionId}";
        $response = $this->makeApiCall('/api/query_files', $data, 'Querying files');

        if (!isset($response['result']) || !is_array($response['result'])) {
            throw new Exception('Invalid API response structure for query_files.');
        }

        if (!isset($response['result'][1])) {
            throw new Exception('API response missing expected field at index 1.');
        }

        $parts = explode(':', $response['result'][1], 2);
        if (!isset($parts[1])) {
            throw new Exception('Failed to split API response data for query_files.');
        }

        $files = json_decode($parts[1], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($files)) {
            throw new Exception('Failed to decode file list from query_files response: ' . json_last_error_msg());
        }

        $response['files'] = $files;
        return $response;
    }

    /**
     * Request list of all loan IDs on the system
     * @return array Response data with loan ID list
     * @throws Exception if session is not established or request fails
     */
    public function requestAllLoans() {
        if (!$this->sessionId) {
            throw new Exception("No active session. Call createSession() first.");
        }
        
        $data = "sid={$this->sessionId}&uid={$this->username}";
        $response = $this->makeApiCall('/api/request_all_loans', $data, 'Requesting all loan IDs');

        if (!isset($response['result']) || !is_array($response['result'])) {
            throw new Exception('Invalid API response structure for request_all_loans.');
        }

        if (!isset($response['result'][1])) {
            throw new Exception('API response missing expected field at index 1 for request_all_loans.');
        }

        $parts = explode(':', $response['result'][1], 2);
        if (!isset($parts[1])) {
            throw new Exception('Failed to split API response data for request_all_loans.');
        }

        $loans = json_decode($parts[1], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($loans)) {
            throw new Exception('Failed to decode loan ID list from request_all_loans response: ' . json_last_error_msg());
        }

        $response['loans'] = $loans;
        return $response;
    }
    
    /**
     * Request all documents from the API
     * @return array Response data with document list
     * @throws Exception if session is not established or request fails
     */
    public function requestAllDocuments() {
        if (!$this->sessionId) {
            throw new Exception("No active session. Call createSession() first.");
        }
        
        $data = "sid={$this->sessionId}&uid={$this->username}";
        $response = $this->makeApiCall('/api/request_all_documents', $data, 'Requesting all documents');
        
        if (!isset($response['result'])) {
            throw new Exception('API response missing result for request_all_documents.');
        }
        
        // API returns: ["Status: OK", "MSG: [\"doc1\", \"doc2\", ...]", "Action: Done"]
        // The document names are in the second element (index 1) as a JSON string
        if (!is_array($response['result']) || count($response['result']) < 2) {
            throw new Exception('API response format unexpected. Expected array with at least 2 elements. Got: ' . substr(json_encode($response['result']), 0, 200));
        }
        
        // Check status
        if ($response['result'][0] !== 'Status: OK') {
            throw new Exception('API returned error status: ' . $response['result'][0]);
        }
        
        // Extract document names from MSG field
        $msgField = $response['result'][1];
        if (!is_string($msgField) || strpos($msgField, 'MSG: ') !== 0) {
            throw new Exception('API response MSG field format unexpected: ' . substr($msgField, 0, 200));
        }
        
        // Remove "MSG: " prefix
        $jsonString = substr($msgField, 5);
        
        // Decode the JSON array of document names
        $documents = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode document names JSON: ' . json_last_error_msg() . '. JSON string: ' . substr($jsonString, 0, 200));
        }
        
        if (!is_array($documents)) {
            throw new Exception('Decoded document names is not an array. Type: ' . gettype($documents));
        }
        
        $response['documents'] = $documents;
        return $response;
    }
    
    /**
     * Request/download a specific file
     * @param string $fileId The file ID to download
     * @return array Response data with file content
     * @throws Exception if session is not established or download fails
     */
    public function requestFile($fileId) {
        if (!$this->sessionId) {
            throw new Exception("No active session. Call createSession() first.");
        }
        
        $data = "sid={$this->sessionId}&uid={$this->username}&fid={$fileId}";
        return $this->makeApiCall('/api/request_file', $data, "Downloading file: $fileId");
    }
    
    /**
     * Close the current session
     * @return array Response data
     * @throws Exception if session is not established or close fails
     */
    public function closeSession() {
        if (!$this->sessionId) {
            throw new Exception("No active session to close.");
        }
        
        $data = "sid={$this->sessionId}";
        $response = $this->makeApiCall('/api/close_session', $data, 'Closing session');
        
        // Clear session ID after successful close
        $this->sessionId = null;
        
        return $response;
    }
    
    /**
     * Get the current session ID
     * @return string|null Current session ID or null if no session
     */
    public function getSessionId() {
        return $this->sessionId;
    }
    
    /**
     * Check if there's an active session
     * @return bool True if session is active
     */
    public function hasActiveSession() {
        return $this->sessionId !== null;
    }
    
    /**
     * Set session ID (for use by SessionManager)
     * @param string $sessionId Session ID to set
     */
    public function setSessionId($sessionId) {
        $this->sessionId = $sessionId;
    }

    /**
     * Attach an API call logger instance
     * @param ApiCallLogger|null $logger
     */
    public function setLogger($logger) {
        $this->logger = $logger;
    }

    /**
     * Set contextual data for the next API call (replaces current context)
     * @param array $context
     */
    public function setRequestContext(array $context) {
        $this->requestContext = $context;
    }

    /**
     * Merge additional context into the current context for the next API call
     * @param array $context
     */
    public function mergeRequestContext(array $context) {
        if (empty($this->requestContext)) {
            $this->requestContext = $context;
            return;
        }
        $this->requestContext = array_merge($this->requestContext, $context);
    }
    
    /**
     * Make a generic API call with retry logic for connection failures
     * @param string $endpoint API endpoint
     * @param string $data POST data
     * @param string $description Description for logging
     * @return array Response data with result, execution time, and raw result
     * @throws Exception if API call fails
     */
    private function makeApiCall($endpoint, $data, $description = '') {
        $url = $this->baseUrl . $endpoint;
        
        // Retry configuration for connection failures
        $maxRetries = 3;
        $retryCount = 0;
        $lastException = null;
        
        while ($retryCount <= $maxRetries) {
            $executionStart = microtime(true);
            $success = false;
            $httpCode = null;
            $errorMessage = null;
            $responseSummary = null;
            $responseData = null;
            $logId = null;
            
            // Exponential backoff delay before retry (except first attempt)
            if ($retryCount > 0) {
                $delaySeconds = pow(2, $retryCount - 1); // 1s, 2s, 4s
                if ($this->logger) {
                    error_log("[" . date('Y-m-d H:i:s') . "] Retrying API call to $endpoint (attempt " . ($retryCount + 1) . "/" . ($maxRetries + 1) . ") after {$delaySeconds} second(s)...");
                }
                sleep($delaySeconds);
            }
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout); // Total request timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 90); // Connection timeout (90 seconds for initial connection - increased from 60)
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'content-type: application/x-www-form-urlencoded',
                'content-length: ' . strlen($data)
            ));
            
            // SSL verification bypass for expired certificates
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $start_time = microtime(true);
            $result = curl_exec($ch);
            $end_time = microtime(true);
            $durationSeconds = $end_time - $start_time;
            $exec_time = $durationSeconds / 60;
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            try {
                if ($result === false) {
                    // Check if it's a connection timeout error that we should retry
                    $isConnectionError = stripos($error, 'Failed to connect') !== false || 
                                       stripos($error, 'Connection timeout') !== false ||
                                       stripos($error, 'timeout') !== false;
                    
                    if ($isConnectionError && $retryCount < $maxRetries) {
                        $retryCount++;
                        $lastException = new Exception("cURL error: $error");
                        continue; // Retry the request
                    }
                    throw new Exception("cURL error: $error");
                }
                
                if ($httpCode !== 200) {
                    // Don't retry on HTTP errors (4xx, 5xx) except 504 Gateway Timeout
                    if ($httpCode == 504 && $retryCount < $maxRetries) {
                        $retryCount++;
                        $lastException = new Exception("HTTP error: $httpCode");
                        continue; // Retry the request
                    }
                    throw new Exception("HTTP error: $httpCode");
                }
                
                // Check if this is a file download request
                if (strpos($endpoint, 'request_file') !== false) {
                    // Check if response is actually a JSON error (API can return JSON errors with HTTP 200)
                    $firstChar = substr($result, 0, 1);
                    if ($firstChar === '{' || $firstChar === '[') {
                        // Response looks like JSON - try to decode it
                        $decodedJson = json_decode($result, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // It's a valid JSON response - check if it's a session error
                            if ($this->isSessionError($decodedJson)) {
                                throw new SessionExpiredException("API session expired or invalid.");
                            }
                            
                            // It's a JSON error but not session-related - extract error message
                            $errorMsg = is_array($decodedJson) 
                                ? ($decodedJson['error'] ?? $decodedJson['message'] ?? json_encode($decodedJson))
                                : $result;
                            
                            // Check if it's an array with error messages
                            if (is_array($decodedJson)) {
                                $errorStr = json_encode($decodedJson);
                                if (stripos($errorStr, 'SID not found') !== false || 
                                    stripos($errorStr, 'session') !== false ||
                                    stripos($errorStr, 'Status: ERROR') !== false) {
                                    throw new SessionExpiredException("API returned session error: " . substr($errorStr, 0, 200));
                                }
                                $errorMsg = $errorStr;
                            }
                            
                            throw new Exception("API returned JSON error instead of file: " . substr($errorMsg, 0, 200));
                        }
                    }
                    
                    // Response is not JSON (or invalid JSON) - treat as file content
                    $responseSummary = [
                        'type' => 'file_download',
                        'bytes' => is_string($result) ? strlen($result) : 0,
                        'description' => $description
                    ];
                    
                    $success = true;
                    $responseData = [
                        'result' => null,
                        'exec_time' => $exec_time,
                        'raw_result' => $result,
                        'http_code' => $httpCode,
                        'description' => $description
                    ];
                } else {
                    // For API responses, decode JSON
                    $decodedResult = json_decode($result, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("JSON decode error: " . json_last_error_msg());
                    }

                    if ($this->isSessionError($decodedResult)) {
                        throw new SessionExpiredException("API session expired or invalid.");
                    }

                    $responseSummary = $decodedResult;
                    $success = true;
                    
                    $responseData = [
                        'result' => $decodedResult,
                        'exec_time' => $exec_time,
                        'raw_result' => $result,
                        'http_code' => $httpCode,
                        'description' => $description
                    ];
                }
                
                // Success - break out of retry loop
                break;
                
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $lastException = $e;
                
                // If this is the last retry, log and throw
                if ($retryCount >= $maxRetries) {
                    // Log the final failed attempt
                    $this->logApiCall([
                        'endpoint' => $endpoint,
                        'method' => 'POST',
                        'request_payload' => $data,
                        'http_status' => $httpCode,
                        'success' => false,
                        'error_message' => $errorMessage,
                        'execution_time_seconds' => $durationSeconds,
                        'response_summary' => $responseSummary,
                        'context' => $this->requestContext,
                        'session_id' => $this->sessionId
                    ]);
                    
                    // Clear context after logging
                    $this->requestContext = [];
                    throw $e;
                }
                
                // Continue to retry
                $retryCount++;
            }
        }
        
        // Log successful call (only on final attempt)
        $logId = $this->logApiCall([
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_payload' => $data,
            'http_status' => $httpCode,
            'success' => $success,
            'error_message' => $errorMessage,
            'execution_time_seconds' => $durationSeconds,
            'response_summary' => $responseSummary,
            'context' => $this->requestContext,
            'session_id' => $this->sessionId
        ]);
        
        // Clear context after logging
        $this->requestContext = [];

        if (is_array($responseData) && $logId !== null) {
            $responseData['log_id'] = $logId;
        }

        return $responseData;
    }

    /**
     * Log an API call when a logger is available
     * @param array $data
     */
    private function logApiCall(array $data)
    {
        if ($this->logger && method_exists($this->logger, 'logApiCall')) {
            return $this->logger->logApiCall($data);
        }

        return null;
    }

    /**
     * Identify if the API response indicates a session error.
     *
     * @param mixed $decodedResult
     * @return bool
     */
    private function isSessionError($decodedResult)
    {
        if (!$decodedResult) {
            return false;
        }

        $asString = is_array($decodedResult)
            ? strtoupper(is_array($decodedResult[0] ?? null)
                ? json_encode($decodedResult)
                : implode(' ', array_map('strval', $decodedResult)))
            : strtoupper((string) $decodedResult);

        $mentionsSession = strpos($asString, 'SESSION') !== false || strpos($asString, 'SID') !== false;
        $mentionsInvalidState = strpos($asString, 'EXPIRED') !== false ||
            strpos($asString, 'NOT FOUND') !== false ||
            strpos($asString, 'INVALID') !== false;

        return $mentionsSession && $mentionsInvalidState;
    }
}
?>
