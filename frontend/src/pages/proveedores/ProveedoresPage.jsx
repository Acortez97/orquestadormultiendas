import { useState, useEffect, useCallback } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { proveedoresApi } from '../../services/api/endpoints';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n || 0));

const TIPOS = ['Mercancia', 'Servicios', 'Admin', 'Otro'];

const emptyForm = {
  nombre: '',
  razon_social: '',
  rfc: '',
  tipo: 'Mercancia',
  contacto: '',
  telefono: '',
  calle: '',
  numero_ext: '',
  numero_int: '',
  colonia: '',
  municipio: '',
  estado: '',
  codigo_postal: '',
  pais: 'México',
};

export default function ProveedoresPage() {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [form, setForm] = useState(emptyForm);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await proveedoresApi.listar();
      setData(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo cargar proveedores', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const primerContacto = (p) => (Array.isArray(p.contactos) && p.contactos[0]) || {};

  const abrirCrear = () => {
    setEditId(null);
    setForm(emptyForm);
    setOpen(true);
  };

  const abrirEditar = (p) => {
    const c = primerContacto(p);
    setEditId(p._id);
    setForm({
      nombre: p.nombre || '',
      razon_social: p.razon_social || '',
      rfc: p.rfc || '',
      tipo: p.tipo || 'Mercancia',
      contacto: c.nombre || '',
      telefono: c.telefono || '',
      calle: p.domicilio?.calle || '',
      numero_ext: p.domicilio?.numero_ext || '',
      numero_int: p.domicilio?.numero_int || '',
      colonia: p.domicilio?.colonia || '',
      municipio: p.domicilio?.municipio || '',
      estado: p.domicilio?.estado || '',
      codigo_postal: p.domicilio?.codigo_postal || '',
      pais: p.domicilio?.pais || 'México',
    });
    setOpen(true);
  };

  const guardar = async () => {
    if (!form.nombre.trim()) {
      Swal.fire('Atención', 'El nombre es obligatorio', 'warning');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        nombre: form.nombre.trim(),
        razon_social: form.razon_social || null,
        rfc: form.rfc || null,
        tipo: form.tipo,
        contactos:
          form.contacto || form.telefono
            ? [{ nombre: form.contacto || '', telefono: form.telefono || '' }]
            : [],
        domicilio: {
          calle: form.calle?.trim() || '',
          numero_ext: form.numero_ext?.trim() || '',
          numero_int: form.numero_int?.trim() || '',
          colonia: form.colonia?.trim() || '',
          municipio: form.municipio?.trim() || '',
          estado: form.estado?.trim() || '',
          codigo_postal: form.codigo_postal?.trim() || '',
          pais: form.pais?.trim() || '',
        },
      };
      if (editId) await proveedoresApi.actualizar(editId, payload);
      else await proveedoresApi.crear(payload);
      setOpen(false);
      await cargar();
      Swal.fire('Listo', `Proveedor ${editId ? 'actualizado' : 'creado'}`, 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo guardar', 'error');
    } finally {
      setSaving(false);
    }
  };

  const eliminar = async (p) => {
    const r = await Swal.fire({
      title: '¿Eliminar proveedor?',
      text: p.nombre,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Eliminar',
      cancelButtonText: 'Cancelar',
    });
    if (!r.isConfirmed) return;
    try {
      await proveedoresApi.eliminar(p._id);
      await cargar();
      Swal.fire('Eliminado', '', 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo eliminar', 'error');
    }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    { key: 'rfc', label: 'RFC', render: (r) => r.rfc || '—' },
    { key: 'telefono', label: 'Teléfono', render: (r) => primerContacto(r).telefono || '—' },
    {
      key: 'saldo',
      label: 'Saldo MXN',
      render: (r) => money(r?.saldos?.MXN?.saldo),
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
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Proveedores</h1>
        <button className="btn-primary flex items-center gap-2" onClick={abrirCrear}>
          <PlusIcon className="w-5 h-5" /> Nuevo proveedor
        </button>
      </div>

      <DataTable columns={columns} data={data} loading={loading} empty="Sin proveedores" exportName="proveedores" exportTitle="Proveedores" />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editId ? 'Editar proveedor' : 'Nuevo proveedor'}
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
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Nombre *</label>
            <input
              className="input-base"
              value={form.nombre}
              onChange={(e) => setForm({ ...form, nombre: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Razón social</label>
            <input
              className="input-base"
              value={form.razon_social}
              onChange={(e) => setForm({ ...form, razon_social: e.target.value })}
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">RFC</label>
              <input
                className="input-base"
                value={form.rfc}
                onChange={(e) => setForm({ ...form, rfc: e.target.value.toUpperCase() })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
              <select
                className="input-base"
                value={form.tipo}
                onChange={(e) => setForm({ ...form, tipo: e.target.value })}
              >
                {TIPOS.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Contacto</label>
              <input
                className="input-base"
                value={form.contacto}
                onChange={(e) => setForm({ ...form, contacto: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
              <input
                className="input-base"
                value={form.telefono}
                onChange={(e) => setForm({ ...form, telefono: e.target.value })}
              />
            </div>
          </div>
          <div className="pt-2 border-t border-slate-100">
            <h3 className="text-sm font-semibold text-slate-700 mb-3">Dirección</h3>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Calle</label>
                  <input
                    className="input-base"
                    value={form.calle}
                    onChange={(e) => setForm({ ...form, calle: e.target.value })}
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">No. ext.</label>
                    <input
                      className="input-base"
                      value={form.numero_ext}
                      onChange={(e) => setForm({ ...form, numero_ext: e.target.value })}
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">No. int.</label>
                    <input
                      className="input-base"
                      value={form.numero_int}
                      onChange={(e) => setForm({ ...form, numero_int: e.target.value })}
                    />
                  </div>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Colonia</label>
                  <input
                    className="input-base"
                    value={form.colonia}
                    onChange={(e) => setForm({ ...form, colonia: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Municipio / Ciudad</label>
                  <input
                    className="input-base"
                    value={form.municipio}
                    onChange={(e) => setForm({ ...form, municipio: e.target.value })}
                  />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Estado</label>
                  <input
                    className="input-base"
                    value={form.estado}
                    onChange={(e) => setForm({ ...form, estado: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">C.P.</label>
                  <input
                    className="input-base"
                    value={form.codigo_postal}
                    onChange={(e) => setForm({ ...form, codigo_postal: e.target.value })}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">País</label>
                  <input
                    className="input-base"
                    value={form.pais}
                    onChange={(e) => setForm({ ...form, pais: e.target.value })}
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  );
}
