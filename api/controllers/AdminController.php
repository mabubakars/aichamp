<?php
class AdminController extends BaseController {
    private $adminService;

    public function __construct($db) {
        parent::__construct($db);
        $this->adminService = new AdminService($db);
    }

    /**
     * Check if user is admin
     */
    private function checkAdminAccess($user) {
        // Check if user has admin privileges
        $user = $this->user->getById($user['user_id']);

        if (!$user || !$user['is_active']) {
            Logger::warning("Admin access denied - user not found or inactive", [
                'user_id' => $user['user_id']
            ]);
            return false;
        }

        $isAdmin = (bool)$user['is_admin'];

        if (!$isAdmin) {
            Logger::warning("Admin access denied - user is not admin", [
                'user_id' => $user['user_id'],
                'email' => $user['email']
            ]);
        }

        return $isAdmin;
    }

    /**
     * User Management Endpoints
     */

    /**
     * Get all users
     */
    public function getUsers() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $page = (int)($this->request->getQuery('page', 1));
            $limit = (int)($this->request->getQuery('limit', 50));
            $filters = [];

            // Apply filters if provided
            if ($this->request->getQuery('is_active') !== null) {
                $filters['is_active'] = (bool)$this->request->getQuery('is_active');
            }

            if ($this->request->getQuery('email_verified') !== null) {
                $filters['email_verified'] = (bool)$this->request->getQuery('email_verified');
            }

            return $this->handleServiceCall(function() use ($page, $limit, $filters) {
                return $this->adminService->getAllUsers($page, $limit, $filters);
            }, "Users retrieved successfully.", 'USERS_RETRIEVAL_FAILED');

    }

    /**
     * Get user by ID
     */
    public function getUser($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() use ($userId) {
                return $this->adminService->getUserById($userId);
            }, "User details retrieved successfully.", 'USER_RETRIEVAL_FAILED');

    }

    /**
     * Update user
     */
    public function updateUser($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($userId, $data) {
                return $this->adminService->updateUser($userId, $data);
            }, "User updated successfully.", 'USER_UPDATE_FAILED');

    }

    /**
     * Activate user
     */
    public function activateUser($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() use ($userId) {
                return $this->adminService->activateUser($userId);
            }, "User activated successfully.", 'USER_ACTIVATION_FAILED');

    }

    /**
     * Deactivate user
     */
    public function deactivateUser($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() use ($userId) {
                return $this->adminService->deactivateUser($userId);
            }, "User deactivated successfully.", 'USER_DEACTIVATION_FAILED');

    }

    /**
     * Search users
     */
    public function searchUsers() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $query = $this->request->getQuery('q', '');
            $page = (int)($this->request->getQuery('page', 1));
            $limit = (int)($this->request->getQuery('limit', 50));

            if (empty($query)) {
                return $this->error("Search query is required.", 400, 'SEARCH_QUERY_REQUIRED');
            }

            return $this->handleServiceCall(function() use ($query, $page, $limit) {
                return $this->adminService->searchUsers($query, $page, $limit);
            }, "User search completed successfully.", 'USER_SEARCH_FAILED');

    }

    /**
     * System Monitoring Endpoints
     */

    /**
     * Get system health
     */
    public function getSystemHealth() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() {
                return $this->adminService->getSystemHealth();
            }, "System health retrieved successfully.", 'SYSTEM_HEALTH_FAILED');

    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() {
                return $this->adminService->getDatabaseStats();
            }, "Database statistics retrieved successfully.", 'DATABASE_STATS_FAILED');

    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $hours = (int)($this->request->getQuery('hours', 24));

            return $this->handleServiceCall(function() use ($hours) {
                return $this->adminService->getPerformanceMetrics($hours);
            }, "Performance metrics retrieved successfully.", 'PERFORMANCE_METRICS_FAILED');

    }

    /**
     * Audit Log Endpoints
     */

    /**
     * Get audit logs
     */
    public function getAuditLogs() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $page = (int)($this->request->getQuery('page', 1));
            $limit = (int)($this->request->getQuery('limit', 50));
            $filters = [];

            // Apply filters if provided
            if ($this->request->getQuery('level')) {
                $filters['level'] = $this->request->getQuery('level');
            }

            if ($this->request->getQuery('action')) {
                $filters['action'] = $this->request->getQuery('action');
            }

            return $this->handleServiceCall(function() use ($page, $limit, $filters) {
                return $this->adminService->getAuditLogs($page, $limit, $filters);
            }, "Audit logs retrieved successfully.", 'AUDIT_LOGS_FAILED');

    }

    /**
     * Get user audit logs
     */
    public function getUserAuditLogs($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $page = (int)($this->request->getQuery('page', 1));
            $limit = (int)($this->request->getQuery('limit', 50));

            return $this->handleServiceCall(function() use ($userId, $page, $limit) {
                return $this->adminService->getUserAuditLogs($userId, $page, $limit);
            }, "User audit logs retrieved successfully.", 'USER_AUDIT_LOGS_FAILED');

    }

    /**
     * Background Job Endpoints
     */

    /**
     * Get background jobs status
     */
    public function getBackgroundJobs() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() {
                return $this->adminService->getBackgroundJobs();
            }, "Background jobs status retrieved successfully.", 'BACKGROUND_JOBS_FAILED');

    }

    /**
     * Trigger background job
     */
    public function triggerJob() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $data = $this->getJsonInput();

            if (!isset($data['job_name'])) {
                return $this->error("Job name is required.", 400, 'JOB_NAME_REQUIRED');
            }

            return $this->handleServiceCall(function() use ($data) {
                return $this->adminService->triggerJob($data['job_name']);
            }, "Background job triggered successfully.", 'JOB_TRIGGER_FAILED');

    }

    /**
     * Performance Metrics Endpoints
     */

    /**
     * Get comprehensive system metrics
     */
    public function getSystemMetrics() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            $hours = (int)($this->request->getQuery('hours', 24));

            return $this->handleServiceCall(function() use ($hours) {
                return $this->adminService->getSystemMetrics($hours);
            }, "System metrics retrieved successfully.", 'SYSTEM_METRICS_FAILED');

    }

    /**
     * Get user statistics
     */
    public function getUserStats() {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() {
                return $this->adminService->getUserStats();
            }, "User statistics retrieved successfully.", 'USER_STATS_FAILED');

    }

    /**
     * Promote user to admin
     */
    public function promoteToAdmin($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() use ($userId) {
                return $this->adminService->promoteToAdmin($userId);
            }, "User promoted to admin successfully.", 'PROMOTE_ADMIN_FAILED');

    }

    /**
     * Demote user from admin
     */
    public function demoteFromAdmin($userId) {
        $user = $this->getAuthenticatedUser();
            $this->ensureAdminAccess($user);

            return $this->handleServiceCall(function() use ($userId) {
                return $this->adminService->demoteFromAdmin($userId);
            }, "User demoted from admin successfully.", 'DEMOTE_ADMIN_FAILED');

    }

    /**
     * Helper method to ensure admin access
     */
    private function ensureAdminAccess($user) {
        if (!$this->checkAdminAccess($user)) {
            Logger::warning("Unauthorized admin access attempt", [
                'user_id' => $user['user_id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            $this->error("Access denied. Admin privileges required.", 403, 'ACCESS_DENIED')->send();
            exit;
        }
    }
}
?>