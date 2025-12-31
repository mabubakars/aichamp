<?php

/**
 * Local AI Provider for Ollama models
 * Implements AIProvider interface for local Ollama API integration
 */
class LocalAIProvider implements AIProvider {
    private $model;
    private $apiEndpoint;
    private $timeout = 600; // 5 minutes default timeout
    private $sessionId;
    private $messageInstance;

    /**
     * Constructor
     *
     * @param AIModel $model The AI model configuration
     */
    public function __construct($model) {
        $this->model = $model;
        $this->apiEndpoint = $model->getApiEndpoint();

        Logger::debug("LocalAIProvider initialized", [
            'model_name' => $model->model_name,
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
            // Prepare Ollama chat request with messages array
            $ollamaRequest = [
                'model' => $this->model->model_name,
                'messages' => $messages,
                'stream' => false
            ];

            // Add optional parameters
            if (isset($options['temperature'])) {
                $ollamaRequest['temperature'] = floatval($options['temperature']);
            }
            if (isset($options['max_tokens'])) {
                $ollamaRequest['max_tokens'] = intval($options['max_tokens']);
            }

            // Make API call
            $response = $this->makeApiCall($ollamaRequest);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Local AI chat completion successful", [
                'model' => $this->model->model_name,
                'duration_ms' => $duration,
                'response_length' => strlen($response['message']['content'] ?? '')
            ]);

            // Convert Ollama response to OpenAI format
            return $this->convertOllamaChatToOpenAI($response, $messages);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Local AI chat completion failed", [
                'model' => $this->model->model_name,
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
        Logger::debug("LocalAIProvider streamChatCompletions called", [
            'model_name' => $this->model->model_name,
            'messages_count' => count($messages),
            'session_id' => $options['session_id'] ?? null
        ]);

        // Set streaming headers
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        // Enable output buffering
        if (ob_get_level()) ob_end_clean();
        ob_start();
        ob_implicit_flush(true);

        // Set session and message instance for storage
        $this->sessionId = $options['session_id'] ?? null;
        $this->messageInstance = $options['message_instance'] ?? null;

        $startTime = microtime(true);

        try {
            // Prepare Ollama chat request with messages array
            $ollamaRequest = [
                'model' => $this->model->model_name,
                'messages' => $messages,
                'stream' => true
            ];

            // Add optional parameters
            if (isset($options['temperature'])) {
                $ollamaRequest['temperature'] = floatval($options['temperature']);
            }
            if (isset($options['max_tokens'])) {
                $ollamaRequest['max_tokens'] = intval($options['max_tokens']);
            }

            Logger::debug("Prepared Ollama request", [
                'model' => $this->model->model_name,
                'endpoint' => $this->apiEndpoint,
                'request' => $ollamaRequest
            ]);
            // Check Ollama health before streaming
            $this->checkOllamaHealth($this->model->model_name);

            // Stream from Ollama API

            // Stream from Ollama API
            $this->streamFromOllama($ollamaRequest);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Local AI streaming completion successful", [
                'model' => $this->model->model_name,
                'duration_ms' => $duration
            ]);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Local AI streaming completion failed", [
                'model' => $this->model->model_name,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);

            // Send error as SSE
            echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            echo "data: " . json_encode(['done' => true]) . "\n\n";
        }
    }

    /**
     * Convert OpenAI messages array to Ollama prompt string
     *
     * @param array $messages OpenAI format messages
     * @return string Ollama prompt
     */
    private function convertMessagesToPrompt($messages) {
        $prompt = '';

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            switch ($role) {
                case 'system':
                    $prompt .= "System: " . $content . "\n\n";
                    break;
                case 'user':
                    $prompt .= "User: " . $content . "\n\n";
                    break;
                case 'assistant':
                    $prompt .= "Assistant: " . $content . "\n\n";
                    break;
                default:
                    // Skip unknown roles
                    break;
            }
        }

        // End with Assistant prompt
        $prompt .= "Assistant: ";

        return $prompt;
    }

    /**
     * Make API call to Ollama
     *
     * @param array $request Ollama API request data
     * @return array Ollama API response
     * @throws Exception on API errors
     */
    private function makeApiCall($request) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            throw new Exception("Ollama API cURL error: " . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception("Ollama API HTTP error: " . $httpCode . " - " . $response);
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response from Ollama API");
        }

        return $data;
    }
    /**
     * Check if Ollama is running and available, and if the specific model is loaded
     *
     * @param string $modelName The model name to check for availability
     * @throws Exception if Ollama is not available or model is not found
     */
    private function checkOllamaHealth($modelName) {
        $tagsUrl = str_replace('/api/chat', '/api/tags', $this->apiEndpoint);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $tagsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            throw new Exception("Ollama is not available: " . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Exception("Ollama is not available: HTTP " . $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['models'])) {
            throw new Exception("Ollama is not available: Invalid response");
        }

        // Check if the specific model is available
        $modelFound = false;
        foreach ($data['models'] as $model) {
            if (isset($model['name']) && $model['name'] === $modelName) {
                $modelFound = true;
                break;
            }
        }

        if (!$modelFound) {
            throw new Exception("Model '$modelName' is not available in Ollama");
        }
    }


    /**
     * Stream from Ollama API and convert to OpenAI SSE format
     *
     * @param array $request Ollama API request data
     */
    private function streamFromOllama($request) {
        Logger::debug("Starting streamFromOllama", [
            'endpoint' => $this->apiEndpoint,
            'model' => $this->model->model_name,
            'timeout' => $this->timeout
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                return $this->processOllamaStreamChunk($data);
            }
        ]);

        Logger::debug("Executing curl request to Ollama", [
            'endpoint' => $this->apiEndpoint,
            'model' => $this->model->model_name
        ]);

        curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        Logger::debug("Curl request completed", [
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'model' => $this->model->model_name
        ]);

        curl_close($ch);

        if ($curlError) {
            Logger::error("Ollama API cURL error", [
                'error' => $curlError,
                'endpoint' => $this->apiEndpoint,
                'model' => $this->model->model_name
            ]);
            echo "data: " . json_encode(['error' => 'Ollama API cURL error: ' . $curlError]) . "\n\n";
        } elseif ($httpCode !== 200) {
            Logger::error("Ollama API HTTP error", [
                'http_code' => $httpCode,
                'endpoint' => $this->apiEndpoint,
                'model' => $this->model->model_name
            ]);
            echo "data: " . json_encode(['error' => 'Ollama API HTTP error: ' . $httpCode]) . "\n\n";
        }

        Logger::debug("Sending final done signal", [
            'model' => $this->model->model_name
        ]);

        // Send final done signal
        echo "data: " . json_encode(['done' => true]) . "\n\n";
    }

    /**
     * Process Ollama streaming chunk and convert to OpenAI format
     *
     * @param string $data Raw data from Ollama stream
     * @return int Length of processed data
     */
    private function processOllamaStreamChunk($data) {
        static $buffer = '';
        static $fullContent = '';
        static $aiMessageId = null;

        Logger::debug("Received Ollama stream chunk", [
            'data_length' => strlen($data),
            'data_preview' => substr($data, 0, 100),
            'model' => $this->model->model_name
        ]);

        $buffer .= $data;

        // Process complete JSON objects
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if (empty($line)) continue;

            Logger::debug("Processing Ollama line", [
                'line' => $line,
                'model' => $this->model->model_name
            ]);

            try {
                $ollamaData = json_decode($line, true);

                Logger::debug("Decoded Ollama data", [
                    'ollama_data' => $ollamaData,
                    'model' => $this->model->model_name
                ]);

                if ($ollamaData && isset($ollamaData['message'])) {
                    $content = $ollamaData['message']['content'] ?? '';
                    $fullContent .= $content;

                    Logger::debug("Processing content chunk", [
                        'content' => $content,
                        'full_content_length' => strlen($fullContent),
                        'model' => $this->model->model_name
                    ]);

                    // Create AI message on first chunk
                    if ($aiMessageId === null && $this->messageInstance && $this->sessionId) {
                        $aiMessageId = $this->messageInstance->create([
                            'chat_session_id' => $this->sessionId,
                            'role' => 'assistant',
                            'content' => '',
                            'model_id' => $this->model->id
                        ]);
                        Logger::debug("Created AI message", [
                            'ai_message_id' => $aiMessageId,
                            'session_id' => $this->sessionId,
                            'model' => $this->model->model_name
                        ]);
                    }

                    // Convert to OpenAI streaming format
                    $openaiChunk = [
                        'id' => 'chatcmpl-' . ($aiMessageId ?: uniqid()),
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $this->model->name,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => [
                                    'content' => $content
                                ],
                                'finish_reason' => $ollamaData['done'] ? 'stop' : null
                            ]
                        ]
                    ];

                    Logger::debug("Sending OpenAI chunk", [
                        'chunk' => $openaiChunk,
                        'model' => $this->model->model_name
                    ]);

                    echo "data: " . json_encode($openaiChunk) . "\n\n";
                }

                if ($ollamaData && $ollamaData['done']) {
                    Logger::debug("Ollama stream done", [
                        'full_content_length' => strlen($fullContent),
                        'model' => $this->model->model_name
                    ]);

                    // Update the message tokens and content
                    if ($aiMessageId && $this->messageInstance) {
                        $this->messageInstance->updateTokensUsed($aiMessageId, strlen($fullContent) / 4); // Rough estimate
                        $this->messageInstance->update($aiMessageId, ['content' => $fullContent]);
                    }
                    echo "data: " . json_encode(['done' => true]) . "\n\n";
                    break;
                }

            } catch (Exception $e) {
                Logger::error("Error processing Ollama stream chunk", [
                    'error' => $e->getMessage(),
                    'chunk' => $line,
                    'model' => $this->model->model_name
                ]);
            }
        }

        ob_flush();
        flush();
        return strlen($data);
    }

    /**
     * Convert Ollama chat response to OpenAI format
     *
     * @param array $ollamaResponse Ollama API response
     * @param array $originalMessages Original OpenAI messages
     * @return array OpenAI-compatible response
     */
    private function convertOllamaChatToOpenAI($ollamaResponse, $originalMessages) {
        return [
            'id' => 'chatcmpl-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->model->model_name,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $ollamaResponse['message']['content'] ?? ''
                    ],
                    'finish_reason' => $ollamaResponse['done'] ? 'stop' : 'length'
                ]
            ],
            'usage' => [
                'prompt_tokens' => $ollamaResponse['prompt_eval_count'] ?? 0,
                'completion_tokens' => $ollamaResponse['eval_count'] ?? 0,
                'total_tokens' => ($ollamaResponse['prompt_eval_count'] ?? 0) + ($ollamaResponse['eval_count'] ?? 0)
            ]
        ];
    }

    /**
     * Generate embeddings for text (not supported by Ollama)
     *
     * @param string|array $input Text or array of texts to embed
     * @param array $options Additional options (model, etc.)
     * @return array OpenAI-compatible embeddings response format
     * @throws Exception Always throws exception as Ollama doesn't support embeddings
     */
    public function createEmbeddings($input, $options = []) {
        throw new Exception("Embeddings are not supported by Ollama provider. Use OpenAI or another embedding-capable provider.");
    }
}
?>