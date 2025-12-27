import { apiClient } from "./apiClient";

export const authService = {
  signup: async (payload) => {
    return apiClient.post("signup", payload);
  },

  login: async (payload) => {
    return apiClient.post("login", payload);
  },

  getProfile: async () => {return apiClient.get("profile");},
  updateProfile: async (payload) => apiClient.put("profile", payload),

  changePassword: async (payload) => apiClient.post("change-password", payload),
};
