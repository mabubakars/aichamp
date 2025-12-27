<?php
class AIController extends BaseController {
    public function __construct($db) {
        parent::__construct($db);
    }

    public function getModels() {
        // Return OpenAI compatible models list from database
        $aiModel = new AIModel($this->db);
        $result = $aiModel->getAllActive();

        $models = [];
        foreach ($result['models'] as $model) {
            $models[] = [
                "id" => $model['id'], // Use UUID as id for frontend URL compatibility
                "name" => $model['display_name'],
                "object" => "model",
                "created" => strtotime($model['created_at']),
                "owned_by" => $model['provider']
            ];
        }

        return $this->success($models);
    }
}
?>