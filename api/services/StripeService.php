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
                    'allow_redirects' => 'never',
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
            // Validate required fields
            $this->validateSubscriptionData($subscriptionData);

            $subscriptionParams = [
                'customer' => $subscriptionData['customer_id'],
                'metadata' => $subscriptionData['metadata'] ?? []
            ];

            // Handle subscription items
            if (isset($subscriptionData['price_id'])) {
                // Use existing price
                $subscriptionParams['items'] = [[
                    'price' => $subscriptionData['price_id']
                ]];
            } else {
                // Create price dynamically
                $subscriptionParams['items'] = [[
                    'price_data' => [
                        'currency' => $subscriptionData['currency'],
                        'product_data' => [
                            'name' => $subscriptionData['plan_name'],
                        ],
                        'unit_amount' => $subscriptionData['amount'],
                        'recurring' => [
                            'interval' => $subscriptionData['interval'] ?? 'month',
                            'interval_count' => $subscriptionData['interval_count'] ?? 1,
                        ],
                    ],
                ]];
            }

            // Add optional parameters
            if (isset($subscriptionData['trial_period_days'])) {
                $subscriptionParams['trial_period_days'] = $subscriptionData['trial_period_days'];
            }

            if (isset($subscriptionData['default_payment_method'])) {
                $subscriptionParams['default_payment_method'] = $subscriptionData['default_payment_method'];
            }

            if (isset($subscriptionData['billing_cycle_anchor'])) {
                $subscriptionParams['billing_cycle_anchor'] = $subscriptionData['billing_cycle_anchor'];
            }

            if (isset($subscriptionData['cancel_at'])) {
                $subscriptionParams['cancel_at'] = $subscriptionData['cancel_at'];
            }

            $subscription = \Stripe\Subscription::create($subscriptionParams);

            Logger::info("Stripe subscription created", [
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end
            ]);

            return $this->formatSubscriptionData($subscription);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription creation failed", [
                'error' => $e->getMessage(),
                'customer_id' => $subscriptionData['customer_id'] ?? 'unknown'
            ]);
            throw new RuntimeException("Subscription creation failed: " . $e->getMessage());
        }
    }

    /**
     * Get subscription by ID
     */
    public function getSubscription($subscriptionId) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            Logger::debug("Stripe subscription retrieved", [
                'subscription_id' => $subscriptionId,
                'status' => $subscription->status
            ]);

            return $this->formatSubscriptionData($subscription);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription retrieval failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription retrieval failed: " . $e->getMessage());
        }
    }

    /**
     * Update subscription
     */
    public function updateSubscription($subscriptionId, $updateData) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            $updateParams = [];

            // Handle status updates
            if (isset($updateData['status'])) {
                switch ($updateData['status']) {
                    case 'canceled':
                        $subscription->cancel();
                        Logger::info("Stripe subscription cancelled via update", [
                            'subscription_id' => $subscriptionId
                        ]);
                        break;
                    case 'paused':
                        $subscription->pause_collection(['payment_behavior' => 'keep_as_draft']);
                        break;
                    case 'active':
                        // Resume if paused
                        if ($subscription->status === 'paused') {
                            $subscription->resume_collection();
                        }
                        break;
                }
            }

            // Handle items update
            if (isset($updateData['items'])) {
                $items = [];
                foreach ($updateData['items'] as $item) {
                    if (isset($item['id'])) {
                        // Update existing item
                        $itemParams = [];
                        if (isset($item['price'])) {
                            $itemParams['price'] = $item['price'];
                        }
                        if (isset($item['quantity'])) {
                            $itemParams['quantity'] = $item['quantity'];
                        }
                        $items[] = [
                            'id' => $item['id'],
                            'deleted' => $item['deleted'] ?? false
                        ];
                        if (!empty($itemParams)) {
                            $items[count($items) - 1] = array_merge($items[count($items) - 1], $itemParams);
                        }
                    } elseif (isset($item['price_data'])) {
                        // Add new item
                        $items[] = ['price_data' => $item['price_data']];
                    }
                }
                $updateParams['items'] = $items;
            }

            // Handle cancel at period end
            if (isset($updateData['cancel_at_period_end'])) {
                $subscription->cancel_at_period_end = $updateData['cancel_at_period_end'];
            }

            // Handle proration behavior
            if (isset($updateData['proration_behavior'])) {
                $updateParams['proration_behavior'] = $updateData['proration_behavior'];
            }

            // Apply updates if any
            if (!empty($updateParams)) {
                $updatedSubscription = $subscription->update($updateParams);
                Logger::info("Stripe subscription updated", [
                    'subscription_id' => $subscriptionId,
                    'updated_fields' => array_keys($updateParams)
                ]);
            } else {
                $updatedSubscription = $subscription;
            }

            return $this->formatSubscriptionData($updatedSubscription);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription update failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription update failed: " . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription($subscriptionId, $cancelAtPeriodEnd = true) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            if ($cancelAtPeriodEnd) {
                $subscription->cancel_at_period_end = true;
                $subscription->save();
                $result = [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'cancel_at_period_end' => $subscription->cancel_at_period_end
                ];
            } else {
                $subscription->cancel();
                $result = [
                    'id' => $subscription->id,
                    'status' => $subscription->status
                ];
            }

            Logger::info("Stripe subscription cancelled", [
                'subscription_id' => $subscriptionId,
                'cancel_at_period_end' => $cancelAtPeriodEnd
            ]);

            return $result;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription cancellation failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription cancellation failed: " . $e->getMessage());
        }
    }

    /**
     * Pause subscription
     */
    public function pauseSubscription($subscriptionId, $pauseBehavior = 'keep_as_draft') {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->pause_collection(['payment_behavior' => $pauseBehavior]);

            Logger::info("Stripe subscription paused", [
                'subscription_id' => $subscriptionId,
                'pause_behavior' => $pauseBehavior
            ]);

            return [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'pause_collection' => $subscription->pause_collection
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription pause failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription pause failed: " . $e->getMessage());
        }
    }

    /**
     * Resume subscription
     */
    public function resumeSubscription($subscriptionId) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->resume_collection();

            Logger::info("Stripe subscription resumed", [
                'subscription_id' => $subscriptionId
            ]);

            return [
                'id' => $subscription->id,
                'status' => $subscription->status
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription resume failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription resume failed: " . $e->getMessage());
        }
    }

    /**
     * Get customer subscriptions
     */
    public function getCustomerSubscriptions($customerId, $status = null, $limit = 10) {
        try {
            $params = [
                'customer' => $customerId,
                'limit' => $limit
            ];

            if ($status) {
                $params['status'] = $status;
            }

            $subscriptions = \Stripe\Subscription::all($params);

            $formattedSubscriptions = [];
            foreach ($subscriptions->data as $subscription) {
                $formattedSubscriptions[] = $this->formatSubscriptionData($subscription);
            }

            Logger::debug("Customer subscriptions retrieved", [
                'customer_id' => $customerId,
                'count' => count($formattedSubscriptions),
                'status' => $status
            ]);

            return [
                'subscriptions' => $formattedSubscriptions,
                'has_more' => $subscriptions->has_more
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Customer subscriptions retrieval failed", [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ]);
            throw new RuntimeException("Customer subscriptions retrieval failed: " . $e->getMessage());
        }
    }

    /**
     * Create subscription schedule
     */
    public function createSubscriptionSchedule($scheduleData) {
        try {
            $schedule = \Stripe\SubscriptionSchedule::create([
                'customer' => $scheduleData['customer_id'],
                'default_settings' => [
                    'collection_method' => $scheduleData['collection_method'] ?? 'charge_automatically',
                    'payment_method_types' => $scheduleData['payment_method_types'] ?? ['card']
                ],
                'phases' => $scheduleData['phases']
            ]);

            Logger::info("Stripe subscription schedule created", [
                'schedule_id' => $schedule->id,
                'customer_id' => $scheduleData['customer_id']
            ]);

            return [
                'id' => $schedule->id,
                'customer' => $schedule->customer,
                'status' => $schedule->status,
                'phases' => $schedule->phases
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe subscription schedule creation failed", [
                'error' => $e->getMessage(),
                'customer_id' => $scheduleData['customer_id'] ?? 'unknown'
            ]);
            throw new RuntimeException("Subscription schedule creation failed: " . $e->getMessage());
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

            case 'customer.subscription.trial_will_end':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionTrialWillEnd($subscription);
                break;

            case 'invoice.created':
                $invoice = $event->data->object;
                $result = $this->handleInvoiceCreated($invoice);
                break;

            case 'invoice.finalized':
                $invoice = $event->data->object;
                $result = $this->handleInvoiceFinalized($invoice);
                break;

            case 'invoice.updated':
                $invoice = $event->data->object;
                $result = $this->handleInvoiceUpdated($invoice);
                break;

            case 'invoice.voided':
                $invoice = $event->data->object;
                $result = $this->handleInvoiceVoided($invoice);
                break;

            case 'customer.subscription.paused':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionPaused($subscription);
                break;

            case 'customer.subscription.resumed':
                $subscription = $event->data->object;
                $result = $this->handleSubscriptionResumed($subscription);
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
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end
        ]);

        // Extract metadata for database synchronization
        $metadata = $subscription->metadata;
        $userId = $metadata['user_id'] ?? null;
        $planId = $metadata['plan_id'] ?? null;

        $result = [
            'processed' => true,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status,
            'metadata' => $metadata,
            'user_id' => $userId,
            'plan_id' => $planId
        ];

        // Log additional subscription details for monitoring
        Logger::debug("Subscription created details", [
            'items_count' => count($subscription->items->data),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'trial_start' => $subscription->trial_start,
            'trial_end' => $subscription->trial_end
        ]);

        return $result;
    }

    /**
     * Handle subscription updated
     */
    private function handleSubscriptionUpdated($subscription) {
        Logger::info("Processing customer.subscription.updated", [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end
        ]);

        $metadata = $subscription->metadata;
        $userId = $metadata['user_id'] ?? null;

        $result = [
            'processed' => true,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'metadata' => $metadata,
            'user_id' => $userId
        ];

        // Handle status-specific logic
        switch ($subscription->status) {
            case 'past_due':
                Logger::warning("Subscription payment is past due", [
                    'subscription_id' => $subscription->id,
                    'customer_id' => $subscription->customer
                ]);
                break;
            case 'canceled':
                Logger::info("Subscription has been canceled", [
                    'subscription_id' => $subscription->id,
                    'customer_id' => $subscription->customer
                ]);
                break;
            case 'active':
                Logger::debug("Subscription is now active", [
                    'subscription_id' => $subscription->id
                ]);
                break;
        }

        return $result;
    }

    /**
     * Handle subscription deleted
     */
    private function handleSubscriptionDeleted($subscription) {
        Logger::info("Processing customer.subscription.deleted", [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status
        ]);

        $metadata = $subscription->metadata;
        $userId = $metadata['user_id'] ?? null;

        $result = [
            'processed' => true,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status,
            'metadata' => $metadata,
            'user_id' => $userId
        ];

        // Log final details
        Logger::debug("Subscription deleted details", [
            'canceled_at' => $subscription->canceled_at,
            'ended_at' => $subscription->ended_at
        ]);

        return $result;
    }

    /**
     * Handle subscription trial ending
     */
    private function handleSubscriptionTrialWillEnd($subscription) {
        Logger::info("Processing customer.subscription.trial_will_end", [
            'subscription_id' => $subscription->id,
            'trial_end' => $subscription->trial_end,
            'customer_id' => $subscription->customer
        ]);

        $metadata = $subscription->metadata;
        $userId = $metadata['user_id'] ?? null;

        return [
            'processed' => true,
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'trial_end' => $subscription->trial_end,
            'metadata' => $metadata,
            'user_id' => $userId
        ];
    }

    /**
     * Handle invoice payment succeeded
     */
    private function handleInvoicePaymentSucceeded($invoice) {
        Logger::info("Processing invoice.payment_succeeded", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
            'amount_paid' => $invoice->amount_paid,
            'customer_id' => $invoice->customer
        ]);

        $metadata = $invoice->metadata;
        $subscriptionId = $invoice->subscription;

        $result = [
            'processed' => true,
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
            'customer_id' => $invoice->customer,
            'amount_paid' => $invoice->amount_paid,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'metadata' => $metadata
        ];

        // Log invoice details for monitoring
        Logger::debug("Invoice payment details", [
            'invoice_number' => $invoice->number,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'paid_at' => $invoice->status_transitions->paid_at
        ]);

        return $result;
    }

    /**
     * Handle invoice payment failed
     */
    private function handleInvoicePaymentFailed($invoice) {
        Logger::warning("Processing invoice.payment_failed", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
            'amount_due' => $invoice->amount_due,
            'customer_id' => $invoice->customer
        ]);

        $metadata = $invoice->metadata;
        $subscriptionId = $invoice->subscription;

        $result = [
            'processed' => true,
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscriptionId,
            'customer_id' => $invoice->customer,
            'amount_due' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'metadata' => $metadata
        ];

        // Log failure details
        Logger::warning("Invoice payment failed details", [
            'invoice_number' => $invoice->number,
            'next_payment_attempt' => $invoice->next_payment_attempt,
            'attempt_count' => $invoice->attempt_count
        ]);

        return $result;
    }



    /**
     * Create refund
     */
    public function createRefund($chargeId, $amount = null, $reason = 'requested_by_customer', $metadata = []) {
        try {
            $refundData = [
                'charge' => $chargeId,
                'reason' => $reason,
                'metadata' => $metadata
            ];

            if ($amount) {
                $refundData['amount'] = $amount;
            }

            $refund = \Stripe\Refund::create($refundData);

            Logger::info("Stripe refund created", [
                'refund_id' => $refund->id,
                'charge_id' => $chargeId,
                'amount' => $refund->amount,
                'reason' => $reason
            ]);

            return [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'metadata' => $refund->metadata
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Stripe refund creation failed", [
                'error' => $e->getMessage(),
                'charge_id' => $chargeId
            ]);
            throw new RuntimeException("Refund creation failed: " . $e->getMessage());
        }
    }

    /**
     * Validate subscription data
     */
    private function validateSubscriptionData($data) {
        $required = ['customer_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Required field missing: {$field}");
            }
        }

        // Validate currency
        if (isset($data['currency']) && !preg_match('/^[a-zA-Z]{3}$/', $data['currency'])) {
            throw new InvalidArgumentException("Invalid currency format");
        }

        // Validate interval
        if (isset($data['interval']) && !in_array($data['interval'], ['day', 'week', 'month', 'year'])) {
            throw new InvalidArgumentException("Invalid interval: {$data['interval']}");
        }

        // Validate amount (must be positive integer for cents)
        if (isset($data['amount']) && (!is_int($data['amount']) || $data['amount'] <= 0)) {
            throw new InvalidArgumentException("Invalid amount: must be a positive integer (cents)");
        }

        return true;
    }

    /**
     * Format subscription data for consistent response
     */
    private function formatSubscriptionData($subscription) {
        return [
            'id' => $subscription->id,
            'customer_id' => $subscription->customer,
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end,
            'created' => $subscription->created,
            'canceled_at' => $subscription->canceled_at,
            'ended_at' => $subscription->ended_at,
            'trial_start' => $subscription->trial_start,
            'trial_end' => $subscription->trial_end,
            'cancel_at' => $subscription->cancel_at,
            'metadata' => $subscription->metadata,
            'items' => array_map(function($item) {
                return [
                    'id' => $item->id,
                    'price' => [
                        'id' => $item->price->id,
                        'currency' => $item->price->currency,
                        'unit_amount' => $item->price->unit_amount,
                        'recurring' => $item->price->recurring
                    ],
                    'quantity' => $item->quantity,
                    'created' => $item->created
                ];
            }, $subscription->items->data)
        ];
    }

    /**
     * Get payment methods for customer
     */
    public function getPaymentMethods($customerId, $type = 'card') {
        try {
            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $customerId,
                'type' => $type
            ]);

            $methods = [];
            foreach ($paymentMethods->data as $method) {
                $methods[] = [
                    'id' => $method->id,
                    'type' => $method->type,
                    'card' => $method->card ? [
                        'brand' => $method->card->brand,
                        'last4' => $method->card->last4,
                        'exp_month' => $method->card->exp_month,
                        'exp_year' => $method->card->exp_year
                    ] : null,
                    'billing_details' => $method->billing_details,
                    'created' => $method->created
                ];
            }

            Logger::debug("Retrieved payment methods for customer", [
                'customer_id' => $customerId,
                'count' => count($methods)
            ]);

            return $methods;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to get payment methods", [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ]);
            throw new RuntimeException("Failed to get payment methods: " . $e->getMessage());
        }
    }

    /**
     * Create payment method
     */
    public function createPaymentMethod($paymentMethodData) {
        try {
            $method = \Stripe\PaymentMethod::create($paymentMethodData);

            Logger::info("Payment method created", [
                'payment_method_id' => $method->id,
                'type' => $method->type
            ]);

            return [
                'id' => $method->id,
                'type' => $method->type,
                'card' => $method->card ? [
                    'brand' => $method->card->brand,
                    'last4' => $method->card->last4,
                    'exp_month' => $method->card->exp_month,
                    'exp_year' => $method->card->exp_year
                ] : null,
                'billing_details' => $method->billing_details,
                'created' => $method->created
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to create payment method", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Failed to create payment method: " . $e->getMessage());
        }
    }

    /**
     * Attach payment method to customer
     */
    public function attachPaymentMethodToCustomer($paymentMethodId, $customerId) {
        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach(['customer' => $customerId]);

            Logger::info("Payment method attached to customer", [
                'payment_method_id' => $paymentMethodId,
                'customer_id' => $customerId
            ]);

            return [
                'id' => $paymentMethod->id,
                'customer' => $paymentMethod->customer
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to attach payment method", [
                'error' => $e->getMessage(),
                'payment_method_id' => $paymentMethodId,
                'customer_id' => $customerId
            ]);
            throw new RuntimeException("Failed to attach payment method: " . $e->getMessage());
        }
    }

    /**
     * Detach payment method from customer
     */
    public function detachPaymentMethod($paymentMethodId) {
        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->detach();

            Logger::info("Payment method detached", [
                'payment_method_id' => $paymentMethodId
            ]);

            return [
                'id' => $paymentMethod->id,
                'customer' => $paymentMethod->customer
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to detach payment method", [
                'error' => $e->getMessage(),
                'payment_method_id' => $paymentMethodId
            ]);
            throw new RuntimeException("Failed to detach payment method: " . $e->getMessage());
        }
    }

    /**
     * Update customer default payment method
     */
    public function updateCustomerDefaultPaymentMethod($customerId, $paymentMethodId) {
        try {
            $customer = \Stripe\Customer::update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId
                ]
            ]);

            Logger::info("Customer default payment method updated", [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId
            ]);

            return [
                'customer_id' => $customer->id,
                'default_payment_method' => $customer->invoice_settings->default_payment_method
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to update customer default payment method", [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId
            ]);
            throw new RuntimeException("Failed to update default payment method: " . $e->getMessage());
        }
    }

    /**
     * Additional webhook handlers for enhanced event processing
     */
    private function handleInvoiceCreated($invoice) {
        Logger::info("Processing invoice.created", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
            'amount_due' => $invoice->amount_due
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    private function handleInvoiceFinalized($invoice) {
        Logger::info("Processing invoice.finalized", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    private function handleInvoiceUpdated($invoice) {
        Logger::debug("Processing invoice.updated", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    private function handleInvoiceVoided($invoice) {
        Logger::info("Processing invoice.voided", [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        return ['processed' => true, 'invoice_id' => $invoice->id];
    }

    private function handleSubscriptionPaused($subscription) {
        Logger::info("Processing customer.subscription.paused", [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer
        ]);

        return ['processed' => true, 'subscription_id' => $subscription->id];
    }

    private function handleSubscriptionResumed($subscription) {
        Logger::info("Processing customer.subscription.resumed", [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer
        ]);

        return ['processed' => true, 'subscription_id' => $subscription->id];
    }
}
?>