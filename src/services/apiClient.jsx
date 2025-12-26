import { HTTP_HEADERS } from "./config/HTTP_HEADERS";

const getToken = () => {
  return localStorage.getItem("token") || sessionStorage.getItem("token");
};

const BASE_URL = import.meta.env.VITE_API_BASE_URL;

export const apiClient = {
  get: async (url) => {
    return request(url, "GET");
  },

  post: async (url, payload) => {
    return request(url, "POST", payload);
  },

  put: async (url, payload) => {
    return request(url, "PUT", payload);
  },

  delete: async (url) => {
    return request(url, "DELETE");
  },
};

const request = async (url, method, payload = null) => {
  const token = getToken();

  const headers = {
    ...HTTP_HEADERS,
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };

  try {
    const response = await fetch(`${BASE_URL}${url}`, {
      method,
      headers,
      body: payload ? JSON.stringify(payload) : null,
    });

    if (response.status === 401) {
      window.dispatchEvent(new CustomEvent('unauthorized'));
      return { ok: false, status: 401, data: { message: "Unauthorized" } };
    }

    const data = await response.json();
    return { ok: response.ok, status: response.status, data };

  } catch (error) {
    return { ok: false, status: 500, data: { message: "Network error" } };
  }
};
