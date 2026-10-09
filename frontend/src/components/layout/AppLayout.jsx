import { useState, useEffect, useCallback } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import Sidebar from './Sidebar';
import Header from './Header';
import { Riel, BarraInferior, MenuMas } from './NavMovil';
import BuscadorGlobal from './BuscadorGlobal';
import { PendientesProvider } from '../../contexts/PendientesContext';
import { useOffline } from '../../contexts/OfflineContext';
import OfflineBanner from '../common/OfflineBanner';
import { useAuth } from '../../contexts/AuthContext';

const REVISAR_AVISO_MS = 5 * 60 * 1000;

/** Aviso de pago pendiente: lo pone/quita el superadmin desde el panel; lo ven todos los usuarios de la tienda */
function PaymentBanner() {
  const { user, refreshUser } = useAuth();

  // se revisa cada 5 minutos para que aparezca/desaparezca sin volver a iniciar sesion
  useEffect(() => {
    const t = setInterval(() => { refreshUser().catch(() => {}); }, REVISAR_AVISO_MS);
    return () => clearInterval(t);
  }, [refreshUser]);

  const aviso = user?.tienda?.aviso_pago;
  if (!aviso) return null;
  return (
    <div role="alert" className="flex w-full items-center justify-center gap-2 bg-danger-600 px-4 py-1.5 text-center text-[13px] font-semibold text-white">
      <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-white" />
      <span className="min-w-0">{aviso}</span>
    </div>
  );
}

/** Aviso permanente cuando el superadmin opera una tienda (modo soporte) */
function SoporteBanner() {
  const { user, salirSoporte } = useAuth();
  const navigate = useNavigate();
  if (!user?.soporte) return null;
  return (
    <div className="flex w-full flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-[#FFE8B5] px-4 py-1.5 text-[13px] font-semibold text-[#5C3A00]">
      <span>Soporte en «{user.tienda?.nombre}» · todo lo que hagas queda en su bitácora.</span>
      <button className="min-h-8 rounded-lg border border-[#5C3A00] px-3 text-xs font-semibold hover:bg-[#5C3A00] hover:text-white"
        onClick={() => { salirSoporte(); navigate('/admin/tiendas', { replace: true }); }}>
        Salir del soporte
      </button>
    </div>
  );
}

const CLAVE_PLEGADO = 'ot_menu_plegado';
const leerPlegado = () => { try { return localStorage.getItem(CLAVE_PLEGADO) === '1'; } catch { return false; } };

/**
 * Estructura de la tienda, adaptable a cualquier pantalla:
 *  - computadora (>= 1024 px): menu lateral agrupado, plegable a iconos
 *  - tablet (640 a 1023 px): riel de iconos + «Más»
 *  - celular (< 640 px): barra inferior + «Más» a pantalla completa
 * El punto de venta usa toda la pantalla (sin menu).
 */
function AppLayout() {
  const [plegado, setPlegado] = useState(leerPlegado);
  const [masAbierto, setMasAbierto] = useState(false);
  const [buscador, setBuscador] = useState(false);
  const { isOnline } = useOffline();
  const { pathname } = useLocation();
  const pantallaCompleta = pathname === '/pos';

  const plegar = () => setPlegado((p) => {
    try { localStorage.setItem(CLAVE_PLEGADO, p ? '0' : '1'); } catch { /* sin almacenamiento */ }
    return !p;
  });
  const cerrarMas = useCallback(() => setMasAbierto(false), []);

  // Ctrl+K / Cmd+K abre el buscador universal en cualquier pantalla (menos en el POS, que tiene sus atajos)
  useEffect(() => {
    if (pantallaCompleta) return undefined;
    const tecla = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setBuscador(true); }
    };
    document.addEventListener('keydown', tecla);
    return () => document.removeEventListener('keydown', tecla);
  }, [pantallaCompleta]);

  return (
    <PendientesProvider>
      <div className="flex min-h-dvh flex-col bg-slate-50">
        {/* Avisos arriba, en el flujo de la pagina: el menu y el encabezado empiezan debajo de ellos */}
        <SoporteBanner />
        {!isOnline && <OfflineBanner />}
        <PaymentBanner />

        {pantallaCompleta ? (
          <Outlet />
        ) : (
          <div className="flex flex-1">
            <Sidebar plegado={plegado} onPlegar={plegar} />
            <Riel onMas={() => setMasAbierto(true)} />
            <div className="min-w-0 flex-1">
              <Header onBuscar={() => setBuscador(true)} />
              {/* en celular se deja espacio para la barra inferior */}
              <main className="px-4 pt-4 pb-28 sm:pb-8 lg:px-8 lg:pt-6">
                <Outlet />
              </main>
            </div>
          </div>
        )}
        {!pantallaCompleta && (
          <>
            <BarraInferior onMas={() => setMasAbierto((v) => !v)} masAbierto={masAbierto} />
            <MenuMas abierto={masAbierto} onCerrar={cerrarMas} onBuscar={() => setBuscador(true)} />
            <BuscadorGlobal abierto={buscador} onCerrar={() => setBuscador(false)} />
          </>
        )}
      </div>
    </PendientesProvider>
  );
}

export default AppLayout;
