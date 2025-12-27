<?php
class ChatSession {
    private $db;
    private $table_name = "chat_sessions";

    public $id;
    public $user_id;
    public $title;
    public $version;
    public $continuity_token;
    public $total_tokens;
    public $total_cost;
    public $is_active;
    public $last_message_at;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("ChatSession initialized");
    }

    /**
     * Create a new chat session
     */
    public function create($sessionData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($sessionData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate user exists
            if (!$this->userExists($sessionData['user_id'])) {
                throw new InvalidArgumentException("User does not exist");
            }

            // Validate title
            if (!$this->validateTitle($sessionData['title'])) {
                throw new InvalidArgumentException("Invalid title format");
            }

            // Get default model if not provided
            $defaultModelId = $sessionData['default_model_id'] ?? $this->getDefaultModelId();
            if (!$defaultModelId) {
                throw new InvalidArgumentException("No default model available");
            }

            // Generate UUID for the session
            $uuid = $this->generateUUID();

            // Prepare data for insertion
            $insertData = [
                'id' => $uuid,
                'user_id' => $sessionData['user_id'],
                'title' => $this->sanitizeString($sessionData['title']),
                'default_model_id' => $defaultModelId,
                'version' => 1,
                'continuity_token' => isset($sessionData['continuity_token']) ? $this->sanitizeString($sessionData['continuity_token']) : null,
                'total_tokens' => 0,
                'is_active' => 1,
                'last_message_at' => date('Y-m-d H:i:s')
            ];

            Logger::debug("Creating chat session with data", ['data' => $insertData]);

            // Create session using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id || $uuid) {
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }

                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Logger::info("Chat session created successfully", [
                    'session_id' => $this->id,
                    'user_id' => $sessionData['user_id'],
                    'title' => $sessionData['title'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create chat session");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Chat session creation failed", [
                'error' => $e->getMessage(),
                'user_id' => $sessionData['user_id'] ?? 'unknown',
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
     * Get default model ID
     */
    private function getDefaultModelId() {
        $defaultModel = $this->db->readOne('ai_models', ['is_default' => true], 'id');
        return $defaultModel ? $defaultModel['id'] : null;
    }

    /**
     * Sanitization methods
     */
    private function sanitizeString($string) {
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $string = trim($string);
        $string = preg_replace('/\s+/', ' ', $string);
        return $string;
    }

    /**
     * Get session by ID
     */
    public function getById($sessionId) {
        $startTime = microtime(true);

        try {
            // Direct query to avoid recursion with Database::readOne
            $query = "SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1";
            $stmt = $this->db->conn->prepare($query);
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($session) {
                Logger::debug("Chat session retrieved by ID", [
                    'session_id' => $sessionId,
                    'duration_ms' => $duration
                ]);
                return $session;
            }

            Logger::debug("Chat session not found by ID", [
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get chat session by ID", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get sessions by user ID
     */
    public function getByUserId($userId, $page = 1, $limit = 50, $activeOnly = true) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;
            $conditions = ['user_id' => $userId];

            if ($activeOnly) {
                $conditions['is_active'] = true;
            }

            $sessions = $this->db->readMany(
                $this->table_name,
                $conditions,
                '*',
                'last_message_at DESC',
                $limit,
                $offset
            );

            $total = $this->db->count($this->table_name, $conditions);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved chat sessions by user", [
                'user_id' => $userId,
                'page' => $page,
                'limit' => $limit,
                'active_only' => $activeOnly,
                'total_sessions' => $total,
                'returned_count' => count($sessions),
                'duration_ms' => $duration
            ]);

            return [
                'sessions' => $sessions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get chat sessions by user", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update session
     */
    public function update($sessionId, $updateData) {
        $startTime = microtime(true);

        try {
            $allowedFields = ['title', 'continuity_token', 'total_tokens', 'total_cost', 'last_message_at'];
            $filteredData = [];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'title' || $field === 'continuity_token') {
                        $filteredData[$field] = $this->sanitizeString($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            $filteredData['updated_at'] = date('Y-m-d H:i:s');

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Chat session updated successfully", [
                    'session_id' => $sessionId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($sessionId);
            }

            Logger::warning("No changes made to chat session", [
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            return $this->getById($sessionId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Chat session update failed", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update session stats (tokens and cost)
     */
    public function updateStats($sessionId, $tokensUsed, $cost) {
        $startTime = microtime(true);

        try {
            // Get current stats
            $session = $this->getById($sessionId);
            if (!$session) {
                throw new InvalidArgumentException("Session not found");
            }

            $updateData = [
                'total_tokens' => $session['total_tokens'] + $tokensUsed,
                'total_cost' => $session['total_cost'] + $cost,
                'last_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Chat session stats updated", [
                    'session_id' => $sessionId,
                    'tokens_added' => $tokensUsed,
                    'cost_added' => $cost,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to update chat session stats", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Deactivate session
     */
    public function deactivate($sessionId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_active' => false,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Chat session deactivated", [
                    'session_id' => $sessionId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to deactivate chat session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Activate session
     */
    public function activate($sessionId) {
        $startTime = microtime(true);

        try {
            // First, get the session to verify ownership
            $session = $this->getById($sessionId);
            if (!$session) {
                throw new InvalidArgumentException("Session not found");
            }

            // Deactivate all sessions for this user
            $this->db->update($this->table_name, [
                'is_active' => false,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['user_id' => $session['user_id']]);

            // Activate this session
            $updateData = [
                'is_active' => true,
                'last_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Chat session activated", [
                    'session_id' => $sessionId,
                    'user_id' => $session['user_id'],
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to activate chat session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get active sessions count for user
     */
    public function getActiveCount($userId) {
        $startTime = microtime(true);

        try {
            $count = $this->db->count($this->table_name, [
                'user_id' => $userId,
                'is_active' => true
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::debug("Retrieved active sessions count", [
                'user_id' => $userId,
                'count' => $count,
                'duration_ms' => $duration
            ]);

            return $count;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get active sessions count", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
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
        $required = ['user_id', 'title'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            Logger::warning("Missing required fields for chat session creation", ['fields' => $missing]);
            return false;
        }

        return true;
    }

    private function validateTitle($title) {
        return strlen($title) >= 1 && strlen($title) <= 255;
    }

    /**
     * Create chat_sessions table
     */
    public static function createTable($db) {
        $startTime = microtime(true);

        try {
            $query = "CREATE TABLE IF NOT EXISTS chat_sessions (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                user_id CHAR(36) NOT NULL,
                title VARCHAR(255) NOT NULL,
                version INT DEFAULT 1,
                continuity_token VARCHAR(255),
                total_tokens INT DEFAULT 0,
                total_cost DECIMAL(10,4) DEFAULT 0.0000,
                is_active BOOLEAN DEFAULT TRUE,
                last_message_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_chat_sessions_user_id (user_id),
                INDEX idx_chat_sessions_is_active (is_active),
                INDEX idx_chat_sessions_last_message_at (last_message_at),
                INDEX idx_chat_sessions_user_active_last (user_id, is_active, last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating chat_sessions table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("Chat sessions table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Chat sessions table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Chat sessions table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>