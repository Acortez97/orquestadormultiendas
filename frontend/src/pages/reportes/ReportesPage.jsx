import { useState, useEffect, useCallback, useMemo } from 'react';
import { ChartBarIcon, XMarkIcon } from '@heroicons/react/24/outline';
import Swal from '../../utils/swal';
import { reportesApi, almacenesApi, empleadosApi, proveedoresApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import DataTable from '../../components/common/DataTable';
import BarChart from '../../components/common/BarChart';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import ProductosDashboardView from '../../components/common/ProductosDashboardView';
import { aFecha } from '../../utils/fechas';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const num = (n) => new Intl.NumberFormat('es-MX').format(n || 0);
const pct = (n) => `${num(n)}%`;
const fechaCorta = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');
const fechaHora = (d) => (d ? aFecha(d).toLocaleString('es-MX') : '');
const listaLabel = (k) => (String(k).toUpperCase() === 'OFERTA' ? 'Oferta' : `Lista ${k}`);

const ymd = (d) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const inicioMes = () => ymd(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const hoy = () => ymd(new Date());

// Definición de cada reporte: grupo, filtros visibles, si expone costos.
const REPORTES = {
  dashboardProductos: { grupo: 'Productos', label: 'Dashboard de productos', filtros: ['fechas', 'tienda', 'umbral', 'diasCaducar'] },
  ventas:        { grupo: 'Ventas',     label: 'Ventas',                filtros: ['fechas', 'tienda', 'vendedor', 'agruparVD'] },
  utilidad:      { grupo: 'Ventas',     label: 'Utilidad / margen',     filtros: ['fechas', 'tienda', 'vendedor'], costos: true },
  porLista:      { grupo: 'Ventas',     label: 'Por lista de precios',  filtros: ['fechas', 'tienda', 'vendedor'] },
  topProductos:  { grupo: 'Ventas',     label: 'Top productos',         filtros: ['fechas', 'tienda', 'vendedor', 'orden', 'limit'] },
  cortesPeriodo: { grupo: 'Finanzas',   label: 'Corte por periodo',     filtros: ['fechas', 'tienda', 'agruparPago'] },
  cxcAntiguedad: { grupo: 'Finanzas',   label: 'Cuentas x cobrar',      filtros: ['tienda'] },
  cxpProveedores:{ grupo: 'Finanzas',   label: 'Cuentas x pagar',       filtros: [] },
  comisiones:    { grupo: 'Finanzas',   label: 'Comisiones',            filtros: ['fechas', 'tienda', 'vendedor'] },
  existencias:   { grupo: 'Inventario', label: 'Existencias valorizadas', filtros: ['tienda'], costos: true },
  kardex:        { grupo: 'Inventario', label: 'Kardex de artículo',    filtros: ['articulo', 'tienda', 'fechas'] },
  compras:       { grupo: 'Inventario', label: 'Compras',               filtros: ['fechas', 'tienda', 'proveedor', 'estado'] },
  devoluciones:  { grupo: 'Inventario', label: 'Devoluciones y cambios', filtros: ['fechas', 'tienda'] },
};
const GRUPOS = ['Productos', 'Ventas', 'Finanzas', 'Inventario'];

export default function ReportesPage() {
  const { hasPermiso } = useAuth();
  const verCostos = hasPermiso('reportes.costos');

  const [reporte, setReporte] = useState('ventas');
  const [almacenes, setAlmacenes] = useState([]);
  const [empleados, setEmpleados] = useState([]);
  const [proveedores, setProveedores] = useState([]);
  const [articulo, setArticulo] = useState(null); // para kardex

  const [filtros, setFiltros] = useState({
    desde: inicioMes(), hasta: hoy(),
    id_almacen: '', id_vendedor: '', id_proveedor: '', estado: '',
    agruparVD: 'dia', agruparPago: 'dia', orden: 'cantidad', limit: 20,
    umbral_bajo: 5, dias_caducar: 30,
  });

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  // Clave de los filtros con los que se generó el resultado mostrado (para detectar cambios).
  const [generadoKey, setGeneradoKey] = useState(null);

  const def = REPORTES[reporte];
  const tiene = (f) => def.filtros.includes(f);

  // Identidad de la consulta actual: si cambia respecto a `generadoKey`, el resultado quedó viejo.
  const claveActual = useMemo(
    () => JSON.stringify({ reporte, ...filtros, art: articulo?._id || '' }),
    [reporte, filtros, articulo]
  );
  const desactualizado = data !== null && generadoKey !== null && claveActual !== generadoKey;

  useEffect(() => {
    (async () => {
      try {
        const [a, e, p] = await Promise.all([almacenesApi.listar(), empleadosApi.listar(), proveedoresApi.listar()]);
        setAlmacenes((a.data?.docs ?? a.data ?? []).filter((x) => x.tipo !== 'bodega'));
        setEmpleados((e.data?.docs ?? e.data ?? []).filter((x) => x.es_vendedor));
        setProveedores(p.data?.docs ?? p.data ?? []);
      } catch (_) { /* noop */ }
    })();
  }, []);

  const setF = (k, v) => setFiltros((f) => ({ ...f, [k]: v }));

  const cambiarReporte = (id) => {
    setReporte(id);
    setData(null);
    setGeneradoKey(null);
    setArticulo(null);
  };

  const generar = useCallback(async () => {
    if (reporte === 'kardex' && !articulo) {
      Swal.fire('Falta artículo', 'Selecciona un artículo para el kardex', 'warning');
      return;
    }
    setLoading(true);
    setData(null);
    try {
      const p = {};
      if (tiene('fechas')) { p.desde = filtros.desde || undefined; p.hasta = filtros.hasta || undefined; }
      if (tiene('tienda')) p.id_almacen = filtros.id_almacen || undefined;
      if (tiene('vendedor')) p.id_vendedor = filtros.id_vendedor || undefined;
      if (tiene('proveedor')) p.id_proveedor = filtros.id_proveedor || undefined;
      if (tiene('estado')) p.estado = filtros.estado || undefined;
      if (tiene('orden')) p.orden = filtros.orden;
      if (tiene('limit')) p.limit = filtros.limit;
      if (tiene('umbral')) p.umbral_bajo = filtros.umbral_bajo;
      if (tiene('diasCaducar')) p.dias_caducar = filtros.dias_caducar;
      if (tiene('agruparVD')) p.agrupar = filtros.agruparVD;
      if (tiene('agruparPago')) p.agrupar = filtros.agruparPago;
      if (reporte === 'kardex') p.id_articulo = articulo._id;

      const res = await reportesApi[reporte](p);
      if (res.success === false) throw new Error(res.message);
      setData(res.data);
      setGeneradoKey(claveActual);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo generar el reporte', 'error');
    } finally {
      setLoading(false);
    }
  }, [reporte, filtros, articulo]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center gap-2">
        <ChartBarIcon className="w-6 h-6 text-primary-600" />
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Reportes</h1>
      </div>

      {/* Selector + filtros */}
      <div className="card p-4 space-y-3">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Reporte">
            <select className="input-base min-w-56" value={reporte} onChange={(e) => cambiarReporte(e.target.value)}>
              {GRUPOS.map((g) => (
                <optgroup key={g} label={g}>
                  {Object.entries(REPORTES)
                    .filter(([, r]) => r.grupo === g && (!r.costos || verCostos))
                    .map(([id, r]) => <option key={id} value={id}>{r.label}</option>)}
                </optgroup>
              ))}
            </select>
          </Field>

          {tiene('articulo') && (
            <div className="flex-1 min-w-64">
              <label className="block text-sm font-medium text-slate-700 mb-1">Artículo</label>
              {articulo ? (
                <div className="flex items-center gap-2 input-base bg-slate-50">
                  <span className="text-sm text-slate-700"><b>{articulo.codigo}</b> — {articulo.descripcion}</span>
                  <button onClick={() => setArticulo(null)} className="ml-auto text-slate-400 hover:text-slate-600">
                    <XMarkIcon className="w-4 h-4" />
                  </button>
                </div>
              ) : (
                <ArticuloAutocomplete onSelect={setArticulo} />
              )}
            </div>
          )}

          {tiene('fechas') && (
            <>
              <Field label="Desde"><input type="date" className="input-base" value={filtros.desde} onChange={(e) => setF('desde', e.target.value)} /></Field>
              <Field label="Hasta"><input type="date" className="input-base" value={filtros.hasta} onChange={(e) => setF('hasta', e.target.value)} /></Field>
            </>
          )}
          {tiene('tienda') && (
            <Field label="Tienda">
              <select className="input-base" value={filtros.id_almacen} onChange={(e) => setF('id_almacen', e.target.value)}>
                <option value="">Todas</option>
                {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </Field>
          )}
          {tiene('vendedor') && (
            <Field label="Vendedor">
              <select className="input-base" value={filtros.id_vendedor} onChange={(e) => setF('id_vendedor', e.target.value)}>
                <option value="">Todos</option>
                {empleados.map((e) => <option key={e._id} value={e._id}>{e.nombre} {e.apellido || ''}</option>)}
              </select>
            </Field>
          )}
          {tiene('proveedor') && (
            <Field label="Proveedor">
              <select className="input-base" value={filtros.id_proveedor} onChange={(e) => setF('id_proveedor', e.target.value)}>
                <option value="">Todos</option>
                {proveedores.map((p) => <option key={p._id} value={p._id}>{p.nombre}</option>)}
              </select>
            </Field>
          )}
          {tiene('estado') && (
            <Field label="Estado">
              <select className="input-base" value={filtros.estado} onChange={(e) => setF('estado', e.target.value)}>
                <option value="">Todos</option>
                <option value="aprobada">Aprobada</option>
                <option value="por_aprobar">Por aprobar</option>
                <option value="borrador">Borrador</option>
              </select>
            </Field>
          )}
          {tiene('agruparVD') && (
            <Field label="Agrupar por">
              <select className="input-base" value={filtros.agruparVD} onChange={(e) => setF('agruparVD', e.target.value)}>
                <option value="dia">Día</option><option value="tienda">Tienda</option><option value="vendedor">Vendedor</option>
              </select>
            </Field>
          )}
          {tiene('agruparPago') && (
            <Field label="Agrupar por">
              <select className="input-base" value={filtros.agruparPago} onChange={(e) => setF('agruparPago', e.target.value)}>
                <option value="dia">Día</option><option value="tienda">Tienda</option>
              </select>
            </Field>
          )}
          {tiene('orden') && (
            <Field label="Ordenar por">
              <select className="input-base" value={filtros.orden} onChange={(e) => setF('orden', e.target.value)}>
                <option value="cantidad">Unidades</option><option value="importe">Importe</option>
              </select>
            </Field>
          )}
          {tiene('limit') && (
            <Field label="Top N"><input type="number" min={1} max={200} className="input-base w-24" value={filtros.limit} onChange={(e) => setF('limit', Number(e.target.value))} /></Field>
          )}
          {tiene('umbral') && (
            <Field label="Stock bajo ≤"><input type="number" min={0} className="input-base w-24" value={filtros.umbral_bajo} onChange={(e) => setF('umbral_bajo', Number(e.target.value))} /></Field>
          )}
          {tiene('diasCaducar') && (
            <Field label="Por caducar (días)"><input type="number" min={1} className="input-base w-28" value={filtros.dias_caducar} onChange={(e) => setF('dias_caducar', Number(e.target.value))} /></Field>
          )}

          <button
            onClick={generar}
            disabled={loading}
            className={`flex items-center gap-1.5 btn-primary ${desactualizado ? '!bg-amber-500 hover:!bg-amber-600 ring-2 ring-amber-300 animate-pulse' : ''}`}
          >
            <ChartBarIcon className="w-4 h-4" />
            {loading ? 'Generando…' : desactualizado ? 'Actualizar' : 'Generar'}
          </button>
        </div>
        {desactualizado && (
          <p className="mt-2 text-xs text-amber-600">Cambiaste los filtros — pulsa «Actualizar» para refrescar el resultado.</p>
        )}
      </div>

      {/* Resultados */}
      {data && <Resultado reporte={reporte} data={data} articulo={articulo} />}
    </div>
  );
}

function Resultado({ reporte, data, articulo }) {
  switch (reporte) {
    case 'dashboardProductos': return <ProductosDashboardView data={data} />;
    case 'ventas': return <ReporteVentas data={data} />;
    case 'utilidad': return <ReporteUtilidad data={data} />;
    case 'porLista': return <ReportePorLista data={data} />;
    case 'topProductos': return <ReporteTopProductos data={data} />;
    case 'cortesPeriodo': return <ReporteCortes data={data} />;
    case 'cxcAntiguedad': return <ReporteCxC data={data} />;
    case 'cxpProveedores': return <ReporteCxP data={data} />;
    case 'comisiones': return <ReporteComisiones data={data} />;
    case 'existencias': return <ReporteExistencias data={data} />;
    case 'kardex': return <ReporteKardex data={data} articulo={articulo} />;
    case 'compras': return <ReporteCompras data={data} />;
    case 'devoluciones': return <ReporteDevoluciones data={data} />;
    default: return null;
  }
}

// ---------- Ventas ----------
const DIM_LABEL = { dia: 'Día', tienda: 'Tienda', vendedor: 'Vendedor' };
// Para agrupación por día, la clave es YYYY-MM-DD → mostrar DD/MM.
const labelClave = (clave, agrupar) =>
  agrupar === 'dia' && /^\d{4}-\d{2}-\d{2}$/.test(String(clave)) ? `${clave.slice(8, 10)}/${clave.slice(5, 7)}` : clave;

function ReporteVentas({ data }) {
  const t = data.totales || {};
  const agrupar = data.agrupar || 'dia';
  const dim = DIM_LABEL[agrupar] || 'Día';
  const agrupado = data.agrupado || [];
  const columns = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fechaCorta(r.fecha), exportValue: (r) => fechaCorta(r.fecha) },
    { key: 'tienda', label: 'Tienda' },
    { key: 'cliente', label: 'Cliente' },
    { key: 'vendedor', label: 'Vendedor' },
    { key: 'prendas', label: 'Prendas', className: 'text-right', render: (r) => num(r.prendas) },
    { key: 'subtotal', label: 'Subtotal', className: 'text-right', render: (r) => money(r.subtotal), exportValue: (r) => r.subtotal },
    { key: 'iva', label: 'IVA', className: 'text-right', render: (r) => money(r.iva), exportValue: (r) => r.iva },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total), exportValue: (r) => r.total },
  ];
  const colGrupo = [
    { key: 'clave', label: dim, render: (r) => labelClave(r.clave, agrupar), exportValue: (r) => labelClave(r.clave, agrupar) },
    { key: 'num_notas', label: '# Notas', className: 'text-right', render: (r) => num(r.num_notas) },
    { key: 'prendas', label: 'Prendas', className: 'text-right', render: (r) => num(r.prendas) },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total), exportValue: (r) => r.total },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[
        ['Total vendido', money(t.total)], ['Devoluciones', money(t.devoluciones)], ['Cambios (dif.)', money(t.cambios)],
        ['Venta neta', money(t.neto ?? t.total), true], ['# Notas', num(t.num_notas)], ['Prendas', num(t.prendas)],
        ['Ticket promedio', money(t.ticket_promedio)], ['A crédito', money(t.credito)],
      ]} />

      <BarChart
        title={`Total vendido por ${dim.toLowerCase()}`}
        data={agrupado.map((g) => ({ label: labelClave(g.clave, agrupar), value: g.total }))}
        format={money}
      />

      <div>
        <h3 className="text-sm font-semibold text-slate-700 mb-2">Resumen por {dim.toLowerCase()}</h3>
        <DataTable columns={colGrupo} data={agrupado} exportName={`ventas_por_${agrupar}`} exportTitle={`Ventas por ${dim}`} empty="Sin datos" />
      </div>

      <div>
        <h3 className="text-sm font-semibold text-slate-700 mb-2">Detalle de notas</h3>
        <DataTable columns={columns} data={data.filas} exportName="reporte_ventas" exportTitle="Reporte de ventas" empty="Sin ventas en el periodo" />
      </div>
    </div>
  );
}

// ---------- Utilidad ----------
function ReporteUtilidad({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'cantidad', label: 'Unidades', className: 'text-right', render: (r) => num(r.cantidad) },
    { key: 'ingreso', label: 'Ingreso (s/IVA)', className: 'text-right', render: (r) => money(r.ingreso), exportValue: (r) => r.ingreso },
    { key: 'costo', label: 'Costo', className: 'text-right', render: (r) => money(r.costo), exportValue: (r) => r.costo },
    { key: 'utilidad', label: 'Utilidad', className: 'text-right font-medium', render: (r) => money(r.utilidad), exportValue: (r) => r.utilidad },
    { key: 'margen', label: 'Margen', className: 'text-right', render: (r) => pct(r.margen), exportValue: (r) => r.margen },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Ingreso (s/IVA)', money(t.ingreso)], ['Costo', money(t.costo)], ['Utilidad', money(t.utilidad), true], ['Margen', pct(t.margen)]]} />
      <BarChart title="Utilidad por artículo" color="bg-emerald-500"
        data={data.filas.slice(0, 12).map((f) => ({ label: f.codigo, value: f.utilidad }))} format={money} />
      <p className="text-xs text-slate-500">El ingreso excluye IVA. Las ventas previas al costo histórico usan el costo actual del artículo.</p>
      <DataTable columns={columns} data={data.filas} exportName="reporte_utilidad" exportTitle="Reporte de utilidad" empty="Sin datos en el periodo" />
    </div>
  );
}

// ---------- Por lista ----------
function ReportePorLista({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'lista', label: 'Lista', render: (r) => listaLabel(r.lista), exportValue: (r) => listaLabel(r.lista) },
    { key: 'prendas', label: 'Prendas', className: 'text-right', render: (r) => num(r.prendas) },
    { key: 'num_lineas', label: 'Líneas', className: 'text-right', render: (r) => num(r.num_lineas) },
    { key: 'importe', label: 'Importe', className: 'text-right font-medium', render: (r) => money(r.importe), exportValue: (r) => r.importe },
    { key: 'porcentaje', label: '% del total', className: 'text-right', render: (r) => pct(r.porcentaje), exportValue: (r) => r.porcentaje },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Importe total', money(t.importe), true], ['Prendas', num(t.prendas)]]} />
      <BarChart title="Importe por lista de precios"
        data={data.filas.map((f) => ({ label: listaLabel(f.lista), value: f.importe }))} format={money} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_por_lista" exportTitle="Ventas por lista de precios" empty="Sin ventas en el periodo" />
    </div>
  );
}

// ---------- Top productos ----------
function ReporteTopProductos({ data }) {
  const columns = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'cantidad', label: 'Unidades', className: 'text-right', render: (r) => num(r.cantidad) },
    { key: 'importe', label: 'Importe', className: 'text-right font-medium', render: (r) => money(r.importe), exportValue: (r) => r.importe },
    { key: 'num_ventas', label: '# Notas', className: 'text-right', render: (r) => num(r.num_ventas) },
  ];
  return (
    <div className="space-y-4">
      <BarChart title="Unidades vendidas por artículo" color="bg-indigo-500"
        data={data.filas.slice(0, 12).map((f) => ({ label: f.codigo, value: f.cantidad }))} format={num} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_top_productos" exportTitle="Top productos" empty="Sin ventas en el periodo" />
    </div>
  );
}

// ---------- Corte por periodo ----------
function ReporteCortes({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'clave', label: data.agrupar === 'tienda' ? 'Tienda' : 'Día' },
    { key: 'num_notas', label: '# Notas', className: 'text-right', render: (r) => num(r.num_notas) },
    { key: 'efectivo', label: 'Efectivo', className: 'text-right', render: (r) => money(r.efectivo), exportValue: (r) => r.efectivo },
    { key: 'cambio_efectivo', label: 'Cambio', className: 'text-right text-rose-600', render: (r) => money(r.cambio_efectivo), exportValue: (r) => r.cambio_efectivo },
    { key: 'efectivo_neto', label: 'Efectivo neto', className: 'text-right font-medium', render: (r) => money(r.efectivo_neto), exportValue: (r) => r.efectivo_neto },
    { key: 'tdc', label: 'T. Crédito', className: 'text-right', render: (r) => money(r.tdc), exportValue: (r) => r.tdc },
    { key: 'tdb', label: 'T. Débito', className: 'text-right', render: (r) => money(r.tdb), exportValue: (r) => r.tdb },
    { key: 'transferencia', label: 'Transfer.', className: 'text-right', render: (r) => money(r.transferencia), exportValue: (r) => r.transferencia },
    { key: 'cheque', label: 'Cheque', className: 'text-right', render: (r) => money(r.cheque), exportValue: (r) => r.cheque },
    { key: 'monedero', label: 'Monedero', className: 'text-right', render: (r) => money(r.monedero), exportValue: (r) => r.monedero },
    { key: 'anticipo', label: 'Anticipos previos', className: 'text-right', render: (r) => money(r.anticipo), exportValue: (r) => r.anticipo },
    { key: 'credito', label: 'Crédito', className: 'text-right', render: (r) => money(r.credito), exportValue: (r) => r.credito },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total), exportValue: (r) => r.total },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[
        ['Total vendido', money(t.total), true], ['Efectivo neto', money(t.efectivo_neto ?? t.efectivo)], ['Cambio devuelto', money(t.cambio_efectivo)],
        ['Tarjetas', money((t.tdc || 0) + (t.tdb || 0))], ['Transfer. y cheques', money((t.transferencia || 0) + (t.cheque || 0))],
        ['Anticipos previos', money(t.anticipo)], ['Crédito', money(t.credito)],
      ]} />
      <BarChart title={`Total por ${data.agrupar === 'tienda' ? 'tienda' : 'día'}`}
        data={(data.filas || []).map((r) => ({ label: labelClave(r.clave, data.agrupar), value: r.total }))} format={money} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_corte_periodo" exportTitle="Corte por periodo" empty="Sin ventas en el periodo" />
    </div>
  );
}

// ---------- CxC ----------
function ReporteCxC({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'cliente', label: 'Cliente' }, { key: 'tienda', label: 'Tienda' },
    { key: 'saldo', label: 'Saldo', className: 'text-right font-medium', render: (r) => money(r.saldo), exportValue: (r) => r.saldo },
    { key: 'antiguedad_dias', label: 'Antigüedad', className: 'text-right', render: (r) => `${num(r.antiguedad_dias)} d` },
    { key: 'plazo_dias', label: 'Plazo', className: 'text-right', render: (r) => `${num(r.plazo_dias)} d` },
    { key: 'vencido', label: 'Estado', render: (r) => (r.vencido ? <span className="badge-warning">Vencido</span> : <span className="badge-primary">Vigente</span>), exportValue: (r) => (r.vencido ? 'Vencido' : 'Vigente') },
    { key: 'ultimo_cargo', label: 'Últ. cargo', render: (r) => fechaCorta(r.ultimo_cargo), exportValue: (r) => fechaCorta(r.ultimo_cargo) },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[
        ['Saldo total', money(t.saldo), true], ['0-30 días', money(t.d0_30)], ['31-60', money(t.d31_60)],
        ['61-90', money(t.d61_90)], ['90+', money(t.d90_mas)],
      ]} />
      <BarChart title="Saldo por antigüedad" color="bg-rose-500"
        data={[
          { label: '0-30 d', value: t.d0_30 }, { label: '31-60 d', value: t.d31_60 },
          { label: '61-90 d', value: t.d61_90 }, { label: '90+ d', value: t.d90_mas },
        ]} format={money} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_cxc" exportTitle="Cuentas por cobrar — antigüedad" empty="Sin saldos por cobrar" />
    </div>
  );
}

// ---------- CxP ----------
function ReporteCxP({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'proveedor', label: 'Proveedor' }, { key: 'tipo', label: 'Tipo' },
    { key: 'saldo_mxn', label: 'Saldo MXN', className: 'text-right font-medium', render: (r) => money(r.saldo_mxn), exportValue: (r) => r.saldo_mxn },
    { key: 'saldo_usd', label: 'Saldo USD', className: 'text-right', render: (r) => num(r.saldo_usd), exportValue: (r) => r.saldo_usd },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Total MXN', money(t.mxn), true], ['Total USD', num(t.usd)]]} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_cxp" exportTitle="Cuentas por pagar" empty="Sin saldos por pagar" />
    </div>
  );
}

// ---------- Comisiones ----------
function ReporteComisiones({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'vendedor', label: 'Vendedor' },
    { key: 'num_ventas', label: '# Ventas', className: 'text-right', render: (r) => num(r.num_ventas) },
    { key: 'base', label: 'Base', className: 'text-right', render: (r) => money(r.base), exportValue: (r) => r.base },
    { key: 'importe', label: 'Comisión', className: 'text-right font-medium', render: (r) => money(r.importe), exportValue: (r) => r.importe },
    { key: 'pagado', label: 'Pagado', className: 'text-right', render: (r) => money(r.pagado), exportValue: (r) => r.pagado },
    { key: 'pendiente', label: 'Pendiente', className: 'text-right', render: (r) => money(r.pendiente), exportValue: (r) => r.pendiente },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Comisión total', money(t.importe), true], ['Pagado', money(t.pagado)], ['Pendiente', money(t.pendiente)], ['# Ventas', num(t.num_ventas)]]} />
      <BarChart title="Comisión por vendedor" color="bg-amber-500"
        data={data.filas.map((f) => ({ label: f.vendedor, value: f.importe }))} format={money} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_comisiones" exportTitle="Comisiones por vendedor" empty="Sin comisiones en el periodo" />
    </div>
  );
}

// ---------- Existencias valorizadas ----------
function ReporteExistencias({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'cantidad', label: 'Existencia', className: 'text-right', render: (r) => num(r.cantidad) },
    { key: 'reservado', label: 'Reservado', className: 'text-right', render: (r) => num(r.reservado) },
    { key: 'disponible', label: 'Disponible', className: 'text-right', render: (r) => num(r.disponible) },
    { key: 'costo', label: 'Costo', className: 'text-right', render: (r) => money(r.costo), exportValue: (r) => r.costo },
    { key: 'valor', label: 'Valor', className: 'text-right font-medium', render: (r) => money(r.valor), exportValue: (r) => r.valor },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Valor del inventario', money(t.valor), true], ['Artículos', num(t.articulos)], ['Unidades', num(t.unidades)]]} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_existencias" exportTitle="Existencias valorizadas" empty="Sin existencias" />
    </div>
  );
}

// ---------- Kardex ----------
function ReporteKardex({ data, articulo }) {
  const t = data.totales || {};
  const columns = [
    { key: 'fecha', label: 'Fecha', render: (r) => fechaHora(r.fecha), exportValue: (r) => fechaHora(r.fecha) },
    { key: 'tipo', label: 'Tipo' }, { key: 'folio', label: 'Folio' }, { key: 'almacen', label: 'Almacén' },
    { key: 'color', label: 'Color' }, { key: 'talla', label: 'Talla' },
    { key: 'cantidad', label: 'Movim.', className: 'text-right', render: (r) => num(r.cantidad) },
    { key: 'saldo_resultante', label: 'Saldo', className: 'text-right font-medium', render: (r) => num(r.saldo_resultante) },
    { key: 'motivo', label: 'Motivo' },
  ];
  return (
    <div className="space-y-4">
      {articulo && <p className="text-sm text-slate-600"><b>{articulo.codigo}</b> — {articulo.descripcion}</p>}
      <Cards items={[['Movimientos', num(t.movimientos), true], ['Entradas', num(t.entradas)], ['Salidas', num(t.salidas)], ['Neto', num(t.neto)]]} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_kardex" exportTitle="Kardex de artículo" empty="Sin movimientos en el periodo" />
    </div>
  );
}

// ---------- Compras ----------
function ReporteCompras({ data }) {
  const t = data.totales || {};
  const columns = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fechaCorta(r.fecha), exportValue: (r) => fechaCorta(r.fecha) },
    { key: 'proveedor', label: 'Proveedor' }, { key: 'almacen', label: 'Destino' },
    { key: 'piezas', label: 'Piezas', className: 'text-right', render: (r) => num(r.piezas) },
    { key: 'estado', label: 'Estado' },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total), exportValue: (r) => r.total },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[['Total comprado', money(t.total), true], ['Aprobadas', money(t.aprobadas)], ['Por aprobar', money(t.por_aprobar)],
        ['Canceladas (no suman)', money(t.canceladas)], ['# Compras', num(t.num_compras)], ['Piezas', num(t.piezas)]]} />
      <DataTable columns={columns} data={data.filas} exportName="reporte_compras" exportTitle="Compras por periodo" empty="Sin compras en el periodo" />
    </div>
  );
}

// ---------- Devoluciones ----------
function ReporteDevoluciones({ data }) {
  const t = data.totales || {};
  const colDev = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fechaCorta(r.fecha), exportValue: (r) => fechaCorta(r.fecha) },
    { key: 'folio_venta', label: 'Venta origen' }, { key: 'tienda', label: 'Tienda' },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total), exportValue: (r) => r.total },
    { key: 'destino_saldo', label: 'Destino', render: (r) => (r.destino_saldo === 'cxc' ? 'CxC' : 'Monedero') },
  ];
  const colCam = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fechaCorta(r.fecha), exportValue: (r) => fechaCorta(r.fecha) },
    { key: 'folio_venta', label: 'Venta origen' }, { key: 'tienda', label: 'Tienda' },
    { key: 'total_devuelto', label: 'Devuelto', className: 'text-right', render: (r) => money(r.total_devuelto), exportValue: (r) => r.total_devuelto },
    { key: 'total_nuevo', label: 'Nuevo', className: 'text-right', render: (r) => money(r.total_nuevo), exportValue: (r) => r.total_nuevo },
    { key: 'diferencia', label: 'Diferencia', className: 'text-right font-medium', render: (r) => money(r.diferencia), exportValue: (r) => r.diferencia },
  ];
  return (
    <div className="space-y-4">
      <Cards items={[
        ['Devoluciones', money(t.total_devoluciones), true], ['# Devoluciones', num(t.num_devoluciones)],
        ['Tasa devolución', pct(t.tasa_devolucion)], ['# Cambios', num(t.num_cambios)],
      ]} />
      <div>
        <h3 className="text-sm font-semibold text-slate-700 mb-2">Devoluciones</h3>
        <DataTable columns={colDev} data={data.devoluciones} exportName="reporte_devoluciones" exportTitle="Devoluciones" empty="Sin devoluciones" />
      </div>
      <div>
        <h3 className="text-sm font-semibold text-slate-700 mb-2">Cambios</h3>
        <DataTable columns={colCam} data={data.cambios} exportName="reporte_cambios" exportTitle="Cambios" empty="Sin cambios" />
      </div>
    </div>
  );
}

// ---------- UI helpers ----------
function Field({ label, children }) {
  return (
    <div>
      <label className="block text-sm font-medium text-slate-700 mb-1">{label}</label>
      {children}
    </div>
  );
}

function Cards({ items }) {
  return (
    <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
      {items.map(([label, value, highlight], i) => (
        <div key={i} className={`card p-4 ${highlight ? 'ring-2 ring-primary-200' : ''}`}>
          <p className="text-xs text-slate-500 uppercase tracking-wide">{label}</p>
          <p className="text-xl font-bold text-slate-800 mt-1">{value}</p>
        </div>
      ))}
    </div>
  );
}
