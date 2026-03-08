import { HTTP_HEADERS } from "./config/HTTP_HEADERS";

const getToken = () => {
  return localStorage.getItem("token") || sessionStorage.getItem("token");
};

// Dynamic BASE_URL - fetched from proxy
// const BASE_URL = import.meta.env.VITE_API_BASE_URL;
let BASE_URL = "";
const PROXY_URL = "/proxy-url";

// Fetch proxy URL using native fetch
const fetchProxyUrl = async () => {
  const response = await fetch(PROXY_URL, { cache: "no-store" });

  if (!response.ok) {
    throw new Error("Failed to fetch proxy url");
  }

  const data = await response.json();
  let urlStr = data.url;

  if (!urlStr.endsWith("/")) {
    urlStr += "/";
  }

  return urlStr;
};

// Initialize BASE_URL
const initBaseUrl = async () => {
  if (BASE_URL) return BASE_URL;

  const url = await fetchProxyUrl();

  if (!url) {
    throw new Error("Failed to get base URL from proxy");
  }

  BASE_URL = url;

  return BASE_URL;
};

export const apiClient = {
  get: async (url) => {
    return request(url, "GET");
  },

  post: async (url, payload) => {
    return request(url, "POST", payload);
  },

  postFormData: async (url, formData) => {
    return requestFormData(url, "POST", formData);
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
  
  if (!BASE_URL) {
    await initBaseUrl();
  }

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
    console.error("API error:", error.message);
    return { ok: false, status: 500, data: { message: "Network error" } };
  }
};

const requestFormData = async (url, method, formData) => {
  const token = getToken();

  if (!BASE_URL) {
    await initBaseUrl();
  }

  const headers = {
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };

  try {
    const response = await fetch(`${BASE_URL}${url}`, {
      method,
      headers,
      body: formData,
    });

    if (response.status === 401) {
      window.dispatchEvent(new CustomEvent('unauthorized'));
      return { ok: false, status: 401, data: { message: "Unauthorized" } };
    }

    const data = await response.json();
    return { ok: response.ok, status: response.status, data };

  } catch (error) {
    console.error("API error:", error.message);
    return { ok: false, status: 500, data: { message: "Network error" } };
  }
};
