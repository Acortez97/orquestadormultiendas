import { useState, useEffect, useCallback } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { empleadosApi, almacenesApi } from '../../services/api/endpoints';

const AREAS = ['ventas', 'almacen', 'administracion'];

const emptyForm = {
  nombre: '',
  apellido: '',
  puesto: '',
  area: 'ventas',
  telefono: '',
  email: '',
  es_vendedor: false,
  comision_porcentaje: 0,
  id_tienda: '',
};

export default function EmpleadosPage() {
  const [data, setData] = useState([]);
  const [almacenes, setAlmacenes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [form, setForm] = useState(emptyForm);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const [emp, alm] = await Promise.all([empleadosApi.listar(), almacenesApi.listar()]);
      setData(Array.isArray(emp?.data) ? emp.data : []);
      setAlmacenes(Array.isArray(alm?.data) ? alm.data : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo cargar empleados', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const abrirCrear = () => {
    setEditId(null);
    setForm(emptyForm);
    setOpen(true);
  };

  const abrirEditar = (e) => {
    setEditId(e._id);
    setForm({
      nombre: e.nombre || '',
      apellido: e.apellido || '',
      puesto: e.puesto || '',
      area: e.area || 'ventas',
      telefono: e.telefono || '',
      email: e.email || '',
      es_vendedor: !!e.es_vendedor,
      comision_porcentaje: e.comision_porcentaje ?? 0,
      id_tienda: e.id_tienda?._id || e.id_tienda || '',
    });
    setOpen(true);
  };

  const guardar = async () => {
    if (!form.nombre.trim() || !form.apellido.trim() || !form.puesto.trim()) {
      Swal.fire('Atención', 'Nombre, apellido y puesto son obligatorios', 'warning');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        nombre: form.nombre.trim(),
        apellido: form.apellido.trim(),
        puesto: form.puesto.trim(),
        area: form.area,
        telefono: form.telefono || null,
        email: form.email || null,
        es_vendedor: form.es_vendedor,
        comision_porcentaje: Number(form.comision_porcentaje) || 0,
        id_tienda: form.id_tienda || null,
      };
      if (editId) await empleadosApi.actualizar(editId, payload);
      else await empleadosApi.crear(payload);
      setOpen(false);
      await cargar();
      Swal.fire('Listo', `Empleado ${editId ? 'actualizado' : 'creado'}`, 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo guardar', 'error');
    } finally {
      setSaving(false);
    }
  };

  const eliminar = async (e) => {
    const r = await Swal.fire({
      title: '¿Eliminar empleado?',
      text: `${e.nombre} ${e.apellido}`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Eliminar',
      cancelButtonText: 'Cancelar',
    });
    if (!r.isConfirmed) return;
    try {
      await empleadosApi.eliminar(e._id);
      await cargar();
      Swal.fire('Eliminado', '', 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo eliminar', 'error');
    }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre', render: (r) => `${r.nombre} ${r.apellido || ''}`.trim() },
    { key: 'puesto', label: 'Puesto' },
    { key: 'area', label: 'Área', render: (r) => <span className="badge-slate capitalize">{r.area}</span> },
    {
      key: 'es_vendedor',
      label: 'Vendedor',
      render: (r) =>
        r.es_vendedor ? <span className="badge-success">Sí</span> : <span className="badge-slate">No</span>,
    },
    {
      key: 'comision_porcentaje',
      label: 'Comisión',
      render: (r) => `${Number(r.comision_porcentaje || 0)}%`,
    },
    {
      key: 'acciones',
      label: '',
      render: (r) => (
        <div className="flex gap-2">
          <button className="btn-ghost p-1.5 rounded-lg" onClick={() => abrirEditar(r)}>
            <PencilSquareIcon className="w-5 h-5 text-slate-500" />
          </button>
          <button className="btn-ghost p-1.5 rounded-lg" onClick={() => eliminar(r)}>
            <TrashIcon className="w-5 h-5 text-danger-500" />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Vendedores y empleados</h1>
        <button className="btn-primary flex items-center gap-2" onClick={abrirCrear}>
          <PlusIcon className="w-5 h-5" /> Nuevo empleado
        </button>
      </div>

      <DataTable columns={columns} data={data} loading={loading} empty="Sin empleados" exportName="empleados" exportTitle="Empleados" />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editId ? 'Editar empleado' : 'Nuevo empleado'}
        size="lg"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setOpen(false)}>
              Cancelar
            </button>
            <button className="btn-primary" onClick={guardar} disabled={saving}>
              {saving ? 'Guardando…' : 'Guardar'}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Nombre *</label>
              <input
                className="input-base"
                value={form.nombre}
                onChange={(e) => setForm({ ...form, nombre: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Apellido *</label>
              <input
                className="input-base"
                value={form.apellido}
                onChange={(e) => setForm({ ...form, apellido: e.target.value })}
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Puesto *</label>
              <input
                className="input-base"
                value={form.puesto}
                onChange={(e) => setForm({ ...form, puesto: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Área</label>
              <select
                className="input-base"
                value={form.area}
                onChange={(e) => setForm({ ...form, area: e.target.value })}
              >
                {AREAS.map((a) => (
                  <option key={a} value={a}>
                    {a}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
              <input
                className="input-base"
                value={form.telefono}
                onChange={(e) => setForm({ ...form, telefono: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
              <input
                type="email"
                className="input-base"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4 items-end">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Comisión (%)
              </label>
              <input
                type="number"
                min="0"
                max="100"
                className="input-base"
                value={form.comision_porcentaje}
                onChange={(e) => setForm({ ...form, comision_porcentaje: e.target.value })}
              />
            </div>
            <label className="flex items-center gap-2 pb-2 text-sm font-medium text-slate-700">
              <input
                type="checkbox"
                className="w-4 h-4"
                checked={form.es_vendedor}
                onChange={(e) => setForm({ ...form, es_vendedor: e.target.checked })}
              />
              Es vendedor
            </label>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Tienda</label>
            <select
              className="input-base"
              value={form.id_tienda}
              onChange={(e) => setForm({ ...form, id_tienda: e.target.value })}
            >
              <option value="">— Sin asignar —</option>
              {almacenes.map((a) => (
                <option key={a._id} value={a._id}>
                  {a.nombre}
                </option>
              ))}
            </select>
          </div>
        </div>
      </Modal>
    </div>
  );
}
