<?php
class Subscription {
    private $db;
    private $table_name = "subscriptions";

    public $id;
    public $user_id;
    public $tier;
    public $status;
    public $context_window_size;
    public $max_sessions;
    public $max_messages_per_session;
    public $max_models_per_prompt;
    public $features;
    public $limits;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("Subscription model initialized");
    }

    /**
     * Create a new subscription
     */
    public function create($subscriptionData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($subscriptionData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate tier
            if (!$this->validateTier($subscriptionData['tier'])) {
                throw new InvalidArgumentException("Invalid tier");
            }

            // Set defaults
            $insertData = [
                'user_id' => $subscriptionData['user_id'],
                'tier' => $subscriptionData['tier'] ?? 'free',
                'status' => $subscriptionData['status'] ?? 'active',
                'context_window_size' => $subscriptionData['context_window_size'] ?? 10,
                'max_sessions' => $subscriptionData['max_sessions'] ?? 1,
                'max_messages_per_session' => $subscriptionData['max_messages_per_session'] ?? 100,
                'max_models_per_prompt' => $subscriptionData['max_models_per_prompt'] ?? 1,
                'features' => isset($subscriptionData['features']) ? json_encode($subscriptionData['features']) : null,
                'limits' => isset($subscriptionData['limits']) ? json_encode($subscriptionData['limits']) : null
            ];

            Logger::debug("Creating subscription", ['data' => $insertData]);

            // Create subscription using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Subscription created successfully", [
                    'subscription_id' => $this->id,
                    'user_id' => $subscriptionData['user_id'],
                    'tier' => $subscriptionData['tier'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create subscription");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription creation failed", [
                'error' => $e->getMessage(),
                'user_id' => $subscriptionData['user_id'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get subscription by ID
     */
    public function getById($subscriptionId) {
        $startTime = microtime(true);

        try {
            $subscription = $this->db->readOne($this->table_name, ['id' => $subscriptionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($subscription) {
                // Decode JSON fields
                if ($subscription['features']) {
                    $subscription['features'] = json_decode($subscription['features'], true);
                }
                if ($subscription['limits']) {
                    $subscription['limits'] = json_decode($subscription['limits'], true);
                }

                Logger::debug("Subscription retrieved by ID", [
                    'subscription_id' => $subscriptionId,
                    'duration_ms' => $duration
                ]);
                return $subscription;
            }

            Logger::debug("Subscription not found by ID", [
                'subscription_id' => $subscriptionId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription by ID", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get active subscription by user ID
     */
    public function getActiveByUserId($userId) {
        $startTime = microtime(true);

        try {
            $subscription = $this->db->readOne($this->table_name, [
                'user_id' => $userId,
                'status' => 'active'
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($subscription) {
                // Decode JSON fields
                if ($subscription['features']) {
                    $subscription['features'] = json_decode($subscription['features'], true);
                }
                if ($subscription['limits']) {
                    $subscription['limits'] = json_decode($subscription['limits'], true);
                }

                Logger::debug("Active subscription retrieved for user", [
                    'user_id' => $userId,
                    'subscription_id' => $subscription['id'],
                    'tier' => $subscription['tier'],
                    'duration_ms' => $duration
                ]);
                return $subscription;
            }

            Logger::debug("No active subscription found for user", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get active subscription for user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get all subscriptions by user ID
     */
    public function getByUserId($userId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $subscriptions = $this->db->readMany(
                $this->table_name,
                ['user_id' => $userId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON fields for each subscription
            foreach ($subscriptions as &$subscription) {
                if ($subscription['features']) {
                    $subscription['features'] = json_decode($subscription['features'], true);
                }
                if ($subscription['limits']) {
                    $subscription['limits'] = json_decode($subscription['limits'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['user_id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved subscriptions for user", [
                'user_id' => $userId,
                'page' => $page,
                'limit' => $limit,
                'total_subscriptions' => $total,
                'returned_count' => count($subscriptions),
                'duration_ms' => $duration
            ]);

            return [
                'subscriptions' => $subscriptions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscriptions for user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update subscription
     */
    public function update($subscriptionId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = [
                'tier', 'status', 'context_window_size', 'max_sessions',
                'max_messages_per_session', 'max_models_per_prompt', 'features', 'limits'
            ];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if (in_array($field, ['features', 'limits'])) {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            // Validate tier if being updated
            if (isset($filteredData['tier']) && !$this->validateTier($filteredData['tier'])) {
                throw new InvalidArgumentException("Invalid tier");
            }

            // Add updated timestamp
            $filteredData['updated_at'] = date('Y-m-d H:i:s');

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $subscriptionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription updated successfully", [
                    'subscription_id' => $subscriptionId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($subscriptionId);
            }

            Logger::warning("No changes made to subscription", [
                'subscription_id' => $subscriptionId,
                'duration_ms' => $duration
            ]);
            return $this->getById($subscriptionId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription update failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Upgrade subscription tier
     */
    public function upgradeTier($userId, $newTier) {
        $startTime = microtime(true);

        try {
            if (!$this->validateTier($newTier)) {
                throw new InvalidArgumentException("Invalid tier: {$newTier}");
            }

            // Get current active subscription
            $currentSubscription = $this->getActiveByUserId($userId);
            if (!$currentSubscription) {
                throw new InvalidArgumentException("No active subscription found for user");
            }

            $updateData = [
                'tier' => $newTier,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, [
                'id' => $currentSubscription['id']
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription tier upgraded successfully", [
                    'user_id' => $userId,
                    'old_tier' => $currentSubscription['tier'],
                    'new_tier' => $newTier,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Subscription tier upgrade failed", [
                'user_id' => $userId,
                'new_tier' => $newTier,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription tier upgrade failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'new_tier' => $newTier,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel($userId) {
        $startTime = microtime(true);

        try {
            // Get current active subscription
            $currentSubscription = $this->getActiveByUserId($userId);
            if (!$currentSubscription) {
                throw new InvalidArgumentException("No active subscription found for user");
            }

            $updateData = [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, [
                'id' => $currentSubscription['id']
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription cancelled successfully", [
                    'user_id' => $userId,
                    'subscription_id' => $currentSubscription['id'],
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Subscription cancellation failed", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription cancellation failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get subscription statistics
     */
    public function getStats() {
        $startTime = microtime(true);

        try {
            $sql = "SELECT
                        COUNT(*) as total_subscriptions,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_subscriptions,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_subscriptions,
                        SUM(CASE WHEN tier = 'free' THEN 1 ELSE 0 END) as free_subscriptions,
                        SUM(CASE WHEN tier = 'pro' THEN 1 ELSE 0 END) as pro_subscriptions,
                        SUM(CASE WHEN tier = 'enterprise' THEN 1 ELSE 0 END) as enterprise_subscriptions
                    FROM {$this->table_name}";

            $stats = $this->db->query($sql);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Subscription statistics retrieved", [
                'duration_ms' => $duration
            ]);

            return $stats[0] ?? [];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription statistics", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['user_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    private function validateTier($tier) {
        $validTiers = ['free', 'pro', 'enterprise'];
        return in_array($tier, $validTiers);
    }
}
?>