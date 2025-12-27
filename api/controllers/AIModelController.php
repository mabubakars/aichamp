<?php

/**
 * Controller for AI model management operations
 * Handles CRUD operations, analytics, and administrative functions
 */
class AIModelController extends BaseController {
    private $aiModelModel;
    private $sessionModelModel;
    private $performanceMonitor;

    public function __construct($db) {
        parent::__construct($db);
        $this->aiModelModel = new AIModel($db);
        $this->sessionModelModel = new SessionModel($db);
        $this->performanceMonitor = new PerformanceMonitor($db);

        Logger::debug("AIModelController initialized");
    }

    /**
     * Get all AI models with optional filtering
     */
    public function getModels($request, $response) {
        try {
            $params = $request->getQueryParams();
            $filters = [];

            // Apply filters
            if (isset($params['provider'])) {
                $filters['provider'] = $params['provider'];
            }

            if (isset($params['is_active'])) {
                $filters['is_active'] = $params['is_active'] === 'true';
            }

            if (isset($params['capability'])) {
                $filters['capability'] = $params['capability'];
            }

            $models = $this->aiModelModel->getAll($filters);

            // Add performance metrics for each model
            foreach ($models as &$model) {
                $model['performance_metrics'] = $this->getModelPerformanceMetrics($model['id']);
            }

            // Map model_name to name for frontend compatibility
            foreach ($models as &$model) {
                $model['name'] = $model['model_name'];
            }

            $response->json([
                'success' => true,
                'data' => $models,
                'count' => count($models)
            ]);

        } catch (Exception $e) {
            Logger::error("Failed to get models", ['error' => $e->getMessage()]);
            $response->json([
                'success' => false,
                'error' => 'Failed to retrieve models'
            ], 500);
        }
    }

    /**
     * Get a specific AI model by ID
     */
    public function getModel($request, $response) {
        try {
            $modelId = $request->getParam('id');

            if (!$modelId) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model ID is required'
                ], 400);
            }

            $model = $this->aiModelModel->getById($modelId);

            if (!$model) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model not found'
                ], 404);
            }

            // Add performance metrics
            $model['performance_metrics'] = $this->getModelPerformanceMetrics($modelId);

            // Add usage statistics
            $model['usage_stats'] = $this->getModelUsageStats($modelId);

            // Map model_name to name for frontend compatibility
            $model['name'] = $model['model_name'];

            $response->json([
                'success' => true,
                'data' => $model
            ]);

        } catch (Exception $e) {
            Logger::error("Failed to get model", [
                'model_id' => $modelId ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $response->json([
                'success' => false,
                'error' => 'Failed to retrieve model'
            ], 500);
        }
    }

    /**
     * Create a new AI model
     */
    public function createModel($request, $response) {
        try {
            $data = $request->getParsedBody();

            // Validate required fields
            $requiredFields = ['provider', 'model_name'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $response->json([
                        'success' => false,
                        'error' => "Field '{$field}' is required"
                    ], 400);
                }
            }

            // Validate provider
            $validProviders = ['openai', 'anthropic', 'groq', 'together', 'huggingface', 'ollama'];
            if (!in_array(strtolower($data['provider']), $validProviders)) {
                return $response->json([
                    'success' => false,
                    'error' => 'Invalid provider'
                ], 400);
            }

            // Create model
            $modelData = [
                'provider' => strtolower($data['provider']),
                'model_name' => $data['model_name'],
                'display_name' => $data['display_name'] ?? $data['model_name'],
                'description' => $data['description'] ?? '',
                'capabilities' => $data['capabilities'] ?? [],
                'config' => $data['config'] ?? [],
                'is_active' => $data['is_active'] ?? true,
                'max_tokens' => $data['max_tokens'] ?? 4096,
                'pricing' => $data['pricing'] ?? []
            ];

            $model = $this->aiModelModel->create($modelData);

            Logger::info("AI model created", [
                'model_id' => $model['id'],
                'model_name' => $model['model_name'],
                'display_name' => $model['display_name'],
                'provider' => $model['provider']
            ]);

            // Map model_name to name for frontend compatibility
            $model['name'] = $model['model_name'];

            $response->json([
                'success' => true,
                'data' => $model
            ], 201);

        } catch (Exception $e) {
            Logger::error("Failed to create model", ['error' => $e->getMessage()]);
            $response->json([
                'success' => false,
                'error' => 'Failed to create model'
            ], 500);
        }
    }

    /**
     * Update an existing AI model
     */
    public function updateModel($request, $response) {
        try {
            $modelId = $request->getParam('id');
            $data = $request->getParsedBody();

            if (!$modelId) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model ID is required'
                ], 400);
            }

            // Check if model exists
            $existingModel = $this->aiModelModel->getById($modelId);
            if (!$existingModel) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model not found'
                ], 404);
            }

            // Update model
            $updatedModel = $this->aiModelModel->update($modelId, $data);

            Logger::info("AI model updated", [
                'model_id' => $modelId,
                'updated_fields' => array_keys($data)
            ]);

            // Map model_name to name for frontend compatibility
            $updatedModel['name'] = $updatedModel['model_name'];

            $response->json([
                'success' => true,
                'data' => $updatedModel
            ]);

        } catch (Exception $e) {
            Logger::error("Failed to update model", [
                'model_id' => $modelId ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $response->json([
                'success' => false,
                'error' => 'Failed to update model'
            ], 500);
        }
    }

    /**
     * Delete an AI model (soft delete by deactivating)
     */
    public function deleteModel($request, $response) {
        try {
            $modelId = $request->getParam('id');

            if (!$modelId) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model ID is required'
                ], 400);
            }

            // Check if model exists
            $model = $this->aiModelModel->getById($modelId);
            if (!$model) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model not found'
                ], 404);
            }

            // Check if model is being used in active sessions
            $activeSessions = $this->sessionModelModel->getActiveSessionsForModel($modelId);
            if (!empty($activeSessions)) {
                return $response->json([
                    'success' => false,
                    'error' => 'Cannot delete model that is being used in active sessions',
                    'active_sessions' => count($activeSessions)
                ], 409);
            }

            // Soft delete by deactivating
            $success = $this->aiModelModel->deactivate($modelId);

            if ($success) {
                Logger::info("AI model deactivated", ['model_id' => $modelId]);
                $response->json([
                    'success' => true,
                    'message' => 'Model deactivated successfully'
                ]);
            } else {
                $response->json([
                    'success' => false,
                    'error' => 'Failed to deactivate model'
                ], 500);
            }

        } catch (Exception $e) {
            Logger::error("Failed to delete model", [
                'model_id' => $modelId ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $response->json([
                'success' => false,
                'error' => 'Failed to delete model'
            ], 500);
        }
    }

    /**
     * Get model performance metrics
     */
    public function getModelAnalytics($request, $response) {
        try {
            $modelId = $request->getParam('id');
            $params = $request->getQueryParams();

            $period = $params['period'] ?? '24h'; // 1h, 24h, 7d, 30d
            $metrics = $params['metrics'] ?? ['latency', 'success_rate', 'usage'];

            if (!$modelId) {
                return $response->json([
                    'success' => false,
                    'error' => 'Model ID is required'
                ], 400);
            }

            $analytics = [
                'model_id' => $modelId,
                'period' => $period,
                'metrics' => []
            ];

            foreach ($metrics as $metric) {
                $analytics['metrics'][$metric] = $this->getModelMetricData($modelId, $metric, $period);
            }

            $response->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (Exception $e) {
            Logger::error("Failed to get model analytics", [
                'model_id' => $modelId ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $response->json([
                'success' => false,
                'error' => 'Failed to retrieve analytics'
            ], 500);
        }
    }

    /**
     * Get session model configuration
     */
    public function getSessionModels() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');

            return $this->handleServiceCall(function() use ($sessionId) {
                $sessionModels = $this->sessionModelModel->getBySessionId($sessionId);

                // Enrich with model details
                $enrichedModels = [];
                foreach ($sessionModels as $sessionModel) {
                    $model = $this->aiModelModel->getById($sessionModel['model_id']);
                    if ($model) {
                        $enrichedModels[] = array_merge($sessionModel, [
                            'model_name' => $model['model_name'],
                            'name' => $model['model_name'], // For frontend compatibility
                            'provider' => $model['provider'],
                            'display_name' => $model['display_name'],
                            'capabilities' => $model['capabilities']
                        ]);
                    }
                }

                return [
                    "models" => $enrichedModels,
                    "count" => count($enrichedModels)
                ];
            }, "Session models retrieved successfully.", 'SESSION_MODELS_RETRIEVAL_FAILED');

    }

    /**
     * Update session model configuration
     */
    public function updateSessionModels() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $data = $this->getJsonInput();

            if (!isset($data['model_ids']) || !is_array($data['model_ids'])) {
                return $this->error("model_ids array is required.", 400, 'VALIDATION_ERROR');
            }

            return $this->handleServiceCall(function() use ($sessionId, $data) {
                // Remove existing models
                $existingModels = $this->sessionModelModel->getBySessionId($sessionId);
                foreach ($existingModels as $existingModel) {
                    $this->sessionModelModel->remove($existingModel['id']);
                }

                // Add new models
                $addedModels = [];
                foreach ($data['model_ids'] as $modelId) {
                    $model = $this->aiModelModel->getById($modelId);
                    if ($model && $model['is_active']) {
                        $association = $this->sessionModelModel->create([
                            'session_id' => $sessionId,
                            'model_id' => $modelId,
                            'is_visible' => true,
                            'configuration' => $data['configuration'][$modelId] ?? []
                        ]);
                        $addedModels[] = $association;
                    }
                }

                Logger::info("Session models updated", [
                    'session_id' => $sessionId,
                    'model_count' => count($addedModels)
                ]);

                return [
                    "models" => $addedModels,
                    "count" => count($addedModels)
                ];
            }, "Session models updated successfully.", 'SESSION_MODELS_UPDATE_FAILED');

    }

    /**
     * Get model performance metrics (helper method)
     */
    private function getModelPerformanceMetrics($modelId) {
        // This would integrate with PerformanceMonitor
        // For now, return placeholder data
        return [
            'avg_latency_ms' => 0,
            'success_rate' => 0,
            'total_requests' => 0,
            'last_updated' => date('c')
        ];
    }

    /**
     * Get model usage statistics (helper method)
     */
    private function getModelUsageStats($modelId) {
        // This would query usage data
        // For now, return placeholder data
        return [
            'total_sessions' => 0,
            'total_tokens' => 0,
            'total_cost' => 0,
            'last_used' => null
        ];
    }

    /**
     * Get specific metric data (helper method)
     */
    private function getModelMetricData($modelId, $metric, $period) {
        // This would query actual metrics from database/cache
        // For now, return placeholder data
        return [
            'metric' => $metric,
            'period' => $period,
            'data_points' => [],
            'average' => 0,
            'min' => 0,
            'max' => 0
        ];
    }
}