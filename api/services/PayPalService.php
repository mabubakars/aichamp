<?php
class PayPalService {
    private $clientId;
    private $clientSecret;
    private $baseUrl;
    private $webhookId;
    private $accessToken;
    private $tokenExpires;

    public function __construct() {
        $this->clientId = Environment::get('PAYPAL_CLIENT_ID');
        $this->clientSecret = Environment::get('PAYPAL_CLIENT_SECRET');
        $this->webhookId = Environment::get('PAYPAL_WEBHOOK_ID');
        $mode = Environment::get('PAYPAL_MODE', 'sandbox');

        if (!$this->clientId || !$this->clientSecret) {
            Logger::error("PayPal credentials not configured");
            throw new RuntimeException("PayPal service not configured");
        }

        $this->baseUrl = $mode === 'live'
            ? 'https://api.paypal.com'
            : 'https://api.sandbox.paypal.com';

        Logger::debug("PayPal service initialized", ['mode' => $mode]);
    }

    /**
     * Get access token
     */
    private function getAccessToken() {
        if ($this->accessToken && $this->tokenExpires > time()) {
            return $this->accessToken;
        }

        try {
            $response = $this->makeRequest('POST', '/v1/oauth2/token', [
                'grant_type' => 'client_credentials'
            ], null, true);

            $this->accessToken = $response['access_token'];
            $this->tokenExpires = time() + ($response['expires_in'] ?? 3600) - 60; // 1 minute buffer

            return $this->accessToken;

        } catch (Exception $e) {
            Logger::error("PayPal access token request failed", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Failed to get PayPal access token: " . $e->getMessage());
        }
    }

    /**
     * Create payment order
     */
    public function createPaymentOrder($data) {
        $accessToken = $this->getAccessToken();

        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($data['currency']),
                    'value' => number_format($data['amount'] / 100, 2, '.', '') // Convert cents to dollars
                ],
                'reference_id' => $data['metadata']['transaction_id'] ?? uniqid(),
                'custom_id' => $data['metadata']['transaction_id'] ?? null
            ]],
            'application_context' => [
                'return_url' => $data['return_url'] ?? null,
                'cancel_url' => $data['cancel_url'] ?? null,
                'user_action' => 'PAY_NOW'
            ]
        ];

        try {
            $response = $this->makeRequest('POST', '/v2/checkout/orders', $orderData, $accessToken);

            Logger::info("PayPal order created", [
                'order_id' => $response['id'],
                'status' => $response['status'],
                'amount' => $data['amount'],
                'currency' => $data['currency']
            ]);

            return [
                'id' => $response['id'],
                'status' => $response['status'],
                'approval_url' => $this->getApprovalUrl($response['links']),
                'amount' => $data['amount'],
                'currency' => $data['currency']
            ];

        } catch (Exception $e) {
            Logger::error("PayPal order creation failed", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new RuntimeException("Order creation failed: " . $e->getMessage());
        }
    }

    /**
     * Capture payment order
     */
    public function capturePaymentOrder($orderId) {
        $accessToken = $this->getAccessToken();

        try {
            $response = $this->makeRequest('POST', "/v2/checkout/orders/{$orderId}/capture", [], $accessToken);

            Logger::info("PayPal order captured", [
                'order_id' => $orderId,
                'status' => $response['status']
            ]);

            return [
                'id' => $response['id'],
                'status' => $response['status'],
                'capture_id' => $response['purchase_units'][0]['payments']['captures'][0]['id'] ?? null
            ];

        } catch (Exception $e) {
            Logger::error("PayPal order capture failed", [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            throw new RuntimeException("Order capture failed: " . $e->getMessage());
        }
    }

    /**
     * Get order details
     */
    public function getOrder($orderId) {
        $accessToken = $this->getAccessToken();

        try {
            $response = $this->makeRequest('GET', "/v2/checkout/orders/{$orderId}", [], $accessToken);

            return [
                'id' => $response['id'],
                'status' => $response['status'],
                'amount' => $response['purchase_units'][0]['amount']['value'],
                'currency' => $response['purchase_units'][0]['amount']['currency_code']
            ];

        } catch (Exception $e) {
            Logger::error("PayPal order retrieval failed", [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            return null;
        }
    }

    /**
     * Create subscription plan
     */
    public function createSubscriptionPlan($planData) {
        $accessToken = $this->getAccessToken();

        $planPayload = [
            'product_id' => $planData['product_id'],
            'name' => $planData['name'],
            'description' => $planData['description'] ?? '',
            'billing_cycles' => [[
                'frequency' => [
                    'interval_unit' => $planData['interval'] ?? 'MONTH',
                    'interval_count' => 1
                ],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0, // Infinite
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => number_format($planData['price'], 2, '.', ''),
                        'currency_code' => strtoupper($planData['currency'])
                    ]
                ]
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee_failure_action' => 'CANCEL',
                'payment_failure_threshold' => 3
            ]
        ];

        try {
            $response = $this->makeRequest('POST', '/v1/billing/plans', $planPayload, $accessToken);

            Logger::info("PayPal subscription plan created", [
                'plan_id' => $response['id'],
                'name' => $response['name'],
                'status' => $response['status']
            ]);

            return [
                'id' => $response['id'],
                'name' => $response['name'],
                'status' => $response['status'],
                'product_id' => $response['product_id']
            ];

        } catch (Exception $e) {
            Logger::error("PayPal subscription plan creation failed", [
                'error' => $e->getMessage(),
                'plan_data' => $planData
            ]);
            throw new RuntimeException("Subscription plan creation failed: " . $e->getMessage());
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription($subscriptionData) {
        $accessToken = $this->getAccessToken();

        $subscriptionPayload = [
            'plan_id' => $subscriptionData['plan_id'],
            'subscriber' => [
                'email_address' => $subscriptionData['email'],
                'name' => [
                    'given_name' => $subscriptionData['first_name'],
                    'surname' => $subscriptionData['last_name']
                ]
            ],
            'application_context' => [
                'brand_name' => 'AI Chat Platform',
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'payment_method' => [
                    'payer_selected' => 'PAYPAL',
                    'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'
                ],
                'return_url' => $subscriptionData['return_url'],
                'cancel_url' => $subscriptionData['cancel_url']
            ]
        ];

        try {
            $response = $this->makeRequest('POST', '/v1/billing/subscriptions', $subscriptionPayload, $accessToken);

            Logger::info("PayPal subscription created", [
                'subscription_id' => $response['id'],
                'status' => $response['status'],
                'plan_id' => $response['plan_id']
            ]);

            return [
                'id' => $response['id'],
                'status' => $response['status'],
                'approval_url' => $this->getApprovalUrl($response['links']),
                'subscriber' => $response['subscriber']
            ];

        } catch (Exception $e) {
            Logger::error("PayPal subscription creation failed", [
                'error' => $e->getMessage(),
                'subscription_data' => $subscriptionData
            ]);
            throw new RuntimeException("Subscription creation failed: " . $e->getMessage());
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription($subscriptionId, $reason = 'User requested cancellation') {
        $accessToken = $this->getAccessToken();

        try {
            $cancelData = [
                'reason' => $reason
            ];

            $response = $this->makeRequest('POST', "/v1/billing/subscriptions/{$subscriptionId}/cancel", $cancelData, $accessToken);

            Logger::info("PayPal subscription cancelled", [
                'subscription_id' => $subscriptionId
            ]);

            return ['cancelled' => true];

        } catch (Exception $e) {
            Logger::error("PayPal subscription cancellation failed", [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId
            ]);
            throw new RuntimeException("Subscription cancellation failed: " . $e->getMessage());
        }
    }

    /**
     * Handle webhook
     */
    public function handleWebhook($payload) {
        try {
            $event = json_decode($payload, true);

            if (!$event) {
                throw new RuntimeException("Invalid webhook payload");
            }

            Logger::info("PayPal webhook received", [
                'event_type' => $event['event_type'],
                'resource_type' => $event['resource_type'],
                'resource_id' => $event['resource']['id'] ?? null
            ]);

            $result = $this->processWebhookEvent($event);

            // Return both result and event for further processing
            return [
                'processed' => $result['processed'],
                'message' => $result['message'],
                'event' => $event
            ];

        } catch (Exception $e) {
            Logger::error("PayPal webhook processing failed", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Webhook processing failed: " . $e->getMessage());
        }
    }

    /**
     * Process webhook event
     */
    private function processWebhookEvent($event) {
        $result = ['processed' => false, 'event_type' => $event['event_type']];

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $result = $this->handlePaymentCaptureCompleted($event['resource']);
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $result = $this->handlePaymentCaptureDenied($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.CREATED':
                $result = $this->handleSubscriptionCreated($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $result = $this->handleSubscriptionCancelled($event['resource']);
                break;

            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                $result = $this->handleSubscriptionSuspended($event['resource']);
                break;

            default:
                Logger::info("Unhandled PayPal webhook event", [
                    'event_type' => $event['event_type']
                ]);
                $result['processed'] = true;
        }

        return $result;
    }

    /**
     * Handle payment capture completed
     */
    private function handlePaymentCaptureCompleted($resource) {
        Logger::info("Processing PAYMENT.CAPTURE.COMPLETED", [
            'capture_id' => $resource['id'],
            'amount' => $resource['amount'],
            'custom_id' => $resource['custom_id'] ?? null
        ]);

        return [
            'processed' => true,
            'capture_id' => $resource['id'],
            'transaction_id' => $resource['custom_id'],
            'amount' => $resource['amount']['value'],
            'currency' => $resource['amount']['currency_code']
        ];
    }

    /**
     * Handle payment capture denied
     */
    private function handlePaymentCaptureDenied($resource) {
        Logger::info("Processing PAYMENT.CAPTURE.DENIED", [
            'capture_id' => $resource['id'],
            'custom_id' => $resource['custom_id'] ?? null
        ]);

        return [
            'processed' => true,
            'capture_id' => $resource['id'],
            'transaction_id' => $resource['custom_id']
        ];
    }

    /**
     * Handle subscription created
     */
    private function handleSubscriptionCreated($resource) {
        Logger::info("Processing BILLING.SUBSCRIPTION.CREATED", [
            'subscription_id' => $resource['id'],
            'status' => $resource['status']
        ]);

        return ['processed' => true, 'subscription_id' => $resource['id']];
    }

    /**
     * Handle subscription cancelled
     */
    private function handleSubscriptionCancelled($resource) {
        Logger::info("Processing BILLING.SUBSCRIPTION.CANCELLED", [
            'subscription_id' => $resource['id']
        ]);

        return ['processed' => true, 'subscription_id' => $resource['id']];
    }

    /**
     * Handle subscription suspended
     */
    private function handleSubscriptionSuspended($resource) {
        Logger::info("Processing BILLING.SUBSCRIPTION.SUSPENDED", [
            'subscription_id' => $resource['id']
        ]);

        return ['processed' => true, 'subscription_id' => $resource['id']];
    }

    /**
     * Get approval URL from links
     */
    private function getApprovalUrl($links) {
        foreach ($links as $link) {
            if ($link['rel'] === 'approve' || $link['rel'] === 'subscriber') {
                return $link['href'];
            }
        }
        return null;
    }

    /**
     * Make HTTP request to PayPal API
     */
    private function makeRequest($method, $endpoint, $data = [], $accessToken = null, $isAuth = false) {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Content-Type: application/json'
        ];

        if ($accessToken && !$isAuth) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        } elseif ($isAuth) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("HTTP request failed: " . $error);
        }

        $responseData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $responseData['message'] ?? 'Unknown error';
            throw new RuntimeException("PayPal API error ({$httpCode}): " . $errorMessage);
        }

        return $responseData;
    }
}
?>