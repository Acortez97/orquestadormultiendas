import { useState, useEffect } from 'react';
import { EyeIcon, PrinterIcon } from '@heroicons/react/24/outline';
import Swal from '../../utils/swal';
import { cortesApi, almacenesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { printCorte } from '../../utils/corte';
import { hoyLocal, aFecha } from '../../utils/fechas';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');

const FORMA_LABEL = {
  efectivo: 'Efectivo',
  tdc: 'T. Crédito',
  tdb: 'T. Débito',
  transferencia: 'Transferencia',
  cheque: 'Cheque',
  monedero: 'Monedero',
  anticipo: 'Anticipo aplicado',
};

function Tabla({ titulo, columnas, filas, vacio }) {
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">{titulo}</div>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>{columnas.map((c) => <th key={c.l} className={`px-4 py-2 text-xs font-semibold text-slate-500 uppercase ${c.r ? 'text-right' : 'text-left'}`}>{c.l}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {filas.length === 0 ? (
              <tr><td colSpan={columnas.length} className="px-4 py-6 text-center text-slate-400">{vacio}</td></tr>
            ) : filas.map((f, i) => (
              <tr key={i}>{columnas.map((c) => <td key={c.l} className={`px-4 py-2 text-slate-700 ${c.r ? 'text-right' : ''}`}>{c.v(f)}</td>)}</tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}


const listaLabel = (k) => (String(k).toUpperCase() === 'OFERTA' ? 'Oferta' : `Lista ${k}`);
// Ordena claves de lista: 1..5 numéricas y luego OFERTA / texto al final.
const ordenLista = (a, b) => {
  const na = Number(a);
  const nb = Number(b);
  if (Number.isNaN(na) && Number.isNaN(nb)) return String(a).localeCompare(String(b));
  if (Number.isNaN(na)) return 1;
  if (Number.isNaN(nb)) return -1;
  return na - nb;
};

const hoy = hoyLocal;   // dia LOCAL (toISOString daria el dia siguiente despues de las 18:00)

export default function CortesPage() {
  const [almacenes, setAlmacenes] = useState([]);
  const [idAlmacen, setIdAlmacen] = useState('');
  const [dia, setDia] = useState(hoy());

  const [preview, setPreview] = useState(null);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const { hasPermiso } = useAuth();
  const [cerrado, setCerrado] = useState(null);           // corte ya cerrado de ese dia (si existe)
  const [historial, setHistorial] = useState([]);
  const [cierre, setCierre] = useState({ fondo: '', contado: '', notas: '' });

  async function cargarHistorial(alm) {
    if (!alm) return;
    try { setHistorial((await cortesApi.listar({ id_almacen: alm })).data || []); } catch (_) { /* noop */ }
  }
  useEffect(() => { cargarHistorial(idAlmacen); }, [idAlmacen]);

  useEffect(() => {
    (async () => {
      try {
        const a = await almacenesApi.listar();
        // Solo tiendas: la bodega no vende al público, no tiene corte de caja.
        const list = (a.data?.docs ?? a.data ?? []).filter((x) => x.tipo !== 'bodega');
        setAlmacenes(list);
        if (list[0]) setIdAlmacen(list[0]._id);
      } catch (_) {
        /* noop */
      }
    })();
  }, []);

  async function previsualizar() {
    if (!idAlmacen || !dia) {
      Swal.fire('Faltan datos', 'Selecciona tienda y fecha', 'warning');
      return;
    }
    setLoadingPreview(true);
    setPreview(null);
    try {
      const res = await cortesApi.preview({ id_almacen: idAlmacen, fecha: dia });
      if (res.success === false) throw new Error(res.message);
      // si el dia ya se cerro, se muestra el corte cerrado (snapshot) en lugar del calculo
      const ya = historial.find((c) => c.fecha === dia) || null;
      setCerrado(ya);
      setPreview(ya || res.data);
      setCierre({ fondo: '', contado: '', notas: '' });
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo calcular el corte', 'error');
    } finally {
      setLoadingPreview(false);
    }
  }

  function imprimirPdf() {
    if (!preview) return;
    const tienda = almacenes.find((a) => a._id === idAlmacen)?.nombre || '';
    printCorte(preview, { tienda, fecha: dia });
  }

  const resumen = preview?.resumen;
  const detalle = preview?.detalle_notas || [];
  const devoluciones = preview?.devoluciones || [];
  const cambios = preview?.cambios || [];
  const anticipos = preview?.anticipos || [];
  const porLista = resumen?.vendido_por_lista || {};
  const clavesLista = Object.keys(porLista).sort(ordenLista);

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Corte de caja</h1>
      </div>

      <div className="card p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Tienda</label>
            <select className="input-base" value={idAlmacen} onChange={(e) => setIdAlmacen(e.target.value)}>
              <option value="">Selecciona…</option>
              {almacenes.map((a) => (
                <option key={a._id} value={a._id}>
                  {a.nombre}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Fecha</label>
            <input type="date" className="input-base" value={dia} onChange={(e) => setDia(e.target.value)} />
          </div>
          <button onClick={previsualizar} disabled={loadingPreview} className="btn-primary flex items-center gap-1.5">
            <EyeIcon className="w-4 h-4" />
            {loadingPreview ? 'Calculando…' : 'Ver corte'}
          </button>
          <button
            onClick={imprimirPdf}
            disabled={!preview}
            className="btn-secondary flex items-center gap-1.5"
            title="Imprimir o guardar como PDF"
          >
            <PrinterIcon className="w-4 h-4" />
            Imprimir / PDF
          </button>
        </div>
      </div>

      {historial.length > 0 && (
        <Tabla titulo="Cortes cerrados de esta tienda" vacio="" filas={historial.slice(0, 15)} columnas={[
          { l: 'Fecha', v: (c) => c.fecha }, { l: 'Folio', v: (c) => c.folio },
          { l: 'Esperado', r: true, v: (c) => money(c.efectivo_esperado) }, { l: 'Contado', r: true, v: (c) => money(c.efectivo_contado) },
          { l: 'Diferencia', r: true, v: (c) => <span className={c.diferencia === 0 ? 'text-emerald-600' : 'text-rose-600'}>{money(c.diferencia)}</span> },
          { l: '', v: (c) => <button className="btn-ghost text-sm" onClick={() => { setDia(c.fecha); setCerrado(c); setPreview(c); }}>Ver</button> },
        ]} />
      )}

      {resumen && (
        <div className="space-y-4">
          {/* Tarjetas resumen */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Card label="Total vendido" value={money(resumen.total_vendido)} />
            <Card label="Efectivo esperado en caja" value={money(resumen.efectivo_esperado_caja)} highlight />
            <Card label="# Notas" value={resumen.num_notas ?? 0} />
            <Card label="Total crédito" value={money(resumen.total_credito)} />
            <Card label="Anticipos de apartado" value={money(resumen.total_anticipos)} />
            <Card label="Saldo a favor usado (monedero)" value={money(resumen.saldo_favor_usado)} />
            <Card label="Saldo a favor generado" value={money(resumen.saldo_favor_generado)} />
            <Card label="Cambio devuelto (efvo.)" value={money(resumen.cambio_efectivo)} />
            <Card label="Devoluciones del día" value={money(resumen.total_devoluciones)} />
            <Card label="Efectivo por cambios" value={money(resumen.efectivo_cambios)} />
          </div>

          <p className="text-xs text-slate-500">
            El monedero no es efectivo nuevo: es saldo a favor que el cliente ya tenía (normalmente de una devolución).
            Las devoluciones nunca regresan efectivo, por eso no restan de la caja.
          </p>

          {/* Por forma de pago */}
          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Por forma de pago</div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Forma</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Monto</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {Object.entries(resumen.por_forma_pago || {}).map(([k, v]) => (
                    <tr key={k}>
                      <td className="px-4 py-2 text-slate-700">{FORMA_LABEL[k] || k}</td>
                      <td className="px-4 py-2 text-right text-slate-700">{money(v)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Donde quedo el dinero */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Card label="Abonos de clientes" value={money(resumen.total_abonos)} />
            <Card label="Entradas de efectivo" value={money(resumen.caja_entradas)} />
            <Card label="Salidas de efectivo" value={money(resumen.caja_salidas)} />
            <Card label="Cobrado en cuentas / terminales" value={money(resumen.cobrado_en_cuentas)} />
          </div>
          <Tabla titulo="Cobros que no son efectivo: dónde quedó el dinero" vacio="Sin cobros con tarjeta, transferencia o cheque"
            filas={preview.por_destino || []} columnas={[
              { l: 'Cuenta', v: (d) => d.cuenta }, { l: 'Terminal', v: (d) => d.terminal || '—' },
              { l: 'Forma', v: (d) => FORMA_LABEL[d.forma] || d.forma }, { l: 'Monto', r: true, v: (d) => money(d.monto) },
            ]} />
          <Tabla titulo="Movimientos de la caja (efectivo)" vacio="Sin movimientos de efectivo"
            filas={preview.caja_movimientos || []} columnas={[
              { l: 'Hora', v: (m) => m.hora }, { l: 'Concepto', v: (m) => m.concepto },
              { l: 'Entrada', r: true, v: (m) => (m.tipo === 'ingreso' ? money(m.monto) : '') },
              { l: 'Salida', r: true, v: (m) => (m.tipo === 'egreso' ? money(m.monto) : '') },
            ]} />
          {(preview.abonos || []).length > 0 && (
            <Tabla titulo="Abonos de clientes recibidos" vacio="" filas={preview.abonos} columnas={[
              { l: 'Cliente', v: (a) => a.cliente }, { l: 'Forma', v: (a) => FORMA_LABEL[a.forma] || a.forma }, { l: 'Monto', r: true, v: (a) => money(a.monto) },
            ]} />
          )}

          {/* Cierre del corte */}
          <div className="card p-4 space-y-3">
            <p className="text-sm font-semibold text-slate-700">{cerrado ? `Corte cerrado · ${cerrado.folio}` : 'Cerrar corte'}</p>
            {cerrado ? (
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div>Fondo: <b>{money(cerrado.fondo)}</b></div>
                <div>Esperado (fondo + efectivo): <b>{money(cerrado.efectivo_esperado)}</b></div>
                <div>Contado: <b>{money(cerrado.efectivo_contado)}</b></div>
                <div>Diferencia: <b className={cerrado.diferencia === 0 ? 'text-emerald-600' : 'text-rose-600'}>{money(cerrado.diferencia)}</b></div>
              </div>
            ) : hasPermiso('cortes.crear') ? (() => {
              const esperado = Number(cierre.fondo || 0) + (resumen.efectivo_esperado_caja || 0);
              const dif = Number(cierre.contado || 0) - esperado;
              return (
                <div className="flex flex-wrap items-end gap-3">
                  <label className="text-sm">Fondo de caja<input type="number" className="input-base w-36" value={cierre.fondo} onChange={(e) => setCierre({ ...cierre, fondo: e.target.value })} /></label>
                  <label className="text-sm">Efectivo contado<input type="number" className="input-base w-36" value={cierre.contado} onChange={(e) => setCierre({ ...cierre, contado: e.target.value })} /></label>
                  <label className="text-sm flex-1 min-w-48">Notas<input className="input-base" value={cierre.notas} onChange={(e) => setCierre({ ...cierre, notas: e.target.value })} /></label>
                  <div className="text-sm">Esperado: <b>{money(esperado)}</b><br />Diferencia: <b className={Math.abs(dif) < 0.01 ? 'text-emerald-600' : 'text-rose-600'}>{money(dif)}</b></div>
                  <button className="btn-primary" onClick={async () => {
                    const c = await Swal.fire({ title: '¿Cerrar el corte?', text: 'Ya no se podrá volver a cerrar este día en esta tienda.', icon: 'question', showCancelButton: true });
                    if (!c.isConfirmed) return;
                    try {
                      const r = await cortesApi.cerrar({ id_almacen: idAlmacen, fecha: dia, fondo: Number(cierre.fondo || 0), efectivo_contado: Number(cierre.contado || 0), notas: cierre.notas });
                      Swal.fire('Corte cerrado', `Diferencia: ${money(r.data.diferencia)}`, Math.abs(r.data.diferencia) < 0.01 ? 'success' : 'warning');
                      setCerrado(r.data); setPreview(r.data); cargarHistorial(idAlmacen);
                    } catch (e) { Swal.fire('Error', e.message, 'error'); }
                  }}>Cerrar corte</button>
                </div>
              );
            })() : <p className="text-sm text-slate-500">No tienes permiso para cerrar cortes.</p>}
          </div>

          {/* Vendido por lista de precios */}
          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Vendido por lista de precios</div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Lista</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {clavesLista.length === 0 ? (
                    <tr><td colSpan={2} className="px-4 py-6 text-center text-slate-400">Sin ventas</td></tr>
                  ) : (
                    clavesLista.map((k) => (
                      <tr key={k}>
                        <td className="px-4 py-2 text-slate-700">{listaLabel(k)}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(porLista[k])}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Anticipos de apartado del día */}
          {anticipos.length > 0 && (
            <div className="card overflow-hidden">
              <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Anticipos de apartado del día</div>
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm divide-y divide-slate-200">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Folio</th>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Cliente</th>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Vendedor</th>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Forma</th>
                      <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {anticipos.map((a, i) => (
                      <tr key={i}>
                        <td className="px-4 py-2 text-slate-700">{a.folio}</td>
                        <td className="px-4 py-2 text-slate-600">{a.cliente || '—'}</td>
                        <td className="px-4 py-2 text-slate-600">{a.vendedor || '—'}</td>
                        <td className="px-4 py-2 text-slate-600">{FORMA_LABEL[a.forma] || a.forma}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(a.importe)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Detalle de notas con desglose por línea */}
          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Detalle de notas</div>
            {detalle.length === 0 ? (
              <div className="px-4 py-6 text-center text-slate-400">Sin notas</div>
            ) : (
              <div className="divide-y divide-slate-100">
                {detalle.map((n, i) => (
                  <div key={n.folio || i} className="px-4 py-3 space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                      <span className="font-semibold text-slate-800">{n.folio}</span>
                      <span className="text-slate-500">
                        {(n.formas_pago || []).map((f) => FORMA_LABEL[f] || f).join(', ') || '—'}
                      </span>
                      <span className="font-medium text-slate-800">{money(n.total)}</span>
                    </div>
                    <div className="overflow-x-auto">
                      <table className="min-w-full text-xs divide-y divide-slate-200">
                        <thead className="bg-slate-50">
                          <tr>
                            <th className="px-3 py-1.5 text-left font-semibold text-slate-500 uppercase">Código</th>
                            <th className="px-3 py-1.5 text-left font-semibold text-slate-500 uppercase">Descripción</th>
                            <th className="px-3 py-1.5 text-right font-semibold text-slate-500 uppercase">Cant.</th>
                            <th className="px-3 py-1.5 text-right font-semibold text-slate-500 uppercase">P. Unit.</th>
                            <th className="px-3 py-1.5 text-left font-semibold text-slate-500 uppercase">Lista</th>
                            <th className="px-3 py-1.5 text-right font-semibold text-slate-500 uppercase">Importe</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                          {(n.lineas || []).map((l, j) => (
                            <tr key={j}>
                              <td className="px-3 py-1.5 text-slate-700">{l.codigo}</td>
                              <td className="px-3 py-1.5 text-slate-600">{l.descripcion}</td>
                              <td className="px-3 py-1.5 text-right text-slate-700">{l.cantidad}</td>
                              <td className="px-3 py-1.5 text-right text-slate-700">{money(l.precio_unitario)}</td>
                              <td className="px-3 py-1.5 text-slate-600">{listaLabel(l.lista_aplicada)}</td>
                              <td className="px-3 py-1.5 text-right font-medium text-slate-800">{money(l.importe)}</td>
                            </tr>
                          ))}
                          {(!n.lineas || n.lineas.length === 0) && (
                            <tr><td colSpan={6} className="px-3 py-3 text-center text-slate-400">Sin líneas</td></tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Devoluciones del día */}
          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Devoluciones del día</div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Folio</th>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Venta origen</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Total</th>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Destino</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {devoluciones.length === 0 ? (
                    <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Sin devoluciones</td></tr>
                  ) : (
                    devoluciones.map((d, i) => (
                      <tr key={d.folio || i}>
                        <td className="px-4 py-2 text-slate-700">{d.folio}</td>
                        <td className="px-4 py-2 text-slate-600">{d.folio_venta || '—'}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(d.total)}</td>
                        <td className="px-4 py-2">
                          <span className={d.destino_saldo === 'cxc' ? 'badge-warning' : 'badge-primary'}>
                            {d.destino_saldo === 'cxc' ? 'CxC' : 'Monedero'}
                          </span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Cambios del día */}
          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Cambios del día</div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Folio</th>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Venta origen</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Devuelto</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Nuevo</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Diferencia</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Pago efvo.</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {cambios.length === 0 ? (
                    <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Sin cambios</td></tr>
                  ) : (
                    cambios.map((c, i) => (
                      <tr key={c.folio || i}>
                        <td className="px-4 py-2 text-slate-700">{c.folio}</td>
                        <td className="px-4 py-2 text-slate-600">{c.folio_venta || '—'}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(c.total_devuelto)}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(c.total_nuevo)}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(c.diferencia)}</td>
                        <td className="px-4 py-2 text-right text-slate-700">{money(c.pago_diferencia)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Card({ label, value, highlight = false }) {
  return (
    <div className={`card p-4 ${highlight ? 'ring-2 ring-primary-200' : ''}`}>
      <p className="text-xs text-slate-500 uppercase tracking-wide">{label}</p>
      <p className="text-xl font-bold text-slate-800 mt-1">{value}</p>
    </div>
  );
}
