import { useState, useEffect, useCallback } from 'react';
import { PlusIcon, TrashIcon, CheckIcon, XMarkIcon, PencilSquareIcon, EyeIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import MatrizColorTalla, { expandirMatriz, sumarMatriz } from '../../components/common/MatrizColorTalla';
import { traspasosApi, almacenesApi, articulosApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';

const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');

const estadoBadge = (estado) => {
  if (estado === 'aceptado') return 'badge-success';
  if (estado === 'pendiente') return 'badge-warning';
  return 'badge-danger'; // rechazado / cancelado
};

const nombreAlmacen = (a) => a?.nombre || a || '—';
// Cada artículo lleva una matriz colores×tallas en `cant` (clave `${colorId}_${tallaId}`).
const lineaVacia = () => ({ art: null, id_articulo: '', codigo: '', descripcion: '', cant: {} });

export default function TraspasosPage() {
  const { hasPermiso } = useAuth();

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  const [almacenes, setAlmacenes] = useState([]);

  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [detalle, setDetalle] = useState(null);
  const [detalleLoading, setDetalleLoading] = useState(false);
  const [form, setForm] = useState({
    id_almacen_origen: '',
    id_almacen_destino: '',
    notas: '',
    lineas: [lineaVacia()],
  });

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await traspasosApi.listar();
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
    almacenesApi.listar().then((r) => setAlmacenes(r.data?.docs ?? r.data ?? [])).catch(() => {});
  }, []);

  function setLinea(idx, patch) {
    setForm((f) => {
      const lineas = [...f.lineas];
      lineas[idx] = { ...lineas[idx], ...patch };
      return { ...f, lineas };
    });
  }
  function addLinea() {
    setForm((f) => ({ ...f, lineas: [...f.lineas, lineaVacia()] }));
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

  function abrirForm() {
    setEditId(null);
    setForm({ id_almacen_origen: '', id_almacen_destino: '', notas: '', lineas: [lineaVacia()] });
    setShowForm(true);
  }

  async function abrirEditar(row) {
    try {
      const res = await traspasosApi.obtener(row._id);
      const t = res.data ?? row;

      // Reagrupa las líneas planas (una por color×talla) por artículo y arma la matriz.
      const byArt = new Map();
      for (const l of t.lineas || []) {
        const aid = l.id_articulo?._id || l.id_articulo;
        if (!byArt.has(aid)) byArt.set(aid, {});
        const cId = l.id_color?._id || l.id_color || '';
        const tId = l.id_talla?._id || l.id_talla || '';
        byArt.get(aid)[`${cId}_${tId}`] = l.cantidad;
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
          return { art, id_articulo: aid, codigo: art.codigo, descripcion: art.descripcion, cant: byArt.get(aid) };
        })
        .filter(Boolean);

      setEditId(row._id);
      setForm({
        id_almacen_origen: t.id_almacen_origen?._id || t.id_almacen_origen || '',
        id_almacen_destino: t.id_almacen_destino?._id || t.id_almacen_destino || '',
        notas: t.notas || '',
        lineas: lineas.length ? lineas : [lineaVacia()],
      });
      setShowForm(true);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  async function abrirDetalle(row) {
    setDetalle(row); // muestra de inmediato lo que ya tenemos
    setDetalleLoading(true);
    try {
      const res = await traspasosApi.obtener(row._id);
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
        });
      }
    }
    if (lineas.length === 0) {
      Swal.fire('Datos incompletos', 'Captura al menos una cantidad.', 'warning');
      return;
    }
    if (!editId) {
      if (!form.id_almacen_origen || !form.id_almacen_destino) {
        Swal.fire('Datos incompletos', 'Selecciona origen y destino.', 'warning');
        return;
      }
      if (form.id_almacen_origen === form.id_almacen_destino) {
        Swal.fire('Almacenes inválidos', 'El origen y el destino deben ser distintos.', 'warning');
        return;
      }
    }
    setSaving(true);
    try {
      if (editId) {
        // Al editar sólo se ajustan artículos/cantidades y notas (origen/destino no cambian).
        await traspasosApi.actualizar(editId, { notas: form.notas, lineas });
      } else {
        await traspasosApi.crear({
          id_almacen_origen: form.id_almacen_origen,
          id_almacen_destino: form.id_almacen_destino,
          notas: form.notas,
          lineas,
        });
      }
      Swal.fire({
        icon: 'success',
        title: editId ? 'Traspaso actualizado' : 'Traspaso creado',
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

  async function aceptar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Aceptar traspaso?',
      text: `Folio ${row.folio}. Se moverá el stock al almacén destino.`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#16a34a',
      confirmButtonText: 'Aceptar',
      cancelButtonText: 'Cancelar',
    });
    if (!isConfirmed) return;
    try {
      await traspasosApi.aceptar(row._id);
      Swal.fire({ icon: 'success', title: 'Traspaso aceptado', timer: 1600, showConfirmButton: false });
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  async function rechazar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Rechazar traspaso?',
      text: `Folio ${row.folio}.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e11d48',
      confirmButtonText: 'Rechazar',
      cancelButtonText: 'Cancelar',
    });
    if (!isConfirmed) return;
    try {
      await traspasosApi.rechazar(row._id);
      Swal.fire({ icon: 'success', title: 'Traspaso rechazado', timer: 1600, showConfirmButton: false });
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    }
  }

  const columns = [
    { key: 'folio', label: 'Folio', className: 'font-medium text-slate-900' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha) },
    { key: 'origen', label: 'Origen', render: (r) => nombreAlmacen(r.id_almacen_origen) },
    { key: 'destino', label: 'Destino', render: (r) => nombreAlmacen(r.id_almacen_destino) },
    {
      key: 'items',
      label: '# Items',
      className: 'text-right',
      render: (r) => (r.lineas?.length ?? 0),
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
        <div className="flex gap-2 items-center">
          <button
            className="btn-ghost p-1.5 text-sky-600"
            title="Ver detalle"
            onClick={() => abrirDetalle(r)}
          >
            <EyeIcon className="w-5 h-5" />
          </button>
          {r.estado === 'pendiente' && (
            <>
              {hasPermiso('traspasos.editar') && (
                <button
                  className="btn-secondary flex items-center gap-1 px-2 py-1 text-xs"
                  onClick={() => abrirEditar(r)}
                >
                  <PencilSquareIcon className="w-4 h-4" /> Editar
                </button>
              )}
              {hasPermiso('traspasos.aprobar') && (
                <button
                  className="btn-primary flex items-center gap-1 px-2 py-1 text-xs"
                  onClick={() => aceptar(r)}
                >
                  <CheckIcon className="w-4 h-4" /> Aceptar
                </button>
              )}
              <button
                className="btn-danger flex items-center gap-1 px-2 py-1 text-xs"
                onClick={() => rechazar(r)}
              >
                <XMarkIcon className="w-4 h-4" /> Rechazar
              </button>
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Traspasos</h1>
        <button className="btn-primary flex items-center gap-2" onClick={abrirForm}>
          <PlusIcon className="w-4 h-4" /> Nuevo traspaso
        </button>
      </div>

      <DataTable columns={columns} data={rows} loading={loading} empty="Sin traspasos" exportName="traspasos" exportTitle="Traspasos" />

      <Modal
        open={showForm}
        onClose={() => setShowForm(false)}
        title={editId ? 'Editar traspaso' : 'Nuevo traspaso'}
        size="xl"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setShowForm(false)}>
              Cancelar
            </button>
            <button className="btn-primary" disabled={saving} onClick={handleCrear}>
              {saving ? 'Guardando…' : editId ? 'Guardar cambios' : 'Crear traspaso'}
            </button>
          </>
        }
      >
        <form onSubmit={handleCrear} className="space-y-4">
          <div className="grid gap-3 md:grid-cols-2">
            <div>
              <label className="block text-xs font-medium text-slate-500 mb-1">Almacén origen *</label>
              <select
                className="input-base disabled:bg-slate-100 disabled:cursor-not-allowed"
                value={form.id_almacen_origen}
                disabled={!!editId}
                onChange={(e) => setForm({ ...form, id_almacen_origen: e.target.value })}
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
              <label className="block text-xs font-medium text-slate-500 mb-1">Almacén destino *</label>
              <select
                className="input-base disabled:bg-slate-100 disabled:cursor-not-allowed"
                value={form.id_almacen_destino}
                disabled={!!editId}
                onChange={(e) => setForm({ ...form, id_almacen_destino: e.target.value })}
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

          {editId && (
            <p className="text-xs text-slate-500 -mt-1">
              En un traspaso pendiente solo puedes ajustar artículos, cantidades y notas. El origen y destino no se pueden cambiar.
            </p>
          )}

          {/* Líneas */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-semibold text-slate-700">Artículos</p>
              <button type="button" className="btn-ghost text-sky-600 flex items-center gap-1" onClick={addLinea}>
                <PlusIcon className="w-4 h-4" /> Agregar
              </button>
            </div>

            <div className="space-y-3">
              {form.lineas.map((l, idx) => {
                const piezas = sumarMatriz(l.cant);
                return (
                  <div key={idx} className="border border-slate-200 rounded-lg p-3 space-y-3">
                    <div className="grid gap-2 md:grid-cols-12 items-end">
                      <div className="md:col-span-10">
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
                              })
                            }
                          />
                        )}
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
        </form>
      </Modal>

      {/* Detalle de traspaso */}
      <Modal
        open={!!detalle}
        onClose={() => setDetalle(null)}
        title={detalle ? `Detalle de traspaso · ${detalle.folio || ''}` : 'Detalle de traspaso'}
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
                <p className="text-xs font-medium text-slate-500">Origen</p>
                <p className="text-slate-800 flex items-center gap-2">
                  {nombreAlmacen(detalle.id_almacen_origen)}
                  {detalle.id_almacen_origen?.tipo && (
                    <span className="badge-slate capitalize">{detalle.id_almacen_origen.tipo}</span>
                  )}
                </p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Destino</p>
                <p className="text-slate-800 flex items-center gap-2">
                  {nombreAlmacen(detalle.id_almacen_destino)}
                  {detalle.id_almacen_destino?.tipo && (
                    <span className="badge-slate capitalize">{detalle.id_almacen_destino.tipo}</span>
                  )}
                </p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Fecha</p>
                <p className="text-slate-800">{fecha(detalle.fecha)}</p>
              </div>
              <div>
                <p className="text-xs font-medium text-slate-500">Estado</p>
                <p><span className={estadoBadge(detalle.estado)}>{detalle.estado}</span></p>
              </div>
              {detalle.creado_por && (
                <div>
                  <p className="text-xs font-medium text-slate-500">Creado por</p>
                  <p className="text-slate-800">
                    {`${detalle.creado_por.nombre || ''} ${detalle.creado_por.apellido || ''}`.trim() || '—'}
                  </p>
                </div>
              )}
              {detalle.aceptado_por && (
                <div>
                  <p className="text-xs font-medium text-slate-500">Aceptado por</p>
                  <p className="text-slate-800">
                    {`${detalle.aceptado_por.nombre || ''} ${detalle.aceptado_por.apellido || ''}`.trim() || '—'}
                    {detalle.fecha_aceptacion ? ` · ${fecha(detalle.fecha_aceptacion)}` : ''}
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
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                      {detalleLoading ? (
                        <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-400">Cargando…</td></tr>
                      ) : (detalle.lineas || []).length === 0 ? (
                        <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-400">Sin artículos</td></tr>
                      ) : (
                        detalle.lineas.map((l, i) => (
                          <tr key={i}>
                            <td className="px-3 py-2 font-medium text-slate-800">{l.id_articulo?.codigo || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{l.id_articulo?.descripcion || '—'}</td>
                            <td className="px-3 py-2 text-slate-700">{[l.id_color?.nombre, l.id_talla?.nombre].filter(Boolean).join(' / ') || '—'}</td>
                            <td className="px-3 py-2 text-right text-slate-700">{l.cantidad}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                    {!detalleLoading && (detalle.lineas || []).length > 0 && (
                      <tfoot className="bg-slate-50">
                        <tr>
                          <td colSpan={3} className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">
                            {detalle.lineas.length} artículo{detalle.lineas.length === 1 ? '' : 's'} · Total piezas
                          </td>
                          <td className="px-3 py-2 text-right font-bold text-slate-900">
                            {detalle.lineas.reduce((a, l) => a + (Number(l.cantidad) || 0), 0)}
                          </td>
                        </tr>
                      </tfoot>
                    )}
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
          </div>
        )}
      </Modal>
    </div>
  );
}
