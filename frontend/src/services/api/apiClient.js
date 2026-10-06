import axios from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:3000/api/v1';

// UUID seguro para el header de correlación. `crypto.randomUUID` solo existe en contexto
// seguro (HTTPS); en HTTP o navegadores viejos usamos getRandomValues o, en último caso, Math.random.
function uuid() {
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
      const b = crypto.getRandomValues(new Uint8Array(16));
      b[6] = (b[6] & 0x0f) | 0x40;
      b[8] = (b[8] & 0x3f) | 0x80;
      const h = [...b].map((x) => x.toString(16).padStart(2, '0'));
      return `${h[0]}${h[1]}${h[2]}${h[3]}-${h[4]}${h[5]}-${h[6]}${h[7]}-${h[8]}${h[9]}-${h[10]}${h[11]}${h[12]}${h[13]}${h[14]}${h[15]}`;
    }
  } catch (_) { /* sin crypto disponible */ }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
});

apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('levotek_token');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    config.headers['X-Correlation-ID'] = uuid();
    return config;
  },
  (error) => Promise.reject(error)
);

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const { response } = error;

    if (response?.status === 401) {
      localStorage.removeItem('levotek_token');
      localStorage.removeItem('levotek_user');
      if (!window.location.pathname.includes('/login')) {
        window.location.href = '/login';
      }
    }

    const errorMessage =
      response?.data?.error?.message ||
      response?.data?.message ||
      error.message ||
      'Error desconocido';

    const enhancedError = new Error(errorMessage);
    enhancedError.status     = response?.status;
    enhancedError.code       = response?.data?.error?.code;
    enhancedError.details    = response?.data?.error?.details;
    enhancedError.validation = response?.data?.validation;
    enhancedError.response   = response;

    return Promise.reject(enhancedError);
  }
);

export default apiClient;
