<?php
class StripeService {
    private $stripeSecretKey;
    private $webhookSecret;

    public function __construct() {
        // Load Stripe configuration
        $this->stripeSecretKey = Environment::get('STRIPE_SECRET_KEY');
        $this->webhookSecret = Environment::get('STRIPE_WEBHOOK_SECRET');

        if (!$this->stripeSecretKey) {
            Logger::error("Stripe secret key not configured");
            throw new RuntimeException("Stripe service not configured");
        }

        // Check if Stripe SDK is available
        if (!class_exists('\Stripe\Stripe')) {
            Logger::error("Stripe SDK not available");
            throw new RuntimeException("Stripe SDK not installed");
        }

        \Stripe\Stripe::setApiKey($this->stripeSecretKey);
        Logger::debug("Stripe service initialized");
    }

    /**
     * Create payment intent
     */
    public function createPaymentIntent($data) {
        try {
            $intentData = [
                'amount' => $data['amount'], // Amount in cents
                'currency' => $data['currency'],
                'metadata' => $data['metadata'] ?? [],
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ];

            // Add customer ID if provided
            if (isset($data['customer_id'])) {
                $intentData['customer'] = $data['customer_id'];
            }

            $paymentIntent = \Stripe\PaymentIntent::create($intentData);

            Logger::info("Stripe payment intent created", [
                'intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency
            ]);

            return [
                'id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe payment intent creation failed", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new RuntimeException("Payment intent creation failed: " . $e->getMessage());
        }
    }

    /**
     * Confirm payment intent
     */
    public function confirmPaymentIntent($paymentIntentId, $paymentMethodId = null) {
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            if ($paymentMethodId) {
                $paymentIntent->confirm([
                    'payment_method' => $paymentMethodId
                ]);
            } else {
                $paymentIntent->confirm();
            }

            Logger::info("Stripe payment intent confirmed", [
                'intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status
            ]);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'charge_id' => $paymentIntent->latest_charge ?? null
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe payment intent confirmation failed", [
                'error' => $e->getMessage(),
                'intent_id' => $paymentIntentId
            ]);
            throw new RuntimeException("Payment confirmation failed: " . $e->getMessage());
        }
    }

    /**
     * Create customer
     */
    public function createCustomer($customerData) {
        try {
            $customer = \Stripe\Customer::create([
                'email' => $customerData['email'],
                'name' => $customerData['name'] ?? null,
                'metadata' => $customerData['metadata'] ?? []
            ]);

            Logger::info("Stripe customer created", [
                'customer_id' => $customer->id,
                'email' => $customer->email
            ]);

            return [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe customer creation failed", [
                'error' => $e->getMessage(),
                'email' => $customerData['email']
            ]);
            throw new RuntimeException("Customer creation failed: " . $e->getMessage());
        }
    }

    /**
     * Get customer
     */
    public function getCustomer($customerId) {
        try {
            $customer = \Stripe\Customer::retrieve($customerId);

            return [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe customer retrieval failed", [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ]);
            return null;
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription($subscriptionData) {
        try {
            $subscription = \Stripe\Subscription::create([
                'customer' => $subscriptionData['customer_id'],
                'items' => [[
                    'price_data' => [
                        'currency' => $subscriptionData['currency'],
                        'product_data' => [
                            'name' => $subscriptionData['plan_name'],
                        ],
                        'unit_amount' => $subscriptionData['amount'],
                        'recurring' => [
                            'interval' => $subscriptionData['interval'] ?? 'month',
                        ],
                    ],
                ]],
                'metadata' => $subscriptionData['metadata'] ?? []
            ]);

            Logger::info("Stripe subscription created", [
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer,
                'status' => $subscription->status
            ]);

            return [
                'id' => $subscription->id,
                'customer_id' => $subscription->customer,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription creation failed", [
                'error' => $e->getMessage(),
                'customer_id' => $subscriptionData['customer_id']
            ]);
            throw new RuntimeException("Subscription creation failed: " . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription($subscriptionId) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->cancel();

            Logger::info("Stripe subscription cancelled", [
                'subscription_id' => $subscriptionId
            ]);

            return [
                'id' => $subscription->id,
                'status' => $subscription->status
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription cancellation failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription cancellation failed: " . $e->getMessage());
        }
    }

    /**
     * Handle webhook
     */
    public function handleWebhook($payload, $signature) {
        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );

            Logger::info("Stripe webhook verified", [
                'event_type' => $event->type,
                'event_id' => $event->id
            ]);

            $result = $this->processWebhookEvent($event);

            // Return both result and event for further processing
            return [
                'processed' => $result['processed'],
                'message' => $result['message'],
                'event' => [
                    'type' => $event->type,
                    'data' => $event->data->toArray()
                ]
            ];

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Logger::error("Stripe webhook signature verification failed", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Webhook signature verification failed");
        } catch (\Exception $e) {
            Logger::error("Stripe webhook processing failed", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Webhook processing failed: " . $e->getMessage());
        }
    }

    /**
     * Process webhook event
     */
    private function processWebhookEvent($event) {
        $result = ['processed' => false, 'event_type' => $event->type];

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $result = $this->handlePaymentIntentSucceeded($paymentIntent);
                break;

            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $result = $this->handlePaymentIntentFailed($paymentIntent);
                break;

            case 'customer.subscription.created':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionCreated($subscription);
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionUpdated($subscription);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionDeleted($subscription);
                break;

            case 'invoice.payment_succeeded':
                $invoice = $event->data->object;
                $result = $this->handleInvoicePaymentSucceeded($invoice);
                break;

            case 'invoice.payment_failed':
                $invoice = $event->data->object;
                $result = $this->handleInvoicePaymentFailed($invoice);
                break;

            default:
                Logger::info("Unhandled Stripe webhook event", [
                    'event_type' => $event->type
                ]);
                $result['processed'] = true; // Mark as processed even if not handled
        }

        return $result;
    }

    /**
     * Handle payment intent succeeded
     */
    private function handlePaymentIntentSucceeded($paymentIntent) {
        Logger::info("Processing payment_intent.succeeded", [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'metadata' => $paymentIntent->metadata
        ]);

        // Extract metadata
        $transactionId = $paymentIntent->metadata['transaction_id'] ?? null;
        $invoiceId = $paymentIntent->metadata['invoice_id'] ?? null;

        if ($transactionId) {
            // Update transaction status in our system
            // This would typically be handled by the BillingService
            Logger::info("Payment intent succeeded for transaction", [
                'transaction_id' => $transactionId,
                'payment_intent_id' => $paymentIntent->id
            ]);
        }

        return [
            'processed' => true,
            'payment_intent_id' => $paymentIntent->id,
            'transaction_id' => $transactionId,
            'invoice_id' => $invoiceId
        ];
    }

    /**
     * Handle payment intent failed
     */
    private function handlePaymentIntentFailed($paymentIntent) {
        Logger::info("Processing payment_intent.payment_failed", [
            'payment_intent_id' => $paymentIntent->id,
            'metadata' => $paymentIntent->metadata
        ]);

        $transactionId = $paymentIntent->metadata['transaction_id'] ?? null;

        if ($transactionId) {
            Logger::info("Payment intent failed for transaction", [
                'transaction_id' => $transactionId,
                'payment_intent_id' => $paymentIntent->id
            ]);
        }

        return [
            'processed' => true,
            'payment_intent_id' => $paymentIntent->id,
            'transaction_id' => $transactionId
        ];
    }

    /**
     * Handle subscription created
     */
    private function handleSubscriptionCreated($subscription) {
        Logger::info("Processing customer.subscription.created", [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status
        ]);

        return ['processed' => true, 'subscription_id' => $subscription->id];
    }

    /**
     * Handle subscription updated
     */
    private function handleSubscriptionUpdated($subscription) {
        Logger::info("Processing customer.subscription.updated", [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status
        ]);

        return ['processed' => true, 'subscription_id' => $subscription->id];
    }

    /**
     * Handle subscription deleted
     */
    private function handleSubscriptionDeleted($subscription) {
        Logger::info("Processing customer.subscription.deleted", [
            'subscription_id' => $subscription->id
        ]);

        return ['processed' => true, 'subscription_id' => $subscription->id];
    }

    /**
     * Handle invoice payment succeeded
     */
    private function handleInvoicePaymentSucceeded($invoice) {
        Logger::info("Processing invoice.payment_succeeded", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
            'amount_paid' => $invoice->amount_paid
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    /**
     * Handle invoice payment failed
     */
    private function handleInvoicePaymentFailed($invoice) {
        Logger::info("Processing invoice.payment_failed", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    /**
     * Create refund
     */
    public function createRefund($chargeId, $amount = null, $reason = 'requested_by_customer') {
        try {
            $refundData = [
                'charge' => $chargeId,
                'reason' => $reason
            ];

            if ($amount) {
                $refundData['amount'] = $amount;
            }

            $refund = \Stripe\Refund::create($refundData);

            Logger::info("Stripe refund created", [
                'refund_id' => $refund->id,
                'charge_id' => $chargeId,
                'amount' => $refund->amount
            ]);

            return [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'status' => $refund->status
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe refund creation failed", [
                'error' => $e->getMessage(),
                'charge_id' => $chargeId
            ]);
            throw new RuntimeException("Refund creation failed: " . $e->getMessage());
        }
    }
}
?>