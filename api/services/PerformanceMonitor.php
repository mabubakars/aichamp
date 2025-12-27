<?php
class PerformanceMonitor {
    private $db;
    private $metricsTable = "performance_metrics";
    private $slowQueryThreshold = 1000; // ms

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("PerformanceMonitor initialized");
    }

    /**
     * Record performance metric
     */
    public function recordMetric($operation, $duration, $metadata = []) {
        try {
            $metricData = [
                'operation' => $operation,
                'duration_ms' => round($duration, 2),
                'timestamp' => date('Y-m-d H:i:s'),
                'metadata' => json_encode($metadata)
            ];

            // Check if this is a slow query
            if ($duration > $this->slowQueryThreshold) {
                $metricData['is_slow'] = true;
                Logger::warning("Slow operation detected", [
                    'operation' => $operation,
                    'duration_ms' => $duration,
                    'metadata' => $metadata
                ]);
            }

            if ($this->db) {
                $this->db->create($this->metricsTable, $metricData);
            }

        } catch (Throwable $e) {
            // Don't let metrics recording break the main flow
            Logger::error("Failed to record performance metric", [
                'error' => $e->getMessage(),
                'operation' => $operation
            ]);
        }
    }

    /**
     * Record multi-model performance metrics
     */
    public function recordMultiModelMetrics($requestId, $modelCount, $totalDuration, $individualResults, $metadata = []) {
        try {
            // Record overall multi-model request
            $this->recordMetric('multi_model_request', $totalDuration, array_merge($metadata, [
                'request_id' => $requestId,
                'model_count' => $modelCount,
                'successful_models' => count(array_filter($individualResults, fn($r) => $r['success'])),
                'failed_models' => count(array_filter($individualResults, fn($r) => !$r['success']))
            ]));

            // Record individual model metrics
            foreach ($individualResults as $result) {
                $modelId = $result['model']->id ?? 'unknown';
                $this->recordMetric('model_request', $result['duration_ms'], [
                    'request_id' => $requestId,
                    'model_id' => $modelId,
                    'model_name' => $result['model']->model_name ?? 'unknown',
                    'provider' => $result['model']->provider ?? 'unknown',
                    'success' => $result['success'],
                    'error' => $result['error'] ?? null
                ]);
            }

            // Calculate and record coordination overhead
            $individualTotal = array_sum(array_column($individualResults, 'duration_ms'));
            $overhead = $totalDuration - $individualTotal;
            if ($overhead > 0) {
                $this->recordMetric('multi_model_coordination_overhead', $overhead, [
                    'request_id' => $requestId,
                    'model_count' => $modelCount,
                    'total_individual_duration' => $individualTotal,
                    'coordination_overhead' => $overhead
                ]);
            }

        } catch (Exception $e) {
            Logger::error("Failed to record multi-model metrics", [
                'error' => $e->getMessage(),
                'request_id' => $requestId
            ]);
        }
    }

    /**
     * Get multi-model performance statistics
     */
    public function getMultiModelStats($hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $stats = $this->db->query(
            "SELECT
                DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
                COUNT(CASE WHEN operation = 'multi_model_request' THEN 1 END) as multi_model_requests,
                AVG(CASE WHEN operation = 'multi_model_request' THEN duration_ms END) as avg_multi_model_duration,
                AVG(CASE WHEN operation = 'model_request' THEN duration_ms END) as avg_individual_model_duration,
                AVG(CASE WHEN operation = 'multi_model_coordination_overhead' THEN duration_ms END) as avg_coordination_overhead,
                SUM(CASE WHEN operation = 'model_request' AND JSON_EXTRACT(metadata, '$.success') = 'true' THEN 1 ELSE 0 END) as successful_model_requests,
                SUM(CASE WHEN operation = 'model_request' AND JSON_EXTRACT(metadata, '$.success') = 'false' THEN 1 ELSE 0 END) as failed_model_requests
             FROM {$this->metricsTable}
             WHERE timestamp >= ? AND operation IN ('multi_model_request', 'model_request', 'multi_model_coordination_overhead')
             GROUP BY hour
             ORDER BY hour DESC",
            [$cutoffTime]
        );

        return $stats;
    }

    /**
     * Get model-specific performance metrics
     */
    public function getModelPerformanceStats($hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $stats = $this->db->query(
            "SELECT
                JSON_EXTRACT(metadata, '$.model_name') as model_name,
                JSON_EXTRACT(metadata, '$.provider') as provider,
                COUNT(*) as total_requests,
                AVG(duration_ms) as avg_duration,
                MIN(duration_ms) as min_duration,
                MAX(duration_ms) as max_duration,
                SUM(CASE WHEN JSON_EXTRACT(metadata, '$.success') = 'true' THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN JSON_EXTRACT(metadata, '$.success') = 'false' THEN 1 ELSE 0 END) as failed_requests,
                ROUND(
                    (SUM(CASE WHEN JSON_EXTRACT(metadata, '$.success') = 'true' THEN 1 ELSE 0 END) / COUNT(*)) * 100,
                    2
                ) as success_rate_percent
             FROM {$this->metricsTable}
             WHERE timestamp >= ? AND operation = 'model_request'
             GROUP BY JSON_EXTRACT(metadata, '$.model_name'), JSON_EXTRACT(metadata, '$.provider')
             ORDER BY avg_duration DESC",
            [$cutoffTime]
        );

        return $stats;
    }

    /**
     * Record circuit breaker events
     */
    public function recordCircuitBreakerEvent($modelId, $event, $metadata = []) {
        $this->recordMetric('circuit_breaker_' . $event, 0, array_merge($metadata, [
            'model_id' => $modelId,
            'event' => $event
        ]));
    }

    /**
     * Get circuit breaker statistics
     */
    public function getCircuitBreakerStats($hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $stats = $this->db->query(
            "SELECT
                JSON_EXTRACT(metadata, '$.model_id') as model_id,
                JSON_EXTRACT(metadata, '$.event') as event,
                COUNT(*) as event_count
             FROM {$this->metricsTable}
             WHERE timestamp >= ? AND operation LIKE 'circuit_breaker_%'
             GROUP BY JSON_EXTRACT(metadata, '$.model_id'), JSON_EXTRACT(metadata, '$.event')
             ORDER BY event_count DESC",
            [$cutoffTime]
        );

        return $stats;
    }

    /**
     * Time a database query
     */
    public function timeQuery($query, $params = []) {
        $startTime = microtime(true);
        $result = $this->db->query($query, $params);
        $duration = (microtime(true) - $startTime) * 1000;

        $this->recordMetric('database_query', $duration, [
            'query_type' => $this->getQueryType($query),
            'param_count' => count($params)
        ]);

        return $result;
    }

    /**
     * Time a function execution
     */
    public function timeFunction($functionName, callable $callback, $metadata = []) {
        $startTime = microtime(true);
        $result = $callback();
        $duration = (microtime(true) - $startTime) * 1000;

        $this->recordMetric('function_call', $duration, array_merge([
            'function' => $functionName
        ], $metadata));

        return $result;
    }

    /**
     * Get query type from SQL
     */
    private function getQueryType($query) {
        $query = trim(strtoupper($query));
        if (strpos($query, 'SELECT') === 0) return 'SELECT';
        if (strpos($query, 'INSERT') === 0) return 'INSERT';
        if (strpos($query, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($query, 'DELETE') === 0) return 'DELETE';
        return 'OTHER';
    }

    /**
     * Get performance statistics
     */
    public function getPerformanceStats($hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $stats = $this->db->query(
            "SELECT
                operation,
                COUNT(*) as total_calls,
                AVG(duration_ms) as avg_duration,
                MIN(duration_ms) as min_duration,
                MAX(duration_ms) as max_duration,
                SUM(CASE WHEN is_slow = 1 THEN 1 ELSE 0 END) as slow_queries
             FROM {$this->metricsTable}
             WHERE timestamp >= ?
             GROUP BY operation
             ORDER BY avg_duration DESC",
            [$cutoffTime]
        );

        return $stats;
    }

    /**
     * Get slow queries
     */
    public function getSlowQueries($limit = 50, $hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        return $this->db->query(
            "SELECT * FROM {$this->metricsTable}
             WHERE is_slow = 1 AND timestamp >= ?
             ORDER BY duration_ms DESC
             LIMIT ?",
            [$cutoffTime, $limit]
        );
    }

    /**
     * Analyze query performance patterns
     */
    public function analyzeQueryPatterns($hours = 24) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $patterns = $this->db->query(
            "SELECT
                DATE_FORMAT(timestamp, '%Y-%m-%d %H:00:00') as hour,
                operation,
                COUNT(*) as call_count,
                AVG(duration_ms) as avg_duration,
                MAX(duration_ms) as max_duration
             FROM {$this->metricsTable}
             WHERE timestamp >= ?
             GROUP BY hour, operation
             ORDER BY hour DESC, avg_duration DESC",
            [$cutoffTime]
        );

        return $patterns;
    }

    /**
     * Get database connection stats
     */
    public function getDatabaseStats() {
        try {
            // Get table sizes
            $tableStats = $this->db->query(
                "SELECT
                    table_name,
                    table_rows,
                    data_length,
                    index_length,
                    (data_length + index_length) as total_size
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 ORDER BY total_size DESC"
            );

            // Get index usage stats (simplified)
            $indexStats = $this->db->query(
                "SELECT
                    TABLE_NAME,
                    INDEX_NAME,
                    CARDINALITY,
                    PAGES,
                    FILTER_CONDITION
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                 ORDER BY TABLE_NAME, SEQ_IN_INDEX"
            );

            return [
                'table_stats' => $tableStats,
                'index_stats' => $indexStats,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            Logger::error("Failed to get database stats", [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Monitor memory usage
     */
    public function recordMemoryUsage($operation = 'general') {
        $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024; // MB

        $this->recordMetric('memory_usage', $memoryUsage, [
            'operation' => $operation,
            'memory_mb' => round($memoryUsage, 2)
        ]);

        return $memoryUsage;
    }

    /**
     * Clean up old metrics
     */
    public function cleanupOldMetrics($daysOld = 30) {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $deleted = $this->db->query(
            "DELETE FROM {$this->metricsTable} WHERE timestamp < ?",
            [$cutoffDate]
        );

        Logger::info("Old performance metrics cleaned up", [
            'days_old' => $daysOld,
            'records_deleted' => $deleted
        ]);

        return $deleted;
    }

    /**
     * Get system health metrics
     */
    public function getHealthMetrics() {
        return [
            'database_connection' => $this->checkDatabaseConnection(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'load_average' => $this->getLoadAverage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Check database connection health
     */
    private function checkDatabaseConnection() {
        try {
            $startTime = microtime(true);
            $this->db->query("SELECT 1");
            $duration = (microtime(true) - $startTime) * 1000;

            return [
                'status' => 'healthy',
                'response_time_ms' => round($duration, 2)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage() {
        return [
            'current_mb' => round(memory_get_usage() / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
            'limit_mb' => round($this->getMemoryLimit() / 1024 / 1024, 2)
        ];
    }

    /**
     * Get memory limit
     */
    private function getMemoryLimit() {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') return PHP_INT_MAX;
        return $this->parseSize($limit);
    }

    /**
     * Parse size string (e.g., "128M" to bytes)
     */
    private function parseSize($size) {
        $unit = strtolower(substr($size, -1));
        $value = (int)substr($size, 0, -1);

        switch ($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }

        return $value;
    }

    /**
     * Get disk usage
     */
    private function getDiskUsage() {
        $path = __DIR__ . '/../storage';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_gb' => round($used / 1024 / 1024 / 1024, 2),
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'usage_percent' => round(($used / $total) * 100, 2)
        ];
    }

    /**
     * Get system load average
     */
    private function getLoadAverage() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }

        return ['error' => 'Load average not available'];
    }
}
?>