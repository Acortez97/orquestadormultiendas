import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import PropTypes from 'prop-types';
import { useLocation } from 'react-router-dom';
import { tiendaApi } from '../services/api/endpoints';

// Pendientes de la tienda por pantalla ({ '/compras': { n, tono, texto } }) para los contadores del menu.
// Se actualizan al cambiar de pantalla (como mucho cada 30 s) y cada 2 minutos.
const PendientesContext = createContext({ pendientes: {}, refrescar: () => {} });
const CADA_MS = 2 * 60 * 1000;
const MIN_MS = 30 * 1000;

export function PendientesProvider({ children }) {
  const [pendientes, setPendientes] = useState({});
  const [ultima, setUltima] = useState(0);
  const { pathname } = useLocation();

  const refrescar = useCallback(() => {
    setUltima(Date.now());
    tiendaApi.pendientes().then((r) => setPendientes(r.data || {})).catch(() => {});
  }, []);

  useEffect(() => {
    refrescar();
    const t = setInterval(refrescar, CADA_MS);
    return () => clearInterval(t);
  }, [refrescar]);

  useEffect(() => {
    if (Date.now() - ultima > MIN_MS) refrescar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  return <PendientesContext.Provider value={{ pendientes, refrescar }}>{children}</PendientesContext.Provider>;
}
PendientesProvider.propTypes = { children: PropTypes.node };

/** Mapa de pendientes por ruta */
export const usePendientes = () => useContext(PendientesContext).pendientes;
/** Para refrescar despues de aprobar, liquidar, cerrar corte, etc. */
export const useRefrescarPendientes = () => useContext(PendientesContext).refrescar;
