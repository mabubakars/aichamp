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

  sendPromptWithFile: async (sessionId, modelId, content, file) => {
    console.log('sendPromptWithFile URL:', `sessions/${sessionId}/models/${modelId}/chat`);
    const formData = new FormData();
    formData.append('content', content);
    formData.append('file', file);
    return apiClient.postFormData(`sessions/${sessionId}/models/${modelId}/chat`, formData);
  }
};
