import { useState, useEffect } from 'react';
import { Outlet } from 'react-router-dom';
import axios from 'axios';
import Sidebar from './Sidebar';
import Header from './Header';
import { useOffline } from '../../contexts/OfflineContext';
import OfflineBanner from '../common/OfflineBanner';

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

function AppLayout() {
  const [sidebarOpen, setSidebarOpen]           = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const { isOnline } = useOffline();

  return (
    <div className="min-h-screen bg-[#f2f5fc]">
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
