import { HTTP_HEADERS } from "./config/HTTP_HEADERS";

const getToken = () => {
  return localStorage.getItem("token") || sessionStorage.getItem("token");
};

// const BASE_URL = import.meta.env.VITE_API_BASE_URL;
let BASE_URL = "";
let initialized = false;
const PROXY_URL = "https://proxy.mahmanawaz.com/";
const fetchProxyUrl = async () => {
  try {
    const proxies = [
      `https://corsproxy.io/?${encodeURIComponent(PROXY_URL)}`,
      `https://api.allorigins.win/raw?url=${encodeURIComponent(PROXY_URL)}`,
    ];
    
    for (const url of proxies) {
      try {
        const response = await fetch(url, { cache: 'no-store' });
        if (response.ok) {
          const data = await response.json();
          let urlStr = data.url || data.contents || data;
          if (!urlStr.endsWith('/')) {
            urlStr = urlStr + '/';
          }
          return urlStr;
        }
      } catch (e) {
        console.warn('Proxy failed:', e);
      }
    }
    throw new Error("All proxies failed");
  } catch (error) {
    console.error("Failed to fetch proxy URL:", error);
    return null;
  }
};

export const initBaseUrl = async () => {
  if (initialized && BASE_URL) return BASE_URL;
  
  const url = await fetchProxyUrl();
  if (url) {
    BASE_URL = url;
    initialized = true;
    console.log("BASE_URL initialized:", BASE_URL);
  }
  
  return BASE_URL;
};

export const refreshBaseUrl = async () => {
  initialized = false;
  return initBaseUrl();
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
    console.error("API request failed:", error);
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
    return { ok: false, status: 500, data: { message: "Network error" } };
  }
};
