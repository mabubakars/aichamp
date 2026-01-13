<?php
class PaymentMethod {
    private $db;
    private $table_name = "payment_methods";

    public $id;
    public $user_id;
    public $gateway_id;
    public $gateway_payment_method_id;
    public $type;
    public $last4;
    public $brand;
    public $expiry_month;
    public $expiry_year;
    public $is_default;
    public $metadata;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("PaymentMethod model initialized");
    }

    /**
     * Create a new payment method
     */
    public function create($methodData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($methodData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Set defaults
            $insertData = [
                'user_id' => $methodData['user_id'],
                'gateway_id' => $methodData['gateway_id'],
                'gateway_payment_method_id' => $methodData['gateway_payment_method_id'],
                'type' => $methodData['type'] ?? 'card',
                'last4' => $methodData['last4'] ?? null,
                'brand' => $methodData['brand'] ?? null,
                'expiry_month' => $methodData['expiry_month'] ?? null,
                'expiry_year' => $methodData['expiry_year'] ?? null,
                'is_default' => $methodData['is_default'] ?? false,
                'metadata' => json_encode($methodData['metadata'] ?? [])
            ];

            Logger::debug("Creating payment method", ['data' => $insertData]);

            // Create payment method using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Payment method created successfully", [
                    'method_id' => $this->id,
                    'user_id' => $methodData['user_id'],
                    'gateway_payment_method_id' => $methodData['gateway_payment_method_id'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create payment method");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment method creation failed", [
                'error' => $e->getMessage(),
                'user_id' => $methodData['user_id'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get payment method by ID
     */
    public function getById($methodId) {
        $startTime = microtime(true);

        try {
            $method = $this->db->readOne($this->table_name, ['id' => $methodId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($method) {
                // Decode JSON metadata
                if ($method['metadata']) {
                    $method['metadata'] = json_decode($method['metadata'], true);
                }

                Logger::debug("Payment method retrieved by ID", [
                    'method_id' => $methodId,
                    'duration_ms' => $duration
                ]);
                return $method;
            }

            Logger::debug("Payment method not found by ID", [
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment method by ID", [
                'error' => $e->getMessage(),
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get payment methods by user ID
     */
    public function getByUserId($userId) {
        $startTime = microtime(true);

        try {
            $methods = $this->db->readMany(
                $this->table_name,
                ['user_id' => $userId],
                '*',
                'is_default DESC, created_at DESC'
            );

            // Decode JSON metadata for each method
            foreach ($methods as &$method) {
                if ($method['metadata']) {
                    $method['metadata'] = json_decode($method['metadata'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::debug("Payment methods retrieved for user", [
                'user_id' => $userId,
                'count' => count($methods),
                'duration_ms' => $duration
            ]);

            return $methods;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment methods for user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get default payment method for user
     */
    public function getDefaultByUserId($userId) {
        $startTime = microtime(true);

        try {
            $method = $this->db->readOne($this->table_name, [
                'user_id' => $userId,
                'is_default' => true
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($method) {
                // Decode JSON metadata
                if ($method['metadata']) {
                    $method['metadata'] = json_decode($method['metadata'], true);
                }

                Logger::debug("Default payment method retrieved for user", [
                    'user_id' => $userId,
                    'method_id' => $method['id'],
                    'duration_ms' => $duration
                ]);
                return $method;
            }

            Logger::debug("No default payment method found for user", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get default payment method for user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update payment method
     */
    public function update($methodId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = ['type', 'last4', 'brand', 'expiry_month', 'expiry_year', 'is_default', 'metadata'];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'metadata') {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $methodId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment method updated successfully", [
                    'method_id' => $methodId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($methodId);
            }

            Logger::warning("No changes made to payment method", [
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            return $this->getById($methodId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment method update failed", [
                'error' => $e->getMessage(),
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Set as default payment method
     */
    public function setAsDefault($methodId, $userId) {
        $startTime = microtime(true);

        try {
            // Start transaction
            $this->db->beginTransaction();

            // Unset all default methods for this user
            $this->db->update($this->table_name, ['is_default' => false], ['user_id' => $userId]);

            // Set this method as default
            $affectedRows = $this->db->update($this->table_name, ['is_default' => true], ['id' => $methodId]);

            $this->db->commit();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment method set as default", [
                    'method_id' => $methodId,
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Failed to set payment method as default", [
                'method_id' => $methodId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $this->db->rollback();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to set payment method as default", [
                'error' => $e->getMessage(),
                'method_id' => $methodId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Delete payment method
     */
    public function delete($methodId) {
        $startTime = microtime(true);

        try {
            $affectedRows = $this->db->delete($this->table_name, ['id' => $methodId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment method deleted successfully", [
                    'method_id' => $methodId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Payment method deletion failed", [
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment method deletion failed", [
                'error' => $e->getMessage(),
                'method_id' => $methodId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get payment method by gateway payment method ID
     */
    public function getByGatewayMethodId($gatewayId, $gatewayMethodId) {
        $startTime = microtime(true);

        try {
            $method = $this->db->readOne($this->table_name, [
                'gateway_id' => $gatewayId,
                'gateway_payment_method_id' => $gatewayMethodId
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($method) {
                // Decode JSON metadata
                if ($method['metadata']) {
                    $method['metadata'] = json_decode($method['metadata'], true);
                }

                Logger::debug("Payment method retrieved by gateway method ID", [
                    'gateway_id' => $gatewayId,
                    'gateway_method_id' => $gatewayMethodId,
                    'method_id' => $method['id'],
                    'duration_ms' => $duration
                ]);
                return $method;
            }

            Logger::debug("Payment method not found by gateway method ID", [
                'gateway_id' => $gatewayId,
                'gateway_method_id' => $gatewayMethodId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment method by gateway method ID", [
                'error' => $e->getMessage(),
                'gateway_id' => $gatewayId,
                'gateway_method_id' => $gatewayMethodId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['user_id', 'gateway_id', 'gateway_payment_method_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
}
?>