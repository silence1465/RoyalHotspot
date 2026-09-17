import axios from 'axios';

const api = axios.create({
  // Keep browser requests on the same origin. Captive-portal browsers and
  // MikroTik's pre-auth network can block a second origin/port even when
  // the SPA itself loaded successfully. Vite/Nginx proxy /api to Laravel.
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: {
    Accept: 'application/json',
  },
});

// Attach the bearer token (admin or customer — whichever is present) to
// every outgoing request.
api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem('auth_token') || localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if ((sessionStorage.getItem('auth_role') || localStorage.getItem('auth_role')) === 'admin') {
    config.headers['X-Router-Id'] = config.headers['X-Router-Id'] || localStorage.getItem('admin_router_scope') || 'all';
  }
  return config;
});

// On a 401, clear the stale token AND force a redirect immediately.
// Just clearing localStorage used to be enough, because a 401 only ever
// happened as a direct result of a page load — the page's own
// ProtectedRoute check would naturally catch the missing token on the
// next render. That assumption broke once background polling was added
// (NotificationBell, AdminLayout's badge fetch) — those run continuously
// regardless of which page is open, so a 401 from one of them could
// silently wipe the token with no navigation event to ever notice.
// Forcing the redirect here, in the interceptor itself, closes that gap
// unconditionally rather than depending on some future render to catch it.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const wasAdmin = (sessionStorage.getItem('auth_role') || localStorage.getItem('auth_role')) === 'admin';
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_role');
      sessionStorage.removeItem('auth_token');
      sessionStorage.removeItem('auth_role');

      const loginPath = wasAdmin ? '/admin/login' : '/login';
      // Avoid a redirect loop if this 401 came from a request made
      // while already sitting on the login page (e.g. a stale poll
      // that hadn't been torn down yet).
      if (window.location.pathname !== loginPath) {
        window.location.href = loginPath;
      }
    }
    return Promise.reject(error);
  }
);

export default api;
