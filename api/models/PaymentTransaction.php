<?php
class PaymentTransaction {
    private $db;
    private $table_name = "payment_transactions";

    public $id;
    public $user_id;
    public $organization_id;
    public $gateway_id;
    public $gateway_transaction_id;
    public $amount;
    public $currency;
    public $status;
    public $payment_method;
    public $metadata;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("PaymentTransaction model initialized");
    }

    /**
     * Create a new payment transaction
     */
    public function create($transactionData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($transactionData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate amount
            if (!$this->validateAmount($transactionData['amount'])) {
                throw new InvalidArgumentException("Invalid amount");
            }

            // Validate status
            if (!$this->validateStatus($transactionData['status'])) {
                throw new InvalidArgumentException("Invalid status");
            }

            // Set defaults
            $insertData = [
                'user_id' => $transactionData['user_id'] ?? null,
                'organization_id' => $transactionData['organization_id'] ?? null,
                'gateway_id' => $transactionData['gateway_id'],
                'gateway_transaction_id' => $transactionData['gateway_transaction_id'] ?? null,
                'amount' => $transactionData['amount'],
                'currency' => $transactionData['currency'] ?? 'USD',
                'status' => $transactionData['status'],
                'payment_method' => $transactionData['payment_method'] ?? null,
                'metadata' => isset($transactionData['metadata']) ? json_encode($transactionData['metadata']) : null
            ];

            Logger::debug("Creating payment transaction", ['data' => $insertData]);

            // Create transaction using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Payment transaction created successfully", [
                    'transaction_id' => $this->id,
                    'user_id' => $transactionData['user_id'],
                    'gateway_id' => $transactionData['gateway_id'],
                    'amount' => $transactionData['amount'],
                    'currency' => $transactionData['currency'],
                    'status' => $transactionData['status'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create payment transaction");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Payment transaction creation failed", [
                'error' => $e->getMessage(),
                'user_id' => $transactionData['user_id'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transaction by ID
     */
    public function getById($transactionId) {
        $startTime = microtime(true);

        try {
            $transaction = $this->db->readOne($this->table_name, ['id' => $transactionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($transaction) {
                // Decode JSON metadata
                if ($transaction['metadata']) {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                }

                Logger::debug("Payment transaction retrieved by ID", [
                    'transaction_id' => $transactionId,
                    'duration_ms' => $duration
                ]);
                return $transaction;
            }

            Logger::debug("Payment transaction not found by ID", [
                'transaction_id' => $transactionId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment transaction by ID", [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transaction by gateway transaction ID
     */
    public function getByGatewayTransactionId($gatewayTransactionId) {
        $startTime = microtime(true);

        try {
            $transaction = $this->db->readOne($this->table_name, ['gateway_transaction_id' => $gatewayTransactionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($transaction) {
                // Decode JSON metadata
                if ($transaction['metadata']) {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                }

                Logger::debug("Payment transaction retrieved by gateway transaction ID", [
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'duration_ms' => $duration
                ]);
                return $transaction;
            }

            Logger::debug("Payment transaction not found by gateway transaction ID", [
                'gateway_transaction_id' => $gatewayTransactionId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get payment transaction by gateway transaction ID", [
                'error' => $e->getMessage(),
                'gateway_transaction_id' => $gatewayTransactionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transactions by user ID
     */
    public function getByUserId($userId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $transactions = $this->db->readMany(
                $this->table_name,
                ['user_id' => $userId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON metadata for each transaction
            foreach ($transactions as &$transaction) {
                if ($transaction['metadata']) {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['user_id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved transactions for user", [
                'user_id' => $userId,
                'page' => $page,
                'limit' => $limit,
                'total_transactions' => $total,
                'returned_count' => count($transactions),
                'duration_ms' => $duration
            ]);

            return [
                'transactions' => $transactions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get transactions for user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transactions by organization ID
     */
    public function getByOrganizationId($organizationId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $transactions = $this->db->readMany(
                $this->table_name,
                ['organization_id' => $organizationId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON metadata for each transaction
            foreach ($transactions as &$transaction) {
                if ($transaction['metadata']) {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['organization_id' => $organizationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved transactions for organization", [
                'organization_id' => $organizationId,
                'page' => $page,
                'limit' => $limit,
                'total_transactions' => $total,
                'returned_count' => count($transactions),
                'duration_ms' => $duration
            ]);

            return [
                'transactions' => $transactions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get transactions for organization", [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transactions by status
     */
    public function getByStatus($status, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $transactions = $this->db->readMany(
                $this->table_name,
                ['status' => $status],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON metadata for each transaction
            foreach ($transactions as &$transaction) {
                if ($transaction['metadata']) {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                }
            }

            // Get total count
            $total = $this->db->count($this->table_name, ['status' => $status]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved transactions by status", [
                'status' => $status,
                'page' => $page,
                'limit' => $limit,
                'total_transactions' => $total,
                'returned_count' => count($transactions),
                'duration_ms' => $duration
            ]);

            return [
                'transactions' => $transactions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get transactions by status", [
                'error' => $e->getMessage(),
                'status' => $status,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update transaction status
     */
    public function updateStatus($transactionId, $status, $metadata = null) {
        $startTime = microtime(true);

        try {
            if (!$this->validateStatus($status)) {
                throw new InvalidArgumentException("Invalid status: {$status}");
            }

            $updateData = ['status' => $status];

            if ($metadata !== null) {
                $updateData['metadata'] = json_encode($metadata);
            }

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $transactionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Transaction status updated successfully", [
                    'transaction_id' => $transactionId,
                    'new_status' => $status,
                    'duration_ms' => $duration
                ]);

                return $this->getById($transactionId);
            }

            Logger::warning("No changes made to transaction status", [
                'transaction_id' => $transactionId,
                'duration_ms' => $duration
            ]);
            return $this->getById($transactionId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Transaction status update failed", [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'status' => $status,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update transaction
     */
    public function update($transactionId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = [
                'gateway_transaction_id', 'amount', 'currency', 'status',
                'payment_method', 'metadata'
            ];

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

            // Validate amount if being updated
            if (isset($filteredData['amount']) && !$this->validateAmount($filteredData['amount'])) {
                throw new InvalidArgumentException("Invalid amount");
            }

            // Validate status if being updated
            if (isset($filteredData['status']) && !$this->validateStatus($filteredData['status'])) {
                throw new InvalidArgumentException("Invalid status");
            }

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $transactionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Transaction updated successfully", [
                    'transaction_id' => $transactionId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($transactionId);
            }

            Logger::warning("No changes made to transaction", [
                'transaction_id' => $transactionId,
                'duration_ms' => $duration
            ]);
            return $this->getById($transactionId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Transaction update failed", [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get transaction statistics
     */
    public function getStats() {
        $startTime = microtime(true);

        try {
            $sql = "SELECT
                        COUNT(*) as total_transactions,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_transactions,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                        AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as average_transaction_amount
                    FROM {$this->table_name}";

            $stats = $this->db->query($sql);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Transaction statistics retrieved", [
                'duration_ms' => $duration
            ]);

            return $stats[0] ?? [];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get transaction statistics", [
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
        $required = ['gateway_id', 'amount', 'status'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }
        return true;
    }

    private function validateAmount($amount) {
        return is_numeric($amount) && $amount >= 0;
    }

    private function validateStatus($status) {
        $validStatuses = [
            'pending', 'processing', 'completed', 'failed', 'cancelled',
            'refunded', 'partially_refunded', 'disputed'
        ];
        return in_array($status, $validStatuses);
    }
}
?>