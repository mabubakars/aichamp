<?php
class RateLimiter {
    private $db;
    private $rateLimitTable = "rate_limits";

    // Default rate limits by tier
    private $defaultLimits = [
        'free' => [
            'chat_completions_per_minute' => 10,
            'chat_completions_per_hour' => 100,
            'session_creations_per_hour' => 5,
            'session_creations_per_day' => 20
        ],
        'pro' => [
            'chat_completions_per_minute' => 60,
            'chat_completions_per_hour' => 1000,
            'session_creations_per_hour' => 20,
            'session_creations_per_day' => 100
        ],
        'enterprise' => [
            'chat_completions_per_minute' => 300,
            'chat_completions_per_hour' => 10000,
            'session_creations_per_hour' => 100,
            'session_creations_per_day' => 1000
        ]
    ];

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("RateLimiter initialized");
    }

    /**
     * Check if operation is allowed under rate limits
     */
    public function checkLimit($userId, $operation, $tier = 'free') {
        $limits = $this->defaultLimits[$tier] ?? $this->defaultLimits['free'];
        $limit = $limits[$operation] ?? null;

        if (!$limit) {
            Logger::warning("Unknown rate limit operation", [
                'operation' => $operation,
                'tier' => $tier
            ]);
            return ['allowed' => true]; // Allow unknown operations
        }

        $currentUsage = $this->getCurrentUsage($userId, $operation);
        $allowed = $currentUsage < $limit;

        if (!$allowed) {
            Logger::warning("Rate limit exceeded", [
                'user_id' => $userId,
                'operation' => $operation,
                'tier' => $tier,
                'current_usage' => $currentUsage,
                'limit' => $limit
            ]);
        }

        return [
            'allowed' => $allowed,
            'current_usage' => $currentUsage,
            'limit' => $limit,
            'remaining' => max(0, $limit - $currentUsage)
        ];
    }

    /**
     * Check multi-model rate limits
     */
    public function checkMultiModelLimits($userId, $modelCount, $tier = 'free') {
        // Check concurrent multi-model requests
        $concurrentCheck = $this->checkLimit($userId, 'multi_model_concurrent_requests', $tier);
        if (!$concurrentCheck['allowed']) {
            return $concurrentCheck;
        }

        // Check total models per request
        $modelLimit = $this->getMultiModelLimit($tier, 'max_models_per_request');
        if ($modelCount > $modelLimit) {
            return [
                'allowed' => false,
                'current_usage' => $modelCount,
                'limit' => $modelLimit,
                'remaining' => 0,
                'reason' => 'max_models_per_request'
            ];
        }

        // Check aggregate model usage
        $aggregateCheck = $this->checkLimit($userId, 'multi_model_requests_per_hour', $tier);
        if (!$aggregateCheck['allowed']) {
            return $aggregateCheck;
        }

        return [
            'allowed' => true,
            'current_usage' => $aggregateCheck['current_usage'],
            'limit' => $aggregateCheck['limit'],
            'remaining' => $aggregateCheck['remaining'],
            'model_count' => $modelCount
        ];
    }

    /**
     * Record multi-model usage
     */
    public function recordMultiModelUsage($userId, $modelCount, $tier = 'free') {
        // Record concurrent request
        $this->recordUsage($userId, 'multi_model_concurrent_requests');

        // Record aggregate usage (weighted by model count)
        $this->recordUsage($userId, 'multi_model_requests_per_hour', $modelCount);

        // Record individual model usage
        $this->recordUsage($userId, 'multi_model_total_models', $modelCount);

        Logger::info("Multi-model usage recorded", [
            'user_id' => $userId,
            'model_count' => $modelCount,
            'tier' => $tier
        ]);
    }

    /**
     * Get multi-model specific limits
     */
    private function getMultiModelLimit($tier, $limitType) {
        $multiModelLimits = [
            'free' => [
                'max_models_per_request' => 2,
                'multi_model_concurrent_requests' => 1,
                'multi_model_requests_per_hour' => 20
            ],
            'pro' => [
                'max_models_per_request' => 5,
                'multi_model_concurrent_requests' => 3,
                'multi_model_requests_per_hour' => 200
            ],
            'enterprise' => [
                'max_models_per_request' => 10,
                'multi_model_concurrent_requests' => 10,
                'multi_model_requests_per_hour' => 1000
            ]
        ];

        return $multiModelLimits[$tier][$limitType] ?? $multiModelLimits['free'][$limitType];
    }

    /**
     * Record operation usage
     */
    public function recordUsage($userId, $operation, $count = 1) {
        try {
            $key = $this->generateKey($userId, $operation);
            $now = time();

            // Get existing record
            $existing = $this->db->readOne($this->rateLimitTable, ['rate_key' => $key]);

            if ($existing) {
                // Update existing record
                $newCount = $existing['count'] + $count;
                $this->db->update(
                    $this->rateLimitTable,
                    [
                        'count' => $newCount,
                        'last_updated' => date('Y-m-d H:i:s', $now)
                    ],
                    ['id' => $existing['id']]
                );
            } else {
                // Create new record
                $this->db->create($this->rateLimitTable, [
                    'rate_key' => $key,
                    'user_id' => $userId,
                    'operation' => $operation,
                    'count' => $count,
                    'window_start' => date('Y-m-d H:i:s', $now),
                    'last_updated' => date('Y-m-d H:i:s', $now)
                ]);
            }

        } catch (Exception $e) {
            Logger::error("Failed to record rate limit usage", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'operation' => $operation
            ]);
        }
    }

    /**
     * Get current usage for operation
     */
    private function getCurrentUsage($userId, $operation) {
        try {
            $key = $this->generateKey($userId, $operation);
            $windowStart = $this->getWindowStart($operation);

            $record = $this->db->readOne(
                $this->rateLimitTable,
                [
                    'rate_key' => $key,
                    'last_updated' => ['>=', $windowStart]
                ]
            );

            return $record ? (int)$record['count'] : 0;

        } catch (Exception $e) {
            Logger::error("Failed to get current usage", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'operation' => $operation
            ]);
            return 0;
        }
    }

    /**
     * Generate rate limit key
     */
    private function generateKey($userId, $operation) {
        $window = $this->getWindowType($operation);
        $windowStart = $this->getWindowStart($operation);

        return md5("{$userId}:{$operation}:{$window}:{$windowStart}");
    }

    /**
     * Get window type for operation
     */
    private function getWindowType($operation) {
        if (strpos($operation, '_per_minute') !== false) {
            return 'minute';
        } elseif (strpos($operation, '_per_hour') !== false) {
            return 'hour';
        } elseif (strpos($operation, '_per_day') !== false) {
            return 'day';
        }
        return 'hour'; // Default
    }

    /**
     * Get window start time for operation
     */
    private function getWindowStart($operation) {
        $windowType = $this->getWindowType($operation);
        $now = time();

        switch ($windowType) {
            case 'minute':
                return date('Y-m-d H:i:00', $now);
            case 'hour':
                return date('Y-m-d H:00:00', $now);
            case 'day':
                return date('Y-m-d 00:00:00', $now);
            default:
                return date('Y-m-d H:00:00', $now);
        }
    }

    /**
     * Check and record operation in one call
     */
    public function checkAndRecord($userId, $operation, $tier = 'free') {
        $check = $this->checkLimit($userId, $operation, $tier);

        if ($check['allowed']) {
            $this->recordUsage($userId, $operation);
        }

        return $check;
    }

    /**
     * Get rate limit status for user
     */
    public function getUserStatus($userId, $tier = 'free') {
        $limits = $this->defaultLimits[$tier] ?? $this->defaultLimits['free'];
        $status = [];

        foreach ($limits as $operation => $limit) {
            $currentUsage = $this->getCurrentUsage($userId, $operation);
            $status[$operation] = [
                'current' => $currentUsage,
                'limit' => $limit,
                'remaining' => max(0, $limit - $currentUsage),
                'reset_time' => $this->getResetTime($operation)
            ];
        }

        return $status;
    }

    /**
     * Get reset time for operation
     */
    private function getResetTime($operation) {
        $windowType = $this->getWindowType($operation);
        $now = time();

        switch ($windowType) {
            case 'minute':
                return strtotime('next minute', $now);
            case 'hour':
                return strtotime('next hour', $now);
            case 'day':
                return strtotime('tomorrow', $now);
            default:
                return strtotime('next hour', $now);
        }
    }

    /**
     * Clean up old rate limit records
     */
    public function cleanupOldRecords($daysOld = 1) {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

            $deleted = $this->db->delete($this->rateLimitTable, [
                'last_updated' => ['<', $cutoffDate]
            ]);

            Logger::info("Old rate limit records cleaned up", [
                'days_old' => $daysOld,
                'records_deleted' => $deleted
            ]);

            return $deleted;

        } catch (Exception $e) {
            Logger::error("Failed to cleanup old rate limit records", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Reset rate limits for user (admin function)
     */
    public function resetUserLimits($userId) {
        try {
            $deleted = $this->db->delete($this->rateLimitTable, ['user_id' => $userId]);

            Logger::info("User rate limits reset", [
                'user_id' => $userId,
                'records_deleted' => $deleted
            ]);

            return $deleted;

        } catch (Exception $e) {
            Logger::error("Failed to reset user rate limits", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return 0;
        }
    }

    /**
     * Get rate limiting statistics
     */
    public function getStats($hours = 24) {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

            $stats = $this->db->query(
                "SELECT
                    operation,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(count) as total_requests,
                    AVG(count) as avg_requests_per_user,
                    MAX(count) as max_requests_per_user
                 FROM {$this->rateLimitTable}
                 WHERE last_updated >= ?
                 GROUP BY operation
                 ORDER BY total_requests DESC",
                [$cutoffDate]
            );

            return $stats;

        } catch (Exception $e) {
            Logger::error("Failed to get rate limiting stats", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
?>