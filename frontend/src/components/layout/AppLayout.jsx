import { useState, useEffect } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import Sidebar from './Sidebar';
import Header from './Header';
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
    <div role="alert" className="sticky top-0 z-50 w-full bg-red-600 px-4 py-2 text-center text-sm font-semibold tracking-wide text-white">
      {aviso}
    </div>
  );
}

/** Aviso permanente cuando el superadmin opera una tienda (modo soporte) */
function SoporteBanner() {
  const { user, salirSoporte } = useAuth();
  const navigate = useNavigate();
  if (!user?.soporte) return null;
  return (
    <div className="flex w-full flex-wrap items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-semibold text-amber-950">
      <span>Modo soporte: estás operando la tienda «{user.tienda?.nombre}». Todas las acciones quedan en su bitácora.</span>
      <button className="rounded-md bg-amber-950/90 px-3 py-1 text-xs text-white hover:bg-amber-950"
        onClick={() => { salirSoporte(); navigate('/admin/tiendas', { replace: true }); }}>
        Volver al panel de administración
      </button>
    </div>
  );
}

function AppLayout() {
  const [sidebarOpen, setSidebarOpen]           = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const { isOnline } = useOffline();

  return (
    <div className="min-h-screen bg-[#f2f5fc]">
      <SoporteBanner />
      {!isOnline && <OfflineBanner />}
      <PaymentBanner />

      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <Sidebar
        isOpen={sidebarOpen}
        isCollapsed={sidebarCollapsed}
        onClose={() => setSidebarOpen(false)}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />

      <div className={`transition-all duration-300 ${sidebarCollapsed ? 'lg:ml-20' : 'lg:ml-64'}`}>
        <Header
          onMenuClick={() => setSidebarOpen(true)}
          sidebarCollapsed={sidebarCollapsed}
        />
        <main className="p-4 lg:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

export default AppLayout;
