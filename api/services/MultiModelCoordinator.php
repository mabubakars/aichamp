<?php

require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/AIProviderFactory.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/RetryHandler.php';
require_once __DIR__ . '/PerformanceMonitor.php';
require_once __DIR__ . '/../core/Logger.php';

/**
 * Coordinates parallel requests to multiple AI models
 * Handles request distribution, timeout management, and result collection
 */
class MultiModelCoordinator {
    private $config;
    private $logger;
    private $circuitBreakers;
    private $retryHandler;

    public function __construct(array $config = []) {
        $this->config = array_merge([
            'max_concurrent_requests' => 5,
            'request_timeout_ms' => 30000,
            'retry_attempts' => 2,
            'retry_backoff_ms' => 1000,
            'circuit_breaker_enabled' => true,
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout_ms' => 60000
        ], $config);

        $this->logger = Logger::getInstance();
        $this->circuitBreakers = [];
        $this->retryHandler = new RetryHandler(
            $this->config['retry_attempts'],
            $this->config['retry_backoff_ms'],
            $this->config['request_timeout_ms']
        );
    }

    /**
     * Execute parallel requests to multiple models
     *
     * @param array $models Array of AIModel objects
     * @param array $messages OpenAI format messages
     * @param array $options Request options
     * @return array Results from all models
     */
    public function executeParallelRequests($models, $messages, $options = []) {
        $startTime = microtime(true);
        $requestId = $options['request_id'] ?? uniqid('mm_', true);

        $this->logger->info("Starting parallel multi-model requests", [
            'request_id' => $requestId,
            'model_count' => count($models),
            'timeout_ms' => $this->config['request_timeout_ms']
        ]);

        $results = [];
        $handles = [];
        $multiHandle = curl_multi_init();

        // Limit concurrent requests
        $maxConcurrent = min($this->config['max_concurrent_requests'], count($models));
        $batches = array_chunk($models, $maxConcurrent);

        foreach ($batches as $batch) {
            $batchResults = $this->executeBatch($batch, $messages, $options, $multiHandle);
            $results = array_merge($results, $batchResults);
        }

        curl_multi_close($multiHandle);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Record performance metrics
        if (isset($options['performance_monitor'])) {
            $options['performance_monitor']->recordMultiModelMetrics(
                $requestId,
                count($models),
                $duration,
                $results,
                [
                    'aggregation_strategy' => $options['aggregation_strategy'] ?? 'combine_all',
                    'max_concurrent' => $maxConcurrent
                ]
            );
        }

        $this->logger->info("Parallel requests completed", [
            'request_id' => $requestId,
            'total_models' => count($models),
            'successful_responses' => count(array_filter($results, fn($r) => $r['success'])),
            'failed_responses' => count(array_filter($results, fn($r) => !$r['success'])),
            'total_duration_ms' => $duration
        ]);

        return $results;
    }

    /**
     * Execute a batch of requests
     */
    private function executeBatch($models, $messages, $options, $multiHandle) {
        $handles = [];
        $results = [];

        // Initialize curl handles for this batch
        foreach ($models as $model) {
            // Check circuit breaker
            $circuitBreaker = $this->getCircuitBreaker($model->id);
            if (!$circuitBreaker->allowRequest()) {
                $results[] = [
                    'model' => $model,
                    'error' => 'Circuit breaker open',
                    'duration_ms' => 0,
                    'success' => false
                ];
                continue;
            }

            $handle = $this->createCurlHandle($model, $messages, $options);
            if ($handle) {
                $handles[] = [
                    'handle' => $handle,
                    'model' => $model,
                    'circuit_breaker' => $circuitBreaker,
                    'start_time' => microtime(true)
                ];
                curl_multi_add_handle($multiHandle, $handle);
            }
        }

        // Execute requests
        $active = null;
        do {
            $status = curl_multi_exec($multiHandle, $active);
            curl_multi_select($multiHandle);
        } while ($active && $status == CURLM_OK);

        // Collect results
        foreach ($handles as $handleData) {
            $handle = $handleData['handle'];
            $model = $handleData['model'];
            $circuitBreaker = $handleData['circuit_breaker'];

            $response = curl_multi_getcontent($handle);
            $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $duration = round((microtime(true) - $handleData['start_time']) * 1000, 2);

            if ($response && $httpCode == 200) {
                $result = json_decode($response, true);
                if ($result) {
                    $circuitBreaker->recordSuccess();
                    $results[] = [
                        'model' => $model,
                        'response' => $result,
                        'duration_ms' => $duration,
                        'success' => true
                    ];
                } else {
                    $circuitBreaker->recordFailure();
                    $results[] = [
                        'model' => $model,
                        'error' => 'Invalid JSON response',
                        'duration_ms' => $duration,
                        'success' => false
                    ];
                }
            } else {
                $error = curl_error($handle);
                $circuitBreaker->recordFailure();
                $results[] = [
                    'model' => $model,
                    'error' => $error ?: 'HTTP ' . $httpCode,
                    'duration_ms' => $duration,
                    'success' => false
                ];
            }

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        return $results;
    }

    /**
     * Create curl handle for a model request
     */
    private function createCurlHandle($model, $messages, $options) {
        try {
            // Prepare request data similar to RemoteAIProvider
            $requestData = $this->prepareRequestData($model, $messages, $options);

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $model->getApiEndpoint(),
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->getApiKey($model),
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->config['request_timeout_ms'],
                CURLOPT_PRIVATE => $model // Store model reference
            ]);

            return $ch;

        } catch (Exception $e) {
            $this->logger->error("Failed to create curl handle for model", [
                'model_id' => $model->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Prepare request data for a model
     */
    private function prepareRequestData($model, $messages, $options) {
        $requestData = [
            'model' => $model->name,
            'messages' => $messages,
            'stream' => false
        ];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $requestData['temperature'] = floatval($options['temperature']);
        }

        if (isset($options['max_tokens'])) {
            $requestData['max_tokens'] = intval($options['max_tokens']);
        }

        if (isset($options['top_p'])) {
            $requestData['top_p'] = floatval($options['top_p']);
        }

        return $requestData;
    }

    /**
     * Get API key for a model
     */
    private function getApiKey($model) {
        // Get from environment variable based on provider
        $provider = strtoupper($model->provider ?? 'unknown');
        $envKey = $provider . '_API_KEY';

        // Need to include Environment class
        require_once __DIR__ . '/../config/Environment.php';
        $apiKey = Environment::get($envKey, '');

        if (empty($apiKey)) {
            throw new Exception("No API key configured for provider: {$model->provider}");
        }

        return $apiKey;
    }

    /**
     * Get circuit breaker for a model
     */
    private function getCircuitBreaker($modelId) {
        if (!isset($this->circuitBreakers[$modelId])) {
            $this->circuitBreakers[$modelId] = new CircuitBreaker(
                $this->config['circuit_breaker_threshold'],
                $this->config['circuit_breaker_timeout_ms']
            );
        }
        return $this->circuitBreakers[$modelId];
    }

    /**
     * Get circuit breaker status for all models
     */
    public function getCircuitBreakerStatus() {
        $status = [];
        foreach ($this->circuitBreakers as $modelId => $breaker) {
            $status[$modelId] = $breaker->getStatus();
        }
        return $status;
    }

    /**
     * Reset circuit breaker for a model
     */
    public function resetCircuitBreaker($modelId) {
        if (isset($this->circuitBreakers[$modelId])) {
            $this->circuitBreakers[$modelId]->reset();
            $this->logger->info("Circuit breaker reset for model", ['model_id' => $modelId]);
        }
    }

    /**
     * Get configuration
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function setConfig($key, $value) {
        $this->config[$key] = $value;
    }
}