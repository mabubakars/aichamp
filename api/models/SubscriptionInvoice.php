<?php
class SubscriptionInvoice {
    private $db;
    private $table_name = "subscription_invoices";

    public $id;
    public $subscription_id;
    public $amount;
    public $currency;
    public $status;
    public $due_date;
    public $paid_at;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("SubscriptionInvoice model initialized");
    }

    /**
     * Create a new invoice
     */
    public function create($invoiceData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($invoiceData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate amount
            if (!$this->validateAmount($invoiceData['amount'])) {
                throw new InvalidArgumentException("Invalid amount");
            }

            // Set defaults
            $insertData = [
                'subscription_id' => $invoiceData['subscription_id'],
                'amount' => $invoiceData['amount'],
                'currency' => $invoiceData['currency'] ?? 'USD',
                'status' => $invoiceData['status'] ?? 'pending',
                'due_date' => $invoiceData['due_date'] ?? null,
                'paid_at' => null
            ];

            Logger::debug("Creating subscription invoice", ['data' => $insertData]);

            // Create invoice using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Subscription invoice created successfully", [
                    'invoice_id' => $this->id,
                    'subscription_id' => $invoiceData['subscription_id'],
                    'amount' => $invoiceData['amount'],
                    'currency' => $invoiceData['currency'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create subscription invoice");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription invoice creation failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $invoiceData['subscription_id'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get invoice by ID
     */
    public function getById($invoiceId) {
        $startTime = microtime(true);

        try {
            $invoice = $this->db->readOne($this->table_name, ['id' => $invoiceId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($invoice) {
                Logger::debug("Subscription invoice retrieved by ID", [
                    'invoice_id' => $invoiceId,
                    'duration_ms' => $duration
                ]);
                return $invoice;
            }

            Logger::debug("Subscription invoice not found by ID", [
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription invoice by ID", [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get invoices by subscription ID
     */
    public function getBySubscriptionId($subscriptionId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $invoices = $this->db->readMany(
                $this->table_name,
                ['subscription_id' => $subscriptionId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Get total count
            $total = $this->db->count($this->table_name, ['subscription_id' => $subscriptionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved invoices for subscription", [
                'subscription_id' => $subscriptionId,
                'page' => $page,
                'limit' => $limit,
                'total_invoices' => $total,
                'returned_count' => count($invoices),
                'duration_ms' => $duration
            ]);

            return [
                'invoices' => $invoices,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get invoices for subscription", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get pending invoices
     */
    public function getPendingInvoices($page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $invoices = $this->db->readMany(
                $this->table_name,
                ['status' => 'pending'],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Get total count
            $total = $this->db->count($this->table_name, ['status' => 'pending']);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved pending invoices", [
                'page' => $page,
                'limit' => $limit,
                'total_invoices' => $total,
                'returned_count' => count($invoices),
                'duration_ms' => $duration
            ]);

            return [
                'invoices' => $invoices,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get pending invoices", [
                'error' => $e->getMessage(),
                'page' => $page,
                'limit' => $limit,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get overdue invoices
     */
    public function getOverdueInvoices($page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $sql = "SELECT * FROM {$this->table_name}
                    WHERE status = 'pending'
                    AND due_date IS NOT NULL
                    AND due_date < NOW()
                    ORDER BY due_date ASC
                    LIMIT ?, ?";

            $invoices = $this->db->query($sql, [$offset, $limit]);

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM {$this->table_name}
                        WHERE status = 'pending'
                        AND due_date IS NOT NULL
                        AND due_date < NOW()";

            $countResult = $this->db->query($countSql);
            $total = $countResult[0]['total'] ?? 0;

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved overdue invoices", [
                'page' => $page,
                'limit' => $limit,
                'total_invoices' => $total,
                'returned_count' => count($invoices),
                'duration_ms' => $duration
            ]);

            return [
                'invoices' => $invoices,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get overdue invoices", [
                'error' => $e->getMessage(),
                'page' => $page,
                'limit' => $limit,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update invoice status
     */
    public function updateStatus($invoiceId, $status) {
        $startTime = microtime(true);

        try {
            $validStatuses = ['pending', 'paid', 'failed', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                throw new InvalidArgumentException("Invalid status: {$status}");
            }

            $updateData = ['status' => $status];

            // Set paid_at timestamp if status is 'paid'
            if ($status === 'paid') {
                $updateData['paid_at'] = date('Y-m-d H:i:s');
            }

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $invoiceId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Invoice status updated successfully", [
                    'invoice_id' => $invoiceId,
                    'new_status' => $status,
                    'duration_ms' => $duration
                ]);

                return $this->getById($invoiceId);
            }

            Logger::warning("No changes made to invoice status", [
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration
            ]);
            return $this->getById($invoiceId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Invoice status update failed", [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
                'status' => $status,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid($invoiceId) {
        return $this->updateStatus($invoiceId, 'paid');
    }

    /**
     * Update invoice
     */
    public function update($invoiceId, $updateData) {
        $startTime = microtime(true);

        try {
            $filteredData = [];

            // Allowed fields for update
            $allowedFields = ['amount', 'currency', 'status', 'due_date', 'paid_at'];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    $filteredData[$field] = $updateData[$field];
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            // Validate amount if being updated
            if (isset($filteredData['amount']) && !$this->validateAmount($filteredData['amount'])) {
                throw new InvalidArgumentException("Invalid amount");
            }

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $invoiceId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Invoice updated successfully", [
                    'invoice_id' => $invoiceId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($invoiceId);
            }

            Logger::warning("No changes made to invoice", [
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration
            ]);
            return $this->getById($invoiceId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Invoice update failed", [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get invoice statistics
     */
    public function getStats() {
        $startTime = microtime(true);

        try {
            $sql = "SELECT
                        COUNT(*) as total_invoices,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_invoices,
                        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                        AVG(CASE WHEN status = 'paid' THEN amount ELSE NULL END) as average_invoice_amount
                    FROM {$this->table_name}";

            $stats = $this->db->query($sql);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Invoice statistics retrieved", [
                'duration_ms' => $duration
            ]);

            return $stats[0] ?? [];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get invoice statistics", [
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
        $required = ['subscription_id', 'amount'];
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
}
?>