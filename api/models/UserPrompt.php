<?php
class UserPrompt {
    private $db;
    private $table_name = "user_prompts";

    public $id;
    public $session_id;
    public $user_id;
    public $content;
    public $input_tokens;
    public $metadata;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("UserPrompt initialized");
    }

    /**
     * Create a new user prompt
     */
    public function create($promptData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($promptData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate session exists and is active
            if (!$this->sessionExistsAndActive($promptData['session_id'])) {
                throw new InvalidArgumentException("Session does not exist or is not active");
            }

            // Validate user exists
            if (!$this->userExists($promptData['user_id'])) {
                throw new InvalidArgumentException("User does not exist");
            }

            // Validate content
            if (!$this->validateContent($promptData['content'])) {
                throw new InvalidArgumentException("Invalid content format");
            }

            // Generate UUID for the prompt
            $uuid = $this->generateUUID();

            // Prepare data for insertion
            $insertData = [
                'id' => $uuid,
                'session_id' => $promptData['session_id'],
                'user_id' => $promptData['user_id'],
                'content' => $this->sanitizeContent($promptData['content']),
                'input_tokens' => isset($promptData['input_tokens']) ? (int)$promptData['input_tokens'] : 0,
                'metadata' => isset($promptData['metadata']) ? json_encode($promptData['metadata']) : null
            ];

            Logger::debug("Creating user prompt with data", ['data' => $insertData]);

            // Create prompt using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id || $uuid) {
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }

                // Update session last_message_at
                $this->updateSessionLastMessage($promptData['session_id']);

                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Logger::info("User prompt created successfully", [
                    'prompt_id' => $this->id,
                    'session_id' => $promptData['session_id'],
                    'user_id' => $promptData['user_id'],
                    'input_tokens' => $insertData['input_tokens'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create user prompt");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User prompt creation failed", [
                'error' => $e->getMessage(),
                'session_id' => $promptData['session_id'] ?? 'unknown',
                'user_id' => $promptData['user_id'] ?? 'unknown',
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
        // For content, we allow more characters but still prevent XSS
        // Remove any script tags but keep formatting
        $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $content);
        $content = strip_tags($content, '<p><br><strong><em><u><ol><ul><li><blockquote><code><pre>');
        return trim($content);
    }

    /**
     * Update session last message timestamp
     */
    private function updateSessionLastMessage($sessionId) {
        try {
            $this->db->update('chat_sessions', [
                'last_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $sessionId]);
        } catch (Exception $e) {
            Logger::warning("Failed to update session last message timestamp", [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get prompt by ID
     */
    public function getById($promptId) {
        $startTime = microtime(true);

        try {
            $prompt = $this->db->readOne($this->table_name, ['id' => $promptId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($prompt) {
                // Decode JSON metadata
                if ($prompt['metadata']) {
                    $prompt['metadata'] = json_decode($prompt['metadata'], true);
                }

                Logger::debug("User prompt retrieved by ID", [
                    'prompt_id' => $promptId,
                    'duration_ms' => $duration
                ]);
                return $prompt;
            }

            Logger::debug("User prompt not found by ID", [
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user prompt by ID", [
                'error' => $e->getMessage(),
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get prompts by session ID
     */
    public function getBySessionId($sessionId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $prompts = $this->db->readMany(
                $this->table_name,
                ['session_id' => $sessionId],
                '*',
                'created_at ASC',
                $limit,
                $offset
            );

            // Decode JSON metadata for each prompt
            foreach ($prompts as &$prompt) {
                if ($prompt['metadata']) {
                    $prompt['metadata'] = json_decode($prompt['metadata'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['session_id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved user prompts by session", [
                'session_id' => $sessionId,
                'page' => $page,
                'limit' => $limit,
                'total_prompts' => $total,
                'returned_count' => count($prompts),
                'duration_ms' => $duration
            ]);

            return [
                'prompts' => $prompts,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user prompts by session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get prompts by user ID
     */
    public function getByUserId($userId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $prompts = $this->db->readMany(
                $this->table_name,
                ['user_id' => $userId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON metadata
            foreach ($prompts as &$prompt) {
                if ($prompt['metadata']) {
                    $prompt['metadata'] = json_decode($prompt['metadata'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['user_id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved user prompts by user", [
                'user_id' => $userId,
                'page' => $page,
                'limit' => $limit,
                'total_prompts' => $total,
                'returned_count' => count($prompts),
                'duration_ms' => $duration
            ]);

            return [
                'prompts' => $prompts,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user prompts by user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update prompt
     */
    public function update($promptId, $updateData) {
        $startTime = microtime(true);

        try {
            $allowedFields = ['content', 'input_tokens', 'metadata'];
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

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $promptId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("User prompt updated successfully", [
                    'prompt_id' => $promptId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($promptId);
            }

            Logger::warning("No changes made to user prompt", [
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            return $this->getById($promptId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User prompt update failed", [
                'error' => $e->getMessage(),
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Delete prompt
     */
    public function delete($promptId) {
        $startTime = microtime(true);

        try {
            $affectedRows = $this->db->delete($this->table_name, ['id' => $promptId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("User prompt deleted successfully", [
                    'prompt_id' => $promptId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("No prompt found to delete", [
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User prompt deletion failed", [
                'error' => $e->getMessage(),
                'prompt_id' => $promptId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get recent prompts across all sessions for a user
     */
    public function getRecentByUser($userId, $limit = 10) {
        $startTime = microtime(true);

        try {
            $prompts = $this->db->readMany(
                $this->table_name,
                ['user_id' => $userId],
                '*',
                'created_at DESC',
                $limit
            );

            // Decode JSON metadata
            foreach ($prompts as &$prompt) {
                if ($prompt['metadata']) {
                    $prompt['metadata'] = json_decode($prompt['metadata'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved recent user prompts", [
                'user_id' => $userId,
                'limit' => $limit,
                'returned_count' => count($prompts),
                'duration_ms' => $duration
            ]);

            return $prompts;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get recent user prompts", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
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
     * Check if user exists
     */
    private function userExists($userId) {
        $user = $this->db->readOne('users', ['id' => $userId], 'id');
        return !empty($user);
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['session_id', 'user_id', 'content'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            Logger::warning("Missing required fields for user prompt creation", ['fields' => $missing]);
            return false;
        }

        return true;
    }

    private function validateContent($content) {
        $content = trim($content);
        return strlen($content) > 0 && strlen($content) <= 65535; // TEXT field limit
    }

    /**
     * Create user_prompts table
     */
    public static function createTable($db) {
        $startTime = microtime(true);

        try {
            $query = "CREATE TABLE IF NOT EXISTS user_prompts (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                session_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                content TEXT NOT NULL,
                input_tokens INT DEFAULT 0,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_prompts_session_id (session_id),
                INDEX idx_user_prompts_user_id (user_id),
                INDEX idx_user_prompts_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating user_prompts table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("User prompts table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("User prompts table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User prompts table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>