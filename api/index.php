<?php
// Single entry point with optimized loading
require_once 'core/Bootstrap.php';
Bootstrap::init();

try {
    // Rate limiting check
    if (shouldApplyRateLimiting()) {
        checkRateLimits();
    }

    // Initialize database and routes
    initializeApplication();

} catch (Throwable $e) {
    Logger::error("Unhandled exception in index.php", [
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    ErrorHandler::handleException($e);
}

// Request handling and logging is now done in Router::handleRequest()

// Helper functions (not class methods)
function shouldApplyRateLimiting() {
    // Bypass rate limiting in testing environment or when test header is present
    if (Environment::get('APP_ENV') === 'testing') {
        return false;
    }

    // Check for test header (useful for integration tests)
    if (isset($_SERVER['HTTP_X_TEST_MODE']) && $_SERVER['HTTP_X_TEST_MODE'] === 'true') {
        return false;
    }

    return Environment::get('RATE_LIMIT_ENABLED', 'true') === 'true';
}

function seedDefaultAIModels($database) {
    try {
        // Check if models already exist
        $existingModels = $database->readMany('ai_models', [], 'COUNT(*) as count');
        if ($existingModels[0]['count'] > 0) {
            Logger::info("AI models already seeded, skipping");
            return;
        }

        // Seed default models
        $models = [
            [
                'name' => 'deepseek-r1:7b',
                'model_name' => 'deepseek-r1:7b',
                'provider' => 'ollama',
                'description' => 'DeepSeek R1 7B model',
                'context_length' => 32768,
                'config' => '{"api_endpoint": "http://localhost:11434/api/chat", "max_tokens": 4096}'
            ],
            [
                'name' => 'llama3.1:8b',
                'model_name' => 'llama3.1:8b',
                'provider' => 'ollama',
                'description' => 'Llama 3.1 8B model',
                'context_length' => 131072,
                'config' => '{"api_endpoint": "http://localhost:11434/api/chat", "max_tokens": 4096}'
            ]
        ];

        foreach ($models as $model) {
            $database->create('ai_models', $model);
        }

        Logger::info("Default AI models seeded successfully", ['count' => count($models)]);
    } catch (Exception $e) {
        Logger::error("Failed to seed AI models", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function checkRateLimits() {
    require_once 'core/PersistentRateLimiter.php';

    $request = Request::getInstance();
    $response = Response::getInstance();

    $request_uri = $request->getUri();
    // Only strip '/api' prefix if the URI starts with '/api/'
    if (strpos($request_uri, '/api/') === 0) {
        $path = substr($request_uri, 4); // Remove '/api' prefix
    } else {
        $path = $request_uri;
    }

    $rate_limit_result = PersistentRateLimiter::checkEarly($path);

    if (!$rate_limit_result['allowed']) {
        return $response->rateLimit($rate_limit_result['reset_time'] - time())->send();
    }
}

function initializeApplication() {
    Logger::info("Initializing database connection");
    $database = Database::getInstance();
    $db = $database->getConnection(); // This returns PDO, but we need Database instance
    

    // Create AI models table if it doesn't exist
    Logger::info("Creating AI models table");
    AIModel::createTable($database);

    // Seed default AI models if they don't exist
    Logger::info("Seeding default AI models");
    seedDefaultAIModels($database);

    // Create router with Database instance
    $router = new Router($database); // Pass Database instance to router
    
    require_once 'routes/api.php';
    registerRoutes($router);

    $request = Request::getInstance();
    Logger::info("Handling request", [
        'method' => $request->getMethod(),
        'uri' => $request->getUri()
    ]);
    
    $router->handleRequest();
}
?>