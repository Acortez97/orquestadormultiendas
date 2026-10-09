import { useEffect, useState } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import PermisosEditor, { soloHabilitados } from '../../components/common/PermisosEditor';
import { usuariosTiendaApi, almacenesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';

// Usuarios de la tienda. Solo el administrador de la tienda entra aqui.
// El correo de acceso es <usuario>@<dominio de la tienda>: el dominio lo pone el servidor.
const vacio = { usuario: '', nombre: '', apellido: '', email_contacto: '', password: '', id_tienda: '', permisos: {} };

export default function UsuariosPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState([]);
  const [modulos, setModulos] = useState([]);
  const [dominio, setDominio] = useState('');
  const [almacenes, setAlmacenes] = useState([]);
  // El formulario necesita los modulos y el dominio de la tienda: hasta tenerlos no se puede abrir
  const [listo, setListo] = useState(false);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editId, setEditId] = useState(null);

  const cargar = async () => {
    setLoading(true);
    try { setRows((await usuariosTiendaApi.listar()).data || []); } finally { setLoading(false); }
  };
  useEffect(() => {
    cargar();
    Promise.all([usuariosTiendaApi.modulos(), almacenesApi.listar()])
      .then(([m, a]) => {
        setModulos(m.data.modulos || []); setDominio(m.data.dominio || '');
        setAlmacenes(a.data || []);
        setListo(true);
      })
      .catch((e) => Swal.fire('Error', `No se pudieron cargar los módulos de la tienda: ${e.message}`, 'error'));
  }, []);

  const abrir = (u) => {
    setEditId(u?._id || null);
    setForm(u ? { usuario: u.usuario, nombre: u.nombre, apellido: u.apellido || '', email_contacto: u.email_contacto || '',
                  password: '', id_tienda: u.id_tienda || '', permisos: u.permisos || {} } : vacio);
    setOpen(true);
  };

  const guardar = async () => {
    try {
      const data = { ...form, permisos: soloHabilitados(form.permisos, modulos) };
      if (editId) delete data.password;
      if (editId) await usuariosTiendaApi.actualizar(editId, data); else await usuariosTiendaApi.crear(data);
      setOpen(false);
      cargar();
      if (!editId) Swal.fire('Usuario creado', `Acceso: ${form.usuario}@${dominio}<br>Debe cambiar su contraseña al entrar.`, 'success');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const alternarActivo = async (u) => {
    const activar = u.is_active === 'No';
    const c = await Swal.fire({ title: `${activar ? 'Reactivar' : 'Desactivar'} a ${u.nombreCompleto}?`, icon: 'question', showCancelButton: true });
    if (!c.isConfirmed) return;
    try {
      if (activar) await usuariosTiendaApi.actualizar(u._id, { is_active: 'Si' }); else await usuariosTiendaApi.desactivar(u._id);
      cargar();
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const resetear = async (u) => {
    const { value: pass } = await Swal.fire({ title: `Nueva contraseña para ${u.login}`, input: 'password',
      inputAttributes: { minlength: 8, autocomplete: 'new-password' }, showCancelButton: true,
      inputValidator: (v) => (!v || v.length < 8 ? 'Mínimo 8 caracteres' : undefined) });
    if (!pass) return;
    try { await usuariosTiendaApi.resetPassword(u._id, pass); Swal.fire('Listo', 'Deberá cambiarla al entrar.', 'success'); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const columns = [
    { key: 'nombreCompleto', label: 'Nombre' },
    { key: 'login', label: 'Correo de acceso' },
    { key: 'rol', label: 'Rol', render: (u) => (u.rol === 'admin_tienda' ? <span className="badge-primary">Administrador</span> : 'Usuario') },
    { key: 'is_active', label: 'Estado', render: (u) => (u.is_active === 'Si' ? <span className="badge-success">Activo</span> : <span className="badge-slate">Inactivo</span>) },
    { key: 'ultimo_acceso', label: 'Último acceso', render: (u) => u.ultimo_acceso || '—' },
    { key: 'acc', label: '', noExport: true, render: (u) => (u.rol !== 'usuario' || u._id === user?._id ? null : (
      <div className="flex gap-2">
        <button className="btn-ghost text-sm" onClick={() => abrir(u)} disabled={!listo}>Editar</button>
        <button className="btn-ghost text-sm" onClick={() => resetear(u)}>Contraseña</button>
        <button className="btn-ghost text-sm text-rose-600" onClick={() => alternarActivo(u)}>{u.is_active === 'Si' ? 'Desactivar' : 'Reactivar'}</button>
      </div>
    )) },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Usuarios y permisos</h1>
          <p className="text-sm text-slate-500">
            {dominio && <>Los correos de acceso terminan en <b>@{dominio}</b>. </>}
            Los administradores los gestiona el administrador general.
          </p>
        </div>
        <button className="btn-primary" onClick={() => abrir(null)} disabled={!listo}>
          <PlusIcon className="w-4 h-4" /> {listo ? 'Nuevo usuario' : 'Cargando…'}
        </button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin usuarios" exportName="Usuarios" exportTitle="Usuarios" />

      <Modal open={open} onClose={() => setOpen(false)} size="xl" title={editId ? 'Editar usuario' : 'Nuevo usuario'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="text-sm block">Usuario (correo de acceso)
              <div className="flex items-center">
                <input className="input-base rounded-r-none" value={form.usuario} placeholder="cajero1"
                  onChange={(e) => setForm({ ...form, usuario: e.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, '') })} />
                <span className="whitespace-nowrap rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">@{dominio}</span>
              </div>
            </label>
            {!editId && (
              <label className="text-sm block">Contraseña temporal
                <input type="password" className="input-base" value={form.password} autoComplete="new-password" placeholder="mínimo 8 caracteres"
                  onChange={(e) => setForm({ ...form, password: e.target.value })} />
              </label>
            )}
            <label className="text-sm block">Nombre<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
            <label className="text-sm block">Apellido<input className="input-base" value={form.apellido} onChange={(e) => setForm({ ...form, apellido: e.target.value })} /></label>
            <label className="text-sm block">Correo de contacto (opcional)
              <input type="email" className="input-base" value={form.email_contacto} onChange={(e) => setForm({ ...form, email_contacto: e.target.value })} />
            </label>
            <label className="text-sm block">Almacén por defecto
              <select className="input-base" value={form.id_tienda} onChange={(e) => setForm({ ...form, id_tienda: e.target.value })}>
                <option value="">—</option>
                {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </label>
          </div>
          <div>
            <p className="mb-2 text-sm font-semibold text-slate-700">Permisos</p>
            <PermisosEditor modulos={modulos} value={form.permisos} onChange={(permisos) => setForm({ ...form, permisos })} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
