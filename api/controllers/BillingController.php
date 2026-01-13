<?php
class BillingController extends BaseController {
    private $billingService;
    private $subscriptionManager;

    public function __construct($db) {
        parent::__construct($db);
        $this->billingService = new BillingService($db);
        $this->subscriptionManager = new SubscriptionManager($db);
    }

    /**
     * Get current user subscription
     */
    public function getCurrentSubscription() {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user) {
                $subscription = $this->billingService->getCurrentSubscription($user['user_id']);
                return $subscription;
            }, "Current subscription retrieved successfully.", 'SUBSCRIPTION_RETRIEVAL_FAILED');
    }

    /**
     * Get available subscription plans
     */
    public function getSubscriptionPlans() {
        try {
            $plans = $this->billingService->getSubscriptionPlans();
            return $this->success($plans, "Subscription plans retrieved successfully.");
        } catch (Exception $e) {
            Logger::error("Failed to get subscription plans", [
                'error' => $e->getMessage()
            ]);
            return $this->error("Failed to retrieve subscription plans.", 500, 'PLANS_RETRIEVAL_FAILED');
        }
    }

    /**
     * Upgrade user subscription
     */
    public function upgradeSubscription() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->upgradeSubscription(
                    $user['user_id'],
                    $data['plan_id'] ?? null,
                    $data['tier'] ?? null
                );
                return $result;
            }, "Subscription upgraded successfully.", 'SUBSCRIPTION_UPGRADE_FAILED');
    }

    /**
     * Cancel user subscription
     */
    public function cancelSubscription() {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user) {
                $result = $this->billingService->cancelSubscription($user['user_id']);
                return $result;
            }, "Subscription cancelled successfully.", 'SUBSCRIPTION_CANCEL_FAILED');
    }

    /**
     * Create Stripe subscription
     */
    public function createStripeSubscription() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->createStripeSubscription(
                    $user['user_id'],
                    $data['plan_id'],
                    $data['payment_method_id'] ?? null
                );
                return $result;
            }, "Stripe subscription created successfully.", 'STRIPE_SUBSCRIPTION_CREATION_FAILED');
    }

    /**
     * Cancel Stripe subscription
     */
    public function cancelStripeSubscription() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->cancelStripeSubscription(
                    $user['user_id'],
                    $data['cancel_at_period_end'] ?? true
                );
                return $result;
            }, "Stripe subscription cancelled successfully.", 'STRIPE_SUBSCRIPTION_CANCEL_FAILED');
    }

    /**
     * Create payment intent for subscription
     */
    public function createPaymentIntent() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->createPaymentIntent(
                    $user['user_id'],
                    $data['plan_id'],
                    $data['gateway_key'] ?? 'stripe'
                );
                return $result;
            }, "Payment intent created successfully.", 'PAYMENT_INTENT_CREATION_FAILED');
    }

    /**
     * Process payment
     */
    public function processPayment() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->processPayment(
                    $user['user_id'],
                    $data
                );
                return $result;
            }, "Payment processed successfully.", 'PAYMENT_PROCESSING_FAILED');
    }

    /**
     * Get user invoices
     */
    public function getInvoices() {
        $user = $this->getAuthenticatedUser();
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 50;

            return $this->handleServiceCall(function() use ($user, $page, $limit) {
                $result = $this->billingService->getUserInvoices($user['user_id'], $page, $limit);
                return $result;
            }, "Invoices retrieved successfully.", 'INVOICES_RETRIEVAL_FAILED');
    }

    /**
     * Get specific invoice
     */
    public function getInvoice($invoiceId) {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user, $invoiceId) {
                $invoice = $this->billingService->getInvoice($invoiceId, $user['user_id']);
                return $invoice;
            }, "Invoice retrieved successfully.", 'INVOICE_RETRIEVAL_FAILED');
    }

    /**
     * Get payment methods
     */
    public function getPaymentMethods() {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user) {
                $methods = $this->billingService->getPaymentMethods($user['user_id']);
                return $methods;
            }, "Payment methods retrieved successfully.", 'PAYMENT_METHODS_RETRIEVAL_FAILED');
    }

    /**
     * Add payment method
     */
    public function addPaymentMethod() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            return $this->handleServiceCall(function() use ($user, $data) {
                $result = $this->billingService->addPaymentMethod($user['user_id'], $data);
                return $result;
            }, "Payment method added successfully.", 'PAYMENT_METHOD_ADD_FAILED');
    }

    /**
     * Remove payment method
     */
    public function removePaymentMethod($methodId) {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user, $methodId) {
                $result = $this->billingService->removePaymentMethod($user['user_id'], $methodId);
                return $result;
            }, "Payment method removed successfully.", 'PAYMENT_METHOD_REMOVE_FAILED');
    }

    /**
     * Get billing history
     */
    public function getBillingHistory() {
        $user = $this->getAuthenticatedUser();
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 50;

            return $this->handleServiceCall(function() use ($user, $page, $limit) {
                $result = $this->billingService->getBillingHistory($user['user_id'], $page, $limit);
                return $result;
            }, "Billing history retrieved successfully.", 'BILLING_HISTORY_RETRIEVAL_FAILED');
    }

    /**
     * Webhook handler for payment confirmations
     */
    public function handleWebhook() {
        $uri = $_SERVER['REQUEST_URI'];
        if (strpos($uri, '/webhooks/stripe') !== false) {
            $gatewayKey = 'stripe';
        } elseif (strpos($uri, '/webhooks/paypal') !== false) {
            $gatewayKey = 'paypal';
        } else {
            $gatewayKey = null;
        }

        try {
            $result = $this->billingService->handleWebhook($gatewayKey, $_POST, $_GET);
            return $this->success($result, "Webhook processed successfully.");
        } catch (Exception $e) {
            Logger::error("Webhook processing failed", [
                'gateway' => $gatewayKey,
                'error' => $e->getMessage()
            ]);
            // Return 200 to prevent retries for invalid requests
            return $this->success(['error' => $e->getMessage()], "Webhook processing completed with errors.");
        }
    }

    /**
     * Get subscription usage/limits
     */
    public function getSubscriptionUsage() {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user) {
                $usage = $this->billingService->getSubscriptionUsage($user['user_id']);
                return $usage;
            }, "Subscription usage retrieved successfully.", 'USAGE_RETRIEVAL_FAILED');
    }

    /**
     * Admin: Get all subscriptions
     */
    public function getAllSubscriptions() {
        $user = $this->getAuthenticatedUser();
            // TODO: Add admin role check
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 50;
            $filters = $_GET;

            return $this->handleServiceCall(function() use ($page, $limit, $filters) {
                $result = $this->billingService->getAllSubscriptions($page, $limit, $filters);
                return $result;
            }, "All subscriptions retrieved successfully.", 'SUBSCRIPTIONS_RETRIEVAL_FAILED');
    }

    /**
     * Admin: Get billing statistics
     */
    public function getBillingStats() {
        $user = $this->getAuthenticatedUser();
            // TODO: Add admin role check
            return $this->handleServiceCall(function() {
                $stats = $this->billingService->getBillingStats();
                return $stats;
            }, "Billing statistics retrieved successfully.", 'STATS_RETRIEVAL_FAILED');
    }
}
?>