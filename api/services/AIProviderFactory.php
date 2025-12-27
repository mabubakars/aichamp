<?php
// Include required AI provider classes
require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/LocalAIProvider.php';
require_once __DIR__ . '/RemoteAIProvider.php';
require_once __DIR__ . '/../core/Logger.php';

/**
 * Factory class for creating AI provider instances
 * Based on the model's provider field, returns the appropriate AIProvider implementation
 */
class AIProviderFactory {
    /**
     * Create an AI provider instance based on the model configuration
      *
      * @param AIModel $model The AI model configuration
      * @return AIProvider The appropriate provider instance
      * @throws Exception if provider is not supported
      */
     public static function create($model) {
         $provider = strtolower($model->provider);

         Logger::debug("Creating AI provider", [
             'model_name' => $model->model_name,
             'provider' => $provider
         ]);

         switch ($provider) {
             case 'ollama':
                 return new LocalAIProvider($model);
             case 'openai':
             case 'anthropic':
             case 'groq':
             case 'together':
             case 'huggingface':
                 return new RemoteAIProvider($model);
             default:
                 // Default to RemoteAIProvider for unknown providers
                 return new RemoteAIProvider($model);
         }
     }

     /**
      * Create a multi-model AI provider instance
      *
      * @param array $models Array of AIModel instances
      * @param array $config Configuration options for multi-model coordination
      * @return MultiModelAIProvider The multi-model provider instance
      * @throws Exception if models array is empty or invalid
      */
     public static function createMultiModel($models, $config = []) {
         if (empty($models)) {
             throw new InvalidArgumentException("Models array cannot be empty");
         }

         // Validate that all models are AIModel instances
         foreach ($models as $model) {
             if (!is_object($model) || !method_exists($model, 'getApiEndpoint')) {
                 throw new InvalidArgumentException("All models must be valid AIModel instances");
             }
         }

         Logger::debug("Creating multi-model AI provider", [
             'model_count' => count($models),
             'config' => $config
         ]);

         require_once __DIR__ . '/MultiModelCoordinator.php';
         require_once __DIR__ . '/MultiModelAIProvider.php';

         $coordinator = new MultiModelCoordinator($config);
         return new MultiModelAIProvider($coordinator, $config);
     }
}
?>