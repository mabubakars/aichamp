<?php

require_once __DIR__ . '/../config/Environment.php';

/**
 * Remote AI Provider for OpenAI, Anthropic, and other remote AI APIs
 * Implements AIProvider interface for remote API integration
 */
class RemoteAIProvider implements AIProvider {
    private $model;
    private $apiEndpoint;
    private $apiKey;
    private $timeout = 300; // 5 minutes default timeout
    private $sessionId;
    private $messageInstance;

    /**
     * Constructor
     *
     * @param AIModel $model The AI model configuration
     */
    public function __construct($model) {
        $this->model = $model;

        // Read API endpoint and key directly from environment variables
        $provider = strtoupper($model->provider);
        $this->apiEndpoint = Environment::get($provider . '_API_URL', '');
        $this->apiKey = Environment::get($provider . '_API_KEY', '');

        if (empty($this->apiEndpoint)) {
            throw new Exception("No API URL configured for provider: {$model->provider}");
        }

        if (empty($this->apiKey)) {
            throw new Exception("No API key configured for provider: {$model->provider}");
        }

        Logger::debug("RemoteAIProvider initialized", [
            'model_name' => $model->model_name,
            'provider' => $model->provider,
            'endpoint' => $this->apiEndpoint
        ]);
    }

    /**
     * Perform chat completion (non-streaming)
     *
     * @param array $messages OpenAI format messages array
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return array OpenAI-compatible response format
     * @throws Exception on API errors
     */
    public function chatCompletions($messages, $options = []) {
        $startTime = microtime(true);

        try {
            // Prepare OpenAI-compatible request
            $requestData = $this->prepareRequestData($messages, $options, false);

            // Make API call
            $response = $this->makeApiCall($requestData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Remote AI chat completion successful", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'duration_ms' => $duration,
                'response_length' => strlen($response['choices'][0]['message']['content'] ?? '')
            ]);

            return $response;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Remote AI chat completion failed", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Perform streaming chat completion
     *
     * @param array $messages OpenAI format messages array
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return void Streams OpenAI-compatible SSE format
     * @throws Exception on API errors
     */
    public function streamChatCompletions($messages, $options = []) {
        // Set streaming headers
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        // Enable output buffering
        if (ob_get_level()) ob_end_clean();
        ob_start();
        ob_implicit_flush(true);

        $startTime = microtime(true);

        try {
            // Set session and message instance for storage
            $this->sessionId = $options['session_id'] ?? null;
            $this->messageInstance = $options['message_instance'] ?? null;

            // Prepare OpenAI-compatible request
            $requestData = $this->prepareRequestData($messages, $options, true);

            // Stream from remote API
            $this->streamFromRemoteAPI($requestData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Remote AI streaming completion successful", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'duration_ms' => $duration
            ]);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Remote AI streaming completion failed", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);

            // Send error as SSE
            echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            echo "data: " . json_encode(['done' => true]) . "\n\n";
        }
    }

    /**
     * Prepare OpenAI-compatible request data
     *
     * @param array $messages OpenAI format messages
     * @param array $options Additional options
     * @param bool $stream Whether this is a streaming request
     * @return array Request data
     */
    private function prepareRequestData($messages, $options, $stream = false) {
        $requestData = [
            'model' => $this->model->model_name,
            'messages' => $messages,
            'stream' => $stream
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

        if (isset($options['frequency_penalty'])) {
            $requestData['frequency_penalty'] = floatval($options['frequency_penalty']);
        }

        if (isset($options['presence_penalty'])) {
            $requestData['presence_penalty'] = floatval($options['presence_penalty']);
        }

        return $requestData;
    }


    /**
     * Make API call to remote service
     *
     * @param array $requestData Request data
     * @return array OpenAI-compatible response
     * @throws Exception on API errors
     */
    private function makeApiCall($requestData) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        Logger::debug("Remote API request", [
            'endpoint' => $this->apiEndpoint,
            'model' => $this->model->model_name,
            'provider' => $this->model->provider,
            'request_data' => $requestData,
            'headers' => [
                'Authorization' => 'Bearer ' . substr($this->apiKey, 0, 10) . '...', // Partial key for security
                'Content-Type' => 'application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        Logger::debug("Remote API response", [
            'http_code' => $httpCode,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500), // First 500 chars
            'curl_error' => $curlError
        ]);

        if ($curlError) {
            throw new Exception("Remote API cURL error: " . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception("Remote API HTTP error: " . $httpCode . " - " . $response);
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response from remote API");
        }

        return $data;
    }

    /**
     * Stream from remote API and convert to OpenAI SSE format
     *
     * @param array $requestData Request data
     */
    private function streamFromRemoteAPI($requestData) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                return $this->processRemoteStreamChunk($data);
            }
        ]);

        curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            echo "data: " . json_encode(['error' => 'Remote API cURL error: ' . $curlError]) . "\n\n";
        } elseif ($httpCode !== 200) {
            echo "data: " . json_encode(['error' => 'Remote API HTTP error: ' . $httpCode]) . "\n\n";
        }

        // Send final done signal
        echo "data: " . json_encode(['done' => true]) . "\n\n";
    }

    /**
     * Process remote streaming chunk and convert to OpenAI format
     *
     * @param string $data Raw data from remote stream
     * @return int Length of processed data
     */
    private function processRemoteStreamChunk($data) {
        static $buffer = '';
        static $fullContent = '';
        static $aiMessageId = null;

        $buffer .= $data;

        // Process complete lines
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if (empty($line)) continue;

            // Handle SSE format: "data: {...}"
            if (strpos($line, 'data: ') === 0) {
                $jsonData = substr($line, 6);

                if ($jsonData === '[DONE]') {
                    // Update the message tokens
                    if ($aiMessageId && $this->messageInstance) {
                        $this->messageInstance->updateTokensUsed($aiMessageId, strlen($fullContent) / 4); // Rough estimate
                    }
                    echo "data: " . json_encode(['done' => true]) . "\n\n";
                    break;
                }

                try {
                    $chunkData = json_decode($jsonData, true);

                    if ($chunkData && isset($chunkData['choices'][0]['delta']['content'])) {
                        $content = $chunkData['choices'][0]['delta']['content'];
                        $fullContent .= $content;

                        // Create AI message on first content chunk
                        if ($aiMessageId === null && $this->messageInstance && $this->sessionId) {
                            $aiMessageId = $this->messageInstance->create([
                                'chat_session_id' => $this->sessionId,
                                'role' => 'assistant',
                                'content' => '',
                                'model_id' => $this->model->id,
                                'model' => $this->model->model_name
                            ]);
                        }
                    }

                    if ($chunkData && isset($chunkData['choices'])) {
                        // Remote API already returns OpenAI format, just forward it
                        echo "data: " . json_encode($chunkData) . "\n\n";
                    }

                } catch (Exception $e) {
                    Logger::error("Error processing remote stream chunk", [
                        'error' => $e->getMessage(),
                        'chunk' => $line
                    ]);
                }
            }
        }

        ob_flush();
        flush();
        return strlen($data);
    }

    /**
     * Generate embeddings for text
     *
     * @param string|array $input Text or array of texts to embed
     * @param array $options Additional options (model, etc.)
     * @return array OpenAI-compatible embeddings response format
     * @throws Exception on API errors
     */
    public function createEmbeddings($input, $options = []) {
        $startTime = microtime(true);

        try {
            // Prepare OpenAI-compatible embedding request
            $requestData = [
                'input' => $input,
                'model' => $options['model'] ?? $this->model->model_name,
                'encoding_format' => $options['encoding_format'] ?? 'float'
            ];

            // Add optional parameters
            if (isset($options['dimensions'])) {
                $requestData['dimensions'] = intval($options['dimensions']);
            }

            if (isset($options['user'])) {
                $requestData['user'] = $options['user'];
            }

            // Make API call
            $response = $this->makeEmbeddingApiCall($requestData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Remote AI embeddings created successfully", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'input_count' => is_array($input) ? count($input) : 1,
                'duration_ms' => $duration
            ]);

            return $response;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Remote AI embeddings failed", [
                'model' => $this->model->model_name,
                'provider' => $this->model->provider,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Make API call to remote embedding service
     *
     * @param array $requestData Request data
     * @return array OpenAI-compatible embeddings response
     * @throws Exception on API errors
     */
    private function makeEmbeddingApiCall($requestData) {
        // Use embeddings endpoint instead of chat completions
        $embeddingEndpoint = $this->getEmbeddingEndpoint();

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $embeddingEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        Logger::debug("Embedding API request", [
            'endpoint' => $embeddingEndpoint,
            'model' => $this->model->model_name,
            'provider' => $this->model->provider,
            'input_count' => is_array($requestData['input']) ? count($requestData['input']) : 1
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        Logger::debug("Embedding API response", [
            'http_code' => $httpCode,
            'response_length' => strlen($response),
            'curl_error' => $curlError
        ]);

        if ($curlError) {
            throw new Exception("Embedding API cURL error: " . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception("Embedding API HTTP error: " . $httpCode . " - " . $response);
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response from embedding API");
        }

        return $data;
    }

    /**
     * Get embedding endpoint for the provider
     *
     * @return string Embedding API endpoint
     */
    private function getEmbeddingEndpoint() {
        $provider = strtolower($this->model->provider);

        switch ($provider) {
            case 'openai':
                return 'https://api.openai.com/v1/embeddings';
            case 'anthropic':
                // Anthropic doesn't have embeddings, use OpenAI as fallback
                return 'https://api.openai.com/v1/embeddings';
            default:
                // For other providers, try to construct endpoint
                $baseEndpoint = rtrim($this->apiEndpoint, '/');
                if (strpos($baseEndpoint, '/chat/completions') !== false) {
                    return str_replace('/chat/completions', '/embeddings', $baseEndpoint);
                }
                return $baseEndpoint . '/embeddings';
        }
    }
}
?>