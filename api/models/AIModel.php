<?php
class AIModel {
    private $db;
    private $table_name = "ai_models";

    public $id;
    public $provider;
    public $model_name;
    public $display_name;
    public $capabilities;
    public $config;
    public $is_active;
    public $created_at;
    public $updated_at;

    /**
     * Get API endpoint for the model
     */
    public function getApiEndpoint() {
        // For Ollama, use config with fallback to default local endpoint
        if (strtolower($this->provider) === 'ollama') {
            return $this->config['api_endpoint'] ?? 'http://localhost:11434/api/chat';
        }

        // For all other providers, use environment variable
        $envKey = strtoupper($this->provider) . '_API_URL';
        $endpoint = Environment::get($envKey, '');

        // For HuggingFace, append model name if endpoint is base URL
        if (strtolower($this->provider) === 'huggingface' && !empty($endpoint) && !strpos($endpoint, $this->model_name)) {
            $endpoint = rtrim($endpoint, '/') . '/' . $this->model_name;
        }

        return $endpoint;
    }

    /**
     * Get API key for the model
     */
    public function getApiKey() {
        // Get from environment variable
        $envKey = strtoupper($this->provider) . '_API_KEY';
        return Environment::get($envKey, '');
    }

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("AIModel initialized");
    }

    /**
     * Create a new AI model
     */
    public function create($modelData) {
        $startTime = microtime(true);

        try {
            // Validate required fields
            if (!$this->validateRequiredFields($modelData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate model data
            if (!$this->validateModelName($modelData['model_name'])) {
                throw new InvalidArgumentException("Invalid model name format");
            }

            if (!$this->validateProvider($modelData['provider'])) {
                throw new InvalidArgumentException("Invalid provider format");
            }

            // Check if model already exists
            if ($this->modelExists($modelData['provider'], $modelData['model_name'])) {
                throw new InvalidArgumentException("Model already exists for this provider");
            }

            // Generate UUID for the model
            $uuid = $this->generateUUID();

            // Prepare data for insertion
            $insertData = [
                'id' => $uuid,
                'provider' => $this->sanitizeString($modelData['provider']),
                'model_name' => $this->sanitizeString($modelData['model_name']),
                'display_name' => isset($modelData['display_name']) ? $this->sanitizeString($modelData['display_name']) : null,
                'capabilities' => isset($modelData['capabilities']) ? json_encode($modelData['capabilities']) : null,
                'config' => isset($modelData['config']) ? json_encode($modelData['config']) : null,
                'is_active' => 1
            ];

            Logger::debug("Creating AI model with data", ['data' => $insertData]);

            // Create model using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);

            if ($this->id || $uuid) {
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }

                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Logger::info("AI model created successfully", [
                    'model_id' => $this->id,
                    'display_name' => $modelData['display_name'] ?? $modelData['model_name'],
                    'provider' => $modelData['provider'],
                    'model_name' => $modelData['model_name'],
                    'duration_ms' => $duration
                ]);

                return $this->getById($this->id);
            }

            throw new RuntimeException("Failed to create AI model");

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI model creation failed", [
                'error' => $e->getMessage(),
                'model_name' => $modelData['model_name'] ?? 'unknown',
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
    private function sanitizeString($string) {
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $string = trim($string);
        $string = preg_replace('/\s+/', ' ', $string);
        return $string;
    }

    /**
     * Get model by ID
     */
    public function getById($modelId) {
        $startTime = microtime(true);

        try {
            // Direct query to avoid recursion with Database::readOne
            $query = "SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1";
            $stmt = $this->db->conn->prepare($query);
            $stmt->execute(['id' => $modelId]);
            $model = $stmt->fetch(PDO::FETCH_ASSOC);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($model) {
                // Decode JSON fields
                if ($model['capabilities']) {
                    $model['capabilities'] = json_decode($model['capabilities'], true);
                }
                if ($model['config']) {
                    $model['config'] = json_decode($model['config'], true);
                }

                Logger::debug("AI model retrieved by ID", [
                    'model_id' => $modelId,
                    'duration_ms' => $duration
                ]);
                return $model;
            }

            Logger::debug("AI model not found by ID", [
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI model by ID", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get model by model name
     */
    public function getByModelName($modelName) {
        $startTime = microtime(true);

        try {
            $model = $this->db->readOne($this->table_name, [
                'model_name' => $modelName
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($model) {
                // Decode JSON fields
                if ($model['capabilities']) {
                    $model['capabilities'] = json_decode($model['capabilities'], true);
                }
                if ($model['config']) {
                    $model['config'] = json_decode($model['config'], true);
                }

                Logger::debug("AI model retrieved by model name", [
                    'model_name' => $modelName,
                    'duration_ms' => $duration
                ]);
                return $model;
            }

            Logger::debug("AI model not found by model name", [
                'model_name' => $modelName,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI model by model name", [
                'error' => $e->getMessage(),
                'model_name' => $modelName,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get model by provider and model name
     */
    public function getByProviderModel($provider, $modelName) {
        $startTime = microtime(true);

        try {
            $model = $this->db->readOne($this->table_name, [
                'provider' => $provider,
                'model_name' => $modelName
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($model) {
                // Decode JSON fields
                if ($model['capabilities']) {
                    $model['capabilities'] = json_decode($model['capabilities'], true);
                }
                if ($model['config']) {
                    $model['config'] = json_decode($model['config'], true);
                }

                Logger::debug("AI model retrieved by provider and model name", [
                    'provider' => $provider,
                    'model_name' => $modelName,
                    'duration_ms' => $duration
                ]);
                return $model;
            }

            Logger::debug("AI model not found by provider and model name", [
                'provider' => $provider,
                'model_name' => $modelName,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI model by provider and model name", [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'model_name' => $modelName,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if model exists
     */
    public function modelExists($provider, $modelName) {
        $startTime = microtime(true);

        try {
            $model = $this->db->readOne($this->table_name, [
                'provider' => $provider,
                'model_name' => $modelName
            ], 'id');

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $exists = !empty($model);

            Logger::debug("AI model existence check", [
                'provider' => $provider,
                'model_name' => $modelName,
                'exists' => $exists,
                'duration_ms' => $duration
            ]);

            return $exists;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI model existence check failed", [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'model_name' => $modelName,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update model
     */
    public function update($modelId, $updateData) {
        $startTime = microtime(true);

        try {
            $allowedFields = ['display_name', 'capabilities', 'config'];
            $filteredData = [];

            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    if (in_array($field, ['capabilities', 'config'])) {
                        $filteredData[$field] = json_encode($updateData[$field]);
                    } else {
                        $filteredData[$field] = $this->sanitizeString($updateData[$field]);
                    }
                }
            }

            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }

            $filteredData['updated_at'] = date('Y-m-d H:i:s');

            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $modelId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("AI model updated successfully", [
                    'model_id' => $modelId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);

                return $this->getById($modelId);
            }

            Logger::warning("No changes made to AI model", [
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            return $this->getById($modelId);

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI model update failed", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Deactivate model
     */
    public function deactivate($modelId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_active' => false,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $modelId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("AI model deactivated", [
                    'model_id' => $modelId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to deactivate AI model", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Activate model
     */
    public function activate($modelId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_active' => true,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $modelId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("AI model activated", [
                    'model_id' => $modelId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to activate AI model", [
                'error' => $e->getMessage(),
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get all active models
     */
    public function getAllActive($page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $models = $this->db->readMany(
                $this->table_name,
                ['is_active' => true],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON fields for each model
            foreach ($models as &$model) {
                if ($model['capabilities']) {
                    $model['capabilities'] = json_decode($model['capabilities'], true);
                }
                if ($model['config']) {
                    $model['config'] = json_decode($model['config'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['is_active' => true]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved active AI models", [
                'page' => $page,
                'limit' => $limit,
                'total_models' => $total,
                'returned_count' => count($models),
                'duration_ms' => $duration
            ]);

            return [
                'models' => $models,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get active AI models", [
                'error' => $e->getMessage(),
                'page' => $page,
                'limit' => $limit,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get models by provider
     */
    public function getByProvider($provider, $page = 1, $limit = 50) {
        $startTime = microtime(true);

        try {
            $offset = ($page - 1) * $limit;

            $models = $this->db->readMany(
                $this->table_name,
                ['provider' => $provider, 'is_active' => true],
                '*',
                'created_at DESC',
                $limit,
                $offset
            );

            // Decode JSON fields
            foreach ($models as &$model) {
                if ($model['capabilities']) {
                    $model['capabilities'] = json_decode($model['capabilities'], true);
                }
                if ($model['config']) {
                    $model['config'] = json_decode($model['config'], true);
                }
            }

            $total = $this->db->count($this->table_name, ['provider' => $provider, 'is_active' => true]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info("Retrieved AI models by provider", [
                'provider' => $provider,
                'page' => $page,
                'limit' => $limit,
                'total_models' => $total,
                'returned_count' => count($models),
                'duration_ms' => $duration
            ]);

            return [
                'models' => $models,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get AI models by provider", [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['provider', 'model_name'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            Logger::warning("Missing required fields for AI model creation", ['fields' => $missing]);
            return false;
        }

        return true;
    }

    private function validateModelName($modelName) {
        return preg_match('/^[a-zA-Z0-9\-_\.]{1,255}$/', $modelName) === 1;
    }

    private function validateProvider($provider) {
        return preg_match('/^[a-zA-Z0-9\-_\.]{1,100}$/', $provider) === 1;
    }

    /**
     * Create ai_models table
     */
    public static function createTable($db) {
        $startTime = microtime(true);

        try {
            $query = "CREATE TABLE IF NOT EXISTS ai_models (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                provider VARCHAR(100) NOT NULL,
                model_name VARCHAR(255) NOT NULL,
                display_name VARCHAR(255),
                capabilities JSON,
                config JSON,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_provider_model (provider, model_name),
                INDEX idx_ai_models_provider (provider),
                INDEX idx_ai_models_is_active (is_active),
                INDEX idx_ai_models_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating ai_models table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("AI models table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("AI models table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("AI models table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>