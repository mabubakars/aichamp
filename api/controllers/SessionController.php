<?php
class SessionController extends BaseController {
    private $chatService;

    public function __construct($db) {
        parent::__construct($db);
        $this->chatService = new ChatService($db);
    }

    /**
     * Create a new chat session
     */
    public function createSession() {
        $user = $this->getAuthenticatedUser();
        // Try to get JSON input, but allow empty body
        try {
            $data = $this->getJsonInput();
        } catch (InvalidArgumentException $e) {
            $data = [];
        }

        $title = $data['title'] ?? 'New Chat Session';
        $modelIds = $data['model_ids'] ?? [];

        $session = $this->chatService->createSession($user['user_id'], $title, $modelIds);

        return $this->success([
            "session" => [
                "id" => $session['id'],
                "user_id" => $session['user_id'],
                "title" => $session['title'],
                "version" => $session['version'],
                "is_active" => $session['is_active'],
                "total_tokens" => $session['total_tokens'],
                "last_message_at" => $session['last_message_at'],
                "created_at" => $session['created_at'],
                "updated_at" => $session['updated_at']
            ]
        ], "Session created successfully");
    }

    /**
     * Get active session for user
     */
    public function getActiveSession() {
        $user = $this->getAuthenticatedUser();
        try {
            $chatSessionModel = new ChatSession($this->db);
            $sessions = $chatSessionModel->getByUserId($user['user_id'], true, 1, 1);
            if (empty($sessions['sessions'])) {
                return $this->success(["session" => null], "Active session retrieved successfully.");
            }
            $session = $sessions['sessions'][0];
            $session['models'] = $this->chatService->getSessionModels($session['id'], $user['user_id']);
            $result = [
                "session" => [
                    "id" => $session['id'],
                    "user_id" => $session['user_id'],
                    "title" => $session['title'],
                    "version" => $session['version'],
                    "is_active" => $session['is_active'],
                    "total_tokens" => $session['total_tokens'],
                    "last_message_at" => $session['last_message_at'],
                    "created_at" => $session['created_at'],
                    "updated_at" => $session['updated_at'],
                    "models" => $session['models']
                ]
            ];
            return $this->success($result, "Active session retrieved successfully.");
        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Service call failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error("Operation failed", 500, 'SESSION_RETRIEVAL_FAILED');
        }
    }

    /**
     * List user's sessions
     */
    public function listSessions() {
        $user = $this->getAuthenticatedUser();
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 50);
        $activeOnly = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : false;

        Logger::info("listSessions called with parameters", [
            'user_id' => $user['user_id'],
            'page' => $page,
            'limit' => $limit,
            'active_only' => $activeOnly
        ]);

        try {
            $chatSessionModel = new ChatSession($this->db);
            $result = $chatSessionModel->getByUserId($user['user_id'], $page, $limit, $activeOnly);

            Logger::info("listSessions getByUserId result", [
                'total_sessions' => $result['pagination']['total'],
                'returned_sessions' => count($result['sessions']),
                'session_ids' => array_column($result['sessions'], 'id')
            ]);

            // Enrich sessions with model counts
            foreach ($result['sessions'] as &$session) {
                $models = $this->chatService->getSessionModels($session['id'], $user['user_id']);
                $session['model_count'] = count($models);
            }

            $data = [
                "sessions" => array_map(function($session) {
                    return [
                        "id" => $session['id'],
                        "user_id" => $session['user_id'],
                        "title" => $session['title'],
                        "version" => $session['version'],
                        "is_active" => $session['is_active'],
                        "total_tokens" => $session['total_tokens'],
                        "last_message_at" => $session['last_message_at'],
                        "created_at" => $session['created_at'],
                        "updated_at" => $session['updated_at'],
                        "model_count" => $session['model_count']
                    ];
                }, $result['sessions']),
                "pagination" => $result['pagination']
            ];
            return $this->success($data, "Sessions retrieved successfully.");
        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Service call failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error("Operation failed", 500, 'SESSIONS_RETRIEVAL_FAILED');
        }
    }

    /**
     * Get specific session
     */
    public function getSession() {
        $user = $this->getAuthenticatedUser();
        $sessionId = $this->getRouteParam('sessionId');

        try {
            $session = $this->chatService->getSession($sessionId, $user['user_id']);

            $result = [
                "session" => [
                    "id" => $session['id'],
                    "user_id" => $session['user_id'],
                    "title" => $session['title'],
                    "version" => $session['version'],
                    "continuity_token" => $session['continuity_token'],
                    "is_active" => $session['is_active'],
                    "total_tokens" => $session['total_tokens'],
                    "last_message_at" => $session['last_message_at'],
                    "created_at" => $session['created_at'],
                    "updated_at" => $session['updated_at'],
                    "models" => $session['models']
                ]
            ];
            return $this->success($result, "Session retrieved successfully.");
        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Service call failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error("Operation failed", 500, 'SESSION_RETRIEVAL_FAILED');
        }
    }

    /**
     * Rename active session
     */
    public function renameActiveSession() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();
            $title = $data['title'] ?? '';

            if (empty($title)) {
                return $this->error("Title is required.", 400, 'VALIDATION_ERROR');
            }

            try {
                $chatSessionModel = new ChatSession($this->db);
                $sessions = $chatSessionModel->getByUserId($user['user_id'], true, 1, 1);

                if (empty($sessions['sessions'])) {
                    return $this->error("No active session found.", 404, 'SESSION_NOT_FOUND');
                }

                $sessionId = $sessions['sessions'][0]['id'];
                Logger::info("Renaming active session", [
                    'session_id' => $sessionId,
                    'current_title' => $sessions['sessions'][0]['title'],
                    'new_title' => $title
                ]);
                $updatedSession = $this->chatService->updateSession($sessionId, $user['user_id'], ['title' => $title]);

                Logger::info("Active session renamed successfully", [
                    'session_id' => $updatedSession['id'],
                    'new_title' => $updatedSession['title']
                ]);

                $result = [
                    "session" => [
                        "id" => $updatedSession['id'],
                        "title" => $updatedSession['title'],
                        "updated_at" => $updatedSession['updated_at']
                    ]
                ];
                return $this->success($result, "Active session renamed successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'SESSION_UPDATE_FAILED');
            }

    }

    /**
     * Activate a session
     */
    public function activateSession() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');

            try {
                // First verify ownership
                $this->chatService->getSession($sessionId, $user['user_id']);

                $chatSessionModel = new ChatSession($this->db);
                $success = $chatSessionModel->activate($sessionId);

                if (!$success) {
                    return $this->error("Failed to activate session.", 500, 'SESSION_ACTIVATION_FAILED');
                }

                return $this->success(["message" => "Session activated successfully."], "Session activated successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'SESSION_ACTIVATION_FAILED');
            }

    }

    /**
     * Delete a session
     */
    public function deleteSession() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');

            try {
                $success = $this->chatService->deleteSession($sessionId, $user['user_id']);

                if (!$success) {
                    return $this->error("Failed to delete session.", 500, 'SESSION_DELETION_FAILED');
                }

                return $this->success(["message" => "Session deleted successfully."], "Session deleted successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 400, 'VALIDATION_ERROR');
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'SESSION_DELETION_FAILED');
            }

    }

    /**
     * Add model to session
     */
    public function addModel() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $data = $this->getJsonInput();
            $modelId = $data['model_id'] ?? '';

            if (empty($modelId)) {
                return $this->error("Model ID is required.", 400, 'VALIDATION_ERROR');
            }

            try {
                // Verify session ownership
                $this->chatService->getSession($sessionId, $user['user_id']);

                $association = $this->chatService->addModelToSession($sessionId, $modelId, $user['user_id']);

                $result = [
                    "association" => [
                        "id" => $association['id'],
                        "session_id" => $association['session_id'],
                        "model_id" => $association['model_id'],
                        "is_visible" => $association['is_visible'],
                        "created_at" => $association['created_at']
                    ]
                ];
                return $this->success($result, "Model added to session successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 400, 'VALIDATION_ERROR');
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'MODEL_ADD_FAILED');
            }

    }

    /**
     * Update model in session
     */
    public function updateModel() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $modelId = $this->getRouteParam('modelId');
            $data = $this->getJsonInput();

            try {
                // Verify session ownership
                $this->chatService->getSession($sessionId, $user['user_id']);

                // Get model by ID
                $aiModel = new AIModel($this->db);
                $model = $aiModel->getById($modelId);
                if (!$model) {
                    return $this->error("Model not found.", 404, 'MODEL_NOT_FOUND');
                }

                // Find the association
                $sessionModelModel = new SessionModel($this->db);
                $associations = $sessionModelModel->getBySessionId($sessionId);
                $association = null;
                foreach ($associations as $assoc) {
                    if ($assoc['model_id'] == $modelId) {
                        $association = $assoc;
                        break;
                    }
                }
                if (!$association) {
                    return $this->error("Model not associated with session.", 404, 'ASSOCIATION_NOT_FOUND');
                }

                $updatedAssociation = $sessionModelModel->update($association['id'], $data);

                $result = [
                    "association" => $updatedAssociation
                ];
                return $this->success($result, "Model updated successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'MODEL_UPDATE_FAILED');
            }

    }

    /**
     * Toggle model visibility
     */
    public function toggleModel() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $modelId = $this->getRouteParam('modelId');

            try {
                // Verify session ownership
                $this->chatService->getSession($sessionId, $user['user_id']);

                // Get model by ID
                $aiModel = new AIModel($this->db);
                $model = $aiModel->getById($modelId);
                if (!$model) {
                    return $this->error("Model not found.", 404, 'MODEL_NOT_FOUND');
                }

                // Find the association
                $sessionModelModel = new SessionModel($this->db);
                $associations = $sessionModelModel->getBySessionId($sessionId);
                $association = null;
                foreach ($associations as $assoc) {
                    if ($assoc['model_id'] == $modelId) {
                        $association = $assoc;
                        break;
                    }
                }
                if (!$association) {
                    return $this->error("Model not associated with session.", 404, 'ASSOCIATION_NOT_FOUND');
                }

                $visibility = $sessionModelModel->toggleVisibility($association['id']);

                $result = [
                    "model_id" => $modelId,
                    "is_visible" => $visibility
                ];
                return $this->success($result, "Model visibility toggled successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'MODEL_TOGGLE_FAILED');
            }

    }

    /**
     * Remove model from session
     */
    public function removeModel() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $modelId = $this->getRouteParam('modelId');

            try {
                $success = $this->chatService->removeModelFromSession($sessionId, $modelId, $user['user_id']);

                if (!$success) {
                    return $this->error("Failed to remove model from session.", 500, 'MODEL_REMOVAL_FAILED');
                }

                return $this->success(["message" => "Model removed from session successfully."], "Model removed from session successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'MODEL_REMOVAL_FAILED');
            }

    }

    /**
     * List models for session
     */
    public function listSessionModels() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');

            try {
                $models = $this->chatService->getSessionModels($sessionId, $user['user_id']);

                $result = [
                    "models" => array_map(function($model) {
                        return [
                            "id" => $model['id'],
                            "session_id" => $model['session_id'],
                            "model_id" => $model['model_id'],
                            "model_name" => $model['model_name'],
                            "provider" => $model['provider'],
                            "model_name_full" => $model['model_name_full'],
                            "is_visible" => $model['is_visible'],
                            "usage_count" => $model['usage_count'],
                            "capabilities" => $model['capabilities'],
                            "created_at" => $model['created_at'],
                            "updated_at" => $model['updated_at']
                        ];
                    }, $models)
                ];
                return $this->success($result, "Session models retrieved successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'SESSION_MODELS_RETRIEVAL_FAILED');
            }

    }

    /**
     * Get session messages/conversation thread
     */
    public function getSessionMessages() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $limit = (int)($_GET['limit'] ?? 50);

            try {
                $thread = $this->chatService->getConversationThread($sessionId, $user['user_id'], $limit);

                $result = [
                    "messages" => $thread
                ];
                return $this->success($result, "Session messages retrieved successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'SESSION_MESSAGES_RETRIEVAL_FAILED');
            }

    }

    /**
     * Chat with specific model
     */
    public function chatWithModel() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $modelId = $this->getRouteParam('modelId');
            $data = $this->getJsonInput();

            $content = $data['content'] ?? '';
            if (empty($content)) {
                return $this->error("Content is required.", 400, 'VALIDATION_ERROR');
            }

            try {
                $result = $this->chatService->chatWithModel($sessionId, $user['user_id'], $modelId, $content, $data);

                $data = [
                    'prompt' => [
                        'id' => $result['prompt']['id'],
                        'content' => $result['prompt']['content'],
                        'input_tokens' => $result['prompt']['input_tokens'],
                        'created_at' => $result['prompt']['created_at']
                    ],
                    'response' => [
                        'id' => $result['response']['id'],
                        'content' => $result['response']['content'],
                        'output_tokens' => $result['response']['output_tokens'],
                        'created_at' => $result['response']['created_at']
                    ],
                    'metadata' => $result['metadata']
                ];
                return $this->success($data, "Chat completed successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 400, 'VALIDATION_ERROR');
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'CHAT_FAILED');
            }

    }

    /**
     * Toggle model visibility (alternative route)
     */
    public function toggleModelVisibility() {
        $user = $this->getAuthenticatedUser();
            $sessionId = $this->getRouteParam('sessionId');
            $modelId = $this->getRouteParam('modelId');

            try {
                // Verify session ownership
                $this->chatService->getSession($sessionId, $user['user_id']);

                // Get model by ID
                $aiModel = new AIModel($this->db);
                $model = $aiModel->getById($modelId);
                if (!$model) {
                    return $this->error("Model not found.", 404, 'MODEL_NOT_FOUND');
                }

                // Find the association
                $sessionModelModel = new SessionModel($this->db);
                $associations = $sessionModelModel->getBySessionId($sessionId);
                $association = null;
                foreach ($associations as $assoc) {
                    if ($assoc['model_id'] == $modelId) {
                        $association = $assoc;
                        break;
                    }
                }
                if (!$association) {
                    return $this->error("Model not associated with session.", 404, 'ASSOCIATION_NOT_FOUND');
                }

                $visibility = $sessionModelModel->toggleVisibility($association['id']);

                $result = [
                    "model_id" => $modelId,
                    "is_visible" => $visibility
                ];
                return $this->success($result, "Model visibility toggled successfully.");
            } catch (InvalidArgumentException $e) {
                return $this->getValidationErrorResponse();
            } catch (Exception $e) {
                Logger::error("Service call failed", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return $this->error("Operation failed", 500, 'MODEL_VISIBILITY_TOGGLE_FAILED');
            }

    }
}
?>