<?php

class AIProviderFactory {
    public static function create($model) {
        Logger::info("Routing LLM request to Python FastAPI", [
            'model' => is_array($model) ? $model['model_name'] : $model->model_name
        ]);
        
        // Pass the model object to the bridge
        return new FastAPIProvider($model);
    }

    public static function createMultiModel($models, $config = []) {
        Logger::info("Routing Multi-Model request to Python FastAPI", [
            'model_count' => count($models)
        ]);
        
        // For multi-model, we pass the first model or a specific config
        return new FastAPIProvider($models[0] ?? null);
    }
}