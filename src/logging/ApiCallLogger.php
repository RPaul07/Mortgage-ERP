<?php
/**
 * ApiCallLogger
 *
 * Persists API call metadata for auditing and reporting.
 * Stores sanitized request payloads, execution time, and outcome.
 */

class ApiCallLogger
{
    /** @var mysqli */
    private $connection;

    /** @var array */
    private $defaultContext;

    /**
     * @param DatabaseManager|mysqli $connectionSource
     * @param array $defaultContext
     */
    public function __construct($connectionSource, array $defaultContext = [])
    {
        if ($connectionSource instanceof DatabaseManager) {
            $this->connection = $connectionSource->getConnection();
        } elseif ($connectionSource instanceof mysqli) {
            $this->connection = $connectionSource;
        } else {
            throw new InvalidArgumentException('ApiCallLogger requires DatabaseManager or mysqli connection.');
        }

        $this->defaultContext = $defaultContext;
    }

    /**
     * Persist an API call entry.
     *
     * @param array $data
     */
    public function logApiCall(array $data): ?int
    {
        if (!$this->connection instanceof mysqli) {
            return null;
        }

        $cronJob = $this->extractContextValue($data, 'cron_job');
        $endpoint = $data['endpoint'] ?? 'UNKNOWN';
        $httpMethod = $data['method'] ?? 'POST';
        $sessionId = $data['session_id'] ?? null;
        $httpStatus = $data['http_status'] ?? null;
        $success = isset($data['success']) && $data['success'] ? 1 : 0;
        $executionTime = isset($data['execution_time_seconds']) ? (float)$data['execution_time_seconds'] : 0.0;
        $errorMessage = $this->truncate($data['error_message'] ?? null);
        $requestPayload = $this->truncate($this->sanitizePayload($data['request_payload'] ?? null));
        $responseSummary = $this->truncate($this->encodeSummary($data['response_summary'] ?? null));

        $mergedContext = $this->mergeContext($data['context'] ?? []);
        $contextJson = $this->truncate($this->encodeSummary($mergedContext));

        $stmt = $this->connection->prepare("
            INSERT INTO api_call_logs (
                cron_job,
                endpoint,
                http_method,
                session_id,
                http_status,
                success,
                execution_time_seconds,
                error_message,
                request_payload,
                response_summary,
                context_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log('ApiCallLogger prepare failed: ' . $this->connection->error);
            return null;
        }

        $stmt->bind_param(
            'ssssiidssss',
            $cronJob,
            $endpoint,
            $httpMethod,
            $sessionId,
            $httpStatus,
            $success,
            $executionTime,
            $errorMessage,
            $requestPayload,
            $responseSummary,
            $contextJson
        );

        $insertId = null;
        if (!$stmt->execute()) {
            error_log('ApiCallLogger execute failed: ' . $stmt->error);
        } else {
            $insertId = $stmt->insert_id ?: null;
        }

        $stmt->close();

        return $insertId;
    }

    /**
     * Merge default and per-request context.
     *
     * @param array $additional
     * @return array
     */
    private function mergeContext(array $additional): array
    {
        if (empty($this->defaultContext)) {
            return $additional;
        }

        return array_merge($this->defaultContext, $additional);
    }

    /**
     * Retrieve a context value while respecting defaults.
     *
     * @param array $data
     * @param string $key
     * @return ?string
     */
    private function extractContextValue(array $data, string $key): ?string
    {
        $context = $this->mergeContext($data['context'] ?? []);
        return isset($context[$key]) ? (string)$context[$key] : null;
    }

    /**
     * Sanitize payload by redacting sensitive fields.
     *
     * @param mixed $payload
     * @return string|null
     */
    private function sanitizePayload($payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            parse_str($payload, $parsed);
            if (!empty($parsed)) {
                $payload = $parsed;
            }
        }

        if (is_array($payload)) {
            $sensitiveKeys = ['password', 'pwd', 'token', 'api_key', 'secret'];
            foreach ($payload as $key => &$value) {
                if (in_array(strtolower((string)$key), $sensitiveKeys, true)) {
                    $value = '***REDACTED***';
                }
            }
            unset($value);
            return $this->encodeSummary($payload);
        }

        return (string)$payload;
    }

    /**
     * JSON encode with safe fallback.
     *
     * @param mixed $data
     * @return string|null
     */
    private function encodeSummary($data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (is_scalar($data)) {
            return (string)$data;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '[unserializable]';
        }

        return $encoded;
    }

    /**
     * Truncate a string to a safe length.
     *
     * @param string|null $value
     * @param int $maxLength
     * @return string|null
     */
    private function truncate(?string $value, int $maxLength = 5000): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
?>

