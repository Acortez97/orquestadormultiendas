import { useState, useEffect, useCallback } from 'react';
import { MagnifyingGlassIcon, TrashIcon, InformationCircleIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import { VariantesInline } from '../../components/common/MatrizColorTalla';
import { devolucionesApi, ventasApi, clientesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { printTicket } from '../../utils/ticket';

// Nombre del usuario logueado para el campo "Vendedor" del ticket.
const nombreUsuario = (u) => [u?.nombre, u?.apellido].filter(Boolean).join(' ') || u?.email || '';
// Saldos del cliente después del movimiento (para el ticket); nunca rompe el flujo.
async function saldosCliente(idCliente) {
  try {
    const r = await clientesApi.obtener(idCliente);
    return { saldoMonedero: r.data?.saldo_favor, saldoCredito: r.data?.saldo_credito };
  } catch (_) {
    return {};
  }
}

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');
const idOf = (v) => v?._id || v || '';

// Plazo máximo para aceptar una devolución respecto a la fecha de la venta.
const DIAS_LIMITE_DEVOLUCION = 30;

export default function DevolucionesPage() {
  const [tab, setTab] = useState('devoluciones');
  return (
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-bold text-slate-800">Devoluciones y Cambios</h1>
      <div className="flex gap-2 border-b border-slate-200">
        {[['devoluciones', 'Devoluciones'], ['cambios', 'Cambios']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)}
            className={`px-4 py-2 text-sm font-medium -mb-px border-b-2 ${tab === k ? 'border-primary-600 text-primary-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === 'devoluciones' ? <TabDevoluciones /> : <TabCambios />}
    </div>
  );
}

/* ----- Buscador de ticket reutilizable (por folio o por cliente) ----- */
function BuscarTicket({ onFound, label = 'Folio del ticket original…', maxDias, onClienteChange }) {
  const [folio, setFolio] = useState('');
  const [buscando, setBuscando] = useState(false);
  const [clientes, setClientes] = useState([]);
  const [idCliente, setIdCliente] = useState('');
  const [ventasCliente, setVentasCliente] = useState([]);
  const [cargandoVentas, setCargandoVentas] = useState(false);

  useEffect(() => {
    clientesApi.listar().then((r) => setClientes(r.data?.docs ?? r.data ?? [])).catch(() => {});
  }, []);

  // ¿La venta está dentro del plazo permitido (últimos `maxDias` días)?
  const dentroDeVentana = (v) => {
    if (!maxDias) return true;
    const f = v?.fecha || v?.createdAt;
    if (!f) return true;
    const dias = (Date.now() - new Date(f).getTime()) / 86400000;
    return dias <= maxDias;
  };

  // Carga el detalle completo de una venta por folio y la precarga.
  const cargarFolio = async (f) => {
    setBuscando(true);
    try {
      const res = await devolucionesApi.buscarVenta(f);
      if (res.success === false || !res.data) throw new Error(res.message || 'Venta no encontrada');
      if (!dentroDeVentana(res.data)) {
        Swal.fire('Fuera de plazo', `Solo se permiten devoluciones de ventas de los últimos ${maxDias} días.`, 'warning');
        return;
      }
      onFound(res.data);
    } catch (err) {
      Swal.fire('Error', err.message || 'Venta no encontrada', 'error');
    } finally {
      setBuscando(false);
    }
  };

  const buscar = (e) => {
    e.preventDefault();
    if (folio.trim()) cargarFolio(folio.trim());
  };

  // Al elegir cliente, lista sus ventas completadas para seleccionar el ticket.
  const onCliente = async (id) => {
    setIdCliente(id);
    setVentasCliente([]);
    onClienteChange?.(id);
    if (!id) return;
    setCargandoVentas(true);
    try {
      const res = await ventasApi.listar({ id_cliente: id, estado: 'completada' });
      const lista = (res.data?.docs ?? res.data ?? []).filter(dentroDeVentana);
      setVentasCliente(lista);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setCargandoVentas(false);
    }
  };

  return (
    <div className="space-y-2">
      <form onSubmit={buscar} className="flex gap-2">
        <div className="relative flex-1">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
          <input className="input-base pl-9" placeholder={label} value={folio} onChange={(e) => setFolio(e.target.value)} />
        </div>
        <button type="submit" disabled={buscando} className="btn-primary">{buscando ? 'Buscando…' : 'Buscar venta'}</button>
      </form>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs text-slate-400">o por cliente:</span>
        <select className="input-base max-w-xs" value={idCliente} onChange={(e) => onCliente(e.target.value)}>
          <option value="">Selecciona cliente…</option>
          {clientes.map((c) => (
            <option key={c._id} value={c._id}>{c.nombre}</option>
          ))}
        </select>
        {idCliente && (
          <select
            className="input-base flex-1 min-w-[16rem]"
            value=""
            disabled={cargandoVentas}
            onChange={(e) => e.target.value && cargarFolio(e.target.value)}
          >
            <option value="">
              {cargandoVentas ? 'Cargando ventas…' : ventasCliente.length ? 'Selecciona ticket…' : 'Sin ventas de este cliente'}
            </option>
            {ventasCliente.map((v) => (
              <option key={v._id} value={v.folio}>
                {v.folio} · {fecha(v.fecha || v.createdAt)} · {money(v.total)}
              </option>
            ))}
          </select>
        )}
      </div>
    </div>
  );
}

/* ---------------- Tab Devoluciones ---------------- */
function TabDevoluciones() {
  const { user } = useAuth();
  const [venta, setVenta] = useState(null);
  const [sel, setSel] = useState({});
  const [saving, setSaving] = useState(false);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filtroCliente, setFiltroCliente] = useState('');

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtroCliente) {
        // Al seleccionar cliente: solo sus devoluciones y dentro del lapso de 30 días.
        params.id_cliente = filtroCliente;
        params.desde = new Date(Date.now() - DIAS_LIMITE_DEVOLUCION * 86400000).toISOString();
      }
      const res = await devolucionesApi.listar(params);
      setRows(res.data || []);
    }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setLoading(false); }
  }, [filtroCliente]);
  useEffect(() => { cargar(); }, [cargar]);

  const lineas = venta?.lineas || [];
  const totalDevolver = lineas.reduce((s, l, i) => s + Number(sel[i] || 0) * Number(l.precio_unitario || 0), 0);

  async function submit() {
    const out = lineas.map((l, i) => ({ l, cant: Number(sel[i] || 0) })).filter((x) => x.cant > 0).map(({ l, cant }) => ({
      id_articulo: idOf(l.id_articulo), id_color: idOf(l.id_color), id_talla: idOf(l.id_talla),
      cantidad: cant, precio_unitario: Number(l.precio_unitario), importe: cant * Number(l.precio_unitario || 0),
    }));
    if (out.length === 0) return Swal.fire('Sin líneas', 'Indica cantidades a devolver', 'warning');
    setSaving(true);
    try {
      const res = await devolucionesApi.crear({ folio_venta: venta.folio, lineas: out });
      await imprimirTicket(res.data);
      setVenta(null); setSel({}); cargar();
      Swal.fire({ icon: 'success', title: 'Devolución registrada', timer: 1600, showConfirmButton: false });
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setSaving(false); }
  }

  async function imprimirTicket(dev) {
    const ticketLineas = lineas
      .map((l, i) => ({ l, cant: Number(sel[i] || 0) }))
      .filter((x) => x.cant > 0)
      .map(({ l, cant }) => ({
        cant: `${cant}x`,
        texto: `${l.codigo || ''} ${l.id_articulo?.descripcion || l.descripcion || ''}`.trim(),
        detalle: `${l.id_color?.nombre || '—'}/${l.id_talla?.nombre || '—'} · ${money(l.precio_unitario)}`,
        importe: cant * Number(l.precio_unitario || 0),
      }));
    const destino = dev?.destino_saldo === 'cxc' ? 'cuenta por cobrar (CxC)' : 'monedero';
    const { saldoMonedero, saldoCredito } = await saldosCliente(idOf(venta.id_cliente));
    printTicket({
      tipo: 'DEVOLUCIÓN',
      tienda: venta.id_almacen || {},
      folio: dev?.folio,
      fecha: dev?.fecha || new Date(),
      cliente: venta.id_cliente?.nombre,
      vendedor: nombreUsuario(user),
      secciones: [{ titulo: 'Prendas devueltas', lineas: ticketLineas }],
      totales: [{ label: 'Total devuelto', value: dev?.total ?? totalDevolver, fuerte: true }],
      saldoMonedero,
      saldoCredito,
      nota: `Saldo aplicado a ${destino}. No se entrega efectivo.`,
    });
  }

  const columns = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha || r.createdAt) },
    { key: 'folio_venta', label: 'Venta origen' },
    { key: 'total', label: 'Total', render: (r) => money(r.total) },
    { key: 'destino_saldo', label: 'Destino', render: (r) => <span className={r.destino_saldo === 'cxc' ? 'badge-warning' : 'badge-primary'}>{r.destino_saldo === 'cxc' ? 'CxC' : 'Monedero'}</span> },
  ];

  return (
    <div className="space-y-4">
      <div className="card p-3 flex items-start gap-2 bg-slate-50 border border-slate-200">
        <InformationCircleIcon className="w-5 h-5 text-primary-500 shrink-0 mt-0.5" />
        <p className="text-sm text-slate-600">Nunca se regresa efectivo. El saldo va al monedero del cliente; si la venta fue a crédito, se ajusta la cuenta por cobrar (CxC). Solo se aceptan devoluciones de ventas de los últimos <b>{DIAS_LIMITE_DEVOLUCION} días</b>.</p>
      </div>
      <div className="card p-4 space-y-4">
        <BuscarTicket onFound={(v) => { setVenta(v); setSel({}); }} maxDias={DIAS_LIMITE_DEVOLUCION} onClienteChange={setFiltroCliente} />
        {venta && (
          <div className="space-y-3">
            <div className="text-sm text-slate-600">Venta <b>{venta.folio}</b> · {fecha(venta.fecha || venta.createdAt)} · Cliente: {venta.id_cliente?.nombre || '—'}</div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50"><tr>
                  {['Artículo', 'Variante', 'Vendido', 'P. Unit.', 'Devolver'].map((h) => <th key={h} className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">{h}</th>)}
                </tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {lineas.map((l, i) => (
                    <tr key={i}>
                      <td className="px-3 py-2 text-slate-700">{l.codigo} — {l.id_articulo?.descripcion || l.descripcion || '—'}</td>
                      <td className="px-3 py-2 text-slate-600">{[l.id_color?.nombre, l.id_talla?.nombre].filter(Boolean).join(' / ') || '—'}</td>
                      <td className="px-3 py-2 text-slate-600">{l.cantidad}</td>
                      <td className="px-3 py-2 text-slate-600">{money(l.precio_unitario)}</td>
                      <td className="px-3 py-2">
                        <input type="number" min="0" max={l.cantidad} className="input-base w-24" value={sel[i] || ''}
                          onChange={(e) => setSel((s) => ({ ...s, [i]: Math.min(Number(e.target.value || 0), l.cantidad) }))} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-slate-800">Total a devolver: {money(totalDevolver)}</span>
              <button onClick={submit} disabled={saving} className="btn-primary">{saving ? 'Registrando…' : 'Registrar devolución'}</button>
            </div>
          </div>
        )}
      </div>
      <div className="flex items-center gap-2">
        <h2 className="text-lg font-semibold text-slate-700">Devoluciones registradas</h2>
        {filtroCliente && (
          <span className="badge-primary text-xs">Cliente seleccionado · últimos {DIAS_LIMITE_DEVOLUCION} días</span>
        )}
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty={filtroCliente ? `Sin devoluciones de este cliente en los últimos ${DIAS_LIMITE_DEVOLUCION} días` : 'Sin devoluciones'} exportName="devoluciones" exportTitle="Devoluciones" />
    </div>
  );
}

/* ---------------- Tab Cambios ---------------- */
function TabCambios() {
  const { user } = useAuth();
  const [venta, setVenta] = useState(null);
  const [sel, setSel] = useState({});          // líneas devueltas del ticket: index -> cantidad
  const [nuevas, setNuevas] = useState([]);     // líneas nuevas (autocomplete)
  const [pagoDiferencia, setPagoDiferencia] = useState(0);
  const [saving, setSaving] = useState(false);
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  const cargar = useCallback(async () => {
    setLoading(true);
    try { const res = await devolucionesApi.listarCambios(); setRows(res.data || []); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { cargar(); }, [cargar]);

  const lineasTicket = venta?.lineas || [];
  const totalDevuelto = lineasTicket.reduce((s, l, i) => s + Number(sel[i] || 0) * Number(l.precio_unitario || 0), 0);
  const totalNuevo = nuevas.reduce((s, l) => s + Number(l.cantidad || 0) * Number(l.precio_unitario || 0), 0);
  const diferencia = totalNuevo - totalDevuelto;

  // Precio de la prenda nueva en un cambio: el MENOR (mayor descuento) entre la
  // lista del cliente y las listas aplicadas en la venta original (su lista de
  // cliente y su nivel por cantidad). No se recalcula por la cantidad del cambio,
  // para que cambiar 1 pieza (p. ej. sólo talla) conserve el precio de volumen.
  // Las ofertas ignoran las listas y usan precio_oferta.
  const precioParaCliente = (art) => {
    if (art.es_oferta) return Number(art.precio_oferta) || 0;
    const niveles = [...new Set([
      Number(venta?.id_cliente?.lista_precios),
      Number(venta?.lista_cliente),
      Number(venta?.nivel_cantidad),
    ])].filter((n) => n >= 1 && n <= 5);
    const precios = niveles.map((n) => Number(art.precios?.[`lista${n}`] || 0)).filter((p) => p > 0);
    return precios.length ? Math.min(...precios) : Number(art.precios?.lista1 || 0);
  };

  const agregarNueva = (art) => {
    setNuevas((ns) => [...ns, {
      art,
      id_articulo: art._id, codigo: art.codigo, descripcion: art.descripcion,
      id_color: art.colores?.[0]?._id || '', id_talla: art.tallas?.[0]?._id || '',
      cantidad: 1, precio_unitario: precioParaCliente(art),
    }]);
  };
  const updNueva = (i, patch) => setNuevas((ns) => ns.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const quitarNueva = (i) => setNuevas((ns) => ns.filter((_, idx) => idx !== i));

  async function submit() {
    const lineas_devueltas = lineasTicket.map((l, i) => ({ l, cant: Number(sel[i] || 0) })).filter((x) => x.cant > 0).map(({ l, cant }) => ({
      id_articulo: idOf(l.id_articulo), id_color: idOf(l.id_color), id_talla: idOf(l.id_talla),
      cantidad: cant, precio_unitario: Number(l.precio_unitario), importe: cant * Number(l.precio_unitario || 0),
    }));
    const lineas_nuevas = nuevas.filter((l) => l.id_articulo && Number(l.cantidad) > 0).map((l) => ({
      id_articulo: l.id_articulo, id_color: l.id_color || undefined, id_talla: l.id_talla || undefined,
      cantidad: Number(l.cantidad), precio_unitario: Number(l.precio_unitario), importe: Number(l.cantidad) * Number(l.precio_unitario || 0),
    }));
    if (lineas_devueltas.length === 0 || lineas_nuevas.length === 0)
      return Swal.fire('Faltan líneas', 'Indica prendas devueltas (del ticket) y prendas nuevas', 'warning');
    setSaving(true);
    try {
      const res = await devolucionesApi.crearCambio({
        folio_venta: venta?.folio, lineas_devueltas, lineas_nuevas,
        diferencia, pago_diferencia: diferencia > 0 ? Number(pagoDiferencia) : 0,
      });
      await imprimirTicket(res.data);
      setVenta(null); setSel({}); setNuevas([]); setPagoDiferencia(0); cargar();
      Swal.fire({ icon: 'success', title: 'Cambio registrado', timer: 1600, showConfirmButton: false });
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setSaving(false); }
  }

  async function imprimirTicket(cambio) {
    const devueltas = lineasTicket
      .map((l, i) => ({ l, cant: Number(sel[i] || 0) }))
      .filter((x) => x.cant > 0)
      .map(({ l, cant }) => ({
        cant: `${cant}x`,
        texto: `${l.codigo || ''} ${l.id_articulo?.descripcion || l.descripcion || ''}`.trim(),
        detalle: `${l.id_color?.nombre || '—'}/${l.id_talla?.nombre || '—'} · ${money(l.precio_unitario)}`,
        importe: cant * Number(l.precio_unitario || 0),
      }));
    const nuevasLineas = nuevas
      .filter((l) => l.id_articulo && Number(l.cantidad) > 0)
      .map((l) => {
        const color = l.art?.colores?.find((c) => c._id === l.id_color)?.nombre || '—';
        const talla = l.art?.tallas?.find((t) => t._id === l.id_talla)?.nombre || '—';
        return {
          cant: `${l.cantidad}x`,
          texto: `${l.codigo || ''} ${l.descripcion || ''}`.trim(),
          detalle: `${color}/${talla} · ${money(l.precio_unitario)}`,
          importe: Number(l.cantidad) * Number(l.precio_unitario || 0),
        };
      });

    const totales = [
      { label: 'Total devuelto', value: totalDevuelto },
      { label: 'Total nuevo', value: totalNuevo },
      { label: 'Diferencia', value: diferencia, fuerte: true },
    ];
    if (diferencia > 0) totales.push({ label: 'Pago de diferencia', value: Number(pagoDiferencia) });
    if (diferencia < 0) totales.push({ label: 'Saldo a favor (monedero)', value: -diferencia });

    const idCliente = idOf(venta?.id_cliente);
    const { saldoMonedero, saldoCredito } = idCliente ? await saldosCliente(idCliente) : {};
    printTicket({
      tipo: 'CAMBIO',
      tienda: venta?.id_almacen || {},
      folio: cambio?.folio,
      fecha: cambio?.fecha || new Date(),
      cliente: venta?.id_cliente?.nombre,
      vendedor: nombreUsuario(user),
      secciones: [
        { titulo: 'Prendas devueltas', lineas: devueltas },
        { titulo: 'Prendas nuevas', lineas: nuevasLineas },
      ],
      totales,
      saldoMonedero,
      saldoCredito,
      nota: diferencia > 0 ? 'El cliente paga la diferencia.' : diferencia < 0 ? 'Diferencia a favor del cliente.' : 'Cambio sin diferencia.',
    });
  }

  const columns = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha || r.createdAt) },
    { key: 'folio_venta', label: 'Venta origen' },
    { key: 'diferencia', label: 'Diferencia', render: (r) => money(r.diferencia ?? (r.total_nuevo || 0) - (r.total_devuelto || 0)) },
  ];

  return (
    <div className="space-y-4">
      <div className="card p-3 flex items-start gap-2 bg-slate-50 border border-slate-200">
        <InformationCircleIcon className="w-5 h-5 text-primary-500 shrink-0 mt-0.5" />
        <p className="text-sm text-slate-600">Busca el ticket original para elegir las prendas devueltas. Si la diferencia favorece al cliente va al monedero; si debe, paga la diferencia.</p>
      </div>
      <div className="card p-4 space-y-4">
        <BuscarTicket onFound={(v) => { setVenta(v); setSel({}); }} />

        <div className="grid md:grid-cols-2 gap-4">
          {/* Devueltas — del ticket */}
          <div className="border border-slate-200 rounded-lg p-3 space-y-2">
            <h3 className="text-sm font-semibold text-slate-700">Prendas devueltas (del ticket)</h3>
            {!venta ? (
              <p className="text-xs text-slate-400">Busca un ticket para listar sus prendas.</p>
            ) : lineasTicket.map((l, i) => (
              <div key={i} className="flex items-center justify-between gap-2 text-sm">
                <span className="text-slate-600">{l.codigo} · {[l.id_color?.nombre, l.id_talla?.nombre].filter(Boolean).join('/') || '—'} · {money(l.precio_unitario)} (vend. {l.cantidad})</span>
                <input type="number" min="0" max={l.cantidad} className="input-base w-20 text-xs" value={sel[i] || ''}
                  onChange={(e) => setSel((s) => ({ ...s, [i]: Math.min(Number(e.target.value || 0), l.cantidad) }))} />
              </div>
            ))}
          </div>

          {/* Nuevas — autocomplete */}
          <div className="border border-slate-200 rounded-lg p-3 space-y-2">
            <h3 className="text-sm font-semibold text-slate-700">Prendas nuevas</h3>
            <ArticuloAutocomplete onSelect={agregarNueva} placeholder="Agregar artículo…" />
            {nuevas.map((l, i) => (
              <div key={i} className="grid grid-cols-12 gap-1.5 items-center">
                <span className="col-span-3 text-xs text-slate-700 truncate" title={l.descripcion}>{l.codigo}</span>
                <div className="col-span-5">
                  <VariantesInline art={l.art} id_color={l.id_color} id_talla={l.id_talla} onChange={(patch) => updNueva(i, patch)} />
                </div>
                <input type="number" min="1" className="input-base col-span-1 text-xs !py-1" value={l.cantidad} onChange={(e) => updNueva(i, { cantidad: e.target.value })} />
                <input type="number" min="0" step="0.01" className="input-base col-span-2 text-xs !py-1" value={l.precio_unitario} onChange={(e) => updNueva(i, { precio_unitario: e.target.value })} />
                <button type="button" onClick={() => quitarNueva(i)} className="btn-ghost p-1 text-rose-600 col-span-1"><TrashIcon className="w-4 h-4" /></button>
              </div>
            ))}
          </div>
        </div>

        <div className="flex flex-col gap-1 items-end">
          <div className="text-sm text-slate-600">Devuelto: {money(totalDevuelto)}</div>
          <div className="text-sm text-slate-600">Nuevo: {money(totalNuevo)}</div>
          <div className="text-sm font-semibold text-slate-800">
            Diferencia: {money(diferencia)}{' '}
            {diferencia > 0 ? <span className="badge-warning ml-1">Cliente paga</span> : diferencia < 0 ? <span className="badge-primary ml-1">Saldo a favor</span> : null}
          </div>
          {diferencia > 0 && (
            <div className="w-48">
              <label className="block text-xs font-medium text-slate-600 mb-1">Pago de diferencia</label>
              <input type="number" min="0" step="0.01" className="input-base" value={pagoDiferencia} onChange={(e) => setPagoDiferencia(e.target.value)} />
            </div>
          )}
          <button onClick={submit} disabled={saving} className="btn-primary mt-1">{saving ? 'Registrando…' : 'Registrar cambio'}</button>
        </div>
      </div>
      <h2 className="text-lg font-semibold text-slate-700">Cambios registrados</h2>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin cambios" exportName="cambios" exportTitle="Cambios" />
    </div>
  );
}
