import { useState, useEffect, useCallback } from 'react';
import { PlusIcon, TrashIcon, CheckCircleIcon, BanknotesIcon, InformationCircleIcon, EyeIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import MatrizColorTalla, { expandirMatriz, sumarMatriz } from '../../components/common/MatrizColorTalla';
import { comprasApi, proveedoresApi, almacenesApi, articulosApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import DestinoPago, { faltaDestino, destinoPayload } from '../../components/common/DestinoPago';
import { aFecha } from '../../utils/fechas';
import { aviso } from '../../utils/avisos';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');

// Pago a proveedor: efectivo sale de la caja del almacen de la compra; transferencia / cheque, de una cuenta
const FORMAS_PAGO = [
  { value: 'efectivo', label: 'Efectivo (caja de la tienda)' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'cheque', label: 'Cheque' },
];

const estadoBadge = (estado) =>
  estado === 'aprobada' ? 'badge-success' : estado === 'por_aprobar' ? 'badge-warning' : 'badge-slate';

// Cada "línea" del formulario es ahora un artículo con una matriz colores×tallas.
// `cant` mapea `${colorId}_${tallaId}` -> cantidad capturada en esa celda.
// `uid` identifica la línea al pintarla: las nuevas se insertan al principio, y con
// key={idx} React dejaría el estado del autocomplete pegado a la posición, no a la línea.
let uidSeq = 0;
const nuevoUid = () => `linea-${++uidSeq}`;
const lineaVacia = () => ({ uid: nuevoUid(), art: null, id_articulo: '', codigo: '', descripcion: '', costo_unitario: 0, cant: {} });
const piezasDe = (l) => sumarMatriz(l.cant);

export default function ComprasPage() {
  const { hasPermiso, user } = useAuth();
  const ivaTienda = Number(user?.tienda?.iva ?? 0.16);   // la tasa de la tienda (p. ej. 8% en frontera), igual que el servidor
  const puedePagar = hasPermiso('compras.pagar') || hasPermiso('finanzas.crear');

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  const [proveedores, setProveedores] = useState([]);
  const [almacenes, setAlmacenes] = useState([]);

  // Crear / Editar
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [form, setForm] = useState({
    id_proveedor: '',
    id_almacen: '',
    aplica_iva: false,
    notas: '',
    lineas: [lineaVacia()],
  });

  // Detalle
  const [detalle, setDetalle] = useState(null);
  const [detalleLoading, setDetalleLoading] = useState(false);

  // Pagos
  const [pagosCompra, setPagosCompra] = useState(null);
  const [pagos, setPagos] = useState([]);
  const [pagosLoading, setPagosLoading] = useState(false);
  const [pagoForm, setPagoForm] = useState({ importe: '', forma_pago: 'efectivo', aplica_iva: false, referencia: '' });
  const [savingPago, setSavingPago] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await comprasApi.listar();
      setRows(res.data?.docs ?? res.data ?? []);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    proveedoresApi.listar().then((r) => setProveedores(r.data?.docs ?? r.data ?? [])).catch(() => {});
    almacenesApi.listar().then((r) => setAlmacenes(r.data?.docs ?? r.data ?? [])).catch(() => {});
  }, []);

  // ---- Form helpers ----
  function setLinea(idx, patch) {
    setForm((f) => {
      const lineas = [...f.lineas];
      lineas[idx] = { ...lineas[idx], ...patch };
      return { ...f, lineas };
    });
  }
  // En pila: el último artículo agregado queda hasta arriba y los anteriores bajan.
  function addLinea() {
    setForm((f) => ({ ...f, lineas: [lineaVacia(), ...f.lineas] }));
  }
  function removeLinea(idx) {
    setForm((f) => ({ ...f, lineas: f.lineas.filter((_, i) => i !== idx) }));
  }
  function setCant(idx, key, value) {
    setForm((f) => {
      const lineas = [...f.lineas];
      lineas[idx] = { ...lineas[idx], cant: { ...lineas[idx].cant, [key]: value } };
      return { ...f, lineas };
    });
  }

  const totalForm = form.lineas.reduce(
    (acc, l) => acc + piezasDe(l) * (Number(l.costo_unitario) || 0),
    0
  );
  const totalFormConIva = form.aplica_iva ? totalForm * (1 + ivaTienda) : totalForm;

  function abrirForm() {
    setEditId(null);
    setForm({ id_proveedor: '', id_almacen: '', aplica_iva: false, notas: '', lineas: [lineaVacia()] });
    setShowForm(true);
  }

  async function abrirEditar(row) {
    try {
      const res = await comprasApi.obtener(row._id);
      const compra = res.data ?? row;

      // Reagrupa las líneas planas (una por color×talla) por artículo y arma la matriz.
      const byArt = new Map();
      for (const l of compra.lineas || []) {
        const aid = l.id_articulo?._id || l.id_articulo;
        if (!byArt.has(aid)) byArt.set(aid, { costo: l.costo_unitario, cells: {} });
        const cId = l.id_color?._id || l.id_color || '';
        const tId = l.id_talla?._id || l.id_talla || '';
        byArt.get(aid).cells[`${cId}_${tId}`] = l.cantidad;
      }

      // Trae el artículo completo (con sus colores/tallas) para poder pintar la matriz.
      const ids = [...byArt.keys()];
      const arts = await Promise.all(
        ids.map((id) => articulosApi.obtener(id).then((r) => r.data).catch(() => null))
      );

      const lineas = ids
        .map((aid, i) => {
          const art = arts[i];
          if (!art) return null;
          const info = byArt.get(aid);
          return {
            uid: nuevoUid(),
            art,
            id_articulo: aid,
            codigo: art.codigo,
            descripcion: art.descripcion,
            costo_unitario: info.costo,
            cant: info.cells,
          };
        })
        .filter(Boolean);

      setEditId(row._id);
      setForm({
        id_proveedor: compra.id_proveedor?._id || compra.id_proveedor || '',
        id_almacen: compra.id_almacen?._id || compra.id_almacen || '',
        aplica_iva: !!compra.aplica_iva,
        notas: compra.notas || '',
        lineas: lineas.length ? lineas : [lineaVacia()],
      });
      setShowForm(true);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  async function abrirDetalle(row) {
    setDetalle({ _id: row._id, ...row }); // muestra de inmediato lo que ya tenemos
    setDetalleLoading(true);
    try {
      const res = await comprasApi.obtener(row._id);
      setDetalle(res.data ?? row);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
      setDetalle(null);
    } finally {
      setDetalleLoading(false);
    }
  }

  async function handleCrear(e) {
    e.preventDefault();
    // Expandir cada artículo (matriz colores×tallas) en una línea por celda con cantidad > 0.
    const lineas = [];
    for (const l of form.lineas) {
      if (!l.id_articulo) continue;
      for (const celda of expandirMatriz(l.art, l.cant)) {
        lineas.push({
          id_articulo: l.id_articulo,
          id_color: celda.id_color,
          id_talla: celda.id_talla,
          cantidad: celda.valor,
          costo_unitario: Number(l.costo_unitario) || 0,
        });
      }
    }
    if (!form.id_proveedor || !form.id_almacen || lineas.length === 0) {
      Swal.fire('Datos incompletos', 'Selecciona proveedor, almacén y captura al menos una cantidad.', 'warning');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        id_proveedor: form.id_proveedor,
        id_almacen: form.id_almacen,
        aplica_iva: form.aplica_iva,
        notas: form.notas,
        lineas,
      };
      if (editId) await comprasApi.actualizar(editId, payload);
      else await comprasApi.crear(payload);
      Swal.fire({
        icon: 'success',
        title: editId ? 'Compra actualizada' : 'Compra registrada',
        timer: 1600,
        showConfirmButton: false,
      });
      setShowForm(false);
      setEditId(null);
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  async function aprobar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Aprobar compra?',
      text: `Folio ${row.folio}. El stock ingresará al inventario.`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#16a34a',
      confirmButtonText: 'Aprobar',
      cancelButtonText: 'Cancelar',
    });
    if (!isConfirmed) return;
    try {
      await comprasApi.aprobar(row._id);
      aviso('Compra aprobada');
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  async function eliminar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Eliminar compra?',
      html: `Folio <b>${row.folio}</b>. Esta acción no se puede deshacer.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e11d48',
      confirmButtonText: 'Eliminar',
      cancelButtonText: 'Cancelar',
    });
    if (!isConfirmed) return;
    try {
      await comprasApi.eliminar(row._id);
      aviso('Compra eliminada');
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  // ---- Pagos ----
  async function abrirPagos(row) {
    setPagosCompra(row);
    setPagos([]);
    setPagoForm({ importe: '', forma_pago: 'efectivo', aplica_iva: false, referencia: '' });
    setPagosLoading(true);
    try {
      const res = await comprasApi.pagos(row._id);
      setPagos(res.data?.docs ?? res.data ?? []);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setPagosLoading(false);
    }
  }

  async function registrarPago(e) {
    e.preventDefault();
    if (!pagosCompra || !(Number(pagoForm.importe) > 0)) {
      Swal.fire('Importe inválido', 'Captura un importe mayor a cero.', 'warning');
      return;
    }
    const falta = faltaDestino({ forma: pagoForm.forma_pago, importe: pagoForm.importe, id_banco: pagoForm.id_banco });
    if (falta) { Swal.fire('Falta la cuenta', 'Selecciona de qué cuenta sale el pago.', 'warning'); return; }
    setSavingPago(true);
    try {
      await comprasApi.registrarPago(pagosCompra._id, {
        importe: Number(pagoForm.importe),
        forma_pago: pagoForm.forma_pago,
        ...destinoPayload({ forma: pagoForm.forma_pago, id_banco: pagoForm.id_banco }),
        aplica_iva: pagoForm.aplica_iva,
        referencia: pagoForm.referencia,
      });
      const res = await comprasApi.pagos(pagosCompra._id);
      setPagos(res.data?.docs ?? res.data ?? []);
      setPagoForm({ importe: '', forma_pago: 'efectivo', aplica_iva: false, referencia: '' });
      aviso('Pago registrado');
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setSavingPago(false);
    }
  }

  const columns = [
    { key: 'folio', label: 'Folio', className: 'font-medium text-slate-900' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha) },
    {
      key: 'proveedor',
      label: 'Proveedor',
      render: (r) => r.id_proveedor?.nombre || r.id_proveedor || '—',
    },
    {
      key: 'almacen',
      label: 'Almacén',
      render: (r) => r.id_almacen?.nombre || r.id_almacen || '—',
    },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total) },
    {
      key: 'aplica_iva',
      label: 'IVA',
      render: (r) => (
        <span className={r.aplica_iva ? 'badge-primary' : 'badge-slate'}>{r.aplica_iva ? 'Sí' : 'No'}</span>
      ),
    },
    {
      key: 'estado',
      label: 'Estado',
      render: (r) => <span className={estadoBadge(r.estado)}>{r.estado}</span>,
    },
    {
      key: 'acciones',
      label: 'Acciones',
      render: (r) => (
        <div className="flex gap-2">
          <button
            className="btn-ghost p-1.5 text-sky-600"
            title="Ver detalle"
            onClick={() => abrirDetalle(r)}
          >
            <EyeIcon className="w-5 h-5" />
          </button>
          {r.estado === 'por_aprobar' && hasPermiso('compras.editar') && (
            <button
              className="btn-ghost p-1.5 text-slate-600"
              title="Editar"
              onClick={() => abrirEditar(r)}
            >
              <PencilSquareIcon className="w-5 h-5" />
            </button>
          )}
          {r.estado === 'por_aprobar' && hasPermiso('compras.aprobar') && (
            <button
              className="btn-ghost p-1.5 text-green-600"
              title="Aprobar"
              onClick={(e) => {
                e.stopPropagation();
                aprobar(r);
              }}
            >
              <CheckCircleIcon className="w-5 h-5" />
            </button>
          )}
          <button
            className="btn-ghost p-1.5 text-slate-600"
            title="Pagos"
            onClick={(e) => {
              e.stopPropagation();
              abrirPagos(r);
            }}
          >
            <BanknotesIcon className="w-5 h-5" />
          </button>
          {r.estado === 'por_aprobar' && hasPermiso('compras.eliminar') && (
            <button
              className="btn-ghost p-1.5 text-rose-600"
              title="Eliminar"
              onClick={() => eliminar(r)}
            >
              <TrashIcon className="w-5 h-5" />
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Compras</h1>
        {hasPermiso('compras.crear') && (
          <button className="btn-primary flex items-center gap-2" onClick={abrirForm}>
            <PlusIcon className="w-4 h-4" /> Nueva compra
          </button>
        )}
      </div>

      <div className="flex items-start gap-2 rounded-lg bg-sky-50 border border-sky-200 px-4 py-2 text-sm text-sky-800">
        <InformationCircleIcon className="w-5 h-5 shrink-0 mt-0.5" />
        <span>El stock entra al inventario únicamente cuando la compra es aprobada.</span>
      </div>

      <DataTable columns={columns} data={rows} loading={loading} empty="Sin compras" exportName="compras" exportTitle="Compras" />

      {/* Nueva compra */}
      <Modal
        open={showForm}
        onClose={() => setShowForm(false)}
        title={editId ? 'Editar compra' : 'Nueva compra'}
        size="xl"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setShowForm(false)}>
              Cancelar
            </button>
            <button className="btn-primary" disabled={saving} onClick={handleCrear}>
              {saving ? 'Guardando…' : editId ? 'Guardar cambios' : 'Guardar compra'}
            </button>
          </>
        }
      >
        <form onSubmit={handleCrear} className="space-y-4">
          <div className="grid gap-3 md:grid-cols-2">
            <div>
              <label className="block text-xs font-medium text-slate-500 mb-1">Proveedor *</label>
              <select
                className="input-base"
                value={form.id_proveedor}
                onChange={(e) => setForm({ ...form, id_proveedor: e.target.value })}
              >
                <option value="">Selecciona…</option>
                {proveedores.map((p) => (
                  <option key={p._id} value={p._id}>
                    {p.nombre}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-500 mb-1">Almacén *</label>
              <select
                className="input-base"
                value={form.id_almacen}
                onChange={(e) => setForm({ ...form, id_almacen: e.target.value })}
              >
                <option value="">Selecciona…</option>
                {almacenes.map((a) => (
                  <option key={a._id} value={a._id}>
                    {a.nombre}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              checked={form.aplica_iva}
              onChange={(e) => setForm({ ...form, aplica_iva: e.target.checked })}
            />
            Aplica IVA
          </label>

          {/* Artículos: cada uno con su matriz colores × tallas */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-semibold text-slate-700">Artículos</p>
              <button type="button" className="btn-ghost text-sky-600 flex items-center gap-1" onClick={addLinea}>
                <PlusIcon className="w-4 h-4" /> Agregar
              </button>
            </div>

            <div className="space-y-3">
              {form.lineas.map((l, idx) => {
                const piezas = piezasDe(l);
                return (
                  <div key={l.uid} className="border border-slate-200 rounded-lg p-3 space-y-3">
                    <div className="grid gap-2 md:grid-cols-12 items-end">
                      <div className="md:col-span-7">
                        <label className="block text-xs font-medium text-slate-500 mb-1">Artículo</label>
                        {l.art ? (
                          <div className="input-base flex items-center justify-between gap-2">
                            <span className="truncate text-sm" title={l.descripcion}>
                              <b>{l.codigo}</b> — {l.descripcion}
                            </span>
                            <button
                              type="button"
                              className="text-xs text-sky-600 shrink-0"
                              onClick={() => setLinea(idx, { art: null, id_articulo: '', codigo: '', descripcion: '', cant: {} })}
                            >
                              Cambiar
                            </button>
                          </div>
                        ) : (
                          <ArticuloAutocomplete
                            placeholder="Buscar artículo…"
                            onSelect={(art) =>
                              setLinea(idx, {
                                art,
                                id_articulo: art._id,
                                codigo: art.codigo,
                                descripcion: art.descripcion,
                                cant: {},
                                costo_unitario: art.costo ?? l.costo_unitario,
                              })
                            }
                          />
                        )}
                      </div>
                      <div className="md:col-span-3">
                        <label className="block text-xs font-medium text-slate-500 mb-1">Costo unit.</label>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          className="input-base"
                          value={l.costo_unitario}
                          onChange={(e) => setLinea(idx, { costo_unitario: e.target.value })}
                        />
                      </div>
                      <div className="md:col-span-2 flex items-center justify-between md:justify-end gap-3">
                        <span className="text-xs text-slate-500">
                          {piezas} pza{piezas === 1 ? '' : 's'}
                        </span>
                        {form.lineas.length > 1 && (
                          <button
                            type="button"
                            className="btn-ghost p-1.5 text-danger-600"
                            onClick={() => removeLinea(idx)}
                            title="Quitar artículo"
                          >
                            <TrashIcon className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    </div>

                    {l.art && (
                      <MatrizColorTalla
                        art={l.art}
                        cant={l.cant}
                        onCell={(key, value) => setCant(idx, key, value)}
                      />
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Notas</label>
            <textarea
              className="input-base"
              rows={2}
              value={form.notas}
              onChange={(e) => setForm({ ...form, notas: e.target.value })}
            />
          </div>

          <div className="flex justify-end gap-6 border-t border-slate-100 pt-3 text-sm">
            <span className="text-slate-500">
              Subtotal: <span className="font-medium text-slate-800">{money(totalForm)}</span>
            </span>
            <span className="text-slate-500">
              Total{form.aplica_iva ? ' (c/IVA)' : ''}:{' '}
              <span className="font-semibold text-slate-900">{money(totalFormConIva)}</span>
            </span>
          </div>
        </form>
      </Modal>

      {/* Pagos */}
      <Modal
        open={!!pagosCompra}
        onClose={() => setPagosCompra(null)}
        title={pagosCompra ? `Pagos · ${pagosCompra.folio}` : 'Pagos'}
        size="lg"
        footer={
          <button className="btn-secondary" onClick={() => setPagosCompra(null)}>
            Cerrar
          </button>
        }
      >
        {pagosCompra && (
          <div className="space-y-5">
            <div>
              <p className="text-sm font-semibold text-slate-700 mb-2">Pagos registrados</p>
              <div className="card overflow-hidden">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Fecha</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Forma</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Referencia</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {pagosLoading ? (
                      <tr>
                        <td colSpan={4} className="px-3 py-6 text-center text-slate-400">
                          Cargando…
                        </td>
                      </tr>
                    ) : pagos.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="px-3 py-6 text-center text-slate-400">
                          Sin pagos
                        </td>
                      </tr>
                    ) : (
                      pagos.map((p, i) => (
                        <tr key={p._id || i}>
                          <td className="px-3 py-2 text-slate-700">{fecha(p.fecha)}</td>
                          <td className="px-3 py-2 text-slate-700">
                            {FORMAS_PAGO.find((f) => f.value === p.forma_pago)?.label || p.forma_pago}
                          </td>
                          <td className="px-3 py-2 text-slate-700">{p.referencia || '—'}</td>
                          <td className="px-3 py-2 text-right font-medium text-slate-800">{money(p.importe)}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {puedePagar && pagosCompra.estado !== 'cancelada' && (
            <form onSubmit={registrarPago} className="space-y-3 border-t border-slate-100 pt-4">
              <p className="text-sm font-semibold text-slate-700">Registrar pago</p>
              <div className="grid gap-3 md:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Importe *</label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    className="input-base"
                    value={pagoForm.importe}
                    onChange={(e) => setPagoForm({ ...pagoForm, importe: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Forma de pago</label>
                  <select
                    className="input-base"
                    value={pagoForm.forma_pago}
                    onChange={(e) => setPagoForm({ ...pagoForm, forma_pago: e.target.value, id_banco: '' })}
                  >
                    {FORMAS_PAGO.map((f) => (
                      <option key={f.value} value={f.value}>
                        {f.label}
                      </option>
                    ))}
                  </select>
                  <DestinoPago forma={pagoForm.forma_pago} value={pagoForm} className="mt-2 w-full"
                    onChange={(d) => setPagoForm({ ...pagoForm, id_banco: d.id_banco })} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Referencia</label>
                  <input
                    className="input-base"
                    value={pagoForm.referencia}
                    onChange={(e) => setPagoForm({ ...pagoForm, referencia: e.target.value })}
                  />
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-700 md:mt-6">
                  <input
                    type="checkbox"
                    checked={pagoForm.aplica_iva}
                    onChange={(e) => setPagoForm({ ...pagoForm, aplica_iva: e.target.checked })}
                  />
                  Aplica IVA
                </label>
              </div>
              <div className="flex justify-end">
                <button type="submit" className="btn-primary" disabled={savingPago}>
                  {savingPago ? 'Guardando…' : 'Registrar pago'}
                </button>
              </div>
            </form>
            )}
          </div>
        )}
      </Modal>

      {/* Detalle de compra */}
      <Modal
        open={!!detalle}
        onClose={() => setDetalle(null)}
        title={detalle ? `Detalle de compra · ${detalle.folio || ''}` : 'Detalle de compra'}
        size="xl"
        footer={
          <button className="btn-secondary" onClick={() => setDetalle(null)}>
            Cerrar
          </button>
        }
      >
        {detalle && (
          <div className="space-y-5">
            {/* Encabezado */}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
              <div>
                <p className="text-xs font-medium text-slate-500">Proveedor</p>
                <p className="text-slate-800">{detalle.id_proveedor?.nombre || detalle.id_proveedor || '—'}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Almacén</p>
                <p className="text-slate-800">{detalle.id_almacen?.nombre || detalle.id_almacen || '—'}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Fecha</p>
                <p className="text-slate-800">{fecha(detalle.fecha)}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Estado</p>
                <p><span className={estadoBadge(detalle.estado)}>{detalle.estado}</span></p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">IVA</p>
                <p><span className={detalle.aplica_iva ? 'badge-primary' : 'badge-slate'}>{detalle.aplica_iva ? 'Sí' : 'No'}</span></p>
              </div>
              {detalle.aprobada_por && (
                <div>
                  <p className="text-xs font-medium text-slate-500">Aprobada por</p>
                  <p className="text-slate-800">
                    {`${detalle.aprobada_por.nombre || ''} ${detalle.aprobada_por.apellido || ''}`.trim() || '—'}
                    {detalle.fecha_aprobacion ? ` · ${fecha(detalle.fecha_aprobacion)}` : ''}
                  </p>
                </div>
              )}
            </div>

            {/* Artículos */}
            <div>
              <p className="text-sm font-semibold text-slate-700 mb-2">Artículos</p>
              <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Código</th>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Descripción</th>
                        <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Variante</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Cant.</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Costo unit.</th>
                        <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                      {detalleLoading ? (
                        <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-400">Cargando…</td></tr>
                      ) : (detalle.lineas || []).length === 0 ? (
                        <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-400">Sin artículos</td></tr>
                      ) : (
                        detalle.lineas.map((l, i) => (
                          <tr key={i}>
                            <td className="px-3 py-2 font-medium text-slate-800">{l.codigo || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{l.descripcion || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{[l.id_color?.nombre, l.id_talla?.nombre].filter(Boolean).join(' / ') || '—'}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{l.cantidad}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{money(l.costo_unitario)}</td>
                            <td className="px-3 py-2 text-right font-medium text-slate-800">{money(l.importe)}</td>
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

            <div className="flex justify-end border-t border-slate-100 pt-3 text-sm">
              <span className="text-slate-500">
                Total{detalle.aplica_iva ? ' (c/IVA)' : ''}:{' '}
                <span className="font-semibold text-slate-900">{money(detalle.total)}</span>
              </span>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
