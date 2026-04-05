import { apiClient } from "../apiClient";
const API_VERSION = import.meta.env.VITE_API_VERSION;

export const chatService = {

  getModels: async () => {
    const res = await apiClient.get(`${API_VERSION}/models`);
    return res;
  },

  sendPromptToModel: (sessionId, modelId, content) => {
    console.log('sendPromptToModel URL:', `sessions/${sessionId}/models/${modelId}/chat`);
    return apiClient.post(`sessions/${sessionId}/models/${modelId}/chat`, { content });
  },

  sendPromptBatch: (sessionId, modelIds, content) => {
    // Use the first modelId in the URL (PHP route requires it), pass all in body
    return apiClient.post(`sessions/${sessionId}/models/${modelIds[0]}/chat`, {
        content,
        model_ids: modelIds,
    });
  },

  sendPromptWithFile: async (sessionId, modelId, content, file) => {
    console.log('sendPromptWithFile URL:', `sessions/${sessionId}/models/${modelId}/chat`);
    const formData = new FormData();
    formData.append('content', content);
    formData.append('file', file);
    formData.append('file_name', file.name);
    return apiClient.postFormData(`sessions/${sessionId}/models/${modelId}/chat`, formData);
  }
};
