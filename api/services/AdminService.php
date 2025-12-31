<?php
class AdminService {
    private $db;
    private $userModel;
    private $performanceMonitor;
    private $validator;
    private $auditLogTable = "admin_audit_logs";

    public function __construct($db) {
        $this->db = $db;
        $this->userModel = new User($db);
        $this->performanceMonitor = new PerformanceMonitor($db);
        $this->validator = new Validator();

        Logger::debug("AdminService initialized");
    }

    /**
     * User Management Methods
     */

    /**
     * Get all users with pagination and filters
     */
    public function getAllUsers($page = 1, $limit = 50, $filters = []) {
        Logger::info("Admin retrieving all users", [
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters
        ]);

        $result = $this->userModel->getAll($page, $limit, $filters);

        $this->logAdminAction('view_users', null, [
            'page' => $page,
            'limit' => $limit,
            'total_users' => $result['pagination']['total'] ?? 0
        ]);

        return $result;
    }

    /**
     * Get user details by ID
     */
    public function getUserById($userId) {
        Logger::info("Admin retrieving user details", ['user_id' => $userId]);

        $user = $this->userModel->getById($userId);

        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        $this->logAdminAction('view_user_details', $userId, [
            'user_email' => $user['email']
        ]);

        return $user;
    }

    /**
     * Update user profile (admin)
     */
    public function updateUser($userId, $updateData) {
        Logger::info("Admin updating user", [
            'user_id' => $userId,
            'fields' => array_keys($updateData)
        ]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        $updatedUser = $this->userModel->updateProfile($userId, $updateData);

        $this->logAdminAction('update_user', $userId, [
            'user_email' => $user['email'],
            'updated_fields' => array_keys($updateData)
        ]);

        return $updatedUser;
    }

    /**
     * Activate user account
     */
    public function activateUser($userId) {
        Logger::info("Admin activating user", ['user_id' => $userId]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        if ($user['is_active']) {
            throw new InvalidArgumentException("User is already active");
        }

        $success = $this->userModel->activate($userId);

        if (!$success) {
            throw new RuntimeException("Failed to activate user");
        }

        $this->logAdminAction('activate_user', $userId, [
            'user_email' => $user['email']
        ]);

        return $this->userModel->getById($userId);
    }

    /**
     * Deactivate user account
     */
    public function deactivateUser($userId) {
        Logger::info("Admin deactivating user", ['user_id' => $userId]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        if (!$user['is_active']) {
            throw new InvalidArgumentException("User is already inactive");
        }

        $success = $this->userModel->deactivate($userId);

        if (!$success) {
            throw new RuntimeException("Failed to deactivate user");
        }

        $this->logAdminAction('deactivate_user', $userId, [
            'user_email' => $user['email']
        ]);

        return $this->userModel->getById($userId);
    }

    /**
     * Search users
     */
    public function searchUsers($query, $page = 1, $limit = 50) {
        Logger::info("Admin searching users", [
            'query' => $query,
            'page' => $page,
            'limit' => $limit
        ]);

        $result = $this->userModel->search($query, $page, $limit);

        $this->logAdminAction('search_users', null, [
            'query' => $query,
            'results_count' => count($result['users'])
        ]);

        return $result;
    }

    /**
     * System Monitoring Methods
     */

    /**
     * Get system health metrics
     */
    public function getSystemHealth() {
        Logger::info("Admin checking system health");

        $health = $this->performanceMonitor->getHealthMetrics();

        $this->logAdminAction('check_system_health', null, [
            'database_status' => $health['database_connection']['status'] ?? 'unknown',
            'memory_usage_mb' => $health['memory_usage']['current_mb'] ?? 0
        ]);

        return $health;
    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats() {
        Logger::info("Admin retrieving database stats");

        $stats = $this->performanceMonitor->getDatabaseStats();

        $this->logAdminAction('view_database_stats', null, [
            'table_count' => count($stats['table_stats'] ?? [])
        ]);

        return $stats;
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics($hours = 24) {
        Logger::info("Admin retrieving performance metrics", ['hours' => $hours]);

        $metrics = [
            'performance_stats' => $this->performanceMonitor->getPerformanceStats($hours),
            'slow_queries' => $this->performanceMonitor->getSlowQueries(20, $hours),
            'multi_model_stats' => $this->performanceMonitor->getMultiModelStats($hours),
            'model_performance' => $this->performanceMonitor->getModelPerformanceStats($hours),
            'circuit_breaker_stats' => $this->performanceMonitor->getCircuitBreakerStats($hours)
        ];

        $this->logAdminAction('view_performance_metrics', null, [
            'hours' => $hours,
            'total_operations' => count($metrics['performance_stats'])
        ]);

        return $metrics;
    }

    /**
     * Audit Log Methods
     */

    /**
     * Get audit logs
     */
    public function getAuditLogs($page = 1, $limit = 50, $filters = []) {
        Logger::info("Admin retrieving audit logs", [
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters
        ]);

        // For now, use the Logger's getRecentLogs method
        // In a real implementation, you'd have a dedicated audit log table
        $logs = Logger::getRecentLogs($limit * $page);

        // Filter and paginate
        $filteredLogs = $this->filterAuditLogs($logs, $filters);
        $offset = ($page - 1) * $limit;
        $paginatedLogs = array_slice($filteredLogs, $offset, $limit);

        $result = [
            'logs' => $paginatedLogs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => count($filteredLogs),
                'pages' => ceil(count($filteredLogs) / $limit)
            ]
        ];

        $this->logAdminAction('view_audit_logs', null, [
            'page' => $page,
            'limit' => $limit,
            'total_logs' => count($filteredLogs)
        ]);

        return $result;
    }

    /**
     * Get audit logs for specific user
     */
    public function getUserAuditLogs($userId, $page = 1, $limit = 50) {
        Logger::info("Admin retrieving user audit logs", [
            'user_id' => $userId,
            'page' => $page,
            'limit' => $limit
        ]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        // Get recent logs and filter by user
        $logs = Logger::getRecentLogs(1000); // Get more logs to filter
        $userLogs = array_filter($logs, function($log) use ($userId) {
            return strpos($log, $userId) !== false;
        });

        $offset = ($page - 1) * $limit;
        $paginatedLogs = array_slice(array_values($userLogs), $offset, $limit);

        $result = [
            'logs' => $paginatedLogs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => count($userLogs),
                'pages' => ceil(count($userLogs) / $limit)
            ]
        ];

        $this->logAdminAction('view_user_audit_logs', $userId, [
            'user_email' => $user['email'],
            'total_logs' => count($userLogs)
        ]);

        return $result;
    }

    /**
     * Background Job Management Methods
     */

    /**
     * Get background jobs status (placeholder)
     */
    public function getBackgroundJobs() {
        Logger::info("Admin checking background jobs");

        // Placeholder implementation - in a real system, you'd have a job queue
        $jobs = [
            'email_queue' => [
                'status' => 'active',
                'queued' => 0,
                'processing' => 0,
                'failed' => 0
            ],
            'payment_processor' => [
                'status' => 'active',
                'queued' => 0,
                'processing' => 0,
                'failed' => 0
            ],
            'data_cleanup' => [
                'status' => 'scheduled',
                'next_run' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'last_run' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ]
        ];

        $this->logAdminAction('view_background_jobs', null, [
            'job_count' => count($jobs)
        ]);

        return $jobs;
    }

    /**
     * Trigger background job (placeholder)
     */
    public function triggerJob($jobName) {
        Logger::info("Admin triggering background job", ['job_name' => $jobName]);

        // Placeholder implementation
        $allowedJobs = ['data_cleanup', 'cache_clear', 'metrics_cleanup'];

        if (!in_array($jobName, $allowedJobs)) {
            throw new InvalidArgumentException("Invalid job name");
        }

        // Simulate job execution
        switch ($jobName) {
            case 'data_cleanup':
                $this->performanceMonitor->cleanupOldMetrics(30);
                break;
            case 'cache_clear':
                // Placeholder
                break;
            case 'metrics_cleanup':
                $this->performanceMonitor->cleanupOldMetrics(7);
                break;
        }

        $this->logAdminAction('trigger_job', null, [
            'job_name' => $jobName,
            'status' => 'completed'
        ]);

        return [
            'job_name' => $jobName,
            'status' => 'completed',
            'executed_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Performance Metrics Methods
     */

    /**
     * Get comprehensive system metrics
     */
    public function getSystemMetrics($hours = 24) {
        Logger::info("Admin retrieving system metrics", ['hours' => $hours]);

        $metrics = [
            'health' => $this->getSystemHealth(),
            'performance' => $this->getPerformanceMetrics($hours),
            'database' => $this->getDatabaseStats(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->logAdminAction('view_system_metrics', null, [
            'hours' => $hours
        ]);

        return $metrics;
    }

    /**
     * Get user statistics
     */
    public function getUserStats() {
        Logger::info("Admin retrieving user statistics");

        try {
            // Get user counts by status
            $stats = $this->db->query(
                "SELECT
                    COUNT(*) as total_users,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
                    SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) as verified_users,
                    SUM(CASE WHEN email_verified = 0 THEN 1 ELSE 0 END) as unverified_users,
                    SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) as admin_users,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as new_users_24h,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_users_7d,
                    COUNT(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as active_users_24h
                 FROM users"
            );

            $result = $stats[0] ?? [];

            $this->logAdminAction('view_user_stats', null, [
                'total_users' => $result['total_users'] ?? 0
            ]);

            return $result;

        } catch (Exception $e) {
            Logger::error("Failed to get user stats", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Promote user to admin
     */
    public function promoteToAdmin($userId) {
        Logger::info("Admin promoting user to admin", ['user_id' => $userId]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        if ($user['is_admin']) {
            throw new InvalidArgumentException("User is already an admin");
        }

        $success = $this->userModel->promoteToAdmin($userId);

        if (!$success) {
            throw new RuntimeException("Failed to promote user to admin");
        }

        $this->logAdminAction('promote_to_admin', $userId, [
            'user_email' => $user['email']
        ]);

        return $this->userModel->getById($userId);
    }

    /**
     * Demote user from admin
     */
    public function demoteFromAdmin($userId) {
        Logger::info("Admin demoting user from admin", ['user_id' => $userId]);

        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        if (!$user['is_admin']) {
            throw new InvalidArgumentException("User is not an admin");
        }

        $success = $this->userModel->demoteFromAdmin($userId);

        if (!$success) {
            throw new RuntimeException("Failed to demote user from admin");
        }

        $this->logAdminAction('demote_from_admin', $userId, [
            'user_email' => $user['email']
        ]);

        return $this->userModel->getById($userId);
    }

    /**
     * Private helper methods
     */

    /**
     * Log admin action
     */
    private function logAdminAction($action, $targetUserId = null, $metadata = []) {
        Logger::info("Admin action performed", array_merge([
            'action' => $action,
            'target_user_id' => $targetUserId,
            'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'admin_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ], $metadata));
    }

    /**
     * Filter audit logs (placeholder implementation)
     */
    private function filterAuditLogs($logs, $filters) {
        if (empty($filters)) {
            return $logs;
        }

        return array_filter($logs, function($log) use ($filters) {
            // Simple filtering - in real implementation, parse log entries
            $logString = strtolower($log);

            if (isset($filters['level']) && !str_contains($logString, strtolower($filters['level']))) {
                return false;
            }

            if (isset($filters['action']) && !str_contains($logString, strtolower($filters['action']))) {
                return false;
            }

            return true;
        });
    }
}
?>