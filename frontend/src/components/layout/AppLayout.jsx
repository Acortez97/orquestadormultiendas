import { useState, useEffect } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import axios from 'axios';
import Sidebar from './Sidebar';
import Header from './Header';
import { useOffline } from '../../contexts/OfflineContext';
import OfflineBanner from '../common/OfflineBanner';
import { useAuth } from '../../contexts/AuthContext';

const SALDO_URL = import.meta.env.VITE_URL_SALDO;

function PaymentBanner() {
  const [show, setShow] = useState(false);

  useEffect(() => {
    if (!SALDO_URL) return;
    axios.get(SALDO_URL)
      .then(({ data }) => { if (data?.estado === 'Con Adeudo') setShow(true); })
      .catch(() => {});
  }, []);

  if (!show) return null;

  return (
    <div className="w-full bg-red-600 text-white text-center text-sm font-semibold py-2 px-4 tracking-wide">
      {/* SU CUENTA PRESENTA UN ADEUDO — LE SOLICITAMOS REALIZAR EL PAGO A LA BREVEDAD */}
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
