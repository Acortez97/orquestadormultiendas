import PropTypes from 'prop-types';
import DataTable from './DataTable';
import BarChart from './BarChart';
import { aFecha } from '../../utils/fechas';

/**
 * Vista (presentacional) del Dashboard de productos: KPIs, gráfica de más vendidos y
 * tablas de más/menos vendidos, media, sin ventas, stock bajo/agotado y caducidad.
 * Recibe `data` (respuesta de /reportes/dashboard-productos) ya cargada.
 * Se reutiliza en la página fija (Dashboard de productos) y en Reportes.
 */

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const num = (n) => new Intl.NumberFormat('es-MX').format(n || 0);
const fechaCorta = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');

export default function ProductosDashboardView({ data }) {
  if (!data) return null;
  const k = data.kpis || {};
  const cad = data.caducidad || {};

  const colVend = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'cantidad', label: 'Unidades', className: 'text-right font-medium', render: (r) => num(r.cantidad) },
    { key: 'importe', label: 'Importe', className: 'text-right', render: (r) => money(r.importe), exportValue: (r) => r.importe },
  ];
  const colStock = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'stock', label: 'Existencia', className: 'text-right font-medium', render: (r) => num(r.stock) },
  ];
  const colCad = [
    { key: 'codigo', label: 'Código' }, { key: 'descripcion', label: 'Descripción' },
    { key: 'fecha', label: 'Caducidad', render: (r) => fechaCorta(r.fecha), exportValue: (r) => r.fecha },
    { key: 'dias', label: 'Días', className: 'text-right',
      render: (r) => (r.dias < 0
        ? <span className="text-rose-600 font-medium">vencido {Math.abs(r.dias)} d</span>
        : <span className="text-amber-600 font-medium">en {r.dias} d</span>),
      exportValue: (r) => r.dias },
  ];

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
        <Kpi label="Unidades vendidas" value={num(k.unidades_vendidas)} highlight />
        <Kpi label="Importe vendido" value={money(k.importe_vendido)} />
        <Kpi label="Valor inventario" value={money(k.valor_inventario)} />
        <Kpi label="Con ventas" value={num(k.con_ventas)} />
        <Kpi label="Sin ventas" value={num(k.sin_ventas)} />
        <Kpi label="Media (u/producto)" value={num(k.promedio_unidades)} />
        <Kpi label="Agotados" value={num(k.agotados)} tone={k.agotados > 0 ? 'rose' : ''} />
        <Kpi label="Stock bajo" value={num(k.bajos)} tone={k.bajos > 0 ? 'amber' : ''} />
        <Kpi label="Caducados" value={num(k.caducados)} tone={k.caducados > 0 ? 'rose' : ''} />
        <Kpi label="Por caducar" value={num(k.por_caducar)} tone={k.por_caducar > 0 ? 'amber' : ''} />
      </div>

      <BarChart title="Más vendidos (unidades)" color="bg-indigo-500"
        data={(data.top || []).map((f) => ({ label: f.codigo, value: f.cantidad }))} format={num} />

      <div className="grid lg:grid-cols-2 gap-4">
        <Bloque titulo="🔝 Más vendidos">
          <DataTable columns={colVend} data={data.top} exportName="mas_vendidos" exportTitle="Más vendidos" empty="Sin ventas en el periodo" />
        </Bloque>
        <Bloque titulo="🐌 Menos vendidos (con ventas)">
          <DataTable columns={colVend} data={data.bottom} exportName="menos_vendidos" exportTitle="Menos vendidos" empty="Sin ventas en el periodo" />
        </Bloque>
        <Bloque titulo={`📊 En la media (≈ ${num(k.promedio_unidades)} u)`}>
          <DataTable columns={colVend} data={data.media} exportName="en_la_media" exportTitle="En la media" empty="Sin productos en la media" />
        </Bloque>
        <Bloque titulo="💤 Sin ventas (stock parado)">
          <DataTable columns={colStock} data={data.sin_ventas} exportName="sin_ventas" exportTitle="Sin ventas" empty="Todos tuvieron ventas" />
        </Bloque>
        <Bloque titulo={`⚠️ Stock bajo (≤ ${num(data.params?.umbral_bajo)})`}>
          <DataTable columns={colStock} data={data.bajos} exportName="stock_bajo" exportTitle="Stock bajo" empty="Sin productos en stock bajo" />
        </Bloque>
        <Bloque titulo="⛔ Agotados">
          <DataTable columns={colStock} data={data.agotados} exportName="agotados" exportTitle="Agotados" empty="Ninguno agotado" />
        </Bloque>
        <Bloque titulo="🔴 Caducados">
          <DataTable columns={colCad} data={cad.caducados} exportName="caducados" exportTitle="Caducados" empty="Ninguno caducado" />
        </Bloque>
        <Bloque titulo={`🟠 Por caducar (≤ ${num(data.params?.dias_caducar)} días)`}>
          <DataTable columns={colCad} data={cad.por_caducar} exportName="por_caducar" exportTitle="Por caducar" empty="Ninguno por caducar" />
        </Bloque>
      </div>

      {!cad.hay_campo && (
        <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
          Para ver caducidad, agrega en la categoría un campo de <b>ficha</b> tipo fecha cuyo nombre contenga
          «caducidad», «vence» o «expira», y captúralo en el artículo.
        </p>
      )}
    </div>
  );
}

ProductosDashboardView.propTypes = { data: PropTypes.object };

const TONE = { rose: 'ring-2 ring-rose-200', amber: 'ring-2 ring-amber-200', '': '' };
function Kpi({ label, value, highlight, tone = '' }) {
  return (
    <div className={`card p-4 ${highlight ? 'ring-2 ring-primary-200' : TONE[tone]}`}>
      <p className="text-xs text-slate-500 uppercase tracking-wide">{label}</p>
      <p className="text-xl font-bold text-slate-800 mt-1">{value}</p>
    </div>
  );
}
Kpi.propTypes = { label: PropTypes.string, value: PropTypes.node, highlight: PropTypes.bool, tone: PropTypes.string };

function Bloque({ titulo, children }) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-slate-700 mb-2">{titulo}</h3>
      {children}
    </div>
  );
}
Bloque.propTypes = { titulo: PropTypes.string, children: PropTypes.node };
