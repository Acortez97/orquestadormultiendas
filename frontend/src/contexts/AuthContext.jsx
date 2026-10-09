import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import PropTypes from 'prop-types';
import { jwtDecode } from 'jwt-decode';
import apiClient from '../services/api/apiClient';
import session from '../services/session';

const AuthContext = createContext(null);

function isTokenExpired(token) {
  try {
    return jwtDecode(token).exp < Date.now() / 1000;
  } catch {
    return true;
  }
}

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(null);
  const [token, setToken]     = useState(null);
  const [loading, setLoading] = useState(true);

  // Color de la tienda: toda la escala primary-* de la interfaz se deriva de --acento
  const colorTienda = user?.tienda?.color || null;
  useEffect(() => {
    const raiz = document.documentElement;
    if (colorTienda) raiz.style.setProperty('--acento', colorTienda);
    else raiz.style.removeProperty('--acento');
  }, [colorTienda]);

  const aplicar = useCallback((t, u) => {
    session.guardar(t, u);
    setToken(t);
    setUser(u);
  }, []);

  const logout = useCallback(() => {
    session.limpiar();
    setToken(null);
    setUser(null);
  }, []);

  /** Recarga el usuario (permisos efectivos, tienda) desde el servidor */
  const refreshUser = useCallback(async () => {
    const res = await apiClient.get('/auth/me');
    const u = res.data.data;
    session.guardar(null, u);
    setUser(u);
    return u;
  }, []);

  useEffect(() => {
    const t = session.token();
    if (!t || isTokenExpired(t)) {
      // un token de soporte vencido regresa a la sesion de plataforma
      if (t && session.enSoporte()) session.salirSoporte();
      else session.limpiar();
    }
    const t2 = session.token();
    if (!t2) { setLoading(false); return; }
    setToken(t2);
    const guardado = session.user();
    if (guardado) { setUser(guardado); setLoading(false); }
    // siempre se revalida con el servidor (permisos o modulos pudieron cambiar)
    refreshUser().catch(() => {}).finally(() => setLoading(false));
  }, [refreshUser]);

  const login = useCallback(async (email, password) => {
    session.limpiar();
    const response = await apiClient.post('/auth/login', { email, password });
    const { token: t, user: u } = response.data.data;
    aplicar(t, u);
    return u;
  }, [aplicar]);

  /** Tras cambiar la contraseña el servidor emite un token nuevo (las demás sesiones se cierran) */
  const cambiarPassword = useCallback(async (currentPassword, newPassword) => {
    const res = await apiClient.post('/auth/change-password', { currentPassword, newPassword });
    session.guardar(res.data.data.token, null);
    setToken(res.data.data.token);
    return refreshUser();
  }, [refreshUser]);

  /** Superadmin: operar una tienda (soporte) */
  const entrarSoporte = useCallback(async (tokenSoporte) => {
    session.entrarSoporte(tokenSoporte);
    setToken(tokenSoporte);
    return refreshUser();
  }, [refreshUser]);

  const salirSoporte = useCallback(() => {
    const u = session.salirSoporte();
    setToken(session.token());
    setUser(u);
    return u;
  }, []);

  /** permiso 'modulo.accion' o 'modulo' (= modulo.ver) segun permisos efectivos del servidor */
  const hasPermiso = useCallback((permiso) => {
    if (!user?.permisos) return false;
    const [mod, acc = 'ver'] = permiso.split('.');
    return !!user.permisos[mod]?.[acc];
  }, [user]);

  const esAdminTienda = !!user && user.rol === 'admin_tienda';
  const esSuperadmin = !!user?.es_superadmin;

  const value = {
    user,
    token,
    loading,
    isAuthenticated: !!token && !!user,
    login,
    logout,
    hasPermiso,
    isAdmin: () => esAdminTienda,
    esAdminTienda,
    esSuperadmin,
    enSoporte: !!user?.soporte,
    refreshUser,
    cambiarPassword,
    entrarSoporte,
    salirSoporte,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

AuthProvider.propTypes = { children: PropTypes.node.isRequired };

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
