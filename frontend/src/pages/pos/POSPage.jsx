import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Swal from '../../utils/swal';
import clsx from 'clsx';
import { TrashIcon, QrCodeIcon, CameraIcon, ChevronLeftIcon } from '@heroicons/react/24/outline';
import { LogoTienda } from '../../components/layout/Marca';
import { useBarcodeScanner, BarcodeScannerView } from '../../components/common/BarcodeScanner';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import { VariantesInline } from '../../components/common/MatrizColorTalla';
import { clientesApi, almacenesApi, ventasApi, empleadosApi, articulosApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { printTicket } from '../../utils/ticket';
import DestinoPago, { faltaDestino, destinoPayload } from '../../components/common/DestinoPago';
import { aviso } from '../../utils/avisos';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const FORMAS = [
  { v: 'efectivo', l: 'Efectivo' }, { v: 'tdc', l: 'T. Crédito' }, { v: 'tdb', l: 'T. Débito' },
  { v: 'transferencia', l: 'Transfer.' }, { v: 'cheque', l: 'Cheque' }, { v: 'monedero', l: 'Monedero' },
];
const MAX_FRECUENTES = 8;

/** Articulos que mas se agregan en este equipo (por tienda); no depende del servidor ni de internet */
function leerFrecuentes(clave) {
  try { return JSON.parse(localStorage.getItem(clave) || '[]'); } catch { return []; }
}

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
  const [ticketAbierto, setTicketAbierto] = useState(false);   // hoja de cobro en celular
  const [camara, setCamara] = useState(false);
  const scanRef = useRef(null);
  const { scan } = useBarcodeScanner();
  const claveFrecuentes = `ot_pos_frecuentes_${user?.tienda?.slug || 'tienda'}`;
  const [usados, setUsados] = useState(() => leerFrecuentes(claveFrecuentes));
  const frecuentes = useMemo(() => [...usados].sort((a, b) => b.n - a.n).slice(0, MAX_FRECUENTES), [usados]);

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

  // cotizar en vivo (una respuesta vieja que llegue tarde se descarta)
  useEffect(() => {
    if (cart.length === 0) { setCot(null); return undefined; }
    let vigente = true;
    const lineas = cart.map((c) => ({ id_articulo: c.art._id, id_color: c.id_color, id_talla: c.id_talla, cantidad: c.cantidad }));
    ventasApi.cotizar({ id_cliente: cliente?._id, id_almacen: idAlmacen || undefined, lineas })
      .then((r) => { if (vigente) setCot(r.data); })
      .catch(() => { if (vigente) setCot(null); });
    return () => { vigente = false; };
  }, [cart, cliente, idAlmacen]);

  const [scanCode, setScanCode] = useState('');

  const agregar = (art, variante) => {
    const s = variante || {};
    const id_color = (s.id_eje1 && s.id_eje1 !== '0') ? s.id_eje1 : (art.colores?.[0]?._id || '');
    const id_talla = (s.id_eje2 && s.id_eje2 !== '0') ? s.id_eje2 : (art.tallas?.[0]?._id || '');
    setCart((c) => [...c, { art, id_color, id_talla, cantidad: 1 }]);
    // cuenta para «frecuentes» (se guarda lo minimo para volver a agregarlo con un toque)
    setUsados((us) => {
      const lista = us.filter((u) => u.art._id !== art._id);
      const previo = us.find((u) => u.art._id === art._id);
      const nuevo = [{ art, n: (previo?.n || 0) + 1 }, ...lista].sort((a, b) => b.n - a.n).slice(0, 30);
      try { localStorage.setItem(claveFrecuentes, JSON.stringify(nuevo)); } catch { /* sin almacenamiento */ }
      return nuevo;
    });
  };

  // Escaneo con la camara: al detectar un codigo lo agrega como si se hubiera tecleado
  const abrirCamara = () => setCamara(true);
  useEffect(() => {
    if (!camara) return undefined;
    let activo = true;
    scan().then(async (code) => {
      if (!activo) return;
      setCamara(false);
      try { const res = await articulosApi.scan(code); agregar(res.data, res.data?.scan); }
      catch { Swal.fire('No encontrado', `El código “${code}” no corresponde a ningún artículo.`, 'warning'); }
    }).catch(() => {});
    return () => {
      activo = false;
      const v = document.getElementById('barcode-video');
      v?.srcObject?.getTracks?.().forEach((t) => t.stop());   // apaga la camara al cerrar
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [camara]);

  // Escaneo: resuelve el código (SKU/EAN/código de variante) y agrega la variante exacta.
  const onScan = async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = scanCode.trim();
    if (!code) return;
    // se limpia YA: un lector de codigos escribe el siguiente codigo sin esperar a que responda el servidor
    setScanCode('');
    try {
      const res = await articulosApi.scan(code);
      agregar(res.data, res.data?.scan);
    } catch (_) {
      Swal.fire('No encontrado', `El código “${code}” no corresponde a ningún artículo.`, 'warning');
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
  const puedeCambioEfectivo = excedente > 0 && totalEfectivo >= excedente;
  // credito: el servidor bloquea si se rebasa el limite; aqui solo se muestra lo disponible
  const puedeCredito = cliente?.forma_pago === 'Credito' && !cliente?.es_publico_general && cliente?.is_active !== 'No';
  const creditoDisponible = puedeCredito ? Math.max(0, Number(cliente.limite_credito || 0) - Number(cliente.saldo_credito || 0)) : 0;
  const rebasaCredito = aCredito && restante > creditoDisponible + 0.005;
  // si cambian a un cliente sin credito, la venta deja de ser a credito
  useEffect(() => { if (!puedeCredito && aCredito) setACredito(false); }, [puedeCredito, aCredito]);
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

    // lineas tal como las registro el servidor (precio, lista e importe cobrados)
    const lineas = (venta.lineas || []).map((l) => ({
      cant: `${l.cantidad}x`,
      texto: `${l.codigo} ${l.descripcion || ''}`.trim(),
      detalle: `${[l.color, l.talla].filter(Boolean).join('/')} · ${money(l.precio_unitario)} · L${l.lista_aplicada}`,
      importe: l.importe,
    }));

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
    const sinDestino = pagos.map(faltaDestino).find(Boolean);
    if (sinDestino) { Swal.fire('Falta el destino del pago', sinDestino, 'warning'); return; }
    if (rebasaCredito) { Swal.fire('Límite de crédito', `El cliente solo tiene ${money(creditoDisponible)} de crédito disponible.`, 'warning'); return; }
    setBusy(true);
    try {
      const body = {
        id_almacen: idAlmacen,
        id_cliente: cliente._id,
        lineas: cart.map((c) => ({ id_articulo: c.art._id, id_color: c.id_color, id_talla: c.id_talla, cantidad: c.cantidad })),
        // cada pago lleva a donde va el dinero: terminal (tarjeta) o cuenta (transferencia / cheque)
        pagos: pagos.filter((p) => Number(p.importe) > 0).map((p) => ({ forma: p.forma, importe: Number(p.importe), ...destinoPayload(p) })),
        a_credito: aCredito,
        destino_cambio: destinoCambioFinal,
        id_vendedor: idVendedor,
      };
      const r = await ventasApi.crear(body);
      await imprimirTicketVenta(r.data);
      aviso(`Venta ${r.data.folio} registrada · ${money(r.data.total)}${r.data.cambio_efectivo ? ` · cambio ${money(r.data.cambio_efectivo)}` : ''}`);
      setCart([]); setPagos([{ forma: 'efectivo', importe: 0 }]); setCliente(null); setACredito(false); setIdVendedor(''); setDestinoCambio('efectivo');
    } catch (e) { Swal.fire('Error', e.message, 'error'); } finally { setBusy(false); }
  };

  // ---------- teclado del mostrador: F2 buscar, F4 cliente, F12 cobrar, Esc cierra el ticket ----------
  const cobrarRef = useRef(null);
  cobrarRef.current = cobrar;
  useEffect(() => {
    const tecla = (e) => {
      if (e.key === 'F2') { e.preventDefault(); scanRef.current?.focus(); }
      else if (e.key === 'F4') { e.preventDefault(); setOpenCli(true); setCliRes([]); }
      else if (e.key === 'F12') { e.preventDefault(); cobrarRef.current?.(); }
      else if (e.key === 'Escape') setTicketAbierto(false);
    };
    document.addEventListener('keydown', tecla);
    return () => document.removeEventListener('keydown', tecla);
  }, []);
  useEffect(() => { if (cart.length === 0) setTicketAbierto(false); }, [cart.length]);

  const piezas = cart.reduce((a, c) => a + (Number(c.cantidad) || 0), 0);
  const tiendaActual = tiendas.find((t) => t._id === idAlmacen);
  const setPago = (i, patch) => setPagos((ps) => ps.map((x, idx) => (idx === i ? { ...x, ...patch } : x)));
  // lo que falta cubrir sin contar el pago i (para el boton «Exacto»)
  const faltaSin = (i) => Math.max(0, total - pagos.reduce((a, p, idx) => a + (idx === i ? 0 : Number(p.importe) || 0), 0));

  // Panel de cobro: a la derecha en computadora y tablet horizontal; en hoja inferior en celular y tablet vertical
  const panelCobro = (
    <div className="flex flex-col gap-3">
      <div className="card p-4 space-y-3">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-bold uppercase tracking-[0.05em] text-slate-600">Cliente · F4</p>
            {cliente
              ? <p className="truncate text-base font-bold">{cliente.nombre}</p>
              : <p className="text-slate-600">Sin seleccionar</p>}
          </div>
          {cliente
            ? <span className="badge-slate shrink-0">Lista {cliente.lista_precios}</span>
            : null}
        </div>
        {cliente && (
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700">
            <span>Monedero <b>{money(cliente.saldo_favor)}</b></span>
            {puedeCredito ? <span>Crédito disponible <b>{money(creditoDisponible)}</b></span> : <span>Sin crédito</span>}
          </div>
        )}
        <button type="button" className="btn-secondary w-full" onClick={() => { setOpenCli(true); setCliRes([]); buscarCli(); }}>
          {cliente ? 'Cambiar cliente' : 'Seleccionar cliente'}
        </button>
        <label className="block text-xs font-bold uppercase tracking-[0.05em] text-slate-600">Vendedor *
          <select className="input-base mt-1 normal-case tracking-normal font-normal" value={idVendedor} onChange={(e) => setIdVendedor(e.target.value)}>
            <option value="">— Selecciona vendedor —</option>
            {vendedores.map((v) => <option key={v._id} value={v._id}>{v.nombre} {v.apellido || ''}</option>)}
          </select>
        </label>
      </div>

      <div className="rounded-2xl bg-slate-900 p-4 text-white">
        <div className="flex justify-between text-sm text-slate-300">
          <span>{piezas} {piezas === 1 ? 'pieza' : 'piezas'} · nivel lista {cot?.nivel_cantidad ?? 1}</span>
          <span>IVA incluido</span>
        </div>
        <div className="mt-1 flex items-baseline justify-between gap-3">
          <span className="text-lg">Total</span>
          <span className="text-4xl font-bold tracking-tight">{money(total)}</span>
        </div>
      </div>

      <div className="card p-4 space-y-4">
        {pagos.map((p, i) => (
          <div key={i} className={clsx('space-y-2', i > 0 && 'border-t border-slate-200 pt-4')}>
            <div className="flex items-center justify-between">
              <p className="text-xs font-bold uppercase tracking-[0.05em] text-slate-600">{pagos.length > 1 ? `Pago ${i + 1}` : 'Forma de pago'}</p>
              {pagos.length > 1 && (
                <button type="button" className="btn-ghost min-h-8 px-2 text-danger-600" onClick={() => setPagos((ps) => ps.filter((_, idx) => idx !== i))}>
                  <TrashIcon className="h-4 w-4" />Quitar
                </button>
              )}
            </div>
            <div className="grid grid-cols-3 gap-1.5">
              {FORMAS.map((f) => (
                <button key={f.v} type="button" aria-pressed={p.forma === f.v}
                  onClick={() => setPago(i, { forma: f.v, id_terminal: '', id_banco: '' })}
                  className={clsx('min-h-11 rounded-[10px] border px-1 text-[13px] font-semibold',
                    p.forma === f.v ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-300 bg-white text-slate-900 hover:bg-slate-100')}>
                  {f.l}
                </button>
              ))}
            </div>
            <label className="flex items-center gap-3">
              <span className="w-24 text-sm text-slate-700">Recibido</span>
              <input type="number" inputMode="decimal" min="0" step="0.01" className="input-base text-right text-lg font-semibold"
                value={p.importe} onFocus={(e) => e.target.select()}
                onChange={(e) => setPago(i, { importe: e.target.value })} />
            </label>
            {p.forma === 'efectivo' && (
              <div className="grid grid-cols-4 gap-1.5">
                <button type="button" className="btn-secondary px-1" onClick={() => setPago(i, { importe: faltaSin(i).toFixed(2) })}>Exacto</button>
                {[200, 500, 1000].map((b) => (
                  <button key={b} type="button" className="btn-secondary px-1" onClick={() => setPago(i, { importe: b })}>{money(b).replace('.00', '')}</button>
                ))}
              </div>
            )}
            <DestinoPago forma={p.forma} value={p} className="w-full" onChange={(d) => setPago(i, d)} />
          </div>
        ))}
        <button type="button" className="btn-ghost w-full" onClick={() => setPagos((ps) => [...ps, { forma: 'tdc', importe: restante ? restante.toFixed(2) : 0 }])}>
          + Pagar con otra forma
        </button>

        <div className="space-y-1 border-t border-slate-200 pt-3">
          <div className="flex justify-between text-sm"><span className="text-slate-700">Pagado</span><b>{money(totalPagado)}</b></div>
          {restante > 0 && !aCredito && <div className="flex justify-between text-lg font-bold text-danger-600"><span>Faltan</span><span>{money(restante)}</span></div>}
          {aCredito && restante > 0 && <div className="flex justify-between text-sm font-semibold"><span>A crédito</span><span>{money(restante)}</span></div>}
          {excedente > 0 && (
            <>
              <div className="flex justify-between text-lg font-bold text-success-600">
                <span>{puedeCambioEfectivo && destinoCambio === 'efectivo' ? 'Cambio' : 'Al monedero'}</span><span>{money(excedente)}</span>
              </div>
              {puedeCambioEfectivo && cliente && (
                <div className="grid grid-cols-2 gap-1.5">
                  {[['efectivo', 'Entregar cambio'], ['monedero', 'Al monedero']].map(([v, l]) => (
                    <button key={v} type="button" aria-pressed={destinoCambio === v} onClick={() => setDestinoCambio(v)}
                      className={clsx('min-h-10 rounded-[10px] border text-sm font-semibold',
                        destinoCambio === v ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white')}>{l}</button>
                  ))}
                </div>
              )}
            </>
          )}
          {puedeCredito && (
            <label className="mt-2 flex min-h-10 items-center gap-2 text-sm">
              <input type="checkbox" className="h-5 w-5" checked={aCredito} onChange={(e) => setACredito(e.target.checked)} />
              Lo que falte, a crédito
              {rebasaCredito && <span className="ml-auto font-semibold text-danger-600">Rebasa el límite</span>}
            </label>
          )}
        </div>
      </div>

      <button type="button" className="btn-primary min-h-16 w-full rounded-2xl text-xl font-bold" disabled={busy || cart.length === 0} onClick={cobrar}>
        {busy ? 'Procesando…' : <>Cobrar {money(total)}<kbd className="hidden lg:inline font-mono text-xs font-medium opacity-80">F12</kbd></>}
      </button>
    </div>
  );

  return (
    <div className="flex min-h-dvh flex-col bg-slate-50">
      {/* Barra del mostrador */}
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white pt-safe">
        <div className="flex min-h-16 flex-wrap items-center gap-3 px-4 py-2 lg:px-5">
          <Link to="/dashboard" className="btn-ghost w-11 px-0 lg:hidden" aria-label="Volver al inicio"><ChevronLeftIcon className="h-6 w-6" /></Link>
          <div className="flex min-w-0 items-center gap-2.5">
            <LogoTienda />
            <div className="min-w-0 leading-tight">
              <p className="truncate font-bold">{user?.tienda?.nombre || 'Punto de venta'}</p>
              {user?.id_tienda || tiendas.length <= 1
                ? <p className="truncate text-xs text-slate-600">{tiendaActual?.nombre || ''} · {user?.nombre}</p>
                : (
                  <select aria-label="Tienda" className="mt-0.5 rounded-md border border-slate-300 bg-white px-1.5 py-0.5 text-xs" value={idAlmacen} onChange={(e) => setIdAlmacen(e.target.value)}>
                    {tiendas.map((t) => <option key={t._id} value={t._id}>{t.nombre}</option>)}
                  </select>
                )}
            </div>
          </div>
          <div className="flex-1" />
          <div className="hidden gap-1.5 text-xs text-slate-600 xl:flex">
            {[['F2', 'buscar'], ['F4', 'cliente'], ['F12', 'cobrar']].map(([k, l]) => (
              <span key={k} className="rounded-md border border-slate-200 px-2 py-1"><b className="font-mono">{k}</b> {l}</span>
            ))}
          </div>
          <Link to="/dashboard" className="btn-secondary hidden lg:inline-flex">Salir del punto de venta</Link>
        </div>
      </header>

      <div className="flex-1 gap-5 p-4 pb-32 lg:grid lg:grid-cols-[minmax(0,1fr)_400px] lg:p-5 lg:pb-5">
        <section className="flex min-w-0 flex-col gap-4">
          {/* Escaner / busqueda */}
          <div className="card p-3 space-y-2">
            <div className="flex gap-2">
              <label className="flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-xl border-2 border-primary-600 bg-white px-4">
                <QrCodeIcon className="h-6 w-6 shrink-0 text-slate-600" />
                <span className="sr-only">Escanear código</span>
                <input ref={scanRef} autoFocus className="min-w-0 flex-1 bg-transparent text-lg outline-none"
                  placeholder="Escanea o teclea el código y Enter (F2)"
                  value={scanCode} onChange={(e) => setScanCode(e.target.value)} onKeyDown={onScan} />
              </label>
              <button type="button" onClick={abrirCamara} className="btn-secondary min-h-14 px-3 sm:px-4" aria-label="Escanear con la cámara">
                <CameraIcon className="h-6 w-6" /><span className="hidden sm:inline">Cámara</span>
              </button>
            </div>
            <ArticuloAutocomplete onSelect={agregar} placeholder="O busca por nombre…" />
          </div>

          {/* Articulos frecuentes de esta tienda (en este equipo) */}
          <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4">
            {frecuentes.map((f) => (
              <button key={f.art._id} type="button" onClick={() => agregar(f.art)}
                className="flex min-h-[92px] flex-col gap-1 rounded-[14px] border border-slate-200 bg-white p-3 text-left hover:border-slate-300 active:bg-slate-100">
                <span className="folio text-xs text-slate-600">{f.art.codigo}</span>
                <span className="line-clamp-2 text-sm font-semibold leading-snug">{f.art.descripcion}</span>
                <span className="mt-auto text-base font-bold">{f.art.es_oferta ? money(f.art.precio_oferta) : money(f.art.precios?.lista1)}</span>
              </button>
            ))}
            {frecuentes.length < 4 && (
              <div className="flex min-h-[92px] items-center justify-center rounded-[14px] border border-dashed border-slate-300 p-3 text-center text-sm text-slate-600">
                Aquí aparecerán los artículos que más vendes
              </div>
            )}
          </div>

          {/* Ticket */}
          <div className="card overflow-hidden">
            <div className="hidden grid-cols-[minmax(0,1fr)_132px_110px_44px] gap-3 border-b border-slate-200 px-4 py-2.5 text-xs font-bold uppercase tracking-[0.04em] text-slate-600 sm:grid">
              <span>Artículo</span><span className="text-center">Cantidad</span><span className="text-right">Importe</span><span />
            </div>
            {cart.length === 0 && <p className="px-4 py-10 text-center text-slate-600">Escanea un artículo o toca uno de los frecuentes para empezar.</p>}
            {cart.map((it, i) => {
              const calc = cot?.lineas?.[i];
              const poco = calc && calc.disponible !== null && calc.disponible !== undefined && Number(it.cantidad) > Number(calc.disponible);
              return (
                <div key={i} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 border-b border-slate-100 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_132px_110px_44px]">
                  <div className="min-w-0">
                    <p className="truncate font-semibold">{it.art.descripcion}</p>
                    <p className="text-[13px] text-slate-600">
                      <span className="folio">{it.art.codigo}</span>
                      {calc ? <> · {money(calc.precio_unitario)} · Lista {calc.lista_aplicada}</> : null}
                    </p>
                    {(it.art.colores?.length > 0 || it.art.tallas?.length > 0) && (
                      <div className="mt-1"><VariantesInline art={it.art} id_color={it.id_color} id_talla={it.id_talla} onChange={(patch) => upd(i, patch)} /></div>
                    )}
                    {poco && <p className="mt-1 text-xs font-semibold text-warning-700">Solo {Number(calc.disponible)} libres (el resto está apartado o no hay)</p>}
                  </div>
                  <div className="row-span-2 flex items-center justify-end gap-1.5 sm:row-span-1 sm:justify-center">
                    <button type="button" aria-label="Quitar una pieza" className="btn-secondary w-10 px-0 text-lg"
                      onClick={() => (Number(it.cantidad) > 1 ? upd(i, { cantidad: Number(it.cantidad) - 1 }) : quitar(i))}>−</button>
                    <input aria-label="Cantidad" type="number" min="1" inputMode="numeric" className="w-12 rounded-md border border-transparent text-center font-bold focus:border-slate-300"
                      value={it.cantidad} onChange={(e) => upd(i, { cantidad: Math.max(1, Number(e.target.value) || 1) })} />
                    <button type="button" aria-label="Agregar una pieza" className="btn-secondary w-10 px-0 text-lg"
                      onClick={() => upd(i, { cantidad: Number(it.cantidad) + 1 })}>+</button>
                  </div>
                  <p className="text-right font-bold sm:text-base">{calc ? money(calc.importe) : '—'}</p>
                  <button type="button" aria-label="Quitar del ticket" className="btn-ghost hidden w-11 px-0 text-danger-600 sm:inline-flex" onClick={() => quitar(i)}>
                    <TrashIcon className="h-5 w-5" />
                  </button>
                </div>
              );
            })}
          </div>
        </section>

        <aside className="hidden lg:block">{panelCobro}</aside>
      </div>

      {/* Celular y tablet vertical: barra del ticket abajo y hoja de cobro */}
      {!ticketAbierto && (
        <div className="fixed inset-x-0 bottom-0 z-30 px-3 pb-safe lg:hidden">
          <button type="button" onClick={() => setTicketAbierto(true)} disabled={cart.length === 0}
            className="mb-3 flex min-h-[60px] w-full items-center gap-3 rounded-2xl bg-slate-900 px-4 text-white shadow-lg disabled:opacity-60">
            <span className="flex h-7 min-w-7 items-center justify-center rounded-full bg-primary-600 px-1.5 text-sm font-bold">{piezas}</span>
            <span className="flex-1 text-left">{cart.length ? 'Ver ticket y cobrar' : 'Ticket vacío'}</span>
            <span className="text-xl font-bold">{money(total)}</span>
          </button>
        </div>
      )}
      {ticketAbierto && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Ticket y cobro">
          <div className="absolute inset-0 bg-slate-900/45" onClick={() => setTicketAbierto(false)} />
          <div className="absolute inset-x-0 bottom-0 max-h-[92dvh] overflow-y-auto rounded-t-3xl bg-slate-50 px-3 pt-2 pb-safe animate-slide-in-up">
            <div className="sticky top-0 z-10 -mx-3 mb-2 flex items-center justify-between bg-slate-50 px-4 pb-2 pt-1">
              <span aria-hidden="true" className="absolute left-1/2 top-0 h-1.5 w-10 -translate-x-1/2 rounded-full bg-slate-300" />
              <h2 className="pt-3 text-lg font-bold">Cobrar</h2>
              <button type="button" className="btn-ghost mt-2" onClick={() => setTicketAbierto(false)}>Seguir agregando</button>
            </div>
            <div className="pb-3">{panelCobro}</div>
          </div>
        </div>
      )}

      {/* Escaneo con la camara (celular / tablet) */}
      <Modal open={camara} onClose={() => setCamara(false)} title="Escanear con la cámara" size="md">
        <BarcodeScannerView />
      </Modal>

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
