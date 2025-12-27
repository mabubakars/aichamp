<?php
class BillingService {
    private $subscriptionModel;
    private $subscriptionPlanModel;
    private $subscriptionInvoiceModel;
    private $paymentGatewayModel;
    private $paymentTransactionModel;
    private $subscriptionManager;
    private $validator;
    private $stripeService;
    private $paypalService;

    public function __construct($db) {
        $this->subscriptionModel = new Subscription($db);
        $this->subscriptionPlanModel = new SubscriptionPlan($db);
        $this->subscriptionInvoiceModel = new SubscriptionInvoice($db);
        $this->paymentGatewayModel = new PaymentGateway($db);
        $this->paymentTransactionModel = new PaymentTransaction($db);
        $this->subscriptionManager = new SubscriptionManager($db);
        $this->validator = new Validator();

        // Initialize payment services
        try {
            $this->stripeService = new StripeService();
        } catch (Exception $e) {
            Logger::warning("Stripe service not available", ['error' => $e->getMessage()]);
            $this->stripeService = null;
        }

        try {
            $this->paypalService = new PayPalService();
        } catch (Exception $e) {
            Logger::warning("PayPal service not available", ['error' => $e->getMessage()]);
            $this->paypalService = null;
        }
    }

    /**
     * Get current user subscription
     */
    public function getCurrentSubscription($userId) {
        $subscription = $this->subscriptionModel->getActiveByUserId($userId);

        if (!$subscription) {
            // Return free tier info
            return [
                'tier' => 'free',
                'status' => 'active',
                'features' => ['thinking_traces', 'vector_memory', 'summarization'],
                'limits' => [
                    'context_window_size' => 10,
                    'max_sessions' => 1,
                    'max_messages_per_session' => 100,
                    'max_models_per_prompt' => 1
                ]
            ];
        }

        return $subscription;
    }

    /**
     * Get available subscription plans
     */
    public function getSubscriptionPlans() {
        $result = $this->subscriptionPlanModel->getAllActive();
        return $result;
    }

    /**
     * Upgrade user subscription
     */
    public function upgradeSubscription($userId, $planId = null, $tier = null) {
        // If plan_id provided, get tier from plan
        if ($planId) {
            $plan = $this->subscriptionPlanModel->getById($planId);
            if (!$plan) {
                throw new InvalidArgumentException("Invalid plan ID");
            }
            $tier = $this->mapPlanTypeToTier($plan['plan_type']);
        }

        if (!$tier) {
            throw new InvalidArgumentException("Tier or plan ID required");
        }

        // Use subscription manager to upgrade
        $success = $this->subscriptionManager->upgradeSubscription($userId, $tier);

        if (!$success) {
            throw new RuntimeException("Failed to upgrade subscription");
        }

        // Get updated subscription
        $subscription = $this->subscriptionModel->getActiveByUserId($userId);

        return [
            'subscription' => $subscription,
            'message' => "Subscription upgraded to {$tier} tier"
        ];
    }

    /**
     * Cancel user subscription
     */
    public function cancelSubscription($userId) {
        $success = $this->subscriptionManager->cancelSubscription($userId);

        if (!$success) {
            throw new RuntimeException("Failed to cancel subscription");
        }

        return [
            'message' => 'Subscription cancelled successfully'
        ];
    }

    /**
     * Create payment intent
     */
    public function createPaymentIntent($userId, $planId, $gatewayKey = 'stripe') {
        $plan = $this->subscriptionPlanModel->getById($planId);
        if (!$plan) {
            throw new InvalidArgumentException("Invalid plan ID");
        }

        $gateway = $this->paymentGatewayModel->getByKey($gatewayKey);
        if (!$gateway || !$gateway['is_active']) {
            throw new InvalidArgumentException("Payment gateway not available");
        }

        // Create invoice first
        $invoice = $this->subscriptionInvoiceModel->create([
            'subscription_id' => null, // Will be set after subscription creation
            'amount' => $plan['price_monthly'] ?? $plan['price_yearly'] ?? 0,
            'currency' => $plan['currency'],
            'status' => 'pending'
        ]);

        // Create payment transaction
        $transaction = $this->paymentTransactionModel->create([
            'user_id' => $userId,
            'gateway_id' => $gateway['id'],
            'amount' => $plan['price_monthly'] ?? $plan['price_yearly'] ?? 0,
            'currency' => $plan['currency'],
            'status' => 'pending',
            'metadata' => [
                'plan_id' => $planId,
                'invoice_id' => $invoice['id'],
                'type' => 'subscription_upgrade'
            ]
        ]);

        // Create payment intent with gateway
        $paymentIntent = $this->createGatewayPaymentIntent($gatewayKey, [
            'amount' => $transaction['amount'] * 100, // Convert to cents
            'currency' => $transaction['currency'],
            'metadata' => [
                'transaction_id' => $transaction['id'],
                'invoice_id' => $invoice['id'],
                'plan_id' => $planId
            ]
        ]);

        return [
            'payment_intent' => $paymentIntent,
            'transaction_id' => $transaction['id'],
            'invoice_id' => $invoice['id'],
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency']
        ];
    }

    /**
     * Process payment
     */
    public function processPayment($userId, $paymentData) {
        $transactionId = $paymentData['transaction_id'] ?? null;
        $gatewayKey = $paymentData['gateway_key'] ?? 'stripe';

        if (!$transactionId) {
            throw new InvalidArgumentException("Transaction ID required");
        }

        $transaction = $this->paymentTransactionModel->getById($transactionId);
        if (!$transaction || $transaction['user_id'] !== $userId) {
            throw new InvalidArgumentException("Invalid transaction");
        }

        // Process payment with gateway
        $result = $this->processGatewayPayment($gatewayKey, $paymentData);

        if ($result['success']) {
            // Update transaction status
            $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                'gateway_transaction_id' => $result['gateway_transaction_id']
            ]);

            // Update invoice
            $this->subscriptionInvoiceModel->markAsPaid($transaction['metadata']['invoice_id']);

            // Upgrade subscription if this was for a plan
            if (isset($transaction['metadata']['plan_id'])) {
                $this->upgradeSubscription($userId, $transaction['metadata']['plan_id']);
            }

            return [
                'success' => true,
                'transaction' => $this->paymentTransactionModel->getById($transactionId)
            ];
        } else {
            // Update transaction status to failed
            $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                'error' => $result['error']
            ]);

            throw new RuntimeException("Payment failed: " . $result['error']);
        }
    }

    /**
     * Get user invoices
     */
    public function getUserInvoices($userId, $page = 1, $limit = 50) {
        // Get user's subscriptions first
        $subscriptions = $this->subscriptionModel->getByUserId($userId);
        $subscriptionIds = array_column($subscriptions['subscriptions'], 'id');

        if (empty($subscriptionIds)) {
            return [
                'invoices' => [],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'pages' => 0
                ]
            ];
        }

        // Get invoices for these subscriptions
        $sql = "SELECT * FROM subscription_invoices
                WHERE subscription_id IN (" . str_repeat('?,', count($subscriptionIds) - 1) . "?)
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";

        $offset = ($page - 1) * $limit;
        $params = array_merge($subscriptionIds, [$limit, $offset]);

        $invoices = $this->subscriptionInvoiceModel->getBySubscriptionId(null); // We'll need to modify this
        // For now, return empty - need to implement proper multi-subscription invoice retrieval

        return [
            'invoices' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'pages' => 0
            ]
        ];
    }

    /**
     * Get specific invoice
     */
    public function getInvoice($invoiceId, $userId) {
        $invoice = $this->subscriptionInvoiceModel->getById($invoiceId);

        if (!$invoice) {
            throw new InvalidArgumentException("Invoice not found");
        }

        // Check if user owns this invoice (through subscription)
        $subscription = $this->subscriptionModel->getById($invoice['subscription_id']);
        if (!$subscription || $subscription['user_id'] !== $userId) {
            throw new InvalidArgumentException("Access denied");
        }

        return $invoice;
    }

    /**
     * Get payment methods (placeholder)
     */
    public function getPaymentMethods($userId) {
        // In a real implementation, this would retrieve saved payment methods
        return [
            'payment_methods' => []
        ];
    }

    /**
     * Add payment method (placeholder)
     */
    public function addPaymentMethod($userId, $methodData) {
        // In a real implementation, this would save payment method with gateway
        return [
            'message' => 'Payment method added successfully'
        ];
    }

    /**
     * Remove payment method (placeholder)
     */
    public function removePaymentMethod($userId, $methodId) {
        // In a real implementation, this would remove payment method from gateway
        return [
            'message' => 'Payment method removed successfully'
        ];
    }

    /**
     * Get billing history
     */
    public function getBillingHistory($userId, $page = 1, $limit = 50) {
        $result = $this->paymentTransactionModel->getByUserId($userId, $page, $limit);
        return $result;
    }

    /**
     * Handle webhook
     */
    public function handleWebhook($gatewayKey, $postData, $getData) {
        Logger::info("Processing webhook", ['gateway' => $gatewayKey]);

        switch ($gatewayKey) {
            case 'stripe':
                return $this->handleStripeWebhook($postData);
            case 'paypal':
                return $this->handlePayPalWebhook($postData);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    /**
     * Process webhook event and update database
     */
    private function processWebhookEvent($gatewayKey, $event) {
        Logger::info("Processing webhook event", [
            'gateway' => $gatewayKey,
            'event_type' => $event['event_type'] ?? 'unknown',
            'resource_id' => $event['resource_id'] ?? $event['id'] ?? null
        ]);

        switch ($gatewayKey) {
            case 'stripe':
                return $this->processStripeWebhookEvent($event);
            case 'paypal':
                return $this->processPayPalWebhookEvent($event);
            default:
                return ['processed' => false, 'message' => 'Unsupported gateway'];
        }
    }

    /**
     * Process Stripe webhook event
     */
    private function processStripeWebhookEvent($event) {
        $result = ['processed' => false, 'message' => 'Event not handled'];

        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event['data']['object'];
                $transactionId = $paymentIntent['metadata']['transaction_id'] ?? null;
                $invoiceId = $paymentIntent['metadata']['invoice_id'] ?? null;

                if ($transactionId) {
                    // Update transaction status
                    $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                        'gateway_transaction_id' => $paymentIntent['latest_charge']
                    ]);

                    // Update invoice if exists
                    if ($invoiceId) {
                        $this->subscriptionInvoiceModel->markAsPaid($invoiceId);
                    }

                    // Check if this was for subscription upgrade
                    $transaction = $this->paymentTransactionModel->getById($transactionId);
                    if ($transaction && isset($transaction['metadata']['plan_id'])) {
                        // This would need user_id from transaction metadata or lookup
                        // For now, just log that upgrade should happen
                        Logger::info("Subscription upgrade payment completed", [
                            'transaction_id' => $transactionId,
                            'plan_id' => $transaction['metadata']['plan_id']
                        ]);
                    }

                    $result = [
                        'processed' => true,
                        'message' => 'Payment intent succeeded',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            case 'payment_intent.payment_failed':
                $paymentIntent = $event['data']['object'];
                $transactionId = $paymentIntent['metadata']['transaction_id'] ?? null;

                if ($transactionId) {
                    $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                        'error' => 'Payment failed'
                    ]);

                    $result = [
                        'processed' => true,
                        'message' => 'Payment intent failed',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            default:
                $result = ['processed' => true, 'message' => 'Event type not processed'];
        }

        return $result;
    }

    /**
     * Process PayPal webhook event
     */
    private function processPayPalWebhookEvent($event) {
        $result = ['processed' => false, 'message' => 'Event not handled'];

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $capture = $event['resource'];
                $transactionId = $capture['custom_id'] ?? null;

                if ($transactionId) {
                    // Update transaction status
                    $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                        'gateway_transaction_id' => $capture['id']
                    ]);

                    // Find and update invoice
                    $transaction = $this->paymentTransactionModel->getById($transactionId);
                    if ($transaction && isset($transaction['metadata']['invoice_id'])) {
                        $this->subscriptionInvoiceModel->markAsPaid($transaction['metadata']['invoice_id']);
                    }

                    $result = [
                        'processed' => true,
                        'message' => 'Payment capture completed',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $capture = $event['resource'];
                $transactionId = $capture['custom_id'] ?? null;

                if ($transactionId) {
                    $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                        'error' => 'Payment denied'
                    ]);

                    $result = [
                        'processed' => true,
                        'message' => 'Payment capture denied',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            default:
                $result = ['processed' => true, 'message' => 'Event type not processed'];
        }

        return $result;
    }

    /**
     * Get subscription usage
     */
    public function getSubscriptionUsage($userId) {
        $limits = $this->subscriptionManager->getUserLimits($userId);

        // In a real implementation, you'd calculate current usage
        // For now, return limits with placeholder usage
        return [
            'limits' => $limits,
            'usage' => [
                'sessions_used' => 0, // Would calculate from database
                'messages_used' => 0, // Would calculate from database
                'tokens_used' => 0    // Would calculate from database
            ]
        ];
    }

    /**
     * Get all subscriptions (admin)
     */
    public function getAllSubscriptions($page = 1, $limit = 50, $filters = []) {
        // This would be admin functionality
        // For now, return empty
        return [
            'subscriptions' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'pages' => 0
            ]
        ];
    }

    /**
     * Get billing statistics (admin)
     */
    public function getBillingStats() {
        $subscriptionStats = $this->subscriptionModel->getStats();
        $invoiceStats = $this->subscriptionInvoiceModel->getStats();
        $transactionStats = $this->paymentTransactionModel->getStats();

        return [
            'subscriptions' => $subscriptionStats,
            'invoices' => $invoiceStats,
            'transactions' => $transactionStats
        ];
    }

    /**
     * Private helper methods
     */
    private function mapPlanTypeToTier($planType) {
        $mapping = [
            'free' => 'free',
            'basic' => 'pro',
            'premium' => 'enterprise'
        ];
        return $mapping[$planType] ?? 'free';
    }

    private function createGatewayPaymentIntent($gatewayKey, $data) {
        switch ($gatewayKey) {
            case 'stripe':
                return $this->createStripePaymentIntent($data);
            case 'paypal':
                return $this->createPayPalPaymentIntent($data);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    private function processGatewayPayment($gatewayKey, $data) {
        switch ($gatewayKey) {
            case 'stripe':
                return $this->processStripePayment($data);
            case 'paypal':
                return $this->processPayPalPayment($data);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    private function createStripePaymentIntent($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        return $this->stripeService->createPaymentIntent($data);
    }

    private function processStripePayment($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        $paymentIntentId = $data['payment_intent_id'] ?? null;
        if (!$paymentIntentId) {
            throw new InvalidArgumentException("Payment intent ID required");
        }

        $result = $this->stripeService->confirmPaymentIntent($paymentIntentId);

        return [
            'success' => $result['status'] === 'succeeded',
            'gateway_transaction_id' => $result['charge_id'] ?? $result['id']
        ];
    }

    private function createPayPalPaymentIntent($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        return $this->paypalService->createPaymentOrder($data);
    }

    private function processPayPalPayment($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            throw new InvalidArgumentException("Order ID required");
        }

        $result = $this->paypalService->capturePaymentOrder($orderId);

        return [
            'success' => $result['status'] === 'COMPLETED',
            'gateway_transaction_id' => $result['capture_id'] ?? $result['id']
        ];
    }

    private function handleStripeWebhook($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        // Get raw payload and signature from request
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!$signature) {
            throw new RuntimeException("Stripe signature missing");
        }

        $webhookResult = $this->stripeService->handleWebhook($payload, $signature);

        // Process the webhook event to update database
        if (isset($webhookResult['event'])) {
            $processResult = $this->processStripeWebhookEvent($webhookResult['event']);
            return array_merge($webhookResult, ['database_update' => $processResult]);
        }

        return $webhookResult;
    }

    private function handlePayPalWebhook($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        // PayPal sends raw JSON payload
        $payload = file_get_contents('php://input');

        $webhookResult = $this->paypalService->handleWebhook($payload);

        // Process the webhook event to update database
        if (isset($webhookResult['event'])) {
            $processResult = $this->processPayPalWebhookEvent($webhookResult['event']);
            return array_merge($webhookResult, ['database_update' => $processResult]);
        }

        return $webhookResult;
    }
}
?>