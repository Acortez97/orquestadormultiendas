import { Routes, Route, Navigate, NavLink, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import {
  Squares2X2Icon, BuildingStorefrontIcon, UsersIcon, ShieldCheckIcon,
  ArrowRightOnRectangleIcon, KeyIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import AdminDashboard from './AdminDashboard';
import AdminTiendas from './AdminTiendas';
import AdminUsuarios from './AdminUsuarios';
import AdminBitacora from './AdminBitacora';

// Panel de administracion general (solo superadmin). Este codigo se descarga
// unicamente cuando un superadmin entra a /admin (React.lazy en App.jsx).
// Tono oscuro propio («plataforma») para que nunca se confunda con el panel de una tienda.
const NAV = [
  { to: '/admin', label: 'Tablero', icon: Squares2X2Icon, end: true },
  { to: '/admin/tiendas', label: 'Tiendas', icon: BuildingStorefrontIcon },
  { to: '/admin/usuarios', label: 'Usuarios', icon: UsersIcon },
  { to: '/admin/bitacora', label: 'Bitácora', icon: ShieldCheckIcon },
];

export default function AdminApp() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  return (
    <div className="min-h-screen bg-[#EEF0F5]">
      <header className="sticky top-0 z-30 bg-plataforma text-white pt-safe">
        <div className="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-2 lg:px-8">
          <div className="flex items-center gap-2.5">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white text-[13px] font-bold text-plataforma">LT</span>
            <span className="font-bold">Plataforma</span>
            <span className="hidden rounded-md border border-[#3A4566] px-2 py-0.5 font-mono text-[11px] text-[#B9C2DA] sm:inline">SUPERADMIN</span>
          </div>
          <nav aria-label="Panel de plataforma" className="order-3 -mx-1 flex w-full gap-1 overflow-x-auto sm:order-none sm:w-auto">
            {NAV.map((n) => (
              <NavLink key={n.to} to={n.to} end={n.end}
                className={({ isActive }) => clsx('flex min-h-10 shrink-0 items-center gap-2 rounded-[9px] px-3 text-sm font-semibold',
                  isActive ? 'bg-[#263152] text-white' : 'text-[#C9D0E3] hover:bg-white/10 hover:text-white')}>
                <n.icon className="h-5 w-5" />{n.label}
              </NavLink>
            ))}
          </nav>
          <div className="flex-1" />
          <div className="flex items-center gap-1">
            <span className="mr-2 hidden text-right text-sm text-[#C9D0E3] md:block">{user?.login}</span>
            <button type="button" className="flex h-10 w-10 items-center justify-center rounded-[9px] text-[#C9D0E3] hover:bg-white/10 hover:text-white"
              title="Cambiar contraseña" aria-label="Cambiar contraseña" onClick={() => navigate('/cambiar-password')}><KeyIcon className="h-5 w-5" /></button>
            <button type="button" className="flex h-10 w-10 items-center justify-center rounded-[9px] text-[#C9D0E3] hover:bg-white/10 hover:text-white"
              title="Cerrar sesión" aria-label="Cerrar sesión" onClick={() => { logout(); navigate('/login'); }}>
              <ArrowRightOnRectangleIcon className="h-5 w-5" />
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-7xl px-4 py-5 lg:px-8 lg:py-7">
        <Routes>
          <Route index element={<AdminDashboard />} />
          <Route path="tiendas" element={<AdminTiendas />} />
          <Route path="usuarios" element={<AdminUsuarios />} />
          <Route path="bitacora" element={<AdminBitacora />} />
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </main>
    </div>
  );
}
