import { useState, useEffect, useCallback } from 'react';
import Swal from '../../utils/swal';
import { ArrowPathIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import { auditApi } from '../../services/api/endpoints';
import { aFecha } from '../../utils/fechas';

const fecha = (d) =>
  d
    ? aFecha(d).toLocaleString('es-MX', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : '—';

const OPERACION_BADGE = {
  crear: 'badge-success',
  actualizar: 'badge-warning',
  eliminar: 'badge-danger',
  login: 'badge-primary',
  leer: 'badge-slate',
  otro: 'badge-slate',
};

export default function BitacoraPage() {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filtros, setFiltros] = useState({ modulo: '', operacion: '', fecha_desde: '', fecha_hasta: '' });

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtros.modulo) params.modulo = filtros.modulo;
      if (filtros.operacion) params.operacion = filtros.operacion;
      if (filtros.fecha_desde) params.fecha_desde = filtros.fecha_desde;
      if (filtros.fecha_hasta) params.fecha_hasta = filtros.fecha_hasta;
      const res = await auditApi.listar(params);
      const docs = res?.data?.docs ?? res?.data ?? [];
      setData(Array.isArray(docs) ? docs : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo cargar la bitácora', 'error');
    } finally {
      setLoading(false);
    }
  }, [filtros]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const columns = [
    { key: 'createdAt', label: 'Fecha', render: (r) => fecha(r.createdAt) },
    { key: 'nombre_usuario', label: 'Usuario', render: (r) => r.nombre_usuario || 'Sistema' },
    {
      key: 'operacion',
      label: 'Operación',
      render: (r) => <span className={OPERACION_BADGE[r.operacion] || 'badge-slate'}>{r.operacion}</span>,
    },
    { key: 'modulo', label: 'Módulo' },
    { key: 'descripcion', label: 'Descripción', render: (r) => r.descripcion || '—' },
    {
      key: 'ruta',
      label: 'Ruta',
      render: (r) => (
        <span className="text-xs text-slate-500">
          {r.metodo_http ? `${r.metodo_http} ` : ''}
          {r.ruta || '—'}
        </span>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Bitácora</h1>

      <div className="card p-4">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Módulo</label>
            <input
              className="input-base"
              placeholder="Buscar módulo…"
              value={filtros.modulo}
              onChange={(e) => setFiltros({ ...filtros, modulo: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Operación</label>
            <select
              className="input-base"
              value={filtros.operacion}
              onChange={(e) => setFiltros({ ...filtros, operacion: e.target.value })}
            >
              <option value="">Todas</option>
              <option value="crear">Crear</option>
              <option value="actualizar">Actualizar</option>
              <option value="eliminar">Eliminar</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Desde</label>
            <input
              type="date"
              className="input-base"
              value={filtros.fecha_desde}
              onChange={(e) => setFiltros({ ...filtros, fecha_desde: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Hasta</label>
            <input
              type="date"
              className="input-base"
              value={filtros.fecha_hasta}
              onChange={(e) => setFiltros({ ...filtros, fecha_hasta: e.target.value })}
            />
          </div>
          <button className="btn-secondary flex items-center justify-center gap-2" onClick={cargar}>
            <ArrowPathIcon className="w-5 h-5" /> Refrescar
          </button>
        </div>
      </div>

      <DataTable columns={columns} data={data} loading={loading} empty="Sin registros en bitácora" exportName="bitacora" exportTitle="Bitácora" />
    </div>
  );
}
