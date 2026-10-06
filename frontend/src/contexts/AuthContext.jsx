import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import PropTypes from 'prop-types';
import { jwtDecode } from 'jwt-decode';
import apiClient from '../services/api/apiClient';

const AuthContext = createContext(null);

const TOKEN_KEY = 'levotek_token';
const USER_KEY  = 'levotek_user';

function isTokenExpired(token) {
  try {
    const decoded = jwtDecode(token);
    return decoded.exp < Date.now() / 1000;
  } catch {
    return true;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(null);
  const [token, setToken]     = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const storedUser  = localStorage.getItem(USER_KEY);

    if (storedToken && storedUser) {
      if (!isTokenExpired(storedToken)) {
        setToken(storedToken);
        setUser(JSON.parse(storedUser));
        apiClient.defaults.headers.common['Authorization'] = `Bearer ${storedToken}`;
      } else {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
      }
    }
    setLoading(false);
  }, []);

  const login = useCallback(async (email, password) => {
    const response = await apiClient.post('/auth/login', { email, password });
    const { token: newToken, user: userData } = response.data.data;

    localStorage.setItem(TOKEN_KEY, newToken);
    localStorage.setItem(USER_KEY, JSON.stringify(userData));
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${newToken}`;

    setToken(newToken);
    setUser(userData);
    return userData;
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    delete apiClient.defaults.headers.common['Authorization'];
    setToken(null);
    setUser(null);
  }, []);

  const hasPermiso = useCallback(
    (permiso) => {
      if (!user) return false;
      if (user.permisos?.admin === true) return true;

      const parts = permiso.split('.');
      let val = user.permisos;
      for (const p of parts) {
        if (val === null || val === undefined || typeof val === 'boolean') break;
        val = val[p];
      }
      // backward compat: old flat boolean or new nested leaf/module
      if (val === true) return true;
      if (val && typeof val === 'object' && val.ver) return true;
      return false;
    },
    [user]
  );

  const isAdmin = useCallback(() => {
    if (!user) return false;
    return !!user.permisos?.admin;
  }, [user]);

  const refreshUser = useCallback(async () => {
    if (!token) return null;
    try {
      const response = await apiClient.get('/auth/me');
      const userData = response.data.data;
      localStorage.setItem(USER_KEY, JSON.stringify(userData));
      setUser(userData);
      return userData;
    } catch (error) {
      if (error.status === 401) logout();
      throw error;
    }
  }, [token, logout]);

  const value = {
    user,
    token,
    loading,
    isAuthenticated: !!token && !!user,
    login,
    logout,
    hasPermiso,
    isAdmin,
    refreshUser,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

AuthProvider.propTypes = { children: PropTypes.node.isRequired };

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
