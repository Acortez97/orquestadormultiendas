import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Swal from 'sweetalert2';
import { PlusIcon, EllipsisHorizontalIcon } from '@heroicons/react/24/outline';
import Modal from '../../components/common/Modal';
import SelectorColor, { colorLegible } from '../../components/common/SelectorColor';
import { iniciales } from '../../components/layout/Marca';
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
        color: form.color || '',
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

  // Cada tienda es una tarjeta: estado, accesos y las acciones mas usadas; el resto en «···»
  const Tarjeta = ({ t }) => (
    <article className="card flex flex-col gap-4 p-5">
      <div className="flex items-center gap-3">
        {t.logo_url
          ? <img src={t.logo_url} alt="" className="h-11 w-11 shrink-0 rounded-[11px] bg-white object-contain" />
          : <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-[11px] font-bold text-white" style={{ background: t.color || '#1F4FD1' }}>{iniciales(t.nombre)}</span>}
        <div className="min-w-0 flex-1">
          <p className="truncate text-[17px] font-bold">{t.nombre}</p>
          <p className="truncate font-mono text-xs text-slate-600">usuario@{t.dominio}</p>
        </div>
        {t.is_active === 'Si' ? <span className="badge-success">Activa</span> : <span className="badge-danger">Suspendida</span>}
      </div>
      {t.aviso_pago && <p className="rounded-lg bg-danger-100 px-3 py-2 text-sm font-semibold text-danger-600" title={t.aviso_pago}>Con aviso de pago</p>}
      <div className="grid grid-cols-2 gap-2 text-sm">
        <div className="rounded-[10px] bg-slate-100 p-2.5"><p className="text-xs text-slate-600">Usuarios</p><p className="font-bold">{t.max_usuarios ? `máx. ${t.max_usuarios}` : 'sin límite'}</p></div>
        <div className="rounded-[10px] bg-slate-100 p-2.5"><p className="text-xs text-slate-600">Almacenes</p><p className="font-bold">{t.max_almacenes ? `máx. ${t.max_almacenes}` : 'sin límite'}</p></div>
      </div>
      <div className="mt-auto flex flex-wrap gap-2">
        <button type="button" className="btn-accent flex-1 bg-plataforma" onClick={() => entrar(t)} disabled={t.is_active !== 'Si'}>Entrar como soporte</button>
        <button type="button" className="btn-secondary" onClick={() => abrirEditar(t)}>Configurar</button>
        <details className="relative">
          <summary className="btn-secondary w-11 cursor-pointer list-none px-0" aria-label={`Más acciones de ${t.nombre}`}><EllipsisHorizontalIcon className="h-5 w-5" /></summary>
          <div className="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
            {[
              ['Módulos', () => abrirModulos(t)],
              ['Usuarios', () => navigate(`/admin/usuarios?tienda=${t._id}`)],
              ['Conciliar saldos', () => conciliar(t)],
              ['Descargar respaldo', () => respaldo(t)],
              [t.aviso_pago ? 'Quitar aviso de pago' : 'Poner aviso de pago', () => avisoPago(t), t.aviso_pago ? '' : 'text-danger-600'],
              [t.is_active === 'Si' ? 'Suspender tienda' : 'Reactivar tienda', () => alternarEstado(t), t.is_active === 'Si' ? 'text-danger-600' : 'text-success-600'],
            ].map(([l, fn, cls]) => (
              <button key={l} type="button" className={`block min-h-10 w-full px-4 text-left text-sm hover:bg-slate-50 ${cls || ''}`}
                onClick={(e) => { e.currentTarget.closest('details').open = false; fn(); }}>{l}</button>
            ))}
          </div>
        </details>
      </div>
    </article>
  );

  const campo = (k, label, props = {}) => (
    <label className="text-sm block">{label}
      <input className="input-base" value={form[k] ?? ''} {...props} onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
    </label>
  );

  const pie = (onSave, texto = 'Guardar') => (
    <><button className="btn-secondary" onClick={() => setModal(null)}>Cancelar</button>
      <button className="btn-primary" disabled={modal === 'editar' && form.color && !colorLegible(form.color)} onClick={onSave}>{texto}</button></>
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
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">Tiendas</h1>
          {!loading && (
            <p className="text-slate-600">
              {rows.length} {rows.length === 1 ? 'tienda' : 'tiendas'} · {rows.filter((t) => t.is_active === 'Si').length} activas · {rows.filter((t) => t.aviso_pago).length} con aviso de pago
            </p>
          )}
        </div>
        <button className="btn-accent bg-plataforma" onClick={abrirNueva}><PlusIcon className="h-5 w-5" />Nueva tienda</button>
      </div>
      {loading && <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <div key={i} className="skeleton h-56 rounded-[14px]" />)}</div>}
      {!loading && !rows.length && <p className="card p-8 text-center text-slate-600">Aún no hay tiendas.</p>}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {rows.map((t) => <Tarjeta key={t._id} t={t} />)}
      </div>

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
          <div className="sm:col-span-2">
            <p className="mb-2 text-sm">Color de la tienda en la interfaz</p>
            <SelectorColor value={form.color || ''} onChange={(c) => setForm({ ...form, color: c })} />
          </div>
        </div>
      </Modal>

      <Modal open={modal === 'modulos'} onClose={() => setModal(null)} size="lg" title={`Módulos de ${actual?.nombre || ''}`} footer={pie(guardarModulos)}>
        <p className="mb-3 text-sm text-slate-600">Al deshabilitar un módulo desaparece para todos los usuarios de la tienda y se les quitan sus permisos; si lo vuelves a habilitar, hay que asignarlos de nuevo.</p>
        {checksModulos(modsSel, setModsSel)}
      </Modal>
    </div>
  );
}
