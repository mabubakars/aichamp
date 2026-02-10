<?php

class FastAPIProvider implements AIProvider {
    private $baseUrl;
    private $modelName;
    private $provider;

    public function __construct($model = null) {
        $this->baseUrl = Environment::get('PYTHON_FASTAPI_URL');
        
        if (empty($this->baseUrl)) {
            throw new Exception("PYTHON_FASTAPI_URL environment variable is not set");
        }
        
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new Exception("PYTHON_FASTAPI_URL is not a valid URL: " . $this->baseUrl);
        }
        
        if ($model) {
            $this->modelName = is_array($model) ? $model['model_name'] : $model->model_name;
            $this->provider = is_array($model) ? $model['provider'] : $model->provider;
        }
    }

    public function chatCompletions($messages, $options = []) {
        // Normalize messages to ensure they're in the correct format
        $normalizedMessages = $this->normalizeMessages($messages);
        
        // Prepare context_data and options as empty objects if empty
        $contextData = !empty($options['context_data']) 
            ? $options['context_data'] 
            : new \stdClass();
            
        $llmOptions = !empty($options['llm_options']) 
            ? $options['llm_options'] 
            : new \stdClass();
        
        $payload = [
            'session_id' => $options['session_id'] ?? 'none',
            'user_id' => $options['user_id'] ?? 'none',
            'messages' => $normalizedMessages,  // Keep as array
            'model' => $this->modelName ?? $options['model_name'] ?? 'default',
            'provider' => $this->provider ?? $options['provider'] ?? 'ollama',
            'context_data' => $contextData,  // Empty object if not provided
            'options' => $llmOptions  // Empty object if not provided
        ];

        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions';
        
        Logger::debug("Calling FastAPI", [
            'url' => $url, 
            'model' => $payload['model'],
            'provider' => $payload['provider'],
            'message_count' => count($normalizedMessages)
        ]);
        
        // Don't use JSON_FORCE_OBJECT - it breaks arrays!
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        if ($jsonPayload === false) {
            throw new Exception("Failed to encode payload: " . json_last_error_msg());
        }
        
        Logger::debug("FastAPI payload", ['payload' => $jsonPayload]);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception("Connection to Python AI Engine failed: " . $error);
        }

        Logger::debug("FastAPI response", [
            'http_code' => $httpCode,
            'response' => substr($response, 0, 500)
        ]);

        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = is_array($data) && isset($data['detail']) 
                ? json_encode($data['detail']) 
                : $response;
            throw new Exception("Python AI Engine Error ($httpCode): " . $errorMsg);
        }

        // Map Python response to OpenAI-compatible format
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $data['content'] ?? '',
                        'role' => 'assistant'
                    ]
                ]
            ],
            'usage' => $data['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0
            ],
            'metadata' => $data['metadata'] ?? []
        ];
    }

    /**
     * Normalize messages to ensure they're in the correct format for Python API
     * MUST return a real array (not an object) for JSON encoding
     */
    private function normalizeMessages($messages) {
        $normalized = [];
        
        foreach ($messages as $message) {
            if (is_array($message)) {
                $normalized[] = [
                    'role' => $message['role'] ?? 'user',
                    'content' => $message['content'] ?? ''
                ];
            } elseif (is_object($message)) {
                $normalized[] = [
                    'role' => $message->role ?? 'user',
                    'content' => $message->content ?? ''
                ];
            } elseif (is_string($message)) {
                $normalized[] = [
                    'role' => 'user',
                    'content' => $message
                ];
            }
        }
        
        // Ensure it's a real indexed array (not associative)
        return array_values($normalized);
    }

    public function streamChatCompletions($messages, $options = []) {
        throw new Exception("Streaming not yet implemented");
    }
    
    public function createEmbeddings($input, $options = []) {
        throw new Exception("Embeddings not yet implemented");
    }
}