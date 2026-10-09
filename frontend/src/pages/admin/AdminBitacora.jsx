import { useEffect, useState } from 'react';
import DataTable from '../../components/common/DataTable';
import { plataformaApi } from '../../services/api/endpoints';

// Bitacora global: acciones de plataforma (sin tienda) y de todas las tiendas.
export default function AdminBitacora() {
  const [tiendas, setTiendas] = useState([]);
  const [filtro, setFiltro] = useState({ id_tienda: '', solo_plataforma: '', desde: '', hasta: '' });
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => { plataformaApi.tiendas().then((r) => setTiendas(r.data || [])); }, []);
  useEffect(() => {
    setLoading(true);
    const p = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v));
    plataformaApi.auditLog(p).then((r) => setRows(r.data || [])).finally(() => setLoading(false));
  }, [filtro]);

  return (
    <div className="space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Bitácora general</h1>
      <div className="flex flex-wrap gap-2">
        <select className="input-base w-56" value={filtro.solo_plataforma ? 'plataforma' : filtro.id_tienda}
          onChange={(e) => setFiltro({ ...filtro, id_tienda: e.target.value === 'plataforma' ? '' : e.target.value, solo_plataforma: e.target.value === 'plataforma' ? '1' : '' })}>
          <option value="">Todo</option>
          <option value="plataforma">Solo administración general</option>
          {tiendas.map((t) => <option key={t._id} value={t._id}>{t.nombre}</option>)}
        </select>
        <input type="date" className="input-base w-44" value={filtro.desde} onChange={(e) => setFiltro({ ...filtro, desde: e.target.value })} />
        <input type="date" className="input-base w-44" value={filtro.hasta} onChange={(e) => setFiltro({ ...filtro, hasta: e.target.value })} />
      </div>
      <DataTable loading={loading} data={rows} exportName="Bitacora" exportTitle="Bitácora general" columns={[
        { key: 'fecha', label: 'Fecha' },
        { key: 'tienda', label: 'Tienda', exportValue: (r) => r.tienda?.nombre || 'Administración', render: (r) => r.tienda?.nombre || <span className="badge-accent">Administración</span> },
        { key: 'usuario', label: 'Usuario' },
        { key: 'accion', label: 'Acción' },
        { key: 'descripcion', label: 'Detalle' },
        { key: 'ip', label: 'IP' },
      ]} />
    </div>
  );
}
