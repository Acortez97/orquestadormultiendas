import { useEffect, useState } from 'react';
import DataTable from '../../components/common/DataTable';
import { plataformaApi } from '../../services/api/endpoints';
import SubirLogo from '../../components/common/SubirLogo';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);

function Tarjeta({ titulo, valor, color = 'text-slate-900' }) {
  return (
    <div className="card p-5">
      <p className="text-xs font-semibold uppercase text-slate-400">{titulo}</p>
      <p className={`text-2xl font-bold ${color}`}>{valor}</p>
    </div>
  );
}

export default function AdminDashboard() {
  const [d, setD] = useState(null);
  const [logo, setLogo] = useState(null);
  useEffect(() => { plataformaApi.dashboard().then((r) => setD(r.data)); }, []);
  useEffect(() => { plataformaApi.logo().then((r) => setLogo(r.data?.logo || null)).catch(() => {}); }, []);
  const t = d?.totales || {};

  return (
    <div className="space-y-6">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Tablero general</h1>
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <Tarjeta titulo="Tiendas" valor={t.tiendas ?? '—'} />
        <Tarjeta titulo="Activas" valor={t.activas ?? '—'} color="text-emerald-600" />
        <Tarjeta titulo="Usuarios activos" valor={t.usuarios ?? '—'} />
        <Tarjeta titulo="Ventas hoy" valor={money(t.ventas_hoy)} color="text-primary-700" />
        <Tarjeta titulo="Ventas del mes" valor={money(t.ventas_mes)} color="text-primary-700" />
      </div>
      <section className="card p-5">
        <h2 className="text-lg font-bold">Logo de la plataforma (LEVOTEK)</h2>
        <p className="mb-4 text-sm text-slate-600">Sale en los tickets, cortes y reportes de todas las tiendas, junto al logo de cada negocio.</p>
        <SubirLogo src={logo}
          onSubir={async (dataUrl) => setLogo((await plataformaApi.subirLogo(dataUrl)).data?.logo || null)}
          onQuitar={async () => { await plataformaApi.quitarLogo(); setLogo(null); }} />
      </section>

      <DataTable
        loading={!d}
        data={d?.tiendas || []}
        exportName="Tablero" exportTitle="Ventas por tienda"
        columns={[
          { key: 'nombre', label: 'Tienda' },
          { key: 'slug', label: 'Subdominio' },
          { key: 'is_active', label: 'Estado', render: (r) => (r.is_active === 'Si' ? <span className="badge-success">Activa</span> : <span className="badge-danger">Suspendida</span>) },
          { key: 'usuarios', label: 'Usuarios' },
          { key: 'ultimo_acceso', label: 'Último acceso', render: (r) => r.ultimo_acceso || '—' },
          { key: 'ventas_hoy', label: 'Hoy', render: (r) => money(r.ventas_hoy) },
          { key: 'ventas_mes', label: 'Mes', render: (r) => money(r.ventas_mes) },
          { key: 'notas_mes', label: 'Notas del mes' },
        ]}
      />
    </div>
  );
}
