<?php
require_once __DIR__ . '/../config/Environment.php';

class Logger {
    private static $instance = null;
    private static $logDir;
    private static $currentLogFile;
    private static $maxFileSize = 10485760; // 10MB
    private static $maxFiles = 30; // Keep 30 days of logs
    private static $levels = [
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600
    ];

    private function __construct() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function initialize() {
        Environment::load();
        
        self::$logDir = Environment::get('LOG_PATH', __DIR__ . '/../storage/logs');
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        
        // Set current log file based on date
        self::$currentLogFile = self::$logDir . '/app-' . date('Y-m-d') . '.log';
        
        // Set error logging to current daily file
        ini_set('log_errors', '1');
        ini_set('error_log', self::$currentLogFile);
        
        // Cleanup old log files periodically (1% chance per request)
        if (mt_rand(1, 100) === 1) {
            self::cleanupOldLogs();
        }
    }

    public static function log($level, $message, array $context = []) {
        self::initialize();
        
        $level = strtoupper($level);
        if (!isset(self::$levels[$level])) {
            throw new InvalidArgumentException("Invalid log level: {$level}");
        }

        $minLevel = self::$levels[Environment::get('LOG_LEVEL', 'DEBUG')] ?? 100;
        if (self::$levels[$level] < $minLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? json_encode($context) : '';
        $logMessage = "[{$timestamp}] {$level}: {$message} {$contextString}" . PHP_EOL;

        self::write($logMessage);
    }

    private static function write($message) {
        // Check if we need to rotate the current day's log file
        if (file_exists(self::$currentLogFile) && filesize(self::$currentLogFile) > self::$maxFileSize) {
            self::rotateCurrentLog();
        }

        file_put_contents(self::$currentLogFile, $message, FILE_APPEND | LOCK_EX);
    }

    private static function rotateCurrentLog() {
        $baseName = self::$logDir . '/app-' . date('Y-m-d');
        $extension = '.log';
        
        // Find the next available rotation number
        $counter = 1;
        while (file_exists($baseName . '-' . $counter . $extension)) {
            $counter++;
        }
        
        // Rotate the current log file
        $rotatedFile = $baseName . '-' . $counter . $extension;
        rename(self::$currentLogFile, $rotatedFile);
        
        // Update error log to new file
        ini_set('error_log', self::$currentLogFile);
        
        self::debug("Log file rotated", [
            'original' => self::$currentLogFile,
            'rotated_to' => $rotatedFile,
            'rotation_number' => $counter
        ]);
    }

    private static function cleanupOldLogs() {
        $files = glob(self::$logDir . '/app-*.log');
        $now = time();
        $maxAge = self::$maxFiles * 86400; // 30 days in seconds
        $cleaned = 0;

        foreach ($files as $file) {
            if (filemtime($file) < ($now - $maxAge)) {
                if (@unlink($file)) {
                    $cleaned++;
                } else {
                    self::warning("Failed to delete old log file", ['file' => $file]);
                }
            }
        }

        if ($cleaned > 0) {
            self::info("Cleaned up old log files", ['count' => $cleaned]);
        }

        return $cleaned;
    }

    // Convenience methods
    public static function debug($message, array $context = []) {
        self::log('DEBUG', $message, $context);
    }

    public static function info($message, array $context = []) {
        self::log('INFO', $message, $context);
    }

    public static function warning($message, array $context = []) {
        self::log('WARNING', $message, $context);
    }

    public static function error($message, array $context = []) {
        self::log('ERROR', $message, $context);
    }

    public static function critical($message, array $context = []) {
        self::log('CRITICAL', $message, $context);
    }

    public static function alert($message, array $context = []) {
        self::log('ALERT', $message, $context);
    }

    public static function emergency($message, array $context = []) {
        self::log('EMERGENCY', $message, $context);
    }

    // Request logging
    public static function logRequest() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

        self::info('Request received', [
            'method' => $method,
            'uri' => $uri,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'timestamp' => time()
        ]);
    }

    // Database query logging
    public static function logQuery($query, $duration = null, $success = true) {
        $context = [
            'query' => $query,
            'success' => $success
        ];

        if ($duration !== null) {
            $context['duration'] = $duration;
        }

        self::debug('Database query executed', $context);
    }

    // Authentication logging
    public static function logAuth($event, $userId = null, $email = null, $success = true) {
        $context = [
            'event' => $event,
            'user_id' => $userId,
            'email' => $email,
            'success' => $success,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];

        self::info('Authentication event', $context);
    }

    // Rate limiting logging
    public static function logRateLimit($clientId, $action, $allowed, $reason) {
        $level = $allowed ? 'INFO' : 'WARNING';
        self::log($level, 'Rate limit check', [
            'client_id' => $clientId,
            'action' => $action,
            'allowed' => $allowed,
            'reason' => $reason,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ]);
    }

    // Get recent logs (for debugging)
    public static function getRecentLogs($limit = 100, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $logFile = self::$logDir . '/app-' . $date . '.log';
        if (!file_exists($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($lines, -$limit);
    }

    // Get available log dates
    public static function getAvailableLogDates() {
        $files = glob(self::$logDir . '/app-*.log');
        $dates = [];
        
        foreach ($files as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                $dates[] = $matches[1];
            }
        }
        
        sort($dates);
        return $dates;
    }
}
?>