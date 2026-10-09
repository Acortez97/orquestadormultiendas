import { useState, useRef, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import PropTypes from 'prop-types';
import {
  Search as MagnifyingGlassIcon, Bell as BellIcon, CircleUserRound as UserCircleIcon,
  LogOut as ArrowRightOnRectangleIcon, ChevronDown as ChevronDownIcon, KeyRound as KeyIcon, ChevronRight,
} from 'lucide-react';
import Marca from './Marca';
import { pantallaDe } from './navConfig';
import { useAuth } from '../../contexts/AuthContext';
import { useSocket } from '../../contexts/SocketContext';
import clsx from 'clsx';
import moment from 'moment';
import 'moment/locale/es';

moment.locale('es');

/** Barra superior: marca (celular), buscador universal, avisos y usuario */
function Header({ onBuscar }) {
  const { user, logout } = useAuth();
  const { notifications, noLeidas, marcarLeida, limpiarTodas } = useSocket();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const pantalla = pantallaDe(pathname);

  const [showNotif, setShowNotif]   = useState(false);
  const [showUser, setShowUser]     = useState(false);
  const notifRef = useRef(null);
  const userRef  = useRef(null);

  // Cerrar dropdowns al hacer click fuera
  useEffect(() => {
    function handle(e) {
      if (notifRef.current && !notifRef.current.contains(e.target)) setShowNotif(false);
      if (userRef.current  && !userRef.current.contains(e.target))  setShowUser(false);
    }
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, []);

  function handleLogout() {
    logout();
    navigate('/login');
  }

  return (
    <header className="sticky top-0 z-30 bg-white border-b border-slate-200 pt-safe">
      <div className="h-16 flex items-center gap-3 px-4 lg:px-6">
      {/* Celular: la marca de la tienda (el menu esta en la barra inferior) */}
      <div className="min-w-0 flex-1 sm:hidden"><Marca /></div>
      {/* Tablet y computadora: donde estoy (seccion › pantalla) */}
      {pantalla && (
        <div className="hidden min-w-0 items-center gap-2 sm:flex">
          {(() => { const Icon = pantalla.icon; return (
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white"><Icon className="h-[18px] w-[18px]" strokeWidth={2} /></span>
          ); })()}
          <div className="min-w-0 leading-tight">
            {pantalla.grupo && <p className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500">{pantalla.grupo}<ChevronRight className="h-3 w-3" /></p>}
            <p className="truncate text-[15px] font-bold text-slate-900">{pantalla.label}</p>
          </div>
        </div>
      )}
      <div className="hidden flex-1 sm:block" />
      {/* buscador universal */}
      <button type="button" onClick={onBuscar}
        className="hidden min-h-11 w-full max-w-xs items-center gap-2.5 rounded-xl border border-slate-300 bg-slate-50 px-3 text-left text-[15px] text-slate-500 hover:border-slate-400 sm:flex">
        <MagnifyingGlassIcon className="h-[18px] w-[18px]" />
        <span className="flex-1 truncate">Ir a…</span>
        <kbd className="rounded-md border border-slate-300 bg-white px-1.5 font-mono text-xs text-slate-600">Ctrl K</kbd>
      </button>
      <button type="button" onClick={onBuscar} className="btn-secondary w-11 px-0 sm:hidden" aria-label="Buscar">
        <MagnifyingGlassIcon className="h-5 w-5" />
      </button>

      <div className="flex items-center gap-2">
        {/* Notificaciones */}
        <div ref={notifRef} className="relative">
          <button
            onClick={() => { setShowNotif(!showNotif); setShowUser(false); }}
            className="btn-ghost w-11 px-0 relative" aria-label="Notificaciones"
          >
            <BellIcon className="w-5 h-5" />
            {noLeidas > 0 && (
              <span className="absolute top-1 right-1 w-4 h-4 bg-danger-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                {noLeidas > 9 ? '9+' : noLeidas}
              </span>
            )}
          </button>

          {showNotif && (
            <div className="absolute right-0 mt-2 w-80 bg-white rounded-xl border border-slate-200 shadow-lg overflow-hidden z-50">
              <div className="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                <span className="font-semibold text-slate-900 text-sm">Notificaciones</span>
                {notifications.length > 0 && (
                  <button onClick={limpiarTodas} className="text-xs text-primary-600 hover:underline">
                    Limpiar todo
                  </button>
                )}
              </div>
              <div className="max-h-80 overflow-y-auto divide-y divide-slate-100">
                {notifications.length === 0 ? (
                  <p className="text-sm text-slate-400 text-center py-8">Sin notificaciones</p>
                ) : notifications.map((n) => (
                  <button
                    key={n.id}
                    onClick={() => marcarLeida(n.id)}
                    className={clsx(
                      'w-full text-left px-4 py-3 hover:bg-slate-50 transition-colors',
                      !n.leida && 'bg-primary-50'
                    )}
                  >
                    <p className="text-sm text-slate-800 font-medium">{n.mensaje}</p>
                    <p className="text-xs text-slate-400 mt-0.5">{moment(n.timestamp).fromNow()}</p>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Usuario */}
        <div ref={userRef} className="relative hidden sm:block">
          <button
            onClick={() => { setShowUser(!showUser); setShowNotif(false); }}
            className="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-slate-100 transition-colors"
          >
            <UserCircleIcon className="w-7 h-7 text-slate-400" />
            <div className="hidden sm:block text-left">
              <p className="text-sm font-medium text-slate-900 leading-none">{user?.nombre}</p>
              <p className="text-xs text-slate-500 mt-0.5">{user?.rol === 'admin_tienda' ? 'Administrador' : 'Usuario'}{user?.tienda?.nombre ? ` · ${user.tienda.nombre}` : ''}</p>
            </div>
            <ChevronDownIcon className="w-4 h-4 text-slate-400" />
          </button>

          {showUser && (
            <div className="absolute right-0 mt-2 w-48 bg-white rounded-xl border border-slate-200 shadow-lg overflow-hidden z-50">
              <div className="px-4 py-3 border-b border-slate-100">
                <p className="text-sm font-semibold text-slate-900">{user?.nombre}</p>
                <p className="text-xs text-slate-500 truncate">{user?.login}</p>
              </div>
              {!user?.soporte && (
                <button
                  onClick={() => { setShowUser(false); navigate('/cambiar-password'); }}
                  className="w-full flex items-center gap-2 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 transition-colors"
                >
                  <KeyIcon className="w-4 h-4" />
                  Cambiar contraseña
                </button>
              )}
              <button
                onClick={handleLogout}
                className="w-full flex items-center gap-2 px-4 py-3 text-sm text-danger-600 hover:bg-danger-50 transition-colors"
              >
                <ArrowRightOnRectangleIcon className="w-4 h-4" />
                Cerrar sesión
              </button>
            </div>
          )}
        </div>
      </div>
      </div>
    </header>
  );
}

Header.propTypes = {
  onBuscar: PropTypes.func.isRequired,
};

export default Header;
