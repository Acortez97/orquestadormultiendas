import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import PropTypes from 'prop-types';
import {
  Bars3Icon, BellIcon, UserCircleIcon,
  ArrowRightOnRectangleIcon, ChevronDownIcon, KeyIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import { useSocket } from '../../contexts/SocketContext';
import clsx from 'clsx';
import moment from 'moment';
import 'moment/locale/es';

moment.locale('es');

function Header({ onMenuClick }) {
  const { user, logout } = useAuth();
  const { notifications, noLeidas, marcarLeida, limpiarTodas } = useSocket();
  const navigate = useNavigate();

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
    <header className="sticky top-0 z-30 h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-6">
      {/* Botón de menú (móvil) */}
      <button onClick={onMenuClick} className="btn-ghost p-2 lg:hidden">
        <Bars3Icon className="w-5 h-5" />
      </button>
      <div className="flex-1" />

      <div className="flex items-center gap-2">
        {/* Notificaciones */}
        <div ref={notifRef} className="relative">
          <button
            onClick={() => { setShowNotif(!showNotif); setShowUser(false); }}
            className="btn-ghost p-2 relative"
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
        <div ref={userRef} className="relative">
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
    </header>
  );
}

Header.propTypes = {
  onMenuClick: PropTypes.func.isRequired,
};

export default Header;
