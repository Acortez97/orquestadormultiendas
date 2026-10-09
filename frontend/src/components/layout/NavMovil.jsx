import { NavLink, Link, useLocation } from 'react-router-dom';
import PropTypes from 'prop-types';
import clsx from 'clsx';
import { Menu as Bars3Icon, X as XMarkIcon, Search as MagnifyingGlassIcon, LogOut as ArrowRightOnRectangleIcon, KeyRound as KeyIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { usePendientes } from '../../contexts/PendientesContext';
import { gruposVisibles, principales, PREFERENCIA_RIEL, PREFERENCIA_BARRA } from './navConfig';
import { LogoTienda } from './Marca';
import { Contador } from './Sidebar';

const punto = (p) => (p?.n
  ? <span aria-hidden="true" className={clsx('absolute right-3 top-1.5 h-2 w-2 rounded-full', p.tono === 'rojo' ? 'bg-danger-600' : 'bg-warning-600')} />
  : null);

/** Riel de iconos de tablet (640 a 1023 px) */
export function Riel({ onMas }) {
  const { hasPermiso } = useAuth();
  const pendientes = usePendientes();
  const items = principales(hasPermiso, PREFERENCIA_RIEL, 6);
  return (
    <aside className="sticky top-0 z-30 hidden h-dvh w-20 shrink-0 flex-col items-center gap-1 border-r border-slate-200 bg-white py-3 sm:flex lg:hidden">
      <Link to="/dashboard" className="mb-2" aria-label="Inicio"><LogoTienda size={40} /></Link>
      <nav aria-label="Menú principal" className="flex flex-col items-center gap-1">
        {items.map((it) => {
          const Icon = it.icon;
          return (
            <NavLink key={it.path} to={it.path}
              className={({ isActive }) => clsx('relative flex w-16 min-h-14 flex-col items-center justify-center gap-0.5 rounded-xl text-[11px] font-semibold',
                isActive ? 'bg-primary-600 text-white' : 'text-slate-700 hover:bg-slate-100')}>
              <Icon className="h-[22px] w-[22px]" strokeWidth={1.8} />
              <span className="max-w-full truncate px-1">{it.corto || it.label}</span>
              {punto(pendientes[it.path])}
            </NavLink>
          );
        })}
      </nav>
      <div className="flex-1" />
      <button type="button" onClick={onMas}
        className="flex w-16 min-h-14 flex-col items-center justify-center gap-0.5 rounded-xl text-[11px] font-semibold text-slate-700 hover:bg-slate-100">
        <Bars3Icon className="h-[22px] w-[22px]" />Más
      </button>
    </aside>
  );
}
Riel.propTypes = { onMas: PropTypes.func.isRequired };

/** Barra inferior de celular (< 640 px): 3 pantallas principales + «Más» */
export function BarraInferior({ onMas, masAbierto }) {
  const { hasPermiso } = useAuth();
  const pendientes = usePendientes();
  const items = principales(hasPermiso, PREFERENCIA_BARRA, 3);
  const algunPendiente = Object.keys(pendientes).some((k) => !items.find((it) => it.path === k));
  return (
    <nav aria-label="Navegación principal"
      className="fixed inset-x-0 bottom-0 z-40 grid sm:hidden border-t border-slate-200 bg-white px-2 pt-1.5 pb-safe"
      style={{ gridTemplateColumns: `repeat(${items.length + 1}, minmax(0, 1fr))` }}>
      {items.map((it) => {
        const Icon = it.icon;
        return (
          <NavLink key={it.path} to={it.path}
            className={({ isActive }) => clsx('relative flex min-h-[52px] flex-col items-center justify-center gap-0.5 text-xs',
              isActive && !masAbierto ? 'font-bold text-primary-600' : 'font-semibold text-slate-700')}>
            <Icon className="h-[22px] w-[22px]" strokeWidth={1.8} />
            {it.corto || it.label}
            {punto(pendientes[it.path])}
          </NavLink>
        );
      })}
      <button type="button" onClick={onMas} aria-expanded={masAbierto}
        className={clsx('relative flex min-h-[52px] flex-col items-center justify-center gap-0.5 text-xs', masAbierto ? 'font-bold text-primary-600' : 'font-semibold text-slate-700')}>
        <Bars3Icon className="h-[22px] w-[22px]" />Más
        {algunPendiente && <span aria-hidden="true" className="absolute right-[30%] top-1.5 h-2 w-2 rounded-full bg-warning-600" />}
      </button>
    </nav>
  );
}
BarraInferior.propTypes = { onMas: PropTypes.func.isRequired, masAbierto: PropTypes.bool };

/** «Más»: el menu completo, agrupado y con buscador (celular y tablet) */
export function MenuMas({ abierto, onCerrar, onBuscar }) {
  const { hasPermiso, user, logout } = useAuth();
  const pendientes = usePendientes();
  const { pathname } = useLocation();
  const [q, setQ] = useState('');
  const grupos = useMemo(() => gruposVisibles(hasPermiso), [hasPermiso]);

  useEffect(() => { if (abierto) onCerrar(); /* se cierra al navegar */ // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);
  useEffect(() => { if (!abierto) setQ(''); }, [abierto]);
  useEffect(() => {
    if (!abierto) return undefined;
    const esc = (e) => { if (e.key === 'Escape') onCerrar(); };
    document.addEventListener('keydown', esc);
    return () => document.removeEventListener('keydown', esc);
  }, [abierto, onCerrar]);

  if (!abierto) return null;
  const filtro = q.trim().toLowerCase();
  const visibles = grupos
    .map((g) => ({ ...g, items: g.items.filter((it) => !filtro || it.label.toLowerCase().includes(filtro)) }))
    .filter((g) => g.items.length);

  return (
    <div role="dialog" aria-modal="true" aria-label="Menú completo"
      className="fixed inset-0 z-50 flex flex-col bg-slate-50 animate-fade-in lg:hidden sm:left-20">
      <div className="bg-white pt-safe">
        <div className="flex items-center gap-2 px-4 pt-3 pb-2">
          <h2 className="flex-1 text-[22px] font-bold tracking-tight">Más</h2>
          {onBuscar && (
            <button type="button" onClick={() => { onCerrar(); onBuscar(); }} className="btn-ghost px-3" aria-label="Buscar en todo">
              <MagnifyingGlassIcon className="h-5 w-5" />
            </button>
          )}
          <button type="button" onClick={onCerrar} className="btn-secondary w-11 px-0" aria-label="Cerrar menú">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>
        <div className="border-b border-slate-200 px-4 pb-3">
          <label className="flex min-h-11 items-center gap-2 rounded-[10px] border border-slate-300 bg-slate-50 px-3">
            <MagnifyingGlassIcon className="h-[18px] w-[18px] text-slate-500" />
            <span className="sr-only">Buscar pantalla</span>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Busca una pantalla"
              className="min-w-0 flex-1 bg-transparent text-[15px] outline-none" />
          </label>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto px-4 py-4 space-y-5">
        {visibles.map((g) => (
          <section key={g.titulo} className="space-y-2">
            <h3 className="text-[11px] font-bold uppercase tracking-[0.06em] text-slate-600">{g.titulo}</h3>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
              {g.items.map((it) => {
                const Icon = it.icon;
                const p = pendientes[it.path];
                return (
                  <Link key={it.path} to={it.path}
                    className="flex min-h-[76px] flex-col justify-between gap-1 rounded-xl border border-slate-200 bg-white p-2.5 text-[13px] font-semibold text-slate-900 active:bg-slate-100">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Icon className="h-[18px] w-[18px]" strokeWidth={1.8} /></span>
                    <span className="leading-tight">{it.label}</span>
                    {p?.n ? <span className={clsx('text-xs font-semibold', p.tono === 'rojo' ? 'text-danger-600' : 'text-warning-700')}>{p.texto}</span> : null}
                  </Link>
                );
              })}
            </div>
          </section>
        ))}
        {!visibles.length && <p className="py-8 text-center text-slate-600">Ninguna pantalla coincide con «{q}».</p>}
      </div>

      <div className="flex items-center gap-2 border-t border-slate-200 bg-white px-4 pt-3 pb-safe">
        <div className="min-w-0 flex-1 pb-3">
          <p className="truncate font-semibold">{user?.nombre}</p>
          <p className="truncate text-xs text-slate-600">{user?.login}</p>
        </div>
        <div className="flex gap-2 pb-3">
          {!user?.soporte && <Link to="/cambiar-password" className="btn-secondary w-11 px-0" aria-label="Cambiar contraseña"><KeyIcon className="h-5 w-5" /></Link>}
          <button type="button" onClick={logout} className="btn-secondary"><ArrowRightOnRectangleIcon className="h-5 w-5" />Salir</button>
        </div>
      </div>
    </div>
  );
}
MenuMas.propTypes = { abierto: PropTypes.bool, onCerrar: PropTypes.func.isRequired, onBuscar: PropTypes.func };

export { Contador };
