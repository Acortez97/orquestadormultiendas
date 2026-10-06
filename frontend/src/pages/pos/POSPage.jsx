import { useEffect, useMemo, useState } from 'react';
import Swal from 'sweetalert2';
import { TrashIcon, QrCodeIcon } from '@heroicons/react/24/outline';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import { VariantesInline } from '../../components/common/MatrizColorTalla';
import { clientesApi, almacenesApi, ventasApi, empleadosApi, articulosApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { printTicket } from '../../utils/ticket';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const FORMAS = [
  { v: 'efectivo', l: 'Efectivo' }, { v: 'tdc', l: 'T. Crédito' }, { v: 'tdb', l: 'T. Débito' },
  { v: 'transferencia', l: 'Transferencia' }, { v: 'monedero', l: 'Saldo a favor' },
];

export default function POSPage() {
  const { user } = useAuth();
  const [tiendas, setTiendas] = useState([]);
  const [idAlmacen, setIdAlmacen] = useState(user?.id_tienda || '');
  const [cliente, setCliente] = useState(null);
  const [cart, setCart] = useState([]); // { art, id_color, id_talla, cantidad }
  const [cot, setCot] = useState(null);
  const [pagos, setPagos] = useState([{ forma: 'efectivo', importe: 0 }]);
  const [aCredito, setACredito] = useState(false);
  const [destinoCambio, setDestinoCambio] = useState('efectivo'); // 'efectivo' | 'monedero'
  const [busy, setBusy] = useState(false);
  const [empleados, setEmpleados] = useState([]);
  const [idVendedor, setIdVendedor] = useState('');

  // cliente picker
  const [openCli, setOpenCli] = useState(false);
  const [cliQ, setCliQ] = useState('');
  const [cliRes, setCliRes] = useState([]);
  const [nuevoCli, setNuevoCli] = useState(null);

  useEffect(() => { almacenesApi.listar().then((r) => { const t = (r.data || []).filter((a) => a.tipo === 'tienda'); setTiendas(t); if (!user?.id_tienda && t[0]) setIdAlmacen(t[0]._id); }); }, [user]);
  useEffect(() => { empleadosApi.listar().then((r) => setEmpleados((r.data?.docs ?? r.data ?? []).filter((e) => e.es_vendedor))).catch(() => {}); }, []);

  // Vendedores de la tienda seleccionada (o sin tienda asignada).
  const vendedores = empleados.filter((e) => {
    const t = e.id_tienda?._id || e.id_tienda;
    return !t || String(t) === String(idAlmacen);
  });
  // Si cambia la tienda y el vendedor elegido ya no pertenece, se limpia.
  useEffect(() => {
    if (idVendedor && !vendedores.some((e) => e._id === idVendedor)) setIdVendedor('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [idAlmacen, empleados]);

  // cotizar en vivo
  useEffect(() => {
    if (cart.length === 0) { setCot(null); return; }
    const lineas = cart.map((c) => ({ id_articulo: c.art._id, id_color: c.id_color, id_talla: c.id_talla, cantidad: c.cantidad }));
    ventasApi.cotizar({ id_cliente: cliente?._id, lineas }).then((r) => setCot(r.data)).catch(() => setCot(null));
  }, [cart, cliente]);

  const [scanCode, setScanCode] = useState('');

  const agregar = (art, variante) => {
    const s = variante || {};
    const id_color = (s.id_eje1 && s.id_eje1 !== '0') ? s.id_eje1 : (art.colores?.[0]?._id || '');
    const id_talla = (s.id_eje2 && s.id_eje2 !== '0') ? s.id_eje2 : (art.tallas?.[0]?._id || '');
    setCart((c) => [...c, { art, id_color, id_talla, cantidad: 1 }]);
  };

  // Escaneo: resuelve el código (SKU/EAN/código de variante) y agrega la variante exacta.
  const onScan = async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = scanCode.trim();
    if (!code) return;
    try {
      const res = await articulosApi.scan(code);
      agregar(res.data, res.data?.scan);
      setScanCode('');
    } catch (_) {
      Swal.fire('No encontrado', `El código “${code}” no corresponde a ningún artículo.`, 'warning');
      setScanCode('');
    }
  };
  const upd = (i, patch) => setCart((c) => c.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));
  const quitar = (i) => setCart((c) => c.filter((_, idx) => idx !== i));

  const total = cot?.total || 0;
  const totalPagado = useMemo(() => pagos.reduce((a, p) => a + (Number(p.importe) || 0), 0), [pagos]);
  const restante = Math.max(0, total - totalPagado);
  const excedente = Math.max(0, totalPagado - total);
  // Solo se puede devolver cambio en efectivo si el efectivo recibido cubre el excedente.
  const totalEfectivo = useMemo(() => pagos.filter((p) => p.forma === 'efectivo').reduce((a, p) => a + (Number(p.importe) || 0), 0), [pagos]);
  const puedeCambioEfectivo = excedente > 0 && !aCredito && totalEfectivo >= excedente;
  const destinoCambioFinal = puedeCambioEfectivo && destinoCambio === 'efectivo' ? 'efectivo' : 'monedero';

  const buscarCli = async () => { const r = await clientesApi.listar(cliQ ? { search: cliQ } : {}); setCliRes(r.data || []); };
  const crearCli = async () => {
    try {
      const r = await clientesApi.crear(nuevoCli);
      setCliente(r.data); setOpenCli(false); setNuevoCli(null);
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  async function imprimirTicketVenta(venta) {
    const tienda = tiendas.find((t) => t._id === idAlmacen) || {};
    let saldoMonedero, saldoCredito;
    try {
      const cli = await clientesApi.obtener(cliente._id);
      saldoMonedero = cli.data?.saldo_favor;
      saldoCredito = cli.data?.saldo_credito;
    } catch (_) { /* el ticket no debe fallar por esto */ }

    const lineas = cart.map((it, i) => {
      const calc = cot?.lineas?.[i] || {};
      const color = it.art.colores?.find((c) => c._id === it.id_color)?.nombre || '';
      const talla = it.art.tallas?.find((t) => t._id === it.id_talla)?.nombre || '';
      return {
        cant: `${it.cantidad}x`,
        texto: `${it.art.codigo} ${it.art.descripcion || ''}`.trim(),
        detalle: `${[color, talla].filter(Boolean).join('/')} · ${money(calc.precio_unitario)} · L${calc.lista_aplicada}`,
        importe: calc.importe,
      };
    });

    const totales = [{ label: 'Total', value: venta.total, fuerte: true }];
    if (venta.saldo_favor_usado) totales.push({ label: 'Pagado con monedero', value: venta.saldo_favor_usado });
    if (venta.monto_credito) totales.push({ label: 'A crédito', value: venta.monto_credito });
    if (venta.saldo_favor_generado) totales.push({ label: 'Excedente a monedero', value: venta.saldo_favor_generado });
    if (venta.cambio_efectivo) totales.push({ label: 'Cambio', value: venta.cambio_efectivo });

    printTicket({
      tipo: 'NOTA DE VENTA',
      tienda: { nombre: tienda.nombre, direccion: tienda.direccion, telefono: tienda.telefono },
      folio: venta.folio,
      fecha: venta.fecha || new Date(),
      cliente: cliente.nombre,
      vendedor: (() => {
        const v = vendedores.find((e) => e._id === idVendedor);
        return v ? `${v.nombre} ${v.apellido || ''}`.trim() : ([user?.nombre, user?.apellido].filter(Boolean).join(' ') || user?.email);
      })(),
      secciones: [{ lineas }],
      totales,
      pagos: venta.pagos || [],
      saldoMonedero,
      saldoCredito,
    });
  }

  const cobrar = async () => {
    if (!cliente) return Swal.fire('Falta cliente', 'Selecciona o registra un cliente', 'warning');
    if (!idVendedor) return Swal.fire('Falta vendedor', 'Selecciona un vendedor para registrar la venta', 'warning');
    if (cart.length === 0) return;
    setBusy(true);
    try {
      const body = {
        id_almacen: idAlmacen,
        id_cliente: cliente._id,
        lineas: cart.map((c) => ({ id_articulo: c.art._id, id_color: c.id_color, id_talla: c.id_talla, cantidad: c.cantidad })),
        pagos: pagos.filter((p) => Number(p.importe) > 0).map((p) => ({ forma: p.forma, importe: Number(p.importe) })),
        a_credito: aCredito,
        destino_cambio: destinoCambioFinal,
        id_vendedor: idVendedor,
      };
      const r = await ventasApi.crear(body);
      await imprimirTicketVenta(r.data);
      Swal.fire({ icon: 'success', title: `Venta ${r.data.folio}`, html: `Total: <b>${money(r.data.total)}</b>`, timer: 1800, showConfirmButton: false });
      setCart([]); setPagos([{ forma: 'efectivo', importe: 0 }]); setCliente(null); setACredito(false); setIdVendedor(''); setDestinoCambio('efectivo');
    } catch (e) { Swal.fire('Error', e.message, 'error'); } finally { setBusy(false); }
  };

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-bold text-slate-800">Punto de Venta</h1>
        <select className="input-base max-w-xs" value={idAlmacen} onChange={(e) => setIdAlmacen(e.target.value)} disabled={!!user?.id_tienda}>
          {tiendas.map((t) => <option key={t._id} value={t._id}>{t.nombre}</option>)}
        </select>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Carrito */}
        <div className="lg:col-span-2 space-y-3">
          <div className="card p-3 space-y-2">
            <div className="relative">
              <QrCodeIcon className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
              <input
                className="input-base pl-9"
                placeholder="Escanear código de barras / SKU… (Enter)"
                value={scanCode}
                onChange={(e) => setScanCode(e.target.value)}
                onKeyDown={onScan}
              />
            </div>
            <ArticuloAutocomplete onSelect={agregar} autoFocus />
          </div>

          <div className="card overflow-hidden">
            <table className="min-w-full text-sm divide-y divide-slate-200">
              <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                <tr><th className="px-3 py-2 text-left">Artículo</th><th className="px-2 py-2">Variante</th><th className="px-2 py-2">Cant.</th><th className="px-2 py-2">Precio</th><th className="px-2 py-2">Lista</th><th className="px-2 py-2">Importe</th><th /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {cart.length === 0 ? (
                  <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Carrito vacío</td></tr>
                ) : cart.map((it, i) => {
                  const calc = cot?.lineas?.[i];
                  return (
                    <tr key={i}>
                      <td className="px-3 py-2">{it.art.codigo}</td>
                      <td className="px-2 py-2">
                        <VariantesInline art={it.art} id_color={it.id_color} id_talla={it.id_talla} onChange={(patch) => upd(i, patch)} />
                      </td>
                      <td className="px-2 py-2 w-20"><input type="number" min="1" className="input-base !py-1 w-16 text-xs" value={it.cantidad} onChange={(e) => upd(i, { cantidad: Math.max(1, Number(e.target.value)) })} /></td>
                      <td className="px-2 py-2 text-right">{calc ? money(calc.precio_unitario) : '—'}</td>
                      <td className="px-2 py-2 text-center">{calc ? <span className="badge-primary">{calc.lista_aplicada}</span> : ''}</td>
                      <td className="px-2 py-2 text-right font-medium">{calc ? money(calc.importe) : '—'}</td>
                      <td className="px-2 py-2"><button className="btn-ghost text-rose-600 p-1" onClick={() => quitar(i)}><TrashIcon className="w-4 h-4" /></button></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        {/* Resumen / cobro */}
        <div className="space-y-3">
          <div className="card p-4">
            <p className="text-xs text-slate-400 uppercase font-semibold">Cliente</p>
            {cliente ? (
              <div className="flex items-center justify-between mt-1">
                <div><p className="font-medium text-slate-800">{cliente.nombre}</p><p className="text-xs text-slate-500">Lista {cliente.lista_precios} · Monedero {money(cliente.saldo_favor)}</p></div>
                <button className="btn-ghost text-xs" onClick={() => setCliente(null)}>Cambiar</button>
              </div>
            ) : (
              <button className="btn-secondary w-full mt-1" onClick={() => { setOpenCli(true); setCliRes([]); buscarCli(); }}>Seleccionar cliente</button>
            )}
            <div className="mt-3">
              <label className="block text-xs text-slate-400 uppercase font-semibold mb-1">Vendedor *</label>
              <select className="input-base" value={idVendedor} onChange={(e) => setIdVendedor(e.target.value)}>
                <option value="">— Selecciona vendedor —</option>
                {vendedores.map((v) => (
                  <option key={v._id} value={v._id}>{v.nombre} {v.apellido || ''}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="card p-4 space-y-2">
            <div className="flex justify-between text-sm"><span className="text-slate-500">Prendas (cuentan)</span><span>{cot?.total_prendas_lista ?? 0}</span></div>
            <div className="flex justify-between text-sm"><span className="text-slate-500">Nivel por cantidad</span><span className="badge-accent">Lista {cot?.nivel_cantidad ?? 1}</span></div>
            <div className="flex justify-between text-lg font-bold border-t pt-2"><span>Total</span><span>{money(total)}</span></div>
          </div>

          <div className="card p-4 space-y-2">
            <p className="text-xs text-slate-400 uppercase font-semibold">Pagos</p>
            {pagos.map((p, i) => (
              <div key={i} className="flex gap-2">
                <select className="input-base !py-1 text-sm flex-1" value={p.forma} onChange={(e) => setPagos((ps) => ps.map((x, idx) => idx === i ? { ...x, forma: e.target.value } : x))}>
                  {FORMAS.map((f) => <option key={f.v} value={f.v}>{f.l}</option>)}
                </select>
                <input type="number" className="input-base !py-1 text-sm w-28" value={p.importe} onChange={(e) => setPagos((ps) => ps.map((x, idx) => idx === i ? { ...x, importe: e.target.value } : x))} />
                {pagos.length > 1 && <button className="btn-ghost text-rose-600 p-1" onClick={() => setPagos((ps) => ps.filter((_, idx) => idx !== i))}><TrashIcon className="w-4 h-4" /></button>}
              </div>
            ))}
            <button className="btn-ghost text-xs" onClick={() => setPagos((ps) => [...ps, { forma: 'efectivo', importe: 0 }])}>+ Agregar pago</button>
            <div className="flex justify-between text-sm"><span className="text-slate-500">Pagado</span><span>{money(totalPagado)}</span></div>
            {restante > 0 && !aCredito && <div className="flex justify-between text-sm text-rose-600"><span>Restante</span><span>{money(restante)}</span></div>}
            {excedente > 0 && (
              puedeCambioEfectivo ? (
                <div className="space-y-1">
                  <div className="flex gap-2">
                    <button
                      type="button"
                      className={`flex-1 text-xs rounded-md py-1.5 border ${destinoCambio === 'efectivo' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300'}`}
                      onClick={() => setDestinoCambio('efectivo')}
                    >Devolver cambio</button>
                    <button
                      type="button"
                      className={`flex-1 text-xs rounded-md py-1.5 border ${destinoCambio === 'monedero' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300'}`}
                      onClick={() => setDestinoCambio('monedero')}
                    >A monedero</button>
                  </div>
                  <div className="flex justify-between text-sm text-emerald-600">
                    <span>{destinoCambio === 'efectivo' ? 'Cambio a entregar' : 'Excedente → monedero'}</span>
                    <span>{money(excedente)}</span>
                  </div>
                </div>
              ) : (
                <div className="flex justify-between text-sm text-emerald-600"><span>Excedente → monedero</span><span>{money(excedente)}</span></div>
              )
            )}
            {cliente?.forma_pago === 'Credito' && (
              <label className="text-sm flex items-center gap-2 mt-1"><input type="checkbox" checked={aCredito} onChange={(e) => setACredito(e.target.checked)} /> Venta a crédito</label>
            )}
          </div>

          <button className="btn-primary w-full py-3 text-base" disabled={busy || cart.length === 0} onClick={cobrar}>
            {busy ? 'Procesando…' : `Cobrar ${money(total)}`}
          </button>
        </div>
      </div>

      {/* Selector de cliente */}
      <Modal open={openCli} onClose={() => setOpenCli(false)} title="Seleccionar cliente" size="lg"
        footer={nuevoCli && <><button className="btn-secondary" onClick={() => setNuevoCli(null)}>Cancelar</button><button className="btn-primary" onClick={crearCli}>Registrar y usar</button></>}>
        {!nuevoCli ? (
          <>
            <div className="flex gap-2 mb-3">
              <input className="input-base" placeholder="Buscar nombre / teléfono" value={cliQ} onChange={(e) => setCliQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && buscarCli()} />
              <button className="btn-secondary" onClick={buscarCli}>Buscar</button>
              <button className="btn-accent whitespace-nowrap" onClick={() => setNuevoCli({ nombre: '', telefono: '', lista_precios: 1, domicilio: { colonia: '', ciudad: '' } })}>+ Nuevo</button>
            </div>
            <div className="border border-slate-200 rounded-lg divide-y max-h-72 overflow-y-auto">
              {cliRes.map((c) => (
                <button key={c._id} className="w-full text-left px-3 py-2 hover:bg-slate-50 text-sm flex justify-between" onClick={() => { setCliente(c); setOpenCli(false); }}>
                  <span>{c.nombre} <span className="text-slate-400">· {c.telefono}</span></span>
                  <span className="badge-primary">L{c.lista_precios}</span>
                </button>
              ))}
              {cliRes.length === 0 && <p className="px-3 py-6 text-center text-slate-400 text-sm">Sin resultados</p>}
            </div>
          </>
        ) : (
          <div className="grid grid-cols-2 gap-3">
            <label className="text-sm">Nombre *<input className="input-base" value={nuevoCli.nombre} onChange={(e) => setNuevoCli({ ...nuevoCli, nombre: e.target.value })} /></label>
            <label className="text-sm">Teléfono *<input className="input-base" value={nuevoCli.telefono} onChange={(e) => setNuevoCli({ ...nuevoCli, telefono: e.target.value })} /></label>
            <label className="text-sm">Colonia *<input className="input-base" value={nuevoCli.domicilio.colonia} onChange={(e) => setNuevoCli({ ...nuevoCli, domicilio: { ...nuevoCli.domicilio, colonia: e.target.value } })} /></label>
            <label className="text-sm">Ciudad *<input className="input-base" value={nuevoCli.domicilio.ciudad} onChange={(e) => setNuevoCli({ ...nuevoCli, domicilio: { ...nuevoCli.domicilio, ciudad: e.target.value } })} /></label>
          </div>
        )}
      </Modal>
    </div>
  );
}
