<?php
/**
 * Parse file_download.log and import API call data into api_call_logs table
 * Extracts API call information from HTML-formatted log file
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../DatabaseManager.php';

$config = require __DIR__ . '/../config.php';
$logFile = '/var/log/file_download.log';

// Initialize database connection
try {
    $dbConfig = $config['database'];
    $dbManager = new DatabaseManager(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['database'],
        $config['database']['charset'] ?? 'utf8mb4',
        $config['database']['port'] ?? 3306
    );
    $connection = $dbManager->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// API credentials for reconstructing request payloads
$apiUsername = $config['api']['username'];

/**
 * Parse timestamp from log entry
 */
function parseTimestamp($line) {
    // Try format: "Fri Oct 31 19:00:01 CDT 2025"
    if (preg_match('/(\w{3})\s+(\w{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})\s+\w+\s+(\d{4})/', $line, $matches)) {
        $monthMap = [
            'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
            'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
            'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'
        ];
        $month = $monthMap[$matches[2]] ?? '01';
        $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
        $year = $matches[7];
        $time = $matches[4] . ':' . $matches[5] . ':' . $matches[6];
        return "$year-$month-$day $time";
    }
    
    // Try format: "2025-10-31 19:00:01"
    if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Extract execution time in seconds from log
 */
function extractExecutionTime($content) {
    if (preg_match('/Execution Time:\s*([\d.]+)\s*minutes/i', $content, $matches)) {
        $minutes = (float)$matches[1];
        return round($minutes * 60, 4); // Convert to seconds
    }
    return 0.0;
}

/**
 * Extract API response array from log
 */
function extractApiResponse($content) {
    $response = [];
    
    // Look for Array format: [0] => Status: OK, [1] => MSG: ..., [2] => ...
    // Handle multiline content within the <pre> tag
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (preg_match('/\[\s*0\s*\]\s*=>\s*(.+)$/', $line, $matches)) {
            $response[0] = trim($matches[1]);
        } elseif (preg_match('/\[\s*1\s*\]\s*=>\s*(.+)$/', $line, $matches)) {
            $response[1] = trim($matches[1]);
        } elseif (preg_match('/\[\s*2\s*\]\s*=>\s*(.+)$/', $line, $matches)) {
            $response[2] = trim($matches[1]);
        }
    }
    
    return $response;
}

/**
 * Reconstruct request payload based on endpoint and session ID
 */
function reconstructRequestPayload($endpoint, $sessionId, $apiUsername) {
    switch ($endpoint) {
        case '/api/create_session':
            return json_encode([
                'username' => $apiUsername,
                'password' => '***REDACTED***'
            ]);
        
        case '/api/query_files':
            if ($sessionId) {
                return json_encode([
                    'uid' => $apiUsername,
                    'sid' => $sessionId
                ]);
            }
            return null;
        
        case '/api/close_session':
            if ($sessionId) {
                return json_encode([
                    'sid' => $sessionId
                ]);
            }
            return null;
        
        default:
            return null;
    }
}

/**
 * Infer HTTP status from API status
 */
function inferHttpStatus($apiStatus) {
    if (stripos($apiStatus, 'OK') !== false) {
        return 200;
    } elseif (stripos($apiStatus, 'ERROR') !== false) {
        return 500;
    }
    return null;
}

/**
 * Extract error message from response
 */
function extractErrorMessage($response) {
    if (isset($response[0]) && stripos($response[0], 'ERROR') !== false) {
        $errorMsg = '';
        if (isset($response[1])) {
            $errorMsg = $response[1];
        }
        if (isset($response[2]) && !empty($errorMsg)) {
            $errorMsg .= ' - ' . $response[2];
        }
        return !empty($errorMsg) ? $errorMsg : $response[0];
    }
    return null;
}

// Main parsing logic
echo "Starting log file parsing...\n";
echo "Log file: $logFile\n\n";

if (!file_exists($logFile)) {
    die("Error: Log file not found: $logFile\n");
}

$fileContent = file_get_contents($logFile);
if ($fileContent === false) {
    die("Error: Could not read log file\n");
}

// Check if we should skip existing entries (optional - comment out to allow re-import)
echo "Note: This script will insert all API calls from the log file.\n";
echo "If you want to prevent duplicates, ensure api_call_logs table is empty for the date range.\n\n";

// Split log into entries (separated by "----------------------------------------")
$entries = explode('----------------------------------------', $fileContent);

$totalEntries = count($entries);
$processedCount = 0;
$insertedCount = 0;
$skippedCount = 0;
$currentSessionId = null;

echo "Found $totalEntries log entries\n\n";

// Prepare insert statement
$stmt = $connection->prepare("
    INSERT INTO api_call_logs (
        created_at,
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
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    die("Error preparing statement: " . $connection->error . "\n");
}

foreach ($entries as $index => $entry) {
    if (empty(trim($entry))) {
        continue;
    }
    
    $processedCount++;
    
    // Extract timestamp
    $timestamp = null;
    $lines = explode("\n", $entry);
    foreach ($lines as $line) {
        $ts = parseTimestamp($line);
        if ($ts) {
            $timestamp = $ts;
            break;
        }
    }
    
    if (!$timestamp) {
        $skippedCount++;
        continue;
    }
    
    // Process each API call type in this entry
    $apiCalls = [];
    
    // Step 1: Creating API Session
    if (preg_match('/Step 1: Creating API Session.*?<pre>(.*?)<\/pre>.*?Execution Time:\s*([\d.]+)\s*minutes/is', $entry, $matches)) {
        $responseContent = $matches[1];
        $execTime = (float)$matches[2] * 60;
        $response = extractApiResponse($responseContent);
        
        // Extract session ID from response
        if (isset($response[2])) {
            $currentSessionId = trim($response[2]);
        }
        
        $apiStatus = $response[0] ?? 'UNKNOWN';
        $success = (stripos($apiStatus, 'OK') !== false) ? 1 : 0;
        
        $apiCalls[] = [
            'endpoint' => '/api/create_session',
            'execution_time' => round($execTime, 4),
            'response' => $response,
            'success' => $success,
            'session_id' => $currentSessionId
        ];
    }
    
    // Step 2: Querying Available Files
    if (preg_match('/Step 2: Querying Available Files.*?<pre>(.*?)<\/pre>.*?Execution Time:\s*([\d.]+)\s*minutes/is', $entry, $matches)) {
        $responseContent = $matches[1];
        $execTime = (float)$matches[2] * 60;
        $response = extractApiResponse($responseContent);
        
        $apiStatus = $response[0] ?? 'UNKNOWN';
        $success = (stripos($apiStatus, 'OK') !== false) ? 1 : 0;
        
        $apiCalls[] = [
            'endpoint' => '/api/query_files',
            'execution_time' => round($execTime, 4),
            'response' => $response,
            'success' => $success,
            'session_id' => $currentSessionId
        ];
    }
    
    // Step 4: Closing API Session
    if (preg_match('/Step 4: Closing API Session.*?<pre>(.*?)<\/pre>.*?Execution Time:\s*([\d.]+)\s*minutes/is', $entry, $matches)) {
        $responseContent = $matches[1];
        $execTime = (float)$matches[2] * 60;
        $response = extractApiResponse($responseContent);
        
        $apiStatus = $response[0] ?? 'UNKNOWN';
        $success = (stripos($apiStatus, 'OK') !== false) ? 1 : 0;
        
        $apiCalls[] = [
            'endpoint' => '/api/close_session',
            'execution_time' => round($execTime, 4),
            'response' => $response,
            'success' => $success,
            'session_id' => $currentSessionId
        ];
    }
    
    // Insert each API call into database
    foreach ($apiCalls as $call) {
        $apiStatus = $call['response'][0] ?? 'UNKNOWN';
        $httpStatus = inferHttpStatus($apiStatus);
        $errorMessage = extractErrorMessage($call['response']);
        $requestPayload = reconstructRequestPayload($call['endpoint'], $call['session_id'], $apiUsername);
        $responseSummary = json_encode($call['response']);
        
        $contextJson = json_encode([
            'operation' => str_replace('/api/', '', $call['endpoint']),
            'source' => 'file_download_log',
            'log_entry_index' => $index
        ]);
        
        // Store all values in variables for bind_param (cannot pass literals by reference)
        $cronJob = 'file_download';
        $httpMethod = 'POST';
        $sessionId = $call['session_id'];
        $endpoint = $call['endpoint'];
        $success = $call['success'];
        $execTime = $call['execution_time'];
        
        $stmt->bind_param(
            'sssssiidssss',
            $timestamp,
            $cronJob,
            $endpoint,
            $httpMethod,
            $sessionId,
            $httpStatus,
            $success,
            $execTime,
            $errorMessage,
            $requestPayload,
            $responseSummary,
            $contextJson
        );
        
        if ($stmt->execute()) {
            $insertedCount++;
        } else {
            echo "Warning: Failed to insert log entry: " . $stmt->error . "\n";
        }
    }
    
    // Progress indicator
    if ($processedCount % 50 == 0) {
        echo "Processed $processedCount entries, inserted $insertedCount API calls...\n";
    }
}

$stmt->close();

echo "\n";
echo "=== Parsing Complete ===\n";
echo "Total log entries processed: $processedCount\n";
echo "API calls inserted: $insertedCount\n";
echo "Entries skipped: $skippedCount\n";
echo "\n";

