import { useEffect, useState, useCallback } from 'react';
import { Squares2X2Icon, ArrowPathIcon } from '@heroicons/react/24/outline';
import Swal from '../../utils/swal';
import { reportesApi, almacenesApi } from '../../services/api/endpoints';
import ProductosDashboardView from '../../components/common/ProductosDashboardView';

const ymd = (d) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const hoy = () => ymd(new Date());
const hace = (dias) => { const d = new Date(); d.setDate(d.getDate() - dias); return ymd(d); };

/**
 * Página fija del Dashboard de productos: carga sola al abrir (últimos 30 días) y se
 * refresca al cambiar los filtros. Mismos indicadores que en Reportes, pero siempre visible.
 */
export default function ProductosDashboardPage() {
  const [almacenes, setAlmacenes] = useState([]);
  const [filtros, setFiltros] = useState({ desde: hace(30), hasta: hoy(), id_almacen: '', umbral_bajo: 5, dias_caducar: 30 });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    almacenesApi.listar().then((r) => setAlmacenes((r.data?.docs ?? r.data ?? []).filter((x) => x.tipo !== 'bodega'))).catch(() => {});
  }, []);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const p = {
        desde: filtros.desde || undefined, hasta: filtros.hasta || undefined,
        id_almacen: filtros.id_almacen || undefined,
        umbral_bajo: filtros.umbral_bajo, dias_caducar: filtros.dias_caducar,
      };
      const res = await reportesApi.dashboardProductos(p);
      if (res.success === false) throw new Error(res.message);
      setData(res.data);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo cargar el dashboard', 'error');
    } finally {
      setLoading(false);
    }
  }, [filtros]);

  useEffect(() => { cargar(); }, [cargar]);

  const setF = (k, v) => setFiltros((f) => ({ ...f, [k]: v }));

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center gap-2">
        <Squares2X2Icon className="w-6 h-6 text-primary-600" />
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Análisis de productos</h1>
        {loading && <ArrowPathIcon className="w-5 h-5 text-slate-400 animate-spin ml-1" />}
      </div>

      <div className="card p-4">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Desde"><input type="date" className="input-base" value={filtros.desde} onChange={(e) => setF('desde', e.target.value)} /></Field>
          <Field label="Hasta"><input type="date" className="input-base" value={filtros.hasta} onChange={(e) => setF('hasta', e.target.value)} /></Field>
          <Field label="Tienda">
            <select className="input-base" value={filtros.id_almacen} onChange={(e) => setF('id_almacen', e.target.value)}>
              <option value="">Todas</option>
              {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
            </select>
          </Field>
          <Field label="Stock bajo ≤"><input type="number" min={0} className="input-base w-24" value={filtros.umbral_bajo} onChange={(e) => setF('umbral_bajo', Number(e.target.value))} /></Field>
          <Field label="Por caducar (días)"><input type="number" min={1} className="input-base w-28" value={filtros.dias_caducar} onChange={(e) => setF('dias_caducar', Number(e.target.value))} /></Field>
          <button onClick={cargar} disabled={loading} className="btn-secondary flex items-center gap-1.5">
            <ArrowPathIcon className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Actualizar
          </button>
        </div>
      </div>

      {data ? <ProductosDashboardView data={data} /> : (
        <div className="card p-10 text-center text-slate-400">{loading ? 'Cargando…' : 'Sin datos'}</div>
      )}
    </div>
  );
}

function Field({ label, children }) {
  return (
    <div>
      <label className="block text-sm font-medium text-slate-700 mb-1">{label}</label>
      {children}
    </div>
  );
}
