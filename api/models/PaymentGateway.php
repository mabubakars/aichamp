<?php
class PaymentGateway {
    private $db;
    private $table_name = "payment_gateways";

    public $id;
    public $name;
    public $gateway_key;
    public $is_active;
    public $config;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("PaymentGateway model initialized");
    }

    /**
     * Create a new payment gateway
     */
    public function create($gatewayData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($gatewayData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Check if gateway_key already exists
            if ($this->keyExists($gatewayData['gateway_key'])) {
                throw new InvalidArgumentException("Gateway key already exists");
            }

            // Set defaults
            $insertData = [
                'name' => $gatewayData['name'],
                'gateway_key' => $gatewayData['gateway_key'],
                'is_active' => $gatewayData['is_active'] ?? true,
                'config' => json_encode($gatewayData['config'])
            ];

            Logger::debug("Creating payment gateway", ['data' => $insertData]);

            // Create gateway using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Payment gateway created successfully", [
                    'gateway_id' => $this->id,
                    'name' => $gatewayData['name'],
                    'gateway_key' => $gatewayData['gateway_key'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create payment gateway");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment gateway creation failed", [
                'error' => $e->getMessage(),
                'name' => $gatewayData['name'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get gateway by ID
     */
    public function getById($gatewayId) {
        $startTime = microtime(true);

        try {
            $gateway = $this->db->readOne($this->table_name, ['id' => $gatewayId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($gateway) {
                // Decode JSON config
                if ($gateway['config']) {
                    $gateway['config'] = json_decode($gateway['config'], true);
                }

                Logger::debug("Payment gateway retrieved by ID", [
                    'gateway_id' => $gatewayId,
                    'duration_ms' => $duration
                ]);
                return $gateway;
            }

            Logger::debug("Payment gateway not found by ID", [
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment gateway by ID", [
                'error' => $e->getMessage(),
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get gateway by key
     */
    public function getByKey($gatewayKey) {
        $startTime = microtime(true);

        try {
            $gateway = $this->db->readOne($this->table_name, ['gateway_key' => $gatewayKey]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($gateway) {
                // Decode JSON config
                if ($gateway['config']) {
                    $gateway['config'] = json_decode($gateway['config'], true);
                }

                Logger::debug("Payment gateway retrieved by key", [
                    'gateway_key' => $gatewayKey,
                    'duration_ms' => $duration
                ]);
                return $gateway;
            }

            Logger::debug("Payment gateway not found by key", [
                'gateway_key' => $gatewayKey,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment gateway by key", [
                'error' => $e->getMessage(),
                'gateway_key' => $gatewayKey,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get all active gateways
     */
    public function getAllActive() {
        $startTime = microtime(true);

        try {
            $gateways = $this->db->readMany(
                $this->table_name,
                ['is_active' => true],
                '*',
                'created_at DESC'
            );

            // Decode JSON config for each gateway
            foreach ($gateways as &$gateway) {
                if ($gateway['config']) {
                    $gateway['config'] = json_decode($gateway['config'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved active payment gateways", [
                'count' => count($gateways),
                'duration_ms' => $duration
            ]);

            return $gateways;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get active payment gateways", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update payment gateway
     */
    public function update($gatewayId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = ['name', 'gateway_key', 'is_active', 'config'];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'config') {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            // Check if gateway_key is being updated and already exists
            if (isset($filteredData['gateway_key'])) {
                $existing = $this->db->readOne($this->table_name, [
                    'gateway_key' => $filteredData['gateway_key'],
                    'id:!=' => $gatewayId
                ], 'id');
                if ($existing) {
                    throw new InvalidArgumentException("Gateway key already exists");
                }
            }

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $gatewayId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment gateway updated successfully", [
                    'gateway_id' => $gatewayId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($gatewayId);
            }

            Logger::warning("No changes made to payment gateway", [
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            return $this->getById($gatewayId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment gateway update failed", [
                'error' => $e->getMessage(),
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update gateway config
     */
    public function updateConfig($gatewayId, $config) {
        return $this->update($gatewayId, ['config' => $config]);
    }

    /**
     * Deactivate gateway
     */
    public function deactivate($gatewayId) {
        $startTime = microtime(true);

        try {
            $updateData = ['is_active' => false];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $gatewayId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment gateway deactivated successfully", [
                    'gateway_id' => $gatewayId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Payment gateway deactivation failed", [
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment gateway deactivation failed", [
                'error' => $e->getMessage(),
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Activate gateway
     */
    public function activate($gatewayId) {
        $startTime = microtime(true);

        try {
            $updateData = ['is_active' => true];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $gatewayId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Payment gateway activated successfully", [
                    'gateway_id' => $gatewayId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Payment gateway activation failed", [
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment gateway activation failed", [
                'error' => $e->getMessage(),
                'gateway_id' => $gatewayId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if gateway key exists
     */
    public function keyExists($gatewayKey) {
        $startTime = microtime(true);

        try {
            $gateway = $this->db->readOne($this->table_name, ['gateway_key' => $gatewayKey], 'id');

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $exists = !empty($gateway);

            Logger::debug("Gateway key existence check", [
                'gateway_key' => $gatewayKey,
                'exists' => $exists,
                'duration_ms' => $duration
            ]);

            return $exists;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Gateway key existence check failed", [
                'error' => $e->getMessage(),
                'gateway_key' => $gatewayKey,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['name', 'gateway_key', 'config'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
}
?>