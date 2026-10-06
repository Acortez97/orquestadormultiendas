import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Swal from 'sweetalert2';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { plataformaApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';

const slugify = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9-]+/g, '').replace(/^-+/, '').slice(0, 40);
const nueva = { nombre: '', slug: '', rfc: '', iva: 16, telefono: '', direccion: '', max_usuarios: '', max_almacenes: '', admin_nombre: '', admin_password: '', modulos: null };

export default function AdminTiendas() {
  const { entrarSoporte } = useAuth();
  const navigate = useNavigate();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [catalogo, setCatalogo] = useState([]);
  const [dominioBase, setDominioBase] = useState('');
  const [modal, setModal] = useState(null);          // 'nueva' | 'editar' | 'modulos'
  const [form, setForm] = useState(nueva);
  const [actual, setActual] = useState(null);
  const [modsSel, setModsSel] = useState([]);

  const cargar = async () => {
    setLoading(true);
    try {
      const r = await plataformaApi.tiendas();
      setRows(r.data || []);
      const t = (r.data || [])[0];
      if (t) setDominioBase(t.dominio.slice(t.slug.length + 1));
    } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); plataformaApi.modulos().then((r) => setCatalogo(r.data || [])); }, []);

  const err = (e) => Swal.fire('Error', e.message, 'error');

  // ---- alta ----
  const abrirNueva = () => { setForm({ ...nueva, modulos: catalogo.map((m) => m.clave) }); setModal('nueva'); };
  const crear = async () => {
    try {
      const r = await plataformaApi.crearTienda({
        ...form, iva: Number(form.iva) / 100,
        max_usuarios: form.max_usuarios || null, max_almacenes: form.max_almacenes || null,
      });
      setModal(null); cargar();
      const a = r.data.admin;
      Swal.fire({ icon: 'success', title: 'Tienda creada',
        html: `<p>Acceso del administrador:</p><p><b>${a.login}</b></p>`
            + (a.password ? `<p>Contraseña temporal: <b>${a.password}</b></p><p class="text-sm text-slate-500">Cópiala ahora: no se volverá a mostrar.</p>` : '')
            + '<p class="text-sm">Deberá cambiarla en su primer acceso.</p>' });
    } catch (e) { err(e); }
  };

  // ---- edicion ----
  const abrirEditar = (t) => {
    setActual(t);
    setForm({ ...t, iva: Math.round(t.iva * 10000) / 100, max_usuarios: t.max_usuarios ?? '', max_almacenes: t.max_almacenes ?? '' });
    setModal('editar');
  };
  const guardarEdicion = async () => {
    try {
      await plataformaApi.editarTienda(actual._id, {
        nombre: form.nombre, rfc: form.rfc, iva: Number(form.iva) / 100, telefono: form.telefono, direccion: form.direccion,
        logo_url: form.logo_url, notas: form.notas, max_usuarios: form.max_usuarios || null, max_almacenes: form.max_almacenes || null,
      });
      setModal(null); cargar();
    } catch (e) { err(e); }
  };

  // ---- modulos ----
  const abrirModulos = async (t) => {
    setActual(t);
    const r = await plataformaApi.modulosTienda(t._id);
    setModsSel((r.data || []).filter((m) => m.activo).map((m) => m.clave));
    setModal('modulos');
  };
  const guardarModulos = async () => {
    try { await plataformaApi.guardarModulos(actual._id, modsSel); setModal(null); }
    catch (e) { err(e); }
  };

  // ---- acciones ----
  const alternarEstado = async (t) => {
    const suspender = t.is_active === 'Si';
    const c = await Swal.fire({
      title: suspender ? `¿Suspender «${t.nombre}»?` : `¿Reactivar «${t.nombre}»?`,
      text: suspender ? 'Sus usuarios no podrán entrar ni operar. Sus datos se conservan.' : 'Sus usuarios podrán volver a entrar.',
      icon: 'warning', showCancelButton: true, confirmButtonColor: suspender ? '#e11d48' : undefined,
    });
    if (!c.isConfirmed) return;
    try { await plataformaApi.estadoTienda(t._id, suspender ? 'No' : 'Si'); cargar(); } catch (e) { err(e); }
  };

  const AVISO_DEFAULT = 'Tu mensualidad está pendiente de pago. Realízalo a la brevedad para evitar la suspensión del servicio.';
  const avisoPago = async (t) => {
    if (t.aviso_pago) {
      const c = await Swal.fire({ title: `¿Quitar el aviso de pago de «${t.nombre}»?`, text: t.aviso_pago, icon: 'question', showCancelButton: true, confirmButtonText: 'Quitar aviso' });
      if (!c.isConfirmed) return;
      try { await plataformaApi.avisoPago(t._id, ''); cargar(); } catch (e) { err(e); }
      return;
    }
    const { value, isConfirmed } = await Swal.fire({
      title: `Aviso de pago para «${t.nombre}»`,
      text: 'Todos sus usuarios lo verán en un banner rojo en todas las pantallas hasta que lo quites.',
      input: 'textarea', inputValue: AVISO_DEFAULT, inputAttributes: { maxlength: 500 },
      showCancelButton: true, confirmButtonText: 'Publicar aviso', confirmButtonColor: '#e11d48',
      inputValidator: (v) => (!v.trim() ? 'Escribe el mensaje' : undefined),
    });
    if (!isConfirmed) return;
    try { await plataformaApi.avisoPago(t._id, value); cargar(); } catch (e) { err(e); }
  };

  const entrar = async (t) => {
    const c = await Swal.fire({ title: `Entrar a «${t.nombre}» en modo soporte`,
      text: 'Operarás la tienda durante 2 horas. Todo lo que hagas quedará en su bitácora como "Soporte".',
      icon: 'info', showCancelButton: true, confirmButtonText: 'Entrar' });
    if (!c.isConfirmed) return;
    try {
      const r = await plataformaApi.entrar(t._id);
      await entrarSoporte(r.data.token);
      navigate('/dashboard', { replace: true });
    } catch (e) { err(e); }
  };

  const conciliar = async (t) => {
    try {
      const r = (await plataformaApi.conciliar(t._id)).data;
      Swal.fire(r.cuadra ? 'Saldos correctos' : 'Hay diferencias',
        r.cuadra ? 'Los saldos de clientes cuadran con sus movimientos.'
          : r.diferencias.map((d) => `${d.cliente} (${d.tipo}): saldo ${d.saldo} vs movimientos ${d.movimientos}`).join('<br>'),
        r.cuadra ? 'success' : 'warning');
    } catch (e) { err(e); }
  };

  const respaldo = async (t) => {
    try {
      const res = await plataformaApi.exportar(t._id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url; a.download = `respaldo-${t.slug}.json`; a.click();
      URL.revokeObjectURL(url);
    } catch (e) { err(e); }
  };

  const columns = [
    { key: 'nombre', label: 'Tienda' },
    { key: 'dominio', label: 'Correos de acceso', render: (t) => <span className="text-slate-600">usuario@{t.dominio}</span> },
    { key: 'is_active', label: 'Estado', render: (t) => (
      <div className="flex flex-wrap gap-1">
        {t.is_active === 'Si' ? <span className="badge-success">Activa</span> : <span className="badge-danger">Suspendida</span>}
        {t.aviso_pago && <span className="badge-danger" title={t.aviso_pago}>Aviso de pago</span>}
      </div>
    ) },
    { key: 'acc', label: '', noExport: true, render: (t) => (
      <div className="flex flex-wrap gap-1">
        <button className="btn-ghost text-sm" onClick={() => abrirEditar(t)}>Editar</button>
        <button className="btn-ghost text-sm" onClick={() => abrirModulos(t)}>Módulos</button>
        <button className="btn-ghost text-sm" onClick={() => navigate(`/admin/usuarios?tienda=${t._id}`)}>Usuarios</button>
        <button className="btn-ghost text-sm" onClick={() => entrar(t)} disabled={t.is_active !== 'Si'}>Entrar</button>
        <button className="btn-ghost text-sm" onClick={() => conciliar(t)}>Conciliar</button>
        <button className="btn-ghost text-sm" onClick={() => respaldo(t)}>Respaldo</button>
        <button className={`btn-ghost text-sm ${t.aviso_pago ? 'text-emerald-600' : 'text-rose-600'}`} onClick={() => avisoPago(t)}>
          {t.aviso_pago ? 'Quitar aviso de pago' : 'Aviso de pago'}
        </button>
        <button className={`btn-ghost text-sm ${t.is_active === 'Si' ? 'text-rose-600' : 'text-emerald-600'}`} onClick={() => alternarEstado(t)}>
          {t.is_active === 'Si' ? 'Suspender' : 'Reactivar'}
        </button>
      </div>
    ) },
  ];

  const campo = (k, label, props = {}) => (
    <label className="text-sm block">{label}
      <input className="input-base" value={form[k] ?? ''} {...props} onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
    </label>
  );

  const pie = (onSave, texto = 'Guardar') => (
    <><button className="btn-secondary" onClick={() => setModal(null)}>Cancelar</button><button className="btn-primary" onClick={onSave}>{texto}</button></>
  );

  const checksModulos = (sel, setSel) => (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
      {catalogo.map((m) => (
        <label key={m.clave} className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={sel.includes(m.clave)}
            onChange={(e) => setSel(e.target.checked ? [...sel, m.clave] : sel.filter((x) => x !== m.clave))} />
          {m.nombre}
        </label>
      ))}
    </div>
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Tiendas</h1>
        <button className="btn-primary" onClick={abrirNueva}><PlusIcon className="w-4 h-4" /> Nueva tienda</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Aún no hay tiendas" exportName="Tiendas" exportTitle="Tiendas" />

      <Modal open={modal === 'nueva'} onClose={() => setModal(null)} size="xl" title="Nueva tienda" footer={pie(crear, 'Crear tienda')}>
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="text-sm block">Nombre del negocio
              <input className="input-base" value={form.nombre}
                onChange={(e) => setForm({ ...form, nombre: e.target.value, slug: form.slugTocado ? form.slug : slugify(e.target.value) })} />
            </label>
            <label className="text-sm block">Subdominio de acceso (no se puede cambiar después)
              <input className="input-base" value={form.slug} onChange={(e) => setForm({ ...form, slug: slugify(e.target.value), slugTocado: true })} />
            </label>
          </div>
          <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            El administrador entrará con <b>admin@{form.slug || 'subdominio'}.{dominioBase || 'levotek.com'}</b>.
            Usa el nombre del negocio, no números consecutivos (tienda1, tienda2…), para que nadie pueda adivinar otras tiendas.
          </p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {campo('rfc', 'RFC')}
            {campo('iva', 'IVA (%)', { type: 'number', min: 0, max: 99 })}
            {campo('telefono', 'Teléfono')}
            {campo('max_usuarios', 'Máx. usuarios (vacío = sin límite)', { type: 'number', min: 1 })}
            {campo('max_almacenes', 'Máx. almacenes (vacío = sin límite)', { type: 'number', min: 1 })}
            {campo('direccion', 'Dirección')}
            {campo('admin_nombre', 'Nombre del administrador')}
            {campo('admin_password', 'Contraseña temporal (vacío = generar)', { type: 'password', autoComplete: 'new-password' })}
          </div>
          <div>
            <p className="mb-2 text-sm font-semibold text-slate-700">Módulos habilitados</p>
            {checksModulos(form.modulos || [], (m) => setForm({ ...form, modulos: m }))}
          </div>
        </div>
      </Modal>

      <Modal open={modal === 'editar'} onClose={() => setModal(null)} size="lg" title={`Editar ${actual?.nombre || ''}`} footer={pie(guardarEdicion)}>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {campo('nombre', 'Nombre')}
          <label className="text-sm block">Subdominio<input className="input-base bg-slate-50" value={form.slug || ''} disabled /></label>
          {campo('rfc', 'RFC')}
          {campo('iva', 'IVA (%)', { type: 'number', min: 0, max: 99 })}
          {campo('telefono', 'Teléfono')}
          {campo('direccion', 'Dirección')}
          {campo('logo_url', 'URL del logo')}
          {campo('max_usuarios', 'Máx. usuarios', { type: 'number', min: 1 })}
          {campo('max_almacenes', 'Máx. almacenes', { type: 'number', min: 1 })}
          {campo('notas', 'Notas internas')}
        </div>
      </Modal>

      <Modal open={modal === 'modulos'} onClose={() => setModal(null)} size="lg" title={`Módulos de ${actual?.nombre || ''}`} footer={pie(guardarModulos)}>
        <p className="mb-3 text-sm text-slate-500">Al deshabilitar un módulo desaparece para todos los usuarios de la tienda; sus permisos se conservan por si se vuelve a habilitar.</p>
        {checksModulos(modsSel, setModsSel)}
      </Modal>
    </div>
  );
}
