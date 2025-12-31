<?php
class SessionModel {
    private $db;
    private $table_name = "session_models";

    public $id;
    public $session_id;
    public $model_id;
    public $is_visible;
    public $usage_count;
    public $configuration;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("SessionModel initialized");
    }

    /**
     * Create a new session model association
     */
    public function create($sessionModelData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($sessionModelData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate session exists
            if (!$this->sessionExists($sessionModelData['session_id'])) {
                throw new InvalidArgumentException("Session does not exist");
            }

            // Validate model exists
            if (!$this->modelExists($sessionModelData['model_id'])) {
                throw new InvalidArgumentException("AI model does not exist");
            }

            // Check if association already exists
            if ($this->associationExists($sessionModelData['session_id'], $sessionModelData['model_id'])) {
                throw new InvalidArgumentException("Model is already associated with this session");
            }

            // Generate UUID for the association
            $uuid = $this->generateUUID();

            // Prepare data for insertion
            $insertData = [
                'id' => $uuid,
                'session_id' => $sessionModelData['session_id'],
                'model_id' => $sessionModelData['model_id'],
                'is_visible' => isset($sessionModelData['is_visible']) ? (bool)$sessionModelData['is_visible'] : true,
                'usage_count' => 0,
                'configuration' => isset($sessionModelData['configuration']) ? json_encode($sessionModelData['configuration']) : null
            ];

            Logger::debug("Creating session model association with data", ['data' => $insertData]);

            // Create association using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id || $uuid) {
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }

                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Logger::info("Session model association created successfully", [
                    'association_id' => $this->id,
                    'session_id' => $sessionModelData['session_id'],
                    'model_id' => $sessionModelData['model_id'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create session model association");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Session model association creation failed", [
                'error' => $e->getMessage(),
                'session_id' => $sessionModelData['session_id'] ?? 'unknown',
                'model_id' => $sessionModelData['model_id'] ?? 'unknown',
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
     * Get association by ID
     */
    public function getById($associationId) {
        $startTime = microtime(true);

        try {
            $association = $this->db->readOne($this->table_name, ['id' => $associationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($association) {
                // Decode JSON config
                if ($association['configuration']) {
                    $association['configuration'] = json_decode($association['configuration'], true);
                }

                Logger::debug("Session model association retrieved by ID", [
                    'association_id' => $associationId,
                    'duration_ms' => $duration
                ]);
                return $association;
            }

            Logger::debug("Session model association not found by ID", [
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get session model association by ID", [
                'error' => $e->getMessage(),
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get models for a session
     */
    public function getBySessionId($sessionId, $visibleOnly = false) {
        $startTime = microtime(true);

        try {
            $conditions = ['session_id' => $sessionId];

            if ($visibleOnly) {
                $conditions['is_visible'] = true;
            }

            $associations = $this->db->readMany(
                $this->table_name,
                $conditions,
                '*',
                'created_at ASC'
            );

            // Decode JSON config for each association
            foreach ($associations as &$association) {
                if ($association['configuration']) {
                    $association['configuration'] = json_decode($association['configuration'], true);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved session model associations", [
                'session_id' => $sessionId,
                'visible_only' => $visibleOnly,
                'count' => count($associations),
                'duration_ms' => $duration
            ]);

            return $associations;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get session model associations", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get sessions for a model
     */
    public function getByModelId($modelId, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $associations = $this->db->readMany(
                $this->table_name,
                ['model_id' => $modelId],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON config
            foreach ($associations as &$association) {
                if ($association['configuration']) {
                    $association['configuration'] = json_decode($association['configuration'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['model_id' => $modelId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved sessions for model", [
                'model_id' => $modelId,
                'page' => $page,
                'limit' => $limit,
                'total_associations' => $total,
                'returned_count' => count($associations),
                'duration_ms' => $duration
            ]);

            return [
                'associations' => $associations,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get sessions for model", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update association
     */
    public function update($associationId, $updateData) {
        $startTime = microtime(true);

        try {
            $allowedFields = ['is_visible', 'usage_count', 'configuration'];
            $filteredData = [];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if ($field === 'configuration') {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $updateData[$field];
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            $filteredData['updated_at'] = date('Y-m-d H:i:s');

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $associationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Session model association updated successfully", [
                    'association_id' => $associationId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($associationId);
            }

            Logger::warning("No changes made to session model association", [
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            return $this->getById($associationId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Session model association update failed", [
                'error' => $e->getMessage(),
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Increment usage count
     */
    public function incrementUsage($associationId) {
        $startTime = microtime(true);

        try {
            // Get current usage count
            $association = $this->getById($associationId);
            if (!$association) {
                throw new InvalidArgumentException("Association not found");
            }

            $updateData = [
                'usage_count' => $association['usage_count'] + 1,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $associationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::debug("Usage count incremented", [
                    'association_id' => $associationId,
                    'new_count' => $updateData['usage_count'],
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to increment usage count", [
                'error' => $e->getMessage(),
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Toggle visibility
     */
    public function toggleVisibility($associationId) {
        $startTime = microtime(true);

        try {
            $association = $this->getById($associationId);
            if (!$association) {
                throw new InvalidArgumentException("Association not found");
            }

            $updateData = [
                'is_visible' => !$association['is_visible'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $associationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Visibility toggled", [
                    'association_id' => $associationId,
                    'new_visibility' => $updateData['is_visible'],
                    'duration_ms' => $duration
                ]);
                return $updateData['is_visible'];
            }

            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to toggle visibility", [
                'error' => $e->getMessage(),
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Remove association
     */
    public function remove($associationId) {
        $startTime = microtime(true);

        try {
            $affectedRows = $this->db->delete($this->table_name, ['id' => $associationId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Session model association removed", [
                    'association_id' => $associationId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("No association found to remove", [
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to remove session model association", [
                'error' => $e->getMessage(),
                'association_id' => $associationId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if session exists
     */
    private function sessionExists($sessionId) {
        $session = $this->db->readOne('chat_sessions', ['id' => $sessionId], 'id');
        return !empty($session);
    }

    /**
     * Check if model exists
     */
    private function modelExists($modelId) {
        $model = $this->db->readOne('ai_models', ['id' => $modelId], 'id');
        return !empty($model);
    }

    /**
     * Check if association exists
     */
    private function associationExists($sessionId, $modelId) {
        $association = $this->db->readOne($this->table_name, [
            'session_id' => $sessionId,
            'model_id' => $modelId
        ], 'id');
        return !empty($association);
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['session_id', 'model_id'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            Logger::warning("Missing required fields for session model association", ['fields' => $missing]);
            return false;
        }

        return true;
    }

    /**
     * Create session_models table
     */
    public static function createTable($db) {
        $startTime = microtime(true);

        try {
            $query = "CREATE TABLE IF NOT EXISTS session_models (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                session_id CHAR(36) NOT NULL,
                model_id CHAR(36) NOT NULL,
                is_visible BOOLEAN DEFAULT TRUE,
                usage_count INT DEFAULT 0,
                configuration JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
                UNIQUE KEY unique_session_model (session_id, model_id),
                INDEX idx_session_models_session_id (session_id),
                INDEX idx_session_models_model_id (model_id),
                INDEX idx_session_models_is_visible (is_visible)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating session_models table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("Session models table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Session models table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Session models table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>