<?php
class SubscriptionPlan {
    private $db;
    private $table_name = "subscription_plans";

    public $id;
    public $name;
    public $description;
    public $plan_type;
    public $price_monthly;
    public $price_yearly;
    public $currency;
    public $features;
    public $is_active;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("SubscriptionPlan model initialized");
    }

    /**
     * Create a new subscription plan
     */
    public function create($planData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($planData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Set defaults
            $insertData = [
                'name' => $planData['name'],
                'description' => $planData['description'] ?? null,
                'plan_type' => $planData['plan_type'],
                'price_monthly' => $planData['price_monthly'] ?? null,
                'price_yearly' => $planData['price_yearly'] ?? null,
                'currency' => $planData['currency'] ?? 'USD',
                'features' => isset($planData['features']) ? json_encode($planData['features']) : null,
                'is_active' => $planData['is_active'] ?? true
            ];

            Logger::debug("Creating subscription plan", ['data' => $insertData]);

            // Create plan using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Subscription plan created successfully", [
                    'plan_id' => $this->id,
                    'name' => $planData['name'],
                    'plan_type' => $planData['plan_type'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create subscription plan");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription plan creation failed", [
                'error' => $e->getMessage(),
                'name' => $planData['name'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get plan by ID
     */
    public function getById($planId) {
        $startTime = microtime(true);

        try {
            $plan = $this->db->readOne($this->table_name, ['id' => $planId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($plan) {
                // Decode JSON fields
                if ($plan['features']) {
                    $plan['features'] = json_decode($plan['features'], true);
                }

                Logger::debug("Subscription plan retrieved by ID", [
                    'plan_id' => $planId,
                    'duration_ms' => $duration
                ]);
                return $plan;
            }

            Logger::debug("Subscription plan not found by ID", [
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription plan by ID", [
                'error' => $e->getMessage(),
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get all active plans
     */
    public function getAllActive($page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $plans = $this->db->readMany(
                $this->table_name,
                ['is_active' => true],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON fields for each plan
            foreach ($plans as &$plan) {
                if ($plan['features']) {
                    $plan['features'] = json_decode($plan['features'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['is_active' => true]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved active subscription plans", [
                'page' => $page,
                'limit' => $limit,
                'total_plans' => $total,
                'returned_count' => count($plans),
                'duration_ms' => $duration
            ]);

            return [
                'plans' => $plans,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get active subscription plans", [
                'error' => $e->getMessage(),
                'page' => $page,
                'limit' => $limit,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get plans by type
     */
    public function getByType($planType, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $plans = $this->db->readMany(
                $this->table_name,
                ['plan_type' => $planType, 'is_active' => true],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON fields for each plan
            foreach ($plans as &$plan) {
                if ($plan['features']) {
                    $plan['features'] = json_decode($plan['features'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['plan_type' => $planType, 'is_active' => true]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved subscription plans by type", [
                'plan_type' => $planType,
                'page' => $page,
                'limit' => $limit,
                'total_plans' => $total,
                'returned_count' => count($plans),
                'duration_ms' => $duration
            ]);

            return [
                'plans' => $plans,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription plans by type", [
                'error' => $e->getMessage(),
                'plan_type' => $planType,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update subscription plan
     */
    public function update($planId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = [
                'name', 'description', 'plan_type', 'price_monthly',
                'price_yearly', 'currency', 'features', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'features') {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            // Add updated timestamp (assuming the table has updated_at, but schema doesn't show it)
            // For now, we'll skip the updated_at as it's not in the schema

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $planId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription plan updated successfully", [
                    'plan_id' => $planId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($planId);
            }

            Logger::warning("No changes made to subscription plan", [
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            return $this->getById($planId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription plan update failed", [
                'error' => $e->getMessage(),
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Deactivate plan
     */
    public function deactivate($planId) {
        $startTime = microtime(true);

        try {
            $updateData = ['is_active' => false];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $planId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription plan deactivated successfully", [
                    'plan_id' => $planId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Subscription plan deactivation failed", [
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription plan deactivation failed", [
                'error' => $e->getMessage(),
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Activate plan
     */
    public function activate($planId) {
        $startTime = microtime(true);

        try {
            $updateData = ['is_active' => true];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $planId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Subscription plan activated successfully", [
                    'plan_id' => $planId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Subscription plan activation failed", [
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription plan activation failed", [
                'error' => $e->getMessage(),
                'plan_id' => $planId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['name', 'plan_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
}
?>