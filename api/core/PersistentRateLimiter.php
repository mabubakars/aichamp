<?php
require_once __DIR__ . '/Logger.php';

class PersistentRateLimiter {
    private static $storage_path = __DIR__ . '/../storage/rate_limits/';
    private static $config = [
        'global' => [
            'max_attempts' => 1000,
            'window' => 3600,
            'block_duration' => 7200
        ],
        'signup' => [
            'max_attempts' => 5,
            'window' => 3600,
            'block_duration' => 10800
        ],
        'login' => [
            'max_attempts' => 15,
            'window' => 900,
            'block_duration' => 3600
        ],
        'abusive' => [
            'max_attempts' => 50,
            'window' => 300,
            'block_duration' => 86400
        ]
    ];

    public static function init() {
        // Create storage directory if it doesn't exist
        if (!is_dir(self::$storage_path)) {
            mkdir(self::$storage_path, 0755, true);
            Logger::debug("Created rate limit storage directory", [
                'path' => self::$storage_path
            ]);
        }
        
        // Cleanup old files periodically (1% chance per request)
        if (mt_rand(1, 100) === 1) {
            $cleaned = self::cleanupOldFiles();
            if ($cleaned > 0) {
                Logger::debug("Cleaned up old rate limit files", [
                    'count' => $cleaned
                ]);
            }
        }
    }
    
    public static function checkEarly($path) {
        self::init();
        
        $client_id = self::getFastFingerprint();
        $now = time();
        $action = self::getActionFromPath($path);
        
        Logger::debug("Rate limit check started", [
            'client_id' => $client_id,
            'action' => $action,
            'path' => $path
        ]);
        
        // Check global limits
        $global_check = self::checkPersistentLimit($client_id, 'global', $now);
        if (!$global_check['allowed']) {
            Logger::logRateLimit($client_id, 'global', false, $global_check['reason']);
            return $global_check;
        }
        
        // Check endpoint-specific limits
        $endpoint_check = self::checkPersistentLimit($client_id, $action, $now);
        if (!$endpoint_check['allowed']) {
            Logger::logRateLimit($client_id, $action, false, $endpoint_check['reason']);
            return $endpoint_check;
        }
        
        Logger::debug("Rate limit check passed", [
            'client_id' => $client_id,
            'action' => $action,
            'remaining' => min($global_check['remaining'], $endpoint_check['remaining'])
        ]);
        
        return [
            'allowed' => true,
            'remaining' => min($global_check['remaining'], $endpoint_check['remaining']),
            'reset_time' => $now + 3600,
            'reason' => 'allowed'
        ];
    }
    
    private static function checkPersistentLimit($client_id, $action, $now) {
        $key = $client_id . ':' . $action;
        $config = self::$config[$action];
        $file_path = self::$storage_path . md5($key) . '.json';
        $data = self::readData($file_path);
        
        // Quick block check
        if (isset($data['blocked_until']) && $data['blocked_until'] > $now) {
            Logger::warning("Rate limit blocked - client is blocked", [
                'client_id' => $client_id,
                'action' => $action,
                'blocked_until' => $data['blocked_until'],
                'remaining_time' => $data['blocked_until'] - $now
            ]);
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_time' => $data['blocked_until'],
                'reason' => 'blocked'
            ];
        }
        
        // Remove old attempts using sliding window
        $window_start = $now - $config['window'];
        $recent_attempts = 0;
        
        if (isset($data['attempts'])) {
            foreach ($data['attempts'] as $timestamp) {
                if ($timestamp > $window_start) {
                    $recent_attempts++;
                }
            }
        }

        if ($recent_attempts >= $config['max_attempts']) {
            $data['blocked_until'] = $now + $config['block_duration'];
            self::writeData($file_path, $data);
            
            Logger::warning("Rate limit exceeded - blocking client", [
                'client_id' => $client_id,
                'action' => $action,
                'attempts' => $recent_attempts,
                'max_attempts' => $config['max_attempts'],
                'blocked_until' => $data['blocked_until']
            ]);
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_time' => $data['blocked_until'],
                'reason' => 'rate_limit_exceeded'
            ];
        }
        
        // Add current attempt
        $data['attempts'][] = $now;
        $data['last_updated'] = $now;
        self::writeData($file_path, $data);
        
        return [
            'allowed' => true,
            'remaining' => $config['max_attempts'] - $recent_attempts - 1,
            'reset_time' => $window_start + $config['window'],
            'reason' => 'allowed'
        ];
    }
    
    private static function readData($file_path) {
        if (!file_exists($file_path)) {
            return ['attempts' => [], 'last_updated' => time()];
        }
        
        // Use file locking for concurrent reads
        $fp = fopen($file_path, 'r');
        if (flock($fp, LOCK_SH)) {
            $content = file_get_contents($file_path);
            flock($fp, LOCK_UN);
            fclose($fp);
            
            $data = json_decode($content, true) ?: [];
            return $data;
        }
        
        return ['attempts' => [], 'last_updated' => time()];
    }
    
    private static function writeData($file_path, $data) {
        // Keep only recent attempts to prevent file bloat
        if (isset($data['attempts']) && count($data['attempts']) > 100) {
            $data['attempts'] = array_slice($data['attempts'], -50);
        }
        
        $fp = fopen($file_path, 'w');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
    private static function cleanupOldFiles() {
        $files = glob(self::$storage_path . '*.json');
        $now = time();
        $max_age = 86400; // 24 hours
        $cleaned = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < ($now - $max_age)) {
                if (@unlink($file)) {
                    $cleaned++;
                } else {
                    Logger::warning("Failed to delete old rate limit file", ['file' => $file]);
                }
            }
        }
        
        return $cleaned;
    }
    
    private static function getFastFingerprint() {
        return md5(
            ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' .
            ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . '|' .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')
        );
    }
    
    private static function getActionFromPath($path) {
        $path = rtrim($path, '/');
        
        if (strpos($path, '/signup') !== false) return 'signup';
        if (strpos($path, '/login') !== false) return 'login';
        
        return 'global';
    }
}
?>