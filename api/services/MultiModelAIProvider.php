<?php

require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/MultiModelCoordinator.php';
require_once __DIR__ . '/../core/Logger.php';

/**
 * Multi-Model AI Provider
 * Orchestrates simultaneous queries to multiple AI models and aggregates responses
 */
class MultiModelAIProvider implements AIProvider {
    private $coordinator;
    private $config;
    private $logger;

    /**
     * Constructor
     *
     * @param MultiModelCoordinator $coordinator The coordinator for parallel requests
     * @param array $config Configuration options
     */
    public function __construct(MultiModelCoordinator $coordinator, array $config = []) {
        $this->coordinator = $coordinator;
        $this->config = array_merge([
            'aggregation_strategy' => 'combine_all',
            'min_successful_responses' => 1,
            'max_concurrent_requests' => 5
        ], $config);

        $this->logger = Logger::getInstance();
    }

    /**
     * Perform chat completion across multiple models
     *
     * @param array $messages OpenAI format messages array
     * @param array $options Additional options including 'models' array
     * @return array OpenAI-compatible response format with aggregated results
     * @throws Exception on API errors
     */
    public function chatCompletions($messages, $options = []) {
        $startTime = microtime(true);

        try {
            // Extract models from options
            $models = $options['models'] ?? [];
            if (empty($models)) {
                throw new InvalidArgumentException("No models specified for multi-model request");
            }

            $this->logger->info("Starting multi-model chat completion", [
                'model_count' => count($models),
                'aggregation_strategy' => $this->config['aggregation_strategy']
            ]);

            // Execute parallel requests
            $results = $this->coordinator->executeParallelRequests($models, $messages, $options);

            // Aggregate results
            $aggregatedResponse = $this->aggregateResults($results, $messages, $options);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info("Multi-model chat completion completed", [
                'total_models' => count($models),
                'successful_responses' => count(array_filter($results, fn($r) => $r['success'])),
                'aggregation_strategy' => $this->config['aggregation_strategy'],
                'total_duration_ms' => $duration
            ]);

            return $aggregatedResponse;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->error("Multi-model chat completion failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Aggregate results from multiple model responses
     *
     * @param array $results Individual model results
     * @param array $messages Original messages
     * @param array $options Request options
     * @return array Aggregated OpenAI-compatible response
     */
    private function aggregateResults($results, $messages, $options) {
        $successfulResults = array_filter($results, fn($r) => $r['success']);
        $failedResults = array_filter($results, fn($r) => !$r['success']);

        // Check minimum successful responses
        if (count($successfulResults) < $this->config['min_successful_responses']) {
            return $this->createErrorResponse('insufficient_responses', [
                'successful_count' => count($successfulResults),
                'required_minimum' => $this->config['min_successful_responses'],
                'failures' => array_column($failedResults, 'error', 'model')
            ]);
        }

        // Apply aggregation strategy
        switch ($this->config['aggregation_strategy']) {
            case 'prioritize_fastest':
                return $this->prioritizeFastest($successfulResults);

            case 'prioritize_best':
                return $this->prioritizeBest($successfulResults);

            case 'combine_all':
            default:
                return $this->combineAll($successfulResults, $failedResults);
        }
    }

    /**
     * Prioritize the fastest successful response
     */
    private function prioritizeFastest($results) {
        // Sort by duration (ascending)
        usort($results, fn($a, $b) => $a['duration_ms'] <=> $b['duration_ms']);

        $fastest = $results[0];
        return $this->createSuccessResponse($fastest['response'], [
            'strategy' => 'prioritize_fastest',
            'selected_model' => $fastest['model']->name,
            'response_time_ms' => $fastest['duration_ms'],
            'total_models' => count($results)
        ]);
    }

    /**
     * Prioritize the "best" response (placeholder - would need scoring logic)
     */
    private function prioritizeBest($results) {
        // For now, just return the first response
        // In production, implement scoring based on confidence, coherence, etc.
        $best = $results[0];
        return $this->createSuccessResponse($best['response'], [
            'strategy' => 'prioritize_best',
            'selected_model' => $best['model']->name,
            'total_models' => count($results)
        ]);
    }

    /**
     * Combine all responses into a single aggregated response
     */
    private function combineAll($successfulResults, $failedResults) {
        $combinedContent = '';
        $totalTokens = 0;
        $modelResponses = [];

        foreach ($successfulResults as $result) {
            $content = $result['response']['choices'][0]['message']['content'] ?? '';
            $modelName = $result['model']->name ?? 'unknown';

            $combinedContent .= "\n\n--- Response from {$modelName} ---\n{$content}";
            $totalTokens += $result['response']['usage']['total_tokens'] ?? 0;

            $modelResponses[] = [
                'model' => $modelName,
                'content' => $content,
                'tokens' => $result['response']['usage']['total_tokens'] ?? 0,
                'duration_ms' => $result['duration_ms']
            ];
        }

        // Remove leading newlines
        $combinedContent = ltrim($combinedContent, "\n");

        return [
            'id' => 'multi-model-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'multi-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $combinedContent
                    ],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'prompt_tokens' => $successfulResults[0]['response']['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $totalTokens,
                'total_tokens' => ($successfulResults[0]['response']['usage']['prompt_tokens'] ?? 0) + $totalTokens
            ],
            'metadata' => [
                'aggregation_strategy' => 'combine_all',
                'successful_models' => count($successfulResults),
                'failed_models' => count($failedResults),
                'model_responses' => $modelResponses,
                'total_latency_ms' => max(array_column($successfulResults, 'duration_ms'))
            ]
        ];
    }

    /**
     * Create a successful response
     */
    private function createSuccessResponse($response, $metadata) {
        return array_merge($response, [
            'metadata' => array_merge($response['metadata'] ?? [], $metadata)
        ]);
    }

    /**
     * Create an error response
     */
    private function createErrorResponse($errorType, $details) {
        return [
            'error' => [
                'type' => $errorType,
                'message' => $this->getErrorMessage($errorType),
                'details' => $details
            ]
        ];
    }

    /**
     * Get human-readable error message
     */
    private function getErrorMessage($errorType) {
        $messages = [
            'insufficient_responses' => 'Insufficient successful responses from models',
            'timeout' => 'Request timeout exceeded',
            'network_error' => 'Network communication error',
            'api_error' => 'API error from one or more models'
        ];

        return $messages[$errorType] ?? 'Unknown error';
    }

    /**
     * Streaming is not supported for multi-model requests
     *
     * @throws Exception Always throws exception
     */
    public function streamChatCompletions($messages, $options = []) {
        throw new Exception("Streaming is not supported for multi-model requests");
    }

    /**
     * Create embeddings (not supported for multi-model)
     *
     * @throws Exception Always throws exception
     */
    public function createEmbeddings($input, $options = []) {
        throw new Exception("Embeddings are not supported for multi-model requests");
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