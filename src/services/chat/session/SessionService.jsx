
import { apiClient } from "../../apiClient";

export const sessionService = {
  getSessions: () => apiClient.get("sessions"),

  getActiveSession: () => apiClient.get("sessions/active"),

  createSession: (title) => apiClient.post("sessions", { title }),

  activateSession: (id) => apiClient.put(`sessions/${id}/activate`),

  getSessionById: (id) => apiClient.get(`sessions/${id}`), 

  renameActiveSession: (title) =>
    apiClient.put("sessions/active", { title }),

  deleteSession: (id) => apiClient.delete(`sessions/${id}`),

  assignModelToSession: (sessionId, modelId) =>
    apiClient.post(`sessions/${sessionId}/models`, { model_id: modelId }),

  getSessionModels: (sessionId) =>
    apiClient.get(`sessions/${sessionId}/models`),

  // updateModelVisibility: (sessionId, modelId, payload) =>
  //   apiClient.put(`sessions/${sessionId}/models/${modelId}`, payload),
  
  updateModelVisibility: (sessionId, modelId, payload) =>
    apiClient.put(`sessions/${sessionId}/models/${modelId}`, payload),

  getSessionMessages: (sessionId) =>
    apiClient.get(`sessions/${sessionId}/messages`),
  
};
