import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BuildingStorefrontIcon, ChevronRightIcon, CheckCircleIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import { tiendaApi } from '../../services/api/endpoints';
import { pantallasVisibles } from '../../components/layout/navConfig';
import { aFecha } from '../../utils/fechas';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const hora = (d) => aFecha(d).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
const hoyLargo = () => {
  const s = new Date().toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
  return s.charAt(0).toUpperCase() + s.slice(1);
};

/** Variacion contra el mismo dia de la semana pasada */
function comparacion(hoy, antes) {
  if (!antes) return hoy > 0 ? 'Sin ventas el mismo día de la semana pasada' : null;
  const pct = Math.round(((hoy - antes) / antes) * 100);
  const dia = new Date(Date.now() - 7 * 86400000).toLocaleDateString('es-MX', { weekday: 'long' });
  return `${pct >= 0 ? '+' : ''}${pct}% vs. el ${dia} pasado`;
}

function Kpi({ titulo, valor, nota, oscuro }) {
  return (
    <div className={clsx('flex flex-col gap-1 rounded-2xl p-4 sm:p-5', oscuro ? 'bg-slate-900 text-white' : 'card')}>
      <span className={clsx('text-[13px]', oscuro ? 'text-slate-300' : 'text-slate-600')}>{titulo}</span>
      <span className={clsx('font-bold tracking-tight', oscuro ? 'text-3xl' : 'text-2xl')}>{valor}</span>
      {nota && <span className={clsx('text-[13px]', oscuro ? 'text-[#9BE2BC]' : 'text-slate-600')}>{nota}</span>}
    </div>
  );
}

export default function DashboardPage() {
  const { user, hasPermiso } = useAuth();
  const [d, setD] = useState(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    tiendaApi.hoy().then((r) => setD(r.data)).catch(() => setError(true));
  }, []);

  const pendientes = Object.entries(d?.pendientes || {});
  const v = d?.ventas;
  // horas con actividad (o el horario comercial tipico) para la grafica
  const horas = (() => {
    const ph = d?.por_hora || [];
    const conVenta = ph.map((x, i) => (x > 0 ? i : -1)).filter((i) => i >= 0);
    const desde = Math.min(9, ...(conVenta.length ? conVenta : [9]));
    const hasta = Math.max(20, ...(conVenta.length ? conVenta : [20]));
    return Array.from({ length: hasta - desde + 1 }, (_, k) => ({ h: desde + k, t: ph[desde + k] || 0 }));
  })();
  const maxHora = Math.max(1, ...horas.map((x) => x.t));
  const accesos = pantallasVisibles(hasPermiso).filter((p) => p.path !== '/dashboard').slice(0, 8);

  return (
    <div className="mx-auto max-w-6xl space-y-5 sm:space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-sm text-slate-600">{hoyLargo()}</p>
          <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">Hoy en {user?.tienda?.nombre ? 'tu tienda' : 'la tienda'}</h1>
        </div>
        <div className="flex flex-wrap gap-2">
          {hasPermiso('cortes.ver') && <Link to="/cortes" className="btn-secondary">Ver corte de hoy</Link>}
          {hasPermiso('ventas.crear') && (
            <Link to="/pos" className="btn-primary hidden sm:inline-flex"><BuildingStorefrontIcon className="h-5 w-5" />Abrir punto de venta</Link>
          )}
        </div>
      </div>

      {error && <div className="card p-4 text-danger-600">No se pudo cargar el resumen del día. Revisa tu conexión.</div>}

      {/* Indicadores del dia */}
      {!d && !error && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <div key={i} className="skeleton h-28 rounded-2xl" />)}</div>
      )}
      {d && (v || d.efectivo_caja !== undefined || d.por_cobrar) && (
        <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {v && (
            <div className="col-span-2 lg:col-span-1">
              <Kpi oscuro titulo="Vendido hoy" valor={money(v.total)}
                nota={[`${v.notas} ${v.notas === 1 ? 'nota' : 'notas'}`, comparacion(v.total, v.total_semana_pasada)].filter(Boolean).join(' · ')} />
            </div>
          )}
          {d.efectivo_caja !== undefined && <Kpi titulo="Efectivo en caja" valor={money(d.efectivo_caja)} nota="Según el libro de caja" />}
          {v && <Kpi titulo="Ticket promedio" valor={money(v.ticket_promedio)} nota={v.notas ? `${v.piezas_por_nota} piezas por nota` : 'Aún sin ventas'} />}
          {d.por_cobrar && <Kpi titulo="Por cobrar (crédito)" valor={money(d.por_cobrar.total)} nota={`${d.por_cobrar.clientes} ${d.por_cobrar.clientes === 1 ? 'cliente' : 'clientes'}`} />}
        </section>
      )}

      <section className="grid gap-4 lg:grid-cols-5">
        {/* Ventas por hora */}
        {d?.por_hora && (
          <div className="card flex flex-col gap-4 p-4 sm:p-5 lg:col-span-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="text-lg font-bold">Ventas por hora</h2>
              <span className="text-[13px] text-slate-600">Total vendido por hora, hoy</span>
            </div>
            <div className="flex h-44 items-end gap-1.5 border-b border-slate-200 sm:gap-2" role="img"
              aria-label={`Ventas por hora: ${horas.filter((x) => x.t).map((x) => `${x.h}:00 ${money(x.t)}`).join(', ') || 'sin ventas aún'}`}>
              {horas.map((x) => (
                <div key={x.h} className="flex h-full flex-1 flex-col justify-end" title={`${x.h}:00 · ${money(x.t)}`}>
                  <div className={clsx('rounded-t-md', x.t ? 'bg-primary-600' : 'bg-slate-200')}
                    style={{ height: `${Math.max(2, Math.round((x.t / maxHora) * 100))}%` }} />
                </div>
              ))}
            </div>
            <div className="flex gap-1.5 font-mono text-[11px] text-slate-600 sm:gap-2">
              {horas.map((x) => <span key={x.h} className="flex-1 text-center">{x.h % 2 === 0 || horas.length <= 12 ? x.h : ''}</span>)}
            </div>
          </div>
        )}

        {/* Pendientes */}
        <div className={clsx('card flex flex-col gap-3 p-4 sm:p-5', d?.por_hora ? 'lg:col-span-2' : 'lg:col-span-5')}>
          <h2 className="text-lg font-bold">Pendientes</h2>
          {!d && <div className="skeleton h-24" />}
          {d && !pendientes.length && (
            <p className="flex items-center gap-2 rounded-xl bg-success-50 p-3 text-success-700"><CheckCircleIcon className="h-5 w-5" />Todo al día.</p>
          )}
          {pendientes.map(([ruta, p]) => (
            <Link key={ruta} to={ruta}
              className={clsx('flex min-h-14 items-center gap-3 rounded-xl px-3 py-2', p.tono === 'rojo' ? 'bg-danger-100' : 'bg-warning-100')}>
              <span aria-hidden="true" className={clsx('h-2 w-2 shrink-0 rounded-full', p.tono === 'rojo' ? 'bg-danger-600' : 'bg-warning-600')} />
              <span className="flex-1 font-semibold">{p.texto}</span>
              <ChevronRightIcon className={clsx('h-5 w-5', p.tono === 'rojo' ? 'text-danger-600' : 'text-warning-700')} />
            </Link>
          ))}
        </div>
      </section>

      {/* Ultimas ventas */}
      {d?.ultimas && (
        <section className="card overflow-hidden">
          <div className="flex items-center justify-between px-4 py-3.5 sm:px-5">
            <h2 className="text-lg font-bold">Últimas ventas de hoy</h2>
            <Link to="/ventas" className="font-semibold text-primary-600">Ver todas</Link>
          </div>
          {!d.ultimas.length && <p className="border-t border-slate-200 px-5 py-8 text-center text-slate-600">Aún no hay ventas hoy.</p>}
          <ul className="divide-y divide-slate-100 border-t border-slate-200">
            {d.ultimas.map((x) => (
              <li key={x._id} className="flex items-center gap-3 px-4 py-3 sm:px-5">
                <span className="folio w-20 shrink-0 sm:w-24">{x.folio}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate">{x.cliente}</span>
                  <span className="block truncate text-[13px] text-slate-600">{hora(x.fecha)}{x.vendedor ? ` · ${x.vendedor}` : ''}</span>
                </span>
                {x.estado === 'cancelada' && <span className="badge-slate">Cancelada</span>}
                <span className={clsx('font-bold', x.estado === 'cancelada' && 'text-slate-500 line-through')}>{money(x.total)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* Sin acceso a ventas: accesos a lo que si puede usar */}
      {d && !v && (
        <section className="space-y-2">
          <h2 className="text-lg font-bold">Accesos</h2>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {accesos.map((a) => {
              const Icon = a.icon;
              return (
                <Link key={a.path} to={a.path} className="card flex min-h-20 flex-col justify-between gap-2 p-3 font-semibold hover:border-slate-300">
                  <Icon className="h-5 w-5 text-slate-600" />{a.label}
                </Link>
              );
            })}
          </div>
        </section>
      )}
    </div>
  );
}
