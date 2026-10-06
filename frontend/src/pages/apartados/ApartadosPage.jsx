import { useState, useEffect, useCallback, useMemo } from 'react';
import { PlusIcon, TrashIcon, InformationCircleIcon, EyeIcon, DocumentTextIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import { VariantesInline } from '../../components/common/MatrizColorTalla';
import { useAuth } from '../../contexts/AuthContext';
import { printTicket } from '../../utils/ticket';
import {
  apartadosApi,
  clientesApi,
  almacenesApi,
  empleadosApi,
  articulosApi,
  ventasApi,
} from '../../services/api/endpoints';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');

const FORMAS = [
  { v: 'efectivo', l: 'Efectivo' },
  { v: 'tdc', l: 'T. Crédito' },
  { v: 'tdb', l: 'T. Débito' },
  { v: 'transferencia', l: 'Transferencia' },
];

const ESTADO_BADGE = {
  vigente: 'badge-primary',
  con_anticipo: 'badge-accent',
  vencido: 'badge-danger',
  liquidado: 'badge-success',
  cancelado: 'badge-slate',
};
const ESTADO_LABEL = {
  vigente: 'Vigente',
  con_anticipo: 'Con anticipo',
  vencido: 'Vencido',
  liquidado: 'Liquidado',
  cancelado: 'Cancelado',
};

const emptyLinea = () => ({
  art: null,
  id_articulo: '',
  codigo: '',
  descripcion: '',
  id_color: '',
  id_talla: '',
  cantidad: 1,
  precio_unitario: 0,
});

export default function ApartadosPage() {
  const { hasPermiso } = useAuth();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  // Filtros de la lista
  const [fVendedor, setFVendedor] = useState('');
  const [fEstado, setFEstado] = useState('');
  const [fDesde, setFDesde] = useState('');
  const [fHasta, setFHasta] = useState('');

  const [clientes, setClientes] = useState([]);
  const [almacenes, setAlmacenes] = useState([]);
  const [empleados, setEmpleados] = useState([]);

  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    id_cliente: '',
    id_almacen: '',
    id_vendedor: '',
    notas: '',
    lineas: [emptyLinea()],
  });
  const [cot, setCot] = useState(null);
  const [detalle, setDetalle] = useState(null);
  const [detalleLoading, setDetalleLoading] = useState(false);

  // Modales de anticipo y liquidación
  const [anticipoRow, setAnticipoRow] = useState(null);
  const [anticipoData, setAnticipoData] = useState({ importe: '', forma: 'efectivo' });
  const [liquidarRow, setLiquidarRow] = useState(null);
  const [liquidarPagos, setLiquidarPagos] = useState([{ forma: 'efectivo', importe: 0 }]);
  const [destinoCambioLiq, setDestinoCambioLiq] = useState('efectivo'); // 'efectivo' | 'monedero'
  const [procesando, setProcesando] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (fVendedor) params.id_vendedor = fVendedor;
      if (fEstado) params.estado = fEstado;
      if (fDesde) params.desde = fDesde;
      if (fHasta) params.hasta = fHasta;
      const res = await apartadosApi.listar(params);
      setRows(res.data || []);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo cargar', 'error');
    } finally {
      setLoading(false);
    }
  }, [fVendedor, fEstado, fDesde, fHasta]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    (async () => {
      try {
        const [c, a, e] = await Promise.all([
          clientesApi.listar(),
          almacenesApi.listar(),
          empleadosApi.listar(),
        ]);
        setClientes(c.data?.docs ?? c.data ?? []);
        // Apartados sólo en tiendas.
        setAlmacenes((a.data?.docs ?? a.data ?? []).filter((x) => x.tipo === 'tienda'));
        setEmpleados((e.data?.docs ?? e.data ?? []).filter((x) => x.es_vendedor));
      } catch (_) {
        /* noop */
      }
    })();
  }, []);

  // Vendedores de la tienda seleccionada (o sin tienda asignada).
  const vendedores = useMemo(
    () =>
      empleados.filter((e) => {
        const t = e.id_tienda?._id || e.id_tienda;
        return !t || String(t) === String(form.id_almacen);
      }),
    [empleados, form.id_almacen]
  );

  // Cotización en vivo (mismo motor de precios que la venta de tienda).
  useEffect(() => {
    const completas = form.lineas.filter((l) => l.id_articulo && Number(l.cantidad) > 0);
    if (completas.length === 0) {
      setCot(null);
      return;
    }
    const lineas = completas.map((l) => ({ id_articulo: l.id_articulo, cantidad: Number(l.cantidad) }));
    ventasApi
      .cotizar({ id_cliente: form.id_cliente || undefined, lineas })
      .then((r) => setCot(r.data))
      .catch(() => setCot(null));
  }, [form.lineas, form.id_cliente]);

  // Enriquecer cada línea con su precio cotizado (por orden de líneas completas).
  const lineasConPrecio = useMemo(() => {
    let k = 0;
    return form.lineas.map((l) => {
      if (l.id_articulo && Number(l.cantidad) > 0) {
        const c = cot?.lineas?.[k];
        k += 1;
        return { ...l, _precio: c?.precio_unitario, _lista: c?.lista_aplicada, _importe: c?.importe };
      }
      return { ...l, _precio: undefined, _lista: undefined, _importe: undefined };
    });
  }, [form.lineas, cot]);

  const totalForm =
    cot?.total ??
    form.lineas.reduce((s, l) => s + Number(l.cantidad || 0) * Number(l.precio_unitario || 0), 0);

  function abrirForm() {
    setEditId(null);
    setForm({ id_cliente: '', id_almacen: '', id_vendedor: '', notas: '', lineas: [emptyLinea()] });
    setCot(null);
    setShowForm(true);
  }

  async function abrirEditar(row) {
    try {
      const full = (await apartadosApi.obtener(row._id)).data;
      const arts = await Promise.all(
        (full.lineas || []).map((l) =>
          articulosApi
            .obtener(l.id_articulo?._id || l.id_articulo)
            .then((r) => r.data)
            .catch(() => null)
        )
      );
      const lineas = (full.lineas || []).map((l, i) => ({
        art: arts[i],
        id_articulo: l.id_articulo?._id || l.id_articulo,
        codigo: l.codigo,
        descripcion: l.descripcion,
        id_color: l.id_color?._id || l.id_color || '',
        id_talla: l.id_talla?._id || l.id_talla || '',
        cantidad: l.cantidad,
        precio_unitario: l.precio_unitario,
      }));
      setForm({
        id_cliente: full.id_cliente?._id || full.id_cliente || '',
        id_almacen: full.id_almacen?._id || full.id_almacen || '',
        id_vendedor: full.id_vendedor?._id || full.id_vendedor || '',
        notas: full.notas || '',
        lineas: lineas.length ? lineas : [emptyLinea()],
      });
      setEditId(row._id);
      setShowForm(true);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo abrir el apartado', 'error');
    }
  }

  function setLinea(i, patch) {
    setForm((f) => ({
      ...f,
      lineas: f.lineas.map((l, idx) => (idx === i ? { ...l, ...patch } : l)),
    }));
  }
  function addLinea() {
    setForm((f) => ({ ...f, lineas: [...f.lineas, emptyLinea()] }));
  }
  function removeLinea(i) {
    setForm((f) => ({ ...f, lineas: f.lineas.filter((_, idx) => idx !== i) }));
  }

  async function handleGuardar(e) {
    e.preventDefault();
    if (!editId && (!form.id_cliente || !form.id_almacen)) {
      Swal.fire('Faltan datos', 'Selecciona cliente y tienda', 'warning');
      return;
    }
    if (!editId && !form.id_vendedor) {
      Swal.fire('Falta vendedor', 'El apartado debe llevar vendedor', 'warning');
      return;
    }
    const completas = lineasConPrecio.filter((l) => l.id_articulo && Number(l.cantidad) > 0);
    const lineas = completas.map((l) => ({
      id_articulo: l.id_articulo,
      id_color: l.id_color || undefined,
      id_talla: l.id_talla || undefined,
      cantidad: Number(l.cantidad),
      // El backend recotiza con el motor de precios; mandamos el cotizado para el validador.
      precio_unitario: Number(l._precio ?? l.precio_unitario ?? 0),
    }));
    if (lineas.length === 0) {
      Swal.fire('Faltan líneas', 'Agrega al menos un artículo', 'warning');
      return;
    }
    setSaving(true);
    try {
      let res;
      if (editId) {
        res = await apartadosApi.editar(editId, { lineas, notas: form.notas });
      } else {
        res = await apartadosApi.crear({
          id_cliente: form.id_cliente,
          id_almacen: form.id_almacen,
          id_vendedor: form.id_vendedor,
          lineas,
          notas: form.notas,
        });
      }
      if (res.success === false) throw new Error(res.message);
      setShowForm(false);
      cargar();
      Swal.fire('Listo', editId ? 'Apartado actualizado' : 'Apartado creado', 'success');
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo guardar', 'error');
    } finally {
      setSaving(false);
    }
  }

  // ---- Anticipo ----
  function abrirAnticipo(row) {
    setAnticipoRow(row);
    setAnticipoData({ importe: '', forma: 'efectivo' });
  }
  async function guardarAnticipo() {
    const importe = Number(anticipoData.importe);
    if (!importe || importe <= 0) return Swal.fire('Importe inválido', '', 'warning');
    setProcesando(true);
    try {
      const res = await apartadosApi.anticipo(anticipoRow._id, { importe, forma: anticipoData.forma });
      if (res.success === false) throw new Error(res.message);
      setAnticipoRow(null);
      cargar();
      Swal.fire('Listo', 'Anticipo registrado', 'success');
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo registrar', 'error');
    } finally {
      setProcesando(false);
    }
  }

  // ---- Liquidar ----
  function abrirLiquidar(row) {
    const restante = Math.max(0, (row.total || 0) - (row.anticipo || 0));
    setLiquidarRow(row);
    setLiquidarPagos([{ forma: 'efectivo', importe: restante }]);
    setDestinoCambioLiq('efectivo');
  }
  const liquidarPagado = liquidarPagos.reduce((a, p) => a + (Number(p.importe) || 0), 0);
  const liquidarRestante = liquidarRow ? Math.max(0, (liquidarRow.total || 0) - (liquidarRow.anticipo || 0)) : 0;
  const liquidarExcedente = Math.max(0, liquidarPagado - liquidarRestante);
  // Solo se puede devolver cambio en efectivo si el efectivo cobrado hoy lo cubre.
  const liquidarEfectivoHoy = liquidarPagos.filter((p) => p.forma === 'efectivo').reduce((a, p) => a + (Number(p.importe) || 0), 0);
  const liquidarPuedeCambio = liquidarExcedente > 0 && liquidarEfectivoHoy >= liquidarExcedente;
  const liquidarDestinoFinal = liquidarPuedeCambio && destinoCambioLiq === 'efectivo' ? 'efectivo' : 'monedero';
  async function guardarLiquidacion() {
    if (liquidarPagado < liquidarRestante - 0.001)
      return Swal.fire('Pago insuficiente', `Falta cubrir ${money(liquidarRestante - liquidarPagado)}`, 'warning');
    setProcesando(true);
    try {
      const pagos = liquidarPagos.filter((p) => Number(p.importe) > 0).map((p) => ({ forma: p.forma, importe: Number(p.importe) }));
      const res = await apartadosApi.liquidar(liquidarRow._id, { pagos, destino_cambio: liquidarDestinoFinal });
      if (res.success === false) throw new Error(res.message);
      setLiquidarRow(null);
      cargar();
      Swal.fire('Listo', 'Apartado liquidado (venta generada)', 'success');
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo liquidar', 'error');
    } finally {
      setProcesando(false);
    }
  }

  async function handleCancelar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Cancelar apartado?',
      html:
        Number(row.anticipo) > 0
          ? `Folio <b>${row.folio}</b>. El anticipo de <b>${money(row.anticipo)}</b> se abonará al monedero del cliente (saldo a favor).`
          : `Folio <b>${row.folio}</b>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e11d48',
      confirmButtonText: 'Cancelar apartado',
    });
    if (!isConfirmed) return;
    try {
      const res = await apartadosApi.cancelar(row._id);
      if (res.success === false) throw new Error(res.message);
      cargar();
      Swal.fire('Listo', 'Apartado cancelado', 'success');
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo cancelar', 'error');
    }
  }

  // Ver detalle (solo lectura) de cualquier apartado, en cualquier estado
  // (incluidos vencidos, liquidados y cancelados).
  async function abrirDetalle(row) {
    setDetalle(row); // muestra de inmediato lo que ya tenemos
    setDetalleLoading(true);
    try {
      const full = (await apartadosApi.obtener(row._id)).data;
      setDetalle(full ?? row);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo abrir el detalle', 'error');
      setDetalle(null);
    } finally {
      setDetalleLoading(false);
    }
  }

  // Genera una nota tipo cotización (imprimible/PDF) con el detalle del apartado,
  // para enviar al cliente. NO liquida ni cierra la venta.
  async function generarNota(row) {
    try {
      const full = (await apartadosApi.obtener(row._id)).data;
      const lineas = (full.lineas || []).map((l) => ({
        cant: `${l.cantidad}x`,
        texto: `${l.codigo || ''} ${l.descripcion || ''}`.trim(),
        detalle: `${l.id_color?.nombre || '—'}/${l.id_talla?.nombre || '—'} · ${money(l.precio_unitario)}`,
        importe: l.importe ?? Number(l.cantidad || 0) * Number(l.precio_unitario || 0),
      }));
      const total = full.total || 0;
      const anticipo = full.anticipo || 0;
      const saldo = Math.max(0, total - anticipo);
      printTicket({
        tipo: 'COTIZACIÓN · APARTADO',
        tienda: full.id_almacen || {},
        folio: full.folio,
        fecha: full.fecha || new Date(),
        cliente: full.id_cliente?.nombre,
        vendedor: full.id_vendedor
          ? `${full.id_vendedor.nombre} ${full.id_vendedor.apellido || ''}`.trim()
          : '',
        secciones: [{ titulo: 'Artículos apartados', lineas }],
        totales: [
          { label: 'Total', value: total, fuerte: true },
          { label: 'Anticipo pagado', value: anticipo },
          { label: 'Saldo pendiente', value: saldo, fuerte: true },
        ],
        nota: `Cotización informativa — no es comprobante de pago. El apartado sigue vigente hasta el ${fecha(full.fecha_limite)}.`,
      });
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo generar la nota', 'error');
    }
  }

  const columns = [
    { key: 'folio', label: 'Folio' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha) },
    { key: 'cliente', label: 'Cliente', render: (r) => r.id_cliente?.nombre || '—' },
    {
      key: 'vendedor',
      label: 'Vendedor',
      render: (r) => (r.id_vendedor ? `${r.id_vendedor.nombre} ${r.id_vendedor.apellido || ''}`.trim() : '—'),
    },
    { key: 'total', label: 'Total', render: (r) => money(r.total) },
    { key: 'anticipo', label: 'Anticipo', render: (r) => money(r.anticipo) },
    {
      key: 'estado',
      label: 'Estado',
      render: (r) => (
        <span className={ESTADO_BADGE[r.estado] || 'badge-slate'}>
          {ESTADO_LABEL[r.estado] || r.estado}
        </span>
      ),
    },
    { key: 'fecha_limite', label: 'Límite', render: (r) => fecha(r.fecha_limite) },
    {
      key: 'acciones',
      label: 'Acciones',
      render: (r) => {
        const activo = ['vigente', 'con_anticipo'].includes(r.estado);
        return (
          <div className="flex flex-wrap gap-1.5">
            <button onClick={() => abrirDetalle(r)} className="btn-ghost p-1.5 text-sky-600" title="Ver detalle del apartado">
              <EyeIcon className="w-5 h-5" />
            </button>
            <button onClick={() => generarNota(r)} className="btn-ghost p-1.5 text-slate-600" title="Cotización para el cliente">
              <DocumentTextIcon className="w-5 h-5" />
            </button>
            {activo && hasPermiso('apartados.editar') && (
              <button onClick={() => abrirEditar(r)} className="btn-outline px-2 py-1 text-xs">
                Editar
              </button>
            )}
            {activo && hasPermiso('apartados.anticipo') && (
              <button onClick={() => abrirAnticipo(r)} className="btn-outline px-2 py-1 text-xs">
                Anticipo
              </button>
            )}
            {activo && hasPermiso('apartados.liquidar') && (
              <button onClick={() => abrirLiquidar(r)} className="btn-accent px-2 py-1 text-xs">
                Liquidar
              </button>
            )}
            {activo && hasPermiso('apartados.cancelar') && (
              <button onClick={() => handleCancelar(r)} className="btn-danger px-2 py-1 text-xs">
                Cancelar
              </button>
            )}
          </div>
        );
      },
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Apartados</h1>
        {hasPermiso('apartados.crear') && (
          <button onClick={abrirForm} className="btn-primary flex items-center gap-1.5">
            <PlusIcon className="w-4 h-4" /> Nuevo apartado
          </button>
        )}
      </div>

      <div className="card p-3 flex items-start gap-2 bg-slate-50 border border-slate-200">
        <InformationCircleIcon className="w-5 h-5 text-primary-500 shrink-0 mt-0.5" />
        <p className="text-sm text-slate-600">
          El vendedor crea el apartado; el cajero cobra los anticipos y liquida. Cada anticipo entra al
          corte de la tienda el día que se cobra; al liquidar se genera la venta de la tienda. Día 1–6 sin
          costo, día 7–12 requiere 50% de anticipo, día 13+ se libera la mercancía y el anticipo queda como
          saldo a favor.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">Vendedor(a)</label>
          <select className="input-base max-w-[12rem]" value={fVendedor} onChange={(e) => setFVendedor(e.target.value)}>
            <option value="">Todas</option>
            {empleados.map((v) => (
              <option key={v._id} value={v._id}>{v.nombre} {v.apellido || ''}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">Estado</label>
          <select className="input-base max-w-[10rem]" value={fEstado} onChange={(e) => setFEstado(e.target.value)}>
            <option value="">Todos</option>
            {Object.entries(ESTADO_LABEL).map(([k, l]) => (
              <option key={k} value={k}>{l}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">Desde</label>
          <input type="date" className="input-base" value={fDesde} onChange={(e) => setFDesde(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">Hasta</label>
          <input type="date" className="input-base" value={fHasta} onChange={(e) => setFHasta(e.target.value)} />
        </div>
        {(fVendedor || fEstado || fDesde || fHasta) && (
          <button
            type="button"
            className="btn-ghost text-xs text-sky-600 mb-1"
            onClick={() => { setFVendedor(''); setFEstado(''); setFDesde(''); setFHasta(''); }}
          >
            Limpiar filtros
          </button>
        )}
      </div>

      <DataTable columns={columns} data={rows} loading={loading} empty="Sin apartados" exportName="apartados" exportTitle="Apartados" />

      {/* ---- Form crear / editar ---- */}
      <Modal
        open={showForm}
        onClose={() => setShowForm(false)}
        title={editId ? 'Editar apartado' : 'Nuevo apartado'}
        size="xl"
        footer={
          <>
            <button type="button" onClick={() => setShowForm(false)} className="btn-secondary">
              Cancelar
            </button>
            <button type="submit" form="form-apartado" disabled={saving} className="btn-primary">
              {saving ? 'Guardando…' : editId ? 'Guardar cambios' : 'Crear apartado'}
            </button>
          </>
        }
      >
        <form id="form-apartado" onSubmit={handleGuardar} className="space-y-4">
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Cliente *</label>
              <select
                className="input-base"
                value={form.id_cliente}
                onChange={(e) => setForm({ ...form, id_cliente: e.target.value })}
                disabled={!!editId}
                required
              >
                <option value="">Selecciona…</option>
                {clientes.map((c) => (
                  <option key={c._id} value={c._id}>
                    {c.nombre} {c.lista_precios ? `(L${c.lista_precios})` : ''}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Tienda *</label>
              <select
                className="input-base"
                value={form.id_almacen}
                onChange={(e) => setForm({ ...form, id_almacen: e.target.value, id_vendedor: '' })}
                disabled={!!editId}
                required
              >
                <option value="">Selecciona…</option>
                {almacenes.map((a) => (
                  <option key={a._id} value={a._id}>
                    {a.nombre}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Vendedor *</label>
              <select
                className="input-base"
                value={form.id_vendedor}
                onChange={(e) => setForm({ ...form, id_vendedor: e.target.value })}
                disabled={!!editId}
                required={!editId}
              >
                <option value="">Selecciona…</option>
                {vendedores.map((v) => (
                  <option key={v._id} value={v._id}>
                    {v.nombre} {v.apellido || ''}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="block text-sm font-medium text-slate-700">Líneas</label>
              <button type="button" onClick={addLinea} className="btn-ghost text-xs flex items-center gap-1">
                <PlusIcon className="w-4 h-4" /> Agregar línea
              </button>
            </div>
            <div className="space-y-2">
              {lineasConPrecio.map((l, i) =>
                l.art ? (
                  <div key={i} className="grid grid-cols-12 gap-2 items-center">
                    <span className="col-span-3 text-sm text-slate-700 truncate" title={l.descripcion}>
                      <b>{l.codigo}</b> — {l.descripcion}
                    </span>
                    <div className="col-span-3">
                      <VariantesInline
                        art={l.art}
                        id_color={l.id_color}
                        id_talla={l.id_talla}
                        size="md"
                        onChange={(patch) => setLinea(i, patch)}
                      />
                    </div>
                    <input
                      type="number"
                      min="1"
                      className="input-base col-span-1"
                      placeholder="Cant."
                      value={l.cantidad}
                      onChange={(e) => setLinea(i, { cantidad: e.target.value })}
                    />
                    <span className="col-span-1 text-xs text-center">
                      {l._lista != null ? <span className="badge-primary">{l._lista}</span> : ''}
                    </span>
                    <span className="col-span-2 text-sm text-right text-slate-600">
                      {l._precio != null ? money(l._precio) : '—'}
                    </span>
                    <div className="col-span-2 flex items-center justify-between gap-1">
                      <span className="text-sm text-slate-800 font-medium">
                        {l._importe != null
                          ? money(l._importe)
                          : money(Number(l.cantidad || 0) * Number(l.precio_unitario || 0))}
                      </span>
                      {form.lineas.length > 1 && (
                        <button type="button" onClick={() => removeLinea(i)} className="btn-ghost p-1 text-danger-600">
                          <TrashIcon className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </div>
                ) : (
                  <div key={i} className="grid grid-cols-12 gap-2 items-center">
                    <div className="col-span-10">
                      <ArticuloAutocomplete
                        placeholder="Buscar artículo…"
                        onSelect={(art) =>
                          setLinea(i, {
                            art,
                            id_articulo: art._id,
                            codigo: art.codigo,
                            descripcion: art.descripcion,
                            id_color: art.colores?.[0]?._id || '',
                            id_talla: art.tallas?.[0]?._id || '',
                            cantidad: 1,
                          })
                        }
                      />
                    </div>
                    <div className="col-span-2 flex justify-end">
                      {form.lineas.length > 1 && (
                        <button type="button" onClick={() => removeLinea(i)} className="btn-ghost p-1 text-danger-600">
                          <TrashIcon className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </div>
                )
              )}
            </div>
            <div className="flex justify-end mt-2 text-sm font-semibold text-slate-800">
              Total: {money(totalForm)}
            </div>
            <p className="text-xs text-slate-400 mt-1">
              Los precios se calculan con las listas (cliente / por cantidad), igual que la venta de tienda.
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Notas</label>
            <textarea
              className="input-base min-h-[60px] resize-y"
              value={form.notas}
              onChange={(e) => setForm({ ...form, notas: e.target.value })}
            />
          </div>
        </form>
      </Modal>

      {/* ---- Anticipo ---- */}
      <Modal
        open={!!anticipoRow}
        onClose={() => setAnticipoRow(null)}
        title="Registrar anticipo"
        size="md"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setAnticipoRow(null)}>Cancelar</button>
            <button className="btn-primary" disabled={procesando} onClick={guardarAnticipo}>
              {procesando ? 'Guardando…' : 'Registrar'}
            </button>
          </>
        }
      >
        {anticipoRow && (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">
              Folio <b>{anticipoRow.folio}</b> · Total {money(anticipoRow.total)} · Anticipo actual{' '}
              {money(anticipoRow.anticipo)} · Mínimo 50% ({money((anticipoRow.total || 0) * 0.5)})
            </p>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium text-slate-500 mb-1">Importe *</label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  className="input-base"
                  value={anticipoData.importe}
                  onChange={(e) => setAnticipoData({ ...anticipoData, importe: e.target.value })}
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-slate-500 mb-1">Forma de pago</label>
                <select
                  className="input-base"
                  value={anticipoData.forma}
                  onChange={(e) => setAnticipoData({ ...anticipoData, forma: e.target.value })}
                >
                  {FORMAS.map((f) => (
                    <option key={f.v} value={f.v}>{f.l}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        )}
      </Modal>

      {/* ---- Liquidar ---- */}
      <Modal
        open={!!liquidarRow}
        onClose={() => setLiquidarRow(null)}
        title="Liquidar apartado"
        size="md"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setLiquidarRow(null)}>Cancelar</button>
            <button className="btn-primary" disabled={procesando} onClick={guardarLiquidacion}>
              {procesando ? 'Procesando…' : 'Liquidar y generar venta'}
            </button>
          </>
        }
      >
        {liquidarRow && (
          <div className="space-y-3">
            <div className="text-sm text-slate-600 space-y-0.5">
              <div>Folio <b>{liquidarRow.folio}</b> · Total {money(liquidarRow.total)}</div>
              <div>Anticipo aplicado: {money(liquidarRow.anticipo)}</div>
              <div className="font-semibold text-slate-800">Saldo a cobrar: {money(liquidarRestante)}</div>
            </div>
            <div className="space-y-2">
              <label className="block text-xs font-medium text-slate-500">Pago del saldo</label>
              {liquidarPagos.map((p, i) => (
                <div key={i} className="flex gap-2">
                  <select
                    className="input-base !py-1 text-sm flex-1"
                    value={p.forma}
                    onChange={(e) => setLiquidarPagos((ps) => ps.map((x, idx) => (idx === i ? { ...x, forma: e.target.value } : x)))}
                  >
                    {FORMAS.map((f) => <option key={f.v} value={f.v}>{f.l}</option>)}
                  </select>
                  <input
                    type="number"
                    className="input-base !py-1 text-sm w-32"
                    value={p.importe}
                    onChange={(e) => setLiquidarPagos((ps) => ps.map((x, idx) => (idx === i ? { ...x, importe: e.target.value } : x)))}
                  />
                  {liquidarPagos.length > 1 && (
                    <button className="btn-ghost text-rose-600 p-1" onClick={() => setLiquidarPagos((ps) => ps.filter((_, idx) => idx !== i))}>
                      <TrashIcon className="w-4 h-4" />
                    </button>
                  )}
                </div>
              ))}
              <button className="btn-ghost text-xs" onClick={() => setLiquidarPagos((ps) => [...ps, { forma: 'efectivo', importe: 0 }])}>
                + Agregar pago
              </button>
              <div className="flex justify-between text-sm">
                <span className="text-slate-500">Pagado</span>
                <span>{money(liquidarPagado)}</span>
              </div>
              {liquidarExcedente > 0 && (
                liquidarPuedeCambio ? (
                  <div className="space-y-1">
                    <div className="flex gap-2">
                      <button
                        type="button"
                        className={`flex-1 text-xs rounded-md py-1.5 border ${destinoCambioLiq === 'efectivo' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300'}`}
                        onClick={() => setDestinoCambioLiq('efectivo')}
                      >Devolver cambio</button>
                      <button
                        type="button"
                        className={`flex-1 text-xs rounded-md py-1.5 border ${destinoCambioLiq === 'monedero' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300'}`}
                        onClick={() => setDestinoCambioLiq('monedero')}
                      >A monedero</button>
                    </div>
                    <div className="flex justify-between text-sm text-emerald-600">
                      <span>{destinoCambioLiq === 'efectivo' ? 'Cambio a entregar' : 'Excedente → monedero'}</span>
                      <span>{money(liquidarExcedente)}</span>
                    </div>
                  </div>
                ) : (
                  <div className="flex justify-between text-sm text-emerald-600">
                    <span>Excedente → monedero</span>
                    <span>{money(liquidarExcedente)}</span>
                  </div>
                )
              )}
            </div>
          </div>
        )}
      </Modal>

      {/* ---- Detalle (solo lectura) ---- */}
      <Modal
        open={!!detalle}
        onClose={() => setDetalle(null)}
        title={detalle ? `Detalle de apartado · ${detalle.folio || ''}` : 'Detalle de apartado'}
        size="xl"
        footer={<button className="btn-secondary" onClick={() => setDetalle(null)}>Cerrar</button>}
      >
        {detalle && (
          <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
              <div>
                <p className="text-xs font-medium text-slate-500">Cliente</p>
                <p className="text-slate-800">{detalle.id_cliente?.nombre || '—'}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Tienda</p>
                <p className="text-slate-800">{detalle.id_almacen?.nombre || '—'}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Vendedor</p>
                <p className="text-slate-800">
                  {detalle.id_vendedor ? `${detalle.id_vendedor.nombre || ''} ${detalle.id_vendedor.apellido || ''}`.trim() : '—'}
                </p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Fecha</p>
                <p className="text-slate-800">{fecha(detalle.fecha)}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Límite</p>
                <p className="text-slate-800">{fecha(detalle.fecha_limite)}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Estado</p>
                <p><span className={ESTADO_BADGE[detalle.estado] || 'badge-slate'}>{ESTADO_LABEL[detalle.estado] || detalle.estado}</span></p>
              </div>
            </div>

            <div>
              <p className="text-sm font-semibold text-slate-700 mb-2">Artículos</p>
              <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Código</th>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Descripción</th>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Color</th>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Talla</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Cant.</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">P. Unit.</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                      {detalleLoading ? (
                        <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-400">Cargando…</td></tr>
                      ) : (detalle.lineas || []).length === 0 ? (
                        <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-400">Sin artículos</td></tr>
                      ) : (
                        detalle.lineas.map((l, i) => (
                          <tr key={i}>
                            <td className="px-3 py-2 font-medium text-slate-800">{l.codigo || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{l.descripcion || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{l.id_color?.nombre || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{l.id_talla?.nombre || '—'}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{l.cantidad}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{money(l.precio_unitario)}</td>
                            <td className="px-3 py-2 text-right font-medium text-slate-800">
                              {money(l.importe ?? Number(l.cantidad || 0) * Number(l.precio_unitario || 0))}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            {detalle.notas && (
              <div>
                <p className="text-xs font-medium text-slate-500 mb-1">Notas</p>
                <p className="text-sm text-slate-700 whitespace-pre-wrap">{detalle.notas}</p>
              </div>
            )}

            <div className="flex flex-col items-end gap-0.5 border-t border-slate-100 pt-3 text-sm">
              <span className="text-slate-500">Total: <span className="font-medium text-slate-800">{money(detalle.total)}</span></span>
              <span className="text-slate-500">Anticipo: <span className="font-medium text-slate-800">{money(detalle.anticipo)}</span></span>
              <span className="text-slate-500">Saldo pendiente: <span className="font-semibold text-slate-900">{money(Math.max(0, (detalle.total || 0) - (detalle.anticipo || 0)))}</span></span>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
