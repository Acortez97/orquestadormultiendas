import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import Swal from 'sweetalert2';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import PermisosEditor from '../../components/common/PermisosEditor';
import { plataformaApi } from '../../services/api/endpoints';

// Usuarios de todas las tiendas. Aqui el superadmin crea admins de tienda y ajusta cualquier usuario.
const vacio = { usuario: '', nombre: '', apellido: '', rol: 'usuario', password: '', id_tienda: '', permisos: {}, is_active: 'Si' };

export default function AdminUsuarios() {
  const [params, setParams] = useSearchParams();
  const tiendaSel = params.get('tienda') || '';
  const [tiendas, setTiendas] = useState([]);
  const [catalogo, setCatalogo] = useState([]);
  const [detalle, setDetalle] = useState(null);     // tienda seleccionada: modulos activos y almacenes
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editar, setEditar] = useState(null);

  useEffect(() => {
    plataformaApi.tiendas().then((r) => setTiendas(r.data || []));
    plataformaApi.modulos().then((r) => setCatalogo(r.data || []));
  }, []);

  const cargar = async () => {
    setLoading(true);
    try {
      setRows(((tiendaSel ? await plataformaApi.usuariosTienda(tiendaSel) : await plataformaApi.usuarios()).data) || []);
      setDetalle(tiendaSel ? (await plataformaApi.tienda(tiendaSel)).data : null);
    } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); /* eslint-disable-next-line */ }, [tiendaSel]);

  const modulosTienda = useMemo(
    () => catalogo.filter((m) => detalle?.modulos?.includes(m.clave)),
    [catalogo, detalle]);

  const abrir = async (u) => {
    if (u && u.tienda?._id !== tiendaSel) { setParams({ tienda: u.tienda._id }); return; }
    setEditar(u || null);
    setForm(u ? { usuario: u.usuario, nombre: u.nombre, apellido: u.apellido || '', rol: u.rol, password: '',
                  id_tienda: u.id_tienda || '', permisos: u.permisos || {}, is_active: u.is_active } : vacio);
    setOpen(true);
  };

  const guardar = async () => {
    try {
      const data = { ...form, permisos: form.rol === 'usuario' ? form.permisos : {} };
      if (editar) {
        delete data.password;
        await plataformaApi.editarUsuario(editar._id, data);
        setOpen(false); cargar();
      } else {
        const r = await plataformaApi.crearUsuario(tiendaSel, data);
        setOpen(false); cargar();
        Swal.fire({ icon: 'success', title: 'Usuario creado',
          html: `<p><b>${r.data.login}</b></p>` + (r.data.password_generada ? `<p>Contraseña temporal: <b>${r.data.password_generada}</b></p><p class="text-sm">Cópiala ahora: no se volverá a mostrar.</p>` : '') });
      }
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const resetear = async (u) => {
    const c = await Swal.fire({ title: `Restablecer contraseña de ${u.login}`, text: 'Se generará una contraseña temporal y se cerrarán sus sesiones.', icon: 'warning', showCancelButton: true });
    if (!c.isConfirmed) return;
    try {
      const r = await plataformaApi.resetPassword(u._id);
      Swal.fire('Contraseña temporal', `<b>${r.data.password_generada}</b><br><span class="text-sm">Cópiala ahora: no se volverá a mostrar.</span>`, 'success');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const desactivar = async (u) => {
    const c = await Swal.fire({ title: `¿Desactivar a ${u.login}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (!c.isConfirmed) return;
    try { await plataformaApi.desactivarUsuario(u._id); cargar(); } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const dominio = detalle?.dominio || '';
  const columns = [
    { key: 'nombreCompleto', label: 'Nombre' },
    { key: 'login', label: 'Correo de acceso' },
    { key: 'tienda', label: 'Tienda', exportValue: (u) => u.tienda?.nombre, render: (u) => u.tienda?.nombre },
    { key: 'rol', label: 'Rol', render: (u) => (u.rol === 'admin_tienda' ? <span className="badge-primary">Admin de tienda</span> : 'Usuario') },
    { key: 'is_active', label: 'Estado', render: (u) => (u.is_active === 'Si' ? <span className="badge-success">Activo</span> : <span className="badge-slate">Inactivo</span>) },
    { key: 'acc', label: '', noExport: true, render: (u) => (
      <div className="flex gap-1">
        <button className="btn-ghost text-sm" onClick={() => abrir(u)}>Editar</button>
        <button className="btn-ghost text-sm" onClick={() => resetear(u)}>Contraseña</button>
        {u.is_active === 'Si' && <button className="btn-ghost text-sm text-rose-600" onClick={() => desactivar(u)}>Desactivar</button>}
      </div>
    ) },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-slate-800">Usuarios</h1>
        <div className="flex gap-2">
          <select className="input-base w-64" value={tiendaSel} onChange={(e) => setParams(e.target.value ? { tienda: e.target.value } : {})}>
            <option value="">Todas las tiendas</option>
            {tiendas.map((t) => <option key={t._id} value={t._id}>{t.nombre}</option>)}
          </select>
          <button className="btn-primary" disabled={!tiendaSel} title={tiendaSel ? '' : 'Elige una tienda'} onClick={() => abrir(null)}>
            <PlusIcon className="w-4 h-4" /> Nuevo
          </button>
        </div>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin usuarios" exportName="Usuarios" exportTitle="Usuarios" />

      <Modal open={open} onClose={() => setOpen(false)} size="xl" title={editar ? `Editar ${editar.login}` : `Nuevo usuario en ${detalle?.nombre || ''}`}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="text-sm block">Usuario
              <div className="flex items-center">
                <input className="input-base rounded-r-none" value={form.usuario}
                  onChange={(e) => setForm({ ...form, usuario: e.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, '') })} />
                <span className="whitespace-nowrap rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">@{dominio}</span>
              </div>
            </label>
            <label className="text-sm block">Rol
              <select className="input-base" value={form.rol} onChange={(e) => setForm({ ...form, rol: e.target.value })}>
                <option value="usuario">Usuario (permisos por módulo)</option>
                <option value="admin_tienda">Administrador de la tienda</option>
              </select>
            </label>
            <label className="text-sm block">Nombre<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
            <label className="text-sm block">Apellido<input className="input-base" value={form.apellido} onChange={(e) => setForm({ ...form, apellido: e.target.value })} /></label>
            {!editar && (
              <label className="text-sm block">Contraseña temporal (vacío = generar)
                <input type="password" className="input-base" value={form.password} autoComplete="new-password"
                  onChange={(e) => setForm({ ...form, password: e.target.value })} />
              </label>
            )}
            <label className="text-sm block">Almacén por defecto
              <select className="input-base" value={form.id_tienda} onChange={(e) => setForm({ ...form, id_tienda: e.target.value })}>
                <option value="">—</option>
                {(detalle?.almacenes || []).map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </label>
            {editar && (
              <label className="text-sm block">Estado
                <select className="input-base" value={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.value })}>
                  <option value="Si">Activo</option><option value="No">Inactivo</option>
                </select>
              </label>
            )}
          </div>
          {form.rol === 'usuario' ? (
            <div>
              <p className="mb-2 text-sm font-semibold text-slate-700">Permisos (solo módulos habilitados en la tienda)</p>
              <PermisosEditor modulos={modulosTienda} value={form.permisos} onChange={(permisos) => setForm({ ...form, permisos })} />
            </div>
          ) : (
            <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">El administrador de la tienda tiene todos los módulos habilitados de su tienda y puede gestionar a sus usuarios.</p>
          )}
        </div>
      </Modal>
    </div>
  );
}
