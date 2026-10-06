import { useState } from 'react';
import { Routes, Route, Navigate, NavLink, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import {
  Squares2X2Icon, BuildingStorefrontIcon, UsersIcon, ShieldCheckIcon,
  ArrowRightOnRectangleIcon, KeyIcon, Bars3Icon, XMarkIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import AdminDashboard from './AdminDashboard';
import AdminTiendas from './AdminTiendas';
import AdminUsuarios from './AdminUsuarios';
import AdminBitacora from './AdminBitacora';

// Panel de administracion general (solo superadmin). Este codigo se descarga
// unicamente cuando un superadmin entra a /admin (React.lazy en App.jsx).
const NAV = [
  { to: '/admin', label: 'Tablero', icon: Squares2X2Icon, end: true },
  { to: '/admin/tiendas', label: 'Tiendas', icon: BuildingStorefrontIcon },
  { to: '/admin/usuarios', label: 'Usuarios', icon: UsersIcon },
  { to: '/admin/bitacora', label: 'Bitácora', icon: ShieldCheckIcon },
];

export default function AdminApp() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [abierto, setAbierto] = useState(false);

  const nav = (
    <nav className="space-y-1 p-3">
      {NAV.map((n) => (
        <NavLink key={n.to} to={n.to} end={n.end} onClick={() => setAbierto(false)}
          className={({ isActive }) => clsx('flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
            isActive ? 'bg-amber-500 text-slate-950' : 'text-slate-300 hover:bg-white/10 hover:text-white')}>
          <n.icon className="h-5 w-5" />{n.label}
        </NavLink>
      ))}
    </nav>
  );

  return (
    <div className="min-h-screen bg-slate-100">
      <aside className={clsx('fixed inset-y-0 left-0 z-40 w-60 bg-slate-950 transition-transform lg:translate-x-0',
        abierto ? 'translate-x-0' : '-translate-x-full')}>
        <div className="flex h-16 items-center justify-between border-b border-white/10 px-4">
          <div>
            <p className="text-sm font-bold text-white">Administración</p>
            <p className="text-xs text-amber-400">Panel general</p>
          </div>
          <button className="text-slate-400 lg:hidden" onClick={() => setAbierto(false)}><XMarkIcon className="h-5 w-5" /></button>
        </div>
        {nav}
      </aside>
      {abierto && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={() => setAbierto(false)} />}

      <div className="lg:ml-60">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 lg:px-6">
          <button className="btn-ghost p-2 lg:hidden" onClick={() => setAbierto(true)}><Bars3Icon className="h-5 w-5" /></button>
          <div className="flex-1" />
          <div className="flex items-center gap-2">
            <span className="hidden text-right sm:block">
              <span className="block text-sm font-medium text-slate-900">{user?.nombre}</span>
              <span className="block text-xs text-slate-500">{user?.login}</span>
            </span>
            <button className="btn-ghost p-2" title="Cambiar contraseña" onClick={() => navigate('/cambiar-password')}><KeyIcon className="h-5 w-5" /></button>
            <button className="btn-ghost p-2 text-danger-600" title="Cerrar sesión" onClick={() => { logout(); navigate('/login'); }}>
              <ArrowRightOnRectangleIcon className="h-5 w-5" />
            </button>
          </div>
        </header>
        <main className="p-4 lg:p-6">
          <Routes>
            <Route index element={<AdminDashboard />} />
            <Route path="tiendas" element={<AdminTiendas />} />
            <Route path="usuarios" element={<AdminUsuarios />} />
            <Route path="bitacora" element={<AdminBitacora />} />
            <Route path="*" element={<Navigate to="/admin" replace />} />
          </Routes>
        </main>
      </div>
    </div>
  );
}
