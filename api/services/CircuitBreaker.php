<?php

require_once __DIR__ . '/../core/Logger.php';

/**
 * Circuit Breaker pattern implementation for AI provider resilience
 * Prevents cascading failures by temporarily stopping requests to failing providers
 */
class CircuitBreaker {
    private $failureThreshold;
    private $timeoutMs;
    private $state;
    private $failureCount;
    private $lastFailureTime;
    private $logger;

    const STATE_CLOSED = 'closed';     // Normal operation
    const STATE_OPEN = 'open';         // Failing, reject requests
    const STATE_HALF_OPEN = 'half_open'; // Testing if service recovered

    public function __construct($failureThreshold = 5, $timeoutMs = 60000) {
        $this->failureThreshold = $failureThreshold;
        $this->timeoutMs = $timeoutMs;
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->lastFailureTime = 0;
        $this->logger = Logger::getInstance();
    }

    /**
     * Check if request should be allowed
     */
    public function allowRequest() {
        switch ($this->state) {
            case self::STATE_CLOSED:
                return true;

            case self::STATE_OPEN:
                if ($this->shouldAttemptReset()) {
                    $this->state = self::STATE_HALF_OPEN;
                    $this->logger->info("Circuit breaker transitioning to half-open", [
                        'failure_count' => $this->failureCount,
                        'timeout_ms' => $this->timeoutMs
                    ]);
                    return true;
                }
                return false;

            case self::STATE_HALF_OPEN:
                return true;

            default:
                return false;
        }
    }

    /**
     * Record successful request
     */
    public function recordSuccess() {
        $this->failureCount = 0;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_CLOSED;
            $this->logger->info("Circuit breaker closed - service recovered");
        }
    }

    /**
     * Record failed request
     */
    public function recordFailure() {
        $this->failureCount++;
        $this->lastFailureTime = time() * 1000; // milliseconds

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_OPEN;
            $this->logger->warning("Circuit breaker opened after half-open failure", [
                'failure_count' => $this->failureCount
            ]);
        } elseif ($this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            $this->logger->warning("Circuit breaker opened due to failure threshold", [
                'failure_count' => $this->failureCount,
                'threshold' => $this->failureThreshold
            ]);
        }
    }

    /**
     * Check if we should attempt to reset the circuit
     */
    private function shouldAttemptReset() {
        return (time() * 1000 - $this->lastFailureTime) >= $this->timeoutMs;
    }

    /**
     * Get current state
     */
    public function getState() {
        return $this->state;
    }

    /**
     * Get failure count
     */
    public function getFailureCount() {
        return $this->failureCount;
    }

    /**
     * Reset circuit breaker
     */
    public function reset() {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->lastFailureTime = 0;
        $this->logger->info("Circuit breaker manually reset");
    }

    /**
     * Get circuit breaker status
     */
    public function getStatus() {
        return [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'failure_threshold' => $this->failureThreshold,
            'timeout_ms' => $this->timeoutMs,
            'last_failure_time' => $this->lastFailureTime,
            'time_since_last_failure' => time() * 1000 - $this->lastFailureTime
        ];
    }
}