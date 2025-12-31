<?php
// Include required model classes
require_once __DIR__ . '/../models/Subscription.php';
require_once __DIR__ . '/../models/ChatSession.php';
require_once __DIR__ . '/../core/Logger.php';

class SubscriptionManager {
    private $subscriptionModel;
    private $chatSessionModel;

    // Tier configurations
    private $tierConfigs = [
        'free' => [
            'context_window_size' => 10,
            'max_sessions' => 1,
            'max_messages_per_session' => 100,
            'features' => ['thinking_traces', 'vector_memory', 'summarization']
        ],
        'pro' => [
            'context_window_size' => 100,
            'max_sessions' => 10,
            'max_messages_per_session' => 1000,
            'features' => ['thinking_traces', 'vector_memory', 'summarization']
        ],
        'enterprise' => [
            'context_window_size' => -1, // unlimited
            'max_sessions' => -1, // unlimited
            'max_messages_per_session' => -1, // unlimited
            'features' => ['thinking_traces', 'vector_memory', 'summarization', 'custom_models']
        ]
    ];

    public function __construct($db) {
        $this->subscriptionModel = new Subscription($db);
        $this->chatSessionModel = new ChatSession($db);
        Logger::debug("SubscriptionManager initialized");
    }

    /**
     * Get user limits based on subscription
     */
    public function getUserLimits($userId) {
        $startTime = microtime(true);

        try {
            $subscription = $this->subscriptionModel->getActiveByUserId($userId);

            if (!$subscription) {
                // Return free tier defaults
                $limits = $this->tierConfigs['free'];
                Logger::debug("User has no active subscription, returning free tier limits", [
                    'user_id' => $userId,
                    'limits' => $limits
                ]);
                return $limits;
            }

            $limits = $this->calculateEffectiveLimits($subscription);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("User limits retrieved", [
                'user_id' => $userId,
                'tier' => $subscription['tier'],
                'limits' => $limits,
                'duration_ms' => $duration
            ]);

            return $limits;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user limits", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Enforce subscription limits
     */
    public function enforceLimits($userId, $action) {
        $startTime = microtime(true);

        try {
            $limits = $this->getUserLimits($userId);
            $tier = $this->getUserTier($userId);

            switch ($action) {
                case 'chat_completion':
                    if (!$this->checkRateLimit($userId, $tier)) {
                        throw new Exception('Rate limit exceeded for your tier');
                    }
                    break;

                case 'create_session':
                    $activeSessions = $this->chatSessionModel->countActiveByUser($userId);
                    if ($limits['max_sessions'] > 0 && $activeSessions >= $limits['max_sessions']) {
                        throw new Exception('Maximum sessions reached for your tier');
                    }
                    break;

                case 'thinking_trace':
                    // Thinking traces are now available for all users
                    break;

                case 'summarization':
                    if (!$this->hasFeature($userId, 'summarization')) {
                        throw new Exception('Summarization not available for your tier');
                    }
                    break;

                default:
                    Logger::warning("Unknown action for limit enforcement", [
                        'user_id' => $userId,
                        'action' => $action
                    ]);
                    break;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Limits enforced successfully", [
                'user_id' => $userId,
                'action' => $action,
                'tier' => $tier,
                'duration_ms' => $duration
            ]);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Limit enforcement failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'action' => $action,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if user has specific feature
     */
    public function hasFeature($userId, $feature) {
        $startTime = microtime(true);

        try {
            // Enable thinking traces and vector memory for all users
            if (in_array($feature, ['thinking_traces', 'vector_memory', 'summarization'])) {
                $hasFeature = true;
            } else {
                $subscription = $this->subscriptionModel->getActiveByUserId($userId);

                if (!$subscription) {
                    // Free tier has limited features
                    $hasFeature = false;
                } else {
                    $features = json_decode($subscription['features'], true) ?: [];
                    $hasFeature = in_array($feature, $features);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Feature check completed", [
                'user_id' => $userId,
                'feature' => $feature,
                'has_feature' => $hasFeature,
                'duration_ms' => $duration
            ]);

            return $hasFeature;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Feature check failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'feature' => $feature,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get user tier
     */
    public function getUserTier($userId) {
        $startTime = microtime(true);

        try {
            $subscription = $this->subscriptionModel->getActiveByUserId($userId);
            $tier = $subscription ? $subscription['tier'] : 'free';

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("User tier retrieved", [
                'user_id' => $userId,
                'tier' => $tier,
                'duration_ms' => $duration
            ]);

            return $tier;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user tier", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Upgrade user subscription
     */
    public function upgradeSubscription($userId, $newTier) {
        $startTime = microtime(true);

        try {
            if (!isset($this->tierConfigs[$newTier])) {
                throw new InvalidArgumentException("Invalid tier: {$newTier}");
            }

            $success = $this->subscriptionModel->upgradeTier($userId, $newTier);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($success) {
                Logger::info("Subscription upgraded successfully", [
                    'user_id' => $userId,
                    'new_tier' => $newTier,
                    'duration_ms' => $duration
                ]);
            } else {
                Logger::warning("Subscription upgrade failed", [
                    'user_id' => $userId,
                    'new_tier' => $newTier,
                    'duration_ms' => $duration
                ]);
            }

            return $success;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription upgrade failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'new_tier' => $newTier,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Cancel user subscription
     */
    public function cancelSubscription($userId) {
        $startTime = microtime(true);

        try {
            $success = $this->subscriptionModel->cancel($userId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($success) {
                Logger::info("Subscription cancelled successfully", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
            } else {
                Logger::warning("Subscription cancellation failed", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
            }

            return $success;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Subscription cancellation failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Calculate effective limits from subscription
     */
    private function calculateEffectiveLimits($subscription) {
        $tierLimits = $this->tierConfigs[$subscription['tier']] ?? $this->tierConfigs['free'];
        $customLimits = json_decode($subscription['limits'], true) ?: [];

        return array_merge($tierLimits, $customLimits);
    }

    /**
     * Check rate limits (placeholder implementation)
     */
    private function checkRateLimit($userId, $tier) {
        // TODO: Implement actual rate limiting logic based on tier
        // For now, always return true
        return true;
    }

    /**
     * Get subscription statistics
     */
    public function getSubscriptionStats() {
        $startTime = microtime(true);

        try {
            $stats = $this->subscriptionModel->getStats();

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Subscription statistics retrieved", [
                'duration_ms' => $duration
            ]);

            return $stats;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get subscription statistics", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>