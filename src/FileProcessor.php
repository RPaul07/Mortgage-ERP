<?php
/**
 * FileProcessor class for utility functions
 * Simplified version - handles display formatting and validation for database storage
 */

class FileProcessor {
    
    /**
     * Display API response result in formatted HTML
     * @param array $info Response data
     * @param float $execTime Execution time in minutes
     * @param string $description Description of the operation
     * @param bool $showRawResponse Whether to show raw response
     */
    public static function displayResult($info, $execTime, $description = '', $showRawResponse = false) {
        echo '<div class="api-response">';
        
        if ($description) {
            echo '<h2>' . htmlspecialchars($description) . '</h2>';
        }
        
        echo '<pre>';
        print_r($info);
        echo '</pre>';
        
        echo '<h3>Execution Time: ' . number_format($execTime, 4) . ' minutes</h3>';
        
        if ($showRawResponse && isset($info['raw_result'])) {
            echo '<h3>Raw Response:</h3>';
            echo '<pre>' . htmlspecialchars($info['raw_result']) . '</pre>';
        }
        
        echo '</div>';
        echo '<hr>';
    }
    
    /**
     * Parse filename to extract metadata
     * Assumes format: <loan_number>-<document_type>-<date_time>.pdf
     * @param string $filename The filename to parse
     * @return array Array with parsed metadata components
     */
    public static function parseFilename($filename) {
        // Remove file extension if present
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Split by delimiter '-'
        $parts = explode('-', $nameWithoutExt);
        
        // Expected format: loan_number-document_type-date_time
        $loanNumber = $parts[0] ?? '';
        $documentType = $parts[1] ?? '';
        
        // Date_time might be more than one part (e.g., "20250131143022" or "2025-01-31_14-30-22")
        $dateTimeParts = [];
        if (count($parts) > 2) {
            for ($i = 2; $i < count($parts); $i++) {
                $dateTimeParts[] = $parts[$i];
            }
        }
        $dateTime = implode('-', $dateTimeParts);
        
        return [
            'loan_number' => $loanNumber,
            'document_type' => $documentType,
            'date_time' => $dateTime,
            'original' => $filename,
            'file_size' => 0 // Will be set by caller
        ];
    }
    
    /**
     * Normalize document type name
     * Maps various document type formats to standard names
     * Handles numeric suffixes and variations
     * @param string $type Raw document type from filename
     * @return string Normalized document type name
     */
    public static function normalizeDocumentType($type) {
        if (empty($type)) {
            return 'UNKNOWN';
        }
        
        // Convert to uppercase and handle common variations
        $normalized = strtoupper(trim($type));
        
        // Remove numeric suffixes (e.g., INTERNAL_2 => INTERNAL, TITLE_3 => TITLE, MOU_6 => MOU)
        // Pattern: _ followed by digits at the end
        $normalized = preg_replace('/_\d+$/', '', $normalized);
        
        // Handle common document type mappings
        $typeMappings = [
            'APPLICATION' => 'APPLICATION',
            'INCOME' => 'INCOME',
            'W2' => 'W2',
            'BANK' => 'BANK',
            'STATEMENT' => 'BANK_STATEMENT',
            'TAX' => 'TAX',
            'RETURN' => 'TAX_RETURN',
            'ID' => 'IDENTIFICATION',
            'DRIVER' => 'DRIVERS_LICENSE',
            'PASSPORT' => 'PASSPORT',
            'CONTRACT' => 'CONTRACT',
            'AGREEMENT' => 'AGREEMENT',
            'APPRAISAL' => 'APPRAISAL',
            'TITLE' => 'TITLE',
            'INSURANCE' => 'INSURANCE',
            'INTERNAL' => 'INTERNAL',
            'CLOSING' => 'CLOSING',
            'CREDIT' => 'CREDIT',
            'MOU' => 'MOU',
            'FINANCIAL' => 'FINANCIAL',
        ];
        
        // Check for exact matches first
        if (isset($typeMappings[$normalized])) {
            return $typeMappings[$normalized];
        }
        
        // Check for partial matches as fallback
        foreach ($typeMappings as $key => $value) {
            if (strpos($normalized, $key) !== false) {
                return $value;
            }
        }
        
        // Return as-is if no mapping found
        return $normalized;
    }
    
    /**
     * Validate file content (checks if content is empty)
     * @param string $content File content
     * @return array Validation result with 'valid' boolean and 'message' string
     */
    public static function validateFileContent($content) {
        $result = ['valid' => true, 'message' => ''];
        
        if (empty($content)) {
            $result['valid'] = false;
            $result['message'] = 'File content is empty';
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Check MIME type from file content (not filename)
     * @param string $content File content
     * @return array Result with 'mime_type' string, 'is_pdf' boolean, and 'message' string
     */
    public static function checkMimeType($content) {
        $result = [
            'mime_type' => null,
            'is_pdf' => false,
            'message' => ''
        ];
        
        if (empty($content)) {
            $result['message'] = 'File content is empty';
            return $result;
        }
        
        // Use finfo to detect MIME type from content (not filename)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $result['mime_type'] = finfo_buffer($finfo, $content);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            // Fallback: write to temp file to check (less ideal but still checks content)
            $tempFile = tmpfile();
            fwrite($tempFile, $content);
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            $result['mime_type'] = mime_content_type($tempPath);
            fclose($tempFile);
        } else {
            // Fallback: check PDF magic bytes
            if (substr($content, 0, 5) === '%PDF-') {
                $result['mime_type'] = 'application/pdf';
            } else {
                $result['mime_type'] = 'unknown';
            }
        }
        
        // Check if it's a PDF based on MIME type
        $result['is_pdf'] = ($result['mime_type'] === 'application/pdf');
        
        // Additional verification: check for PDF magic bytes to ensure accuracy
        if ($result['is_pdf'] && substr($content, 0, 5) !== '%PDF-') {
            // MIME type says PDF but magic bytes don't match - reject it
            $result['is_pdf'] = false;
            $result['message'] = 'MIME type indicates PDF but content does not match PDF signature';
        } elseif (!$result['is_pdf'] && substr($content, 0, 5) === '%PDF-') {
            // Content looks like PDF (magic bytes match) but MIME type says otherwise
            // Trust the magic bytes - it's likely a PDF
            $result['is_pdf'] = true;
            $result['mime_type'] = 'application/pdf';
            $result['message'] = 'Content verified as PDF by signature';
        }
        
        if (!$result['is_pdf']) {
            $result['message'] = 'File is not a PDF. MIME type: ' . ($result['mime_type'] ?? 'unknown');
        }
        
        return $result;
    }
    
    /**
     * Strict MIME type check - requires BOTH magic bytes AND MIME type to match
     * Does NOT override MIME check if magic bytes match
     * @param string $content File content
     * @return array Result with 'mime_type', 'is_pdf', 'message', 'magic_bytes_match', 'mime_matches'
     */
    public static function checkMimeTypeStrict($content) {
        $result = [
            'mime_type' => null,
            'is_pdf' => false,
            'message' => '',
            'magic_bytes_match' => false,
            'mime_matches' => false
        ];
        
        if (empty($content)) {
            $result['message'] = 'File content is empty';
            return $result;
        }
        
        // Check magic bytes first
        $result['magic_bytes_match'] = (substr($content, 0, 5) === '%PDF-');
        
        // Get MIME type using finfo (preferred method)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $result['mime_type'] = finfo_buffer($finfo, $content);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $tempFile = tmpfile();
            fwrite($tempFile, $content);
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            $result['mime_type'] = mime_content_type($tempPath);
            fclose($tempFile);
        } else {
            $result['mime_type'] = 'unknown';
        }
        
        // Check if MIME type matches PDF
        $result['mime_matches'] = ($result['mime_type'] === 'application/pdf');
        
        // STRICT: BOTH must match
        $result['is_pdf'] = ($result['magic_bytes_match'] && $result['mime_matches']);
        
        // Generate detailed message
        if ($result['is_pdf']) {
            $result['message'] = 'Valid PDF: Both magic bytes and MIME type confirmed';
        } elseif ($result['magic_bytes_match'] && !$result['mime_matches']) {
            $result['message'] = "MIME type mismatch: Has PDF signature but MIME type is '{$result['mime_type']}'";
        } elseif (!$result['magic_bytes_match'] && $result['mime_matches']) {
            $result['message'] = "Magic bytes mismatch: MIME type says PDF but content doesn't start with %PDF";
        } else {
            $result['message'] = "Not a PDF: MIME type is '{$result['mime_type']}' and no PDF signature found";
        }
        
        return $result;
    }
    
    /**
     * Format bytes to human readable format
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted string
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Display file list in formatted HTML
     * @param array $files Array of file IDs
     * @param string $title Title for the section
     */
    public static function displayFileList($files, $title = 'Files') {
        echo '<div class="file-list">';
        echo '<h2>' . htmlspecialchars($title) . '</h2>';
        echo '<h3>Number of files: ' . count($files) . '</h3>';
        
        if (!empty($files)) {
            echo '<ul>';
            foreach ($files as $file) {
                echo '<li>' . htmlspecialchars($file) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No files found.</p>';
        }
        
        echo '</div>';
        echo '<hr>';
    }
    
    /**
     * Display progress information
     * @param int $current Current item number
     * @param int $total Total number of items
     * @param string $itemName Name of the item being processed
     */
    public static function displayProgress($current, $total, $itemName = 'item') {
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
        echo '<div class="progress">';
        echo '<h3>Processing ' . htmlspecialchars($itemName) . ' ' . $current . ' of ' . $total . ' (' . $percentage . '%)</h3>';
        echo '</div>';
    }
    
    /**
     * Display error message in formatted HTML
     * @param string $message Error message
     * @param string $title Error title
     */
    public static function displayError($message, $title = 'Error') {
        echo '<div class="error">';
        echo '<h2 style="color: red;">' . htmlspecialchars($title) . '</h2>';
        echo '<p style="color: red;">' . htmlspecialchars($message) . '</p>';
        echo '</div>';
    }
    
    /**
     * Display success message in formatted HTML
     * @param string $message Success message
     * @param string $title Success title
     */
    public static function displaySuccess($message, $title = 'Success') {
        echo '<div class="success">';
        echo '<h2 style="color: green;">' . htmlspecialchars($title) . '</h2>';
        echo '<p style="color: green;">' . htmlspecialchars($message) . '</p>';
        echo '</div>';
    }
    
    /**
     * Check system resources (memory and load average)
     * @param array $config Resource monitoring configuration
     * @return array Status with 'should_pause', 'memory_percent', 'load_avg', 'messages'
     */
    public static function checkSystemResources($config) {
        $result = [
            'should_pause' => false,
            'memory_percent' => 0,
            'load_avg' => [0, 0, 0],
            'messages' => []
        ];
        
        // Check memory usage
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        
        // Convert memory_limit to bytes
        $memoryLimitBytes = self::parseMemoryLimit($memoryLimit);
        
        if ($memoryLimitBytes > 0) {
            $memoryPercent = ($memoryUsed / $memoryLimitBytes) * 100;
            $result['memory_percent'] = round($memoryPercent, 2);
            
            if ($memoryPercent > ($config['max_memory_usage_percent'] ?? 75)) {
                $result['should_pause'] = true;
                $result['messages'][] = "High memory usage: " . round($memoryPercent, 1) . "%";
            }
        }
        
        // Check system load average (Linux/Unix systems)
        if (function_exists('sys_getloadavg')) {
            $loadAvg = sys_getloadavg();
            $result['load_avg'] = $loadAvg;
            
            if ($loadAvg && $loadAvg[0] > ($config['max_load_average'] ?? 2.0)) {
                $result['should_pause'] = true;
                $result['messages'][] = "High system load: " . round($loadAvg[0], 2);
            }
        }
        
        return $result;
    }
    
    /**
     * Parse memory limit string to bytes
     * @param string $limit Memory limit string (e.g., "512M", "1G")
     * @return int Memory limit in bytes
     */
    private static function parseMemoryLimit($limit) {
        $limit = trim($limit);
        $lastChar = strtolower(substr($limit, -1));
        $value = (int) $limit;
        
        switch ($lastChar) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}
?>
