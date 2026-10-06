import { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon, PencilSquareIcon, KeyIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import usuariosService from '../../services/api/usuarios.service';
import { almacenesApi } from '../../services/api/endpoints';

// Estructura de permisos: módulo -> acciones
const PERMISO_MODULOS = {
  catalogos: ['ver', 'gestionar'],
  clientes: ['ver', 'crear', 'editar', 'lista_alta', 'autorizar_credito'],
  ventas: ['ver', 'vender', 'anular', 'precio_especial'],
  compras: ['ver', 'crear', 'aprobar'],
  traspasos: ['ver', 'crear', 'aceptar'],
  almacen: ['ver', 'ajustar'],
  apartados: ['ver', 'crear', 'liberar'],
  devoluciones: ['ver', 'devolver', 'cambiar'],
  cortes: ['ver', 'realizar'],
  comisiones: ['ver', 'pagar'],
  finanzas: ['ver', 'registrar', 'eliminar'],
  facturacion: ['ver', 'timbrar', 'cancelar'],
  config_sistema: ['ver', 'editar'],
  usuarios: ['ver', 'gestionar'],
};

const MODULO_LABELS = {
  config_sistema: 'Configuración',
};

// Plantillas de rol — rellenan los permisos con un set predefinido.
const PLANTILLAS = {
  vendedor: {
    label: 'Vendedor (tienda)',
    permisos: {
      ventas: { ver: true, vender: true },
      clientes: { ver: true, crear: true, editar: true },
      apartados: { ver: true, crear: true },
      devoluciones: { ver: true, devolver: true, cambiar: true },
      cortes: { ver: true },
    },
  },
  encargado: {
    label: 'Encargado de tienda',
    permisos: {
      ventas: { ver: true, vender: true, anular: true, precio_especial: true },
      clientes: { ver: true, crear: true, editar: true },
      apartados: { ver: true, crear: true, liberar: true },
      devoluciones: { ver: true, devolver: true, cambiar: true },
      cortes: { ver: true, realizar: true },
      traspasos: { ver: true, aceptar: true },
      almacen: { ver: true },
      comisiones: { ver: true },
    },
  },
  admin: {
    label: 'Administrador (acceso total)',
    permisos: { admin: true },
  },
};

function buildPermisos(source = {}) {
  const out = {};
  for (const [mod, acciones] of Object.entries(PERMISO_MODULOS)) {
    out[mod] = {};
    for (const a of acciones) {
      out[mod][a] = !!source?.[mod]?.[a];
    }
  }
  out.admin = !!source?.admin;
  return out;
}

const emptyForm = () => ({
  nombre: '',
  apellido: '',
  email: '',
  password: '',
  rol: 'usuario',
  is_active: 'Si',
  id_tienda: '',
  permisos: buildPermisos(),
});

export default function UsuariosPage() {
  const [data, setData] = useState([]);
  const [almacenes, setAlmacenes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState(null);
  const [form, setForm] = useState(emptyForm());

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const [usr, alm] = await Promise.all([usuariosService.listar(), almacenesApi.listar()]);
      setData(Array.isArray(usr?.data) ? usr.data : []);
      setAlmacenes(Array.isArray(alm?.data) ? alm.data : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo cargar usuarios', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const tiendaNombre = (u) => {
    const id = u.id_tienda?._id || u.id_tienda;
    if (u.id_tienda?.nombre) return u.id_tienda.nombre;
    return almacenes.find((a) => a._id === id)?.nombre || '—';
  };

  const abrirCrear = () => {
    setEditId(null);
    setForm(emptyForm());
    setOpen(true);
  };

  const abrirEditar = (u) => {
    setEditId(u._id);
    setForm({
      nombre: u.nombre || '',
      apellido: u.apellido || '',
      email: u.email || '',
      password: '',
      rol: u.rol || 'usuario',
      is_active: u.is_active || 'Si',
      id_tienda: u.id_tienda?._id || u.id_tienda || '',
      permisos: buildPermisos(u.permisos),
    });
    setOpen(true);
  };

  const togglePermiso = (mod, accion) =>
    setForm((f) => ({
      ...f,
      permisos: { ...f.permisos, [mod]: { ...f.permisos[mod], [accion]: !f.permisos[mod][accion] } },
    }));

  const toggleAdmin = () =>
    setForm((f) => ({ ...f, permisos: { ...f.permisos, admin: !f.permisos.admin } }));

  const aplicarPlantilla = (key) => {
    const tpl = PLANTILLAS[key];
    if (!tpl) return;
    setForm((f) => ({
      ...f,
      rol: key === 'admin' ? 'admin' : 'usuario',
      permisos: buildPermisos(tpl.permisos),
    }));
  };

  const guardar = async () => {
    if (!form.nombre.trim() || !form.apellido.trim() || !form.email.trim()) {
      Swal.fire('Atención', 'Nombre, apellido y email son obligatorios', 'warning');
      return;
    }
    if (!editId && !form.password) {
      Swal.fire('Atención', 'La contraseña es obligatoria al crear', 'warning');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        nombre: form.nombre.trim(),
        apellido: form.apellido.trim(),
        email: form.email.trim().toLowerCase(),
        rol: form.rol,
        is_active: form.is_active,
        id_tienda: form.id_tienda || null,
        permisos: form.permisos,
      };
      if (editId) {
        await usuariosService.actualizar(editId, payload);
      } else {
        await usuariosService.crear({ ...payload, password: form.password });
      }
      setOpen(false);
      await cargar();
      Swal.fire('Listo', `Usuario ${editId ? 'actualizado' : 'creado'}`, 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo guardar', 'error');
    } finally {
      setSaving(false);
    }
  };

  const resetPassword = async (u) => {
    const { value: pwd } = await Swal.fire({
      title: 'Restablecer contraseña',
      text: `${u.nombre} ${u.apellido}`,
      input: 'password',
      inputplaceholder: 'Nueva contraseña',
      showCancelButton: true,
      confirmButtonText: 'Restablecer',
      cancelButtonText: 'Cancelar',
      inputValidator: (v) => (!v || v.length < 6 ? 'Mínimo 6 caracteres' : undefined),
    });
    if (!pwd) return;
    try {
      await usuariosService.resetPassword(u._id, pwd);
      Swal.fire('Listo', 'Contraseña restablecida', 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo restablecer', 'error');
    }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre', render: (r) => `${r.nombre} ${r.apellido || ''}`.trim() },
    { key: 'email', label: 'Email' },
    {
      key: 'rol',
      label: 'Rol',
      render: (r) =>
        r.rol === 'admin' ? (
          <span className="badge-primary">Admin</span>
        ) : (
          <span className="badge-slate">Usuario</span>
        ),
    },
    { key: 'tienda', label: 'Tienda', render: (r) => tiendaNombre(r) },
    {
      key: 'is_active',
      label: 'Estado',
      render: (r) =>
        r.is_active === 'Si' ? (
          <span className="badge-success">Activo</span>
        ) : (
          <span className="badge-danger">Inactivo</span>
        ),
    },
    {
      key: 'acciones',
      label: '',
      render: (r) => (
        <div className="flex gap-2">
          <button className="btn-ghost p-1.5 rounded-lg" onClick={() => abrirEditar(r)}>
            <PencilSquareIcon className="w-5 h-5 text-slate-500" />
          </button>
          <button className="btn-ghost p-1.5 rounded-lg" onClick={() => resetPassword(r)} title="Restablecer contraseña">
            <KeyIcon className="w-5 h-5 text-amber-500" />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Usuarios</h1>
        <button className="btn-primary flex items-center gap-2" onClick={abrirCrear}>
          <PlusIcon className="w-5 h-5" /> Nuevo usuario
        </button>
      </div>

      <DataTable columns={columns} data={data} loading={loading} empty="Sin usuarios" exportName="usuarios" exportTitle="Usuarios" />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editId ? 'Editar usuario' : 'Nuevo usuario'}
        size="xl"
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
        <div className="space-y-5">
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
              <label className="block text-sm font-medium text-slate-700 mb-1">Email *</label>
              <input
                type="email"
                className="input-base"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </div>
            {!editId && (
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">Contraseña *</label>
                <input
                  type="password"
                  className="input-base"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                />
              </div>
            )}
          </div>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Rol</label>
              <select
                className="input-base"
                value={form.rol}
                onChange={(e) => setForm({ ...form, rol: e.target.value })}
              >
                <option value="usuario">Usuario</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Estado</label>
              <select
                className="input-base"
                value={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.value })}
              >
                <option value="Si">Activo</option>
                <option value="No">Inactivo</option>
              </select>
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

          {/* Permisos */}
          <div className="card p-4 space-y-3">
            <div className="flex items-center justify-between flex-wrap gap-2">
              <h4 className="font-semibold text-slate-800">Permisos</h4>
              <div className="flex items-center gap-3">
                <select
                  className="input-base !py-1 text-sm w-auto"
                  defaultValue=""
                  onChange={(e) => { aplicarPlantilla(e.target.value); e.target.value = ''; }}
                  title="Aplicar una plantilla de permisos"
                >
                  <option value="" disabled>Aplicar plantilla…</option>
                  {Object.entries(PLANTILLAS).map(([k, t]) => (
                    <option key={k} value={k}>{t.label}</option>
                  ))}
                </select>
                <label className="flex items-center gap-2 text-sm font-medium text-primary-700">
                  <input type="checkbox" className="w-4 h-4" checked={form.permisos.admin} onChange={toggleAdmin} />
                  Acceso total (admin)
                </label>
              </div>
            </div>
            {form.permisos.admin ? (
              <p className="text-sm text-slate-500">
                Este usuario tiene acceso completo; los permisos por módulo se ignoran.
              </p>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {Object.entries(PERMISO_MODULOS).map(([mod, acciones]) => (
                  <div key={mod} className="border border-slate-200 rounded-lg p-3">
                    <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
                      {MODULO_LABELS[mod] || mod}
                    </p>
                    <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                      {acciones.map((a) => (
                        <label key={a} className="flex items-center gap-1.5 text-sm text-slate-700">
                          <input
                            type="checkbox"
                            className="w-4 h-4"
                            checked={!!form.permisos[mod]?.[a]}
                            onChange={() => togglePermiso(mod, a)}
                          />
                          {a.replace(/_/g, ' ')}
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </Modal>
    </div>
  );
}
