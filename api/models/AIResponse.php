<?php
class AIResponse {
    private $db;
    private $table_name = "ai_responses";

    public $id;
    public $prompt_id;
    public $model_id;
    public $session_id;
    public $content;
    public $output_tokens;
    public $cost;
    public $metadata;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("AIResponse initialized");
    }

    /**
     * Create a new AI response
     */
    public function create($responseData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($responseData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate prompt exists
            if (!$this->promptExists($responseData['prompt_id'])) {
                throw new InvalidArgumentException("Prompt does not exist");
            }

            // Validate model exists and is active
            if (!$this->modelExistsAndActive($responseData['model_id'])) {
                throw new InvalidArgumentException("AI model does not exist or is not active");
            }

            // Validate session exists and is active
            if (!$this->sessionExistsAndActive($responseData['session_id'])) {
                throw new InvalidArgumentException("Session does not exist or is not active");
            }

            // Check if response already exists for this prompt and model
            if ($this->responseExists($responseData['prompt_id'], $responseData['model_id'])) {
                throw new InvalidArgumentException("Response already exists for this prompt and model");
            }

            // Validate content
            if (!$this->validateContent($responseData['content'])) {
                throw new InvalidArgumentException("Invalid content format");
            }

            // Generate UUID for the response
            $uuid = $this->generateUUID();

            // Prepare data for insertion
            $insertData = [
                'id' => $uuid,
                'prompt_id' => $responseData['prompt_id'],
                'model_id' => $responseData['model_id'],
                'session_id' => $responseData['session_id'],
                'content' => $this->sanitizeContent($responseData['content']),
                'output_tokens' => isset($responseData['token_count']) ? (int)$responseData['token_count'] : 0,
                'metadata' => isset($responseData['metadata']) ? json_encode($responseData['metadata']) : null
            ];

            Logger::debug("Creating AI response with data", ['data' => $insertData]);

            // Create response using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id || $uuid) {
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }

                // Update session stats
                $this->updateSessionStats($responseData['session_id'], $insertData['output_tokens'], 0);

                // Increment model usage count
                $this->incrementModelUsage($responseData['session_id'], $responseData['model_id']);

                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Logger::info("AI response created successfully", [
                    'response_id' => $this->id,
                    'prompt_id' => $responseData['prompt_id'],
                    'model_id' => $responseData['model_id'],
                    'session_id' => $responseData['session_id'],
                    'token_count' => $insertData['output_tokens'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create AI response");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI response creation failed", [
                'error' => $e->getMessage(),
                'prompt_id' => $responseData['prompt_id'] ?? 'unknown',
                'model_id' => $responseData['model_id'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Generate UUID v4
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Sanitization methods
     */
    private function sanitizeContent($content) {
        // For AI responses, allow more formatting but prevent XSS
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $content);
        $content = strip_tags($content, '<p><br><strong><em><u><ol><ul><li><blockquote><code><pre><table><tr><td><th>');
        return trim($content);
    }

    /**
     * Update session stats
     */
    private function updateSessionStats($sessionId, $tokensUsed, $cost) {
        try {
            // Get current session
            $session = $this->db->readOne('chat_sessions', ['id' => $sessionId], 'total_tokens, total_cost');
            if ($session) {
                $this->db->update('chat_sessions', [
                    'total_tokens' => $session['total_tokens'] + $tokensUsed,
                    'total_cost' => $session['total_cost'] + $cost,
                    'last_message_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $sessionId]);
            }
        } catch (Exception $e) {
            Logger::warning("Failed to update session stats", [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Increment model usage count in session_models
     */
    private function incrementModelUsage($sessionId, $modelId) {
        try {
            $association = $this->db->readOne('session_models', [
                'session_id' => $sessionId,
                'model_id' => $modelId
            ], 'usage_count');

            if ($association) {
                $this->db->update('session_models', [
                    'usage_count' => $association['usage_count'] + 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ], [
                    'session_id' => $sessionId,
                    'model_id' => $modelId
                ]);
            }
        } catch (Exception $e) {
            Logger::warning("Failed to increment model usage", [
                'session_id' => $sessionId,
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get response by ID
     */
    public function getById($responseId) {
        $startTime = microtime(true);

        try {
            $response = $this->db->readOne($this->table_name, ['id' => $responseId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($response) {
                // Decode JSON metadata
                if ($response['metadata']) {
                    $response['metadata'] = json_decode($response['metadata'], true);
                }

                Logger::debug("AI response retrieved by ID", [
                    'response_id' => $responseId,
                    'duration_ms' => $duration
                ]);
                return $response;
            }

            Logger::debug("AI response not found by ID", [
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI response by ID", [
                'error' => $e->getMessage(),
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get responses by prompt ID
     */
    public function getByPromptId($promptId) {
        $startTime = microtime(true);

        try {
            $responses = $this->db->readMany(
                $this->table_name,
                ['prompt_id' => $promptId],
                '*',
                'created_at ASC'
            );

            // Decode JSON metadata for each response
            foreach ($responses as &$response) {
                if ($response['metadata']) {
                    $response['metadata'] = json_decode($response['metadata'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved AI responses by prompt", [
                'prompt_id' => $promptId,
                'count' => count($responses),
                'duration_ms' => $duration
            ]);

            return $responses;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI responses by prompt", [
                'error' => $e->getMessage(),
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get responses by session ID
     */
    public function getBySessionId($sessionId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $responses = $this->db->readMany(
                $this->table_name,
                ['session_id' => $sessionId],
                '*',
                'created_at ASC',
                $limit,
                $offset
            );

            // Decode JSON metadata
            foreach ($responses as &$response) {
                if ($response['metadata']) {
                    $response['metadata'] = json_decode($response['metadata'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['session_id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved AI responses by session", [
                'session_id' => $sessionId,
                'page' => $page,
                'limit' => $limit,
                'total_responses' => $total,
                'returned_count' => count($responses),
                'duration_ms' => $duration
            ]);

            return [
                'responses' => $responses,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI responses by session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get responses by model ID
     */
    public function getByModelId($modelId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $responses = $this->db->readMany(
                $this->table_name,
                ['model_id' => $modelId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON metadata
            foreach ($responses as &$response) {
                if ($response['metadata']) {
                    $response['metadata'] = json_decode($response['metadata'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['model_id' => $modelId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved AI responses by model", [
                'model_id' => $modelId,
                'page' => $page,
                'limit' => $limit,
                'total_responses' => $total,
                'returned_count' => count($responses),
                'duration_ms' => $duration
            ]);

            return [
                'responses' => $responses,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI responses by model", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update response
     */
    public function update($responseId, $updateData) {
        $startTime = microtime(true);

        try {
            $allowedFields = ['content', 'output_tokens', 'cost', 'metadata'];
            $filteredData = [];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'content') {
                        $filteredData[$field] = $this->sanitizeContent($updateData[$field]);
                    } elseif ($field === 'metadata') {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $responseId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("AI response updated successfully", [
                    'response_id' => $responseId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($responseId);
            }

            Logger::warning("No changes made to AI response", [
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            return $this->getById($responseId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI response update failed", [
                'error' => $e->getMessage(),
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Delete response
     */
    public function delete($responseId) {
        $startTime = microtime(true);

        try {
            $affectedRows = $this->db->delete($this->table_name, ['id' => $responseId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("AI response deleted successfully", [
                    'response_id' => $responseId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("No response found to delete", [
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI response deletion failed", [
                'error' => $e->getMessage(),
                'response_id' => $responseId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get conversation thread (prompt + responses)
     */
    public function getConversationThread($sessionId, $limit = 50) {
        $startTime = microtime(true);

        try {
            $sql = "SELECT
                        'prompt' as type,
                        up.id,
                        up.session_id,
                        up.user_id,
                        up.content,
                        up.input_tokens,
                        up.metadata,
                        up.created_at,
                        ar.model_id,
                        NULL as cost
                    FROM user_prompts up
                    JOIN ai_responses ar ON ar.prompt_id = up.id
                    WHERE up.session_id = ?
                    UNION ALL
                    SELECT
                        'response' as type,
                        ar.id,
                        ar.session_id,
                        NULL as user_id,
                        ar.content,
                        ar.output_tokens,
                        ar.metadata,
                        ar.created_at,
                        ar.model_id,
                        NULL
                    FROM ai_responses ar
                    WHERE ar.session_id = ?
                    ORDER BY created_at ASC
                    LIMIT ?";

            $params = [$sessionId, $sessionId, $limit];
            $thread = $this->db->query($sql, $params);

            // Decode JSON metadata
            foreach ($thread as &$item) {
                if ($item['metadata']) {
                    $item['metadata'] = json_decode($item['metadata'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved conversation thread", [
                'session_id' => $sessionId,
                'limit' => $limit,
                'returned_count' => count($thread),
                'duration_ms' => $duration
            ]);

            return $thread;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get conversation thread", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if prompt exists
     */
    private function promptExists($promptId) {
        $prompt = $this->db->readOne('user_prompts', ['id' => $promptId], 'id');
        return !empty($prompt);
    }

    /**
     * Check if model exists and is active
     */
    private function modelExistsAndActive($modelId) {
        $model = $this->db->readOne('ai_models', [
            'id' => $modelId,
            'is_active' => true
        ], 'id');
        return !empty($model);
    }

    /**
     * Check if session exists and is active
     */
    private function sessionExistsAndActive($sessionId) {
        $session = $this->db->readOne('chat_sessions', [
            'id' => $sessionId,
            'is_active' => true
        ], 'id');
        return !empty($session);
    }

    /**
     * Check if response exists for prompt and model
     */
    private function responseExists($promptId, $modelId) {
        $response = $this->db->readOne($this->table_name, [
            'prompt_id' => $promptId,
            'model_id' => $modelId
        ], 'id');
        return !empty($response);
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['prompt_id', 'model_id', 'session_id', 'content'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            Logger::warning("Missing required fields for AI response creation", ['fields' => $missing]);
            return false;
        }

        return true;
    }

    private function validateContent($content) {
        $content = trim($content);
        return strlen($content) > 0 && strlen($content) <= 65535; // TEXT field limit
    }

    /**
     * Create ai_responses table
     */
    public static function createTable($db) {
        $startTime = microtime(true);

        try {
            $query = "CREATE TABLE IF NOT EXISTS ai_responses (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                prompt_id CHAR(36) NOT NULL,
                model_id CHAR(36) NOT NULL,
                session_id CHAR(36) NOT NULL,
                content TEXT NOT NULL,
                output_tokens INT DEFAULT 0,
                cost DECIMAL(10,4) DEFAULT 0.0000,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (prompt_id) REFERENCES user_prompts(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
                FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
                UNIQUE KEY unique_prompt_model (prompt_id, model_id),
                INDEX idx_ai_responses_prompt_id (prompt_id),
                INDEX idx_ai_responses_model_id (model_id),
                INDEX idx_ai_responses_session_id (session_id),
                INDEX idx_ai_responses_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating ai_responses table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("AI responses table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("AI responses table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI responses table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>