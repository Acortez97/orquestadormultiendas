import { NavLink, Link } from 'react-router-dom';
import PropTypes from 'prop-types';
import clsx from 'clsx';
import { PanelLeftClose, PanelLeftOpen, ScanBarcode } from 'lucide-react';
import { useAuth } from '../../contexts/AuthContext';
import { usePendientes } from '../../contexts/PendientesContext';
import { INICIO, gruposVisibles } from './navConfig';
import Marca from './Marca';

/** Contador de pendientes junto a una opcion del menu (rojo = urgente, ambar = por revisar) */
export function Contador({ info, className, sobreAcento }) {
  if (!info?.n) return null;
  return (
    <span className={clsx('min-w-5 rounded-full px-1.5 text-center text-[11px] font-bold leading-5',
      sobreAcento ? 'bg-white/25 text-white'
        : info.tono === 'rojo' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-700', className)}>
      {info.n > 99 ? '99+' : info.n}
    </span>
  );
}
Contador.propTypes = { info: PropTypes.object, className: PropTypes.string, sobreAcento: PropTypes.bool };

/**
 * Menu lateral de computadora (>= 1024 px): agrupado y plegable a solo iconos.
 * Es «sticky» (no fijo): empieza debajo de los avisos de soporte / pago, nunca queda tapado.
 */
function Sidebar({ plegado, onPlegar }) {
  const { hasPermiso } = useAuth();
  const pendientes = usePendientes();
  const grupos = gruposVisibles(hasPermiso);
  const puedeVender = hasPermiso('ventas.crear');

  return (
    <aside className={clsx('sticky top-0 z-30 hidden h-dvh shrink-0 flex-col border-r border-slate-200 bg-white transition-[width] duration-200 lg:flex',
      plegado ? 'w-[76px]' : 'w-[260px]')}>
      <div className={clsx('flex h-16 shrink-0 items-center', plegado ? 'justify-center px-2' : 'px-4')}>
        <Marca compacta={plegado} />
      </div>

      {puedeVender && (
        <div className={clsx('pb-2', plegado ? 'px-3' : 'px-4')}>
          <Link to="/pos" title="Abrir punto de venta"
            className={clsx('btn-primary min-h-12 w-full rounded-xl text-[15px]', plegado && 'px-0')}>
            <ScanBarcode className="h-5 w-5 shrink-0" strokeWidth={2} />
            {!plegado && 'Vender'}
            {!plegado && <kbd className="ml-auto rounded bg-white/20 px-1.5 font-mono text-[11px] font-medium">POS</kbd>}
          </Link>
        </div>
      )}

      <nav aria-label="Menú principal" className="flex-1 overflow-y-auto px-3 pb-3">
        <Opcion item={INICIO} plegado={plegado} />
        {grupos.map((g) => (
          <div key={g.titulo} className="mt-4">
            {plegado
              ? <div className="mx-2 mb-2 border-t border-slate-200" />
              : (
                <p className="mb-1 flex items-center gap-2 px-3 text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500">
                  {g.titulo}<span className="h-px flex-1 bg-slate-200" />
                </p>
              )}
            {g.items.filter((it) => it.path !== '/pos').map((it) => (
              <Opcion key={it.path} item={it} plegado={plegado} pendiente={pendientes[it.path]} />
            ))}
          </div>
        ))}
      </nav>

      <button type="button" onClick={onPlegar}
        className="flex h-12 shrink-0 items-center justify-center gap-2 border-t border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50"
        aria-label={plegado ? 'Expandir menú' : 'Plegar menú'}>
        {plegado ? <PanelLeftOpen className="h-5 w-5" /> : <><PanelLeftClose className="h-5 w-5" /> Plegar menú</>}
      </button>
    </aside>
  );
}

function Opcion({ item, plegado, pendiente }) {
  const Icon = item.icon;
  return (
    <NavLink to={item.path} title={plegado ? item.label : undefined}
      className={({ isActive }) => clsx(
        'group relative my-0.5 flex min-h-10 items-center gap-3 rounded-xl text-[15px] transition-colors',
        plegado ? 'justify-center px-2' : 'pl-2 pr-3',
        isActive ? 'bg-primary-600 font-semibold text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900')}>
      {({ isActive }) => (
        <>
          <span className={clsx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
            isActive ? 'bg-white/15' : 'bg-slate-100 text-slate-600 group-hover:bg-white')}>
            <Icon className="h-[18px] w-[18px]" strokeWidth={isActive ? 2.2 : 1.8} />
          </span>
          {!plegado && <span className="flex-1 truncate">{item.label}</span>}
          {plegado
            ? (pendiente?.n ? <span className={clsx('absolute right-1.5 top-1.5 h-2 w-2 rounded-full ring-2 ring-white', pendiente.tono === 'rojo' ? 'bg-danger-600' : 'bg-warning-600')} /> : null)
            : <Contador info={pendiente} sobreAcento={isActive} />}
        </>
      )}
    </NavLink>
  );
}
Opcion.propTypes = { item: PropTypes.object.isRequired, plegado: PropTypes.bool, pendiente: PropTypes.object };

Sidebar.propTypes = {
  plegado: PropTypes.bool.isRequired,
  onPlegar: PropTypes.func.isRequired,
};

export default Sidebar;
