import { createContext, useContext, useState, useCallback, useEffect } from 'react';
import api from '../services/api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [role, setRole] = useState(sessionStorage.getItem('auth_role') || localStorage.getItem('auth_role')); // 'admin' | 'customer'
  const [loading, setLoading] = useState(false);
  const [initializing, setInitializing] = useState(true);

  // On first load, if a token already exists (from a previous session),
  // fetch the user/customer record so `user` isn't null until the next
  // explicit login — otherwise a page refresh leaves you "logged in"
  // per the token but with no profile data available to render.
  useEffect(() => {
    const token = sessionStorage.getItem('auth_token') || localStorage.getItem('auth_token');
    const storedRole = sessionStorage.getItem('auth_role') || localStorage.getItem('auth_role');

    if (!token || !storedRole) {
      setInitializing(false);
      return;
    }

    const mePath = storedRole === 'admin' ? '/admin/me' : '/customer/me';

    api
      .get(mePath)
      .then(({ data }) => setUser(data))
      .catch(() => {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('auth_role');
        sessionStorage.removeItem('auth_token');
        sessionStorage.removeItem('auth_role');
        setRole(null);
      })
      .finally(() => setInitializing(false));
  }, []);

  const login = useCallback(async (path, credentials, loginAs) => {
    setLoading(true);
    try {
      const { data } = await api.post(path, credentials);
      const storage = loginAs === 'admin' ? sessionStorage : localStorage;
      storage.setItem('auth_token', data.token);
      storage.setItem('auth_role', loginAs);
      setRole(loginAs);
      setUser(data.user ?? data.customer ?? null);
      return { success: true };
    } catch (err) {
      return {
        success: false,
        message: err.response?.data?.message || 'Login failed. Please check your details and try again.',
        data: err.response?.data,
      };
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    const logoutPath = role === 'admin' ? '/admin/logout' : '/customer/logout';
    try {
      await api.post(logoutPath);
    } finally {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_role');
      sessionStorage.removeItem('auth_token');
      sessionStorage.removeItem('auth_role');
      setUser(null);
      setRole(null);
    }
  }, [role]);

  return (
    <AuthContext.Provider value={{ user, role, loading, initializing, login, logout, setUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
  return ctx;
}
