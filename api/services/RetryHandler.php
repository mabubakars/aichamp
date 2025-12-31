<?php

require_once __DIR__ . '/../core/Logger.php';

/**
 * Retry handler with exponential backoff for AI provider requests
 */
class RetryHandler {
    private $maxAttempts;
    private $baseDelayMs;
    private $maxDelayMs;
    private $logger;

    public function __construct($maxAttempts = 3, $baseDelayMs = 1000, $maxDelayMs = 30000) {
        $this->maxAttempts = $maxAttempts;
        $this->baseDelayMs = $baseDelayMs;
        $this->maxDelayMs = $maxDelayMs;
        $this->logger = Logger::getInstance();
    }

    /**
     * Execute function with retry logic
     *
     * @param callable $function Function to retry
     * @param array $context Context for logging
     * @return mixed Result of successful execution
     * @throws Exception Last exception if all retries fail
     */
    public function executeWithRetry($function, $context = []) {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $function();

                if ($attempt > 1) {
                    $this->logger->info("Retry successful", array_merge($context, [
                        'attempt' => $attempt,
                        'max_attempts' => $this->maxAttempts
                    ]));
                }

                return $result;

            } catch (Exception $e) {
                $lastException = $e;

                $this->logger->warning("Retry attempt failed", array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'error' => $e->getMessage()
                ]));

                if ($attempt < $this->maxAttempts) {
                    $delay = $this->calculateDelay($attempt);
                    $this->logger->debug("Waiting before retry", [
                        'delay_ms' => $delay,
                        'attempt' => $attempt
                    ]);
                    usleep($delay * 1000); // Convert to microseconds
                }
            }
        }

        $this->logger->error("All retry attempts failed", array_merge($context, [
            'max_attempts' => $this->maxAttempts,
            'final_error' => $lastException->getMessage()
        ]));

        throw $lastException;
    }

    /**
     * Calculate delay for exponential backoff with jitter
     */
    private function calculateDelay($attempt) {
        $delay = $this->baseDelayMs * pow(2, $attempt - 1);

        // Add jitter (±25%)
        $jitter = $delay * 0.25 * (mt_rand(-100, 100) / 100);
        $delay += $jitter;

        // Cap at max delay
        return min($delay, $this->maxDelayMs);
    }

    /**
     * Check if exception is retryable
     */
    public function isRetryableException($exception) {
        $message = strtolower($exception->getMessage());

        // Retry on network errors, timeouts, rate limits
        $retryablePatterns = [
            'timeout',
            'network',
            'connection',
            'rate limit',
            '429',
            '502',
            '503',
            '504'
        ];

        foreach ($retryablePatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get retry configuration
     */
    public function getConfig() {
        return [
            'max_attempts' => $this->maxAttempts,
            'base_delay_ms' => $this->baseDelayMs,
            'max_delay_ms' => $this->maxDelayMs
        ];
    }
}