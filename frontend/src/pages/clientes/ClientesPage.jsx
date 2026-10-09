import { useEffect, useState } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon, KeyIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import EstadoCuentaCliente from '../../components/common/EstadoCuentaCliente';
import { clientesApi, almacenesApi, monederoApi, configApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { aviso } from '../../utils/avisos';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const vacio = {
  nombre: '', telefono: '', rfc: '', lista_precios: 1, id_tienda: '',
  domicilio: { calle: '', colonia: '', ciudad: '', estado: '', codigo_postal: '' },
};

export default function ClientesPage() {
  const { hasPermiso, user } = useAuth();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editId, setEditId] = useState(null);
  const [listaOriginal, setListaOriginal] = useState(1);
  const [tiendas, setTiendas] = useState([]);
  const [search, setSearch] = useState('');
  const [estadoCli, setEstadoCli] = useState(null);
  const puedeCredito = hasPermiso('clientes.autorizar_credito');
  const puedeCambiarPin = hasPermiso('configuracion.editar'); // el PIN de listas 4/5 es configuracion de la tienda

  const [pinEstado, setPinEstado] = useState(null);
  const [showPin, setShowPin] = useState(false);
  const [pinForm, setPinForm] = useState({ pin: '', pin2: '' });
  const [savingPin, setSavingPin] = useState(false);

  useEffect(() => {
    if (puedeCambiarPin) configApi.estadoPinListaAlta().then((r) => setPinEstado(r.data)).catch(() => {});
  }, [puedeCambiarPin]);

  const cargar = async () => {
    setLoading(true);
    try { const res = await clientesApi.listar(search ? { search } : {}); setRows(res.data || []); } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); /* eslint-disable-next-line */ }, []);
  useEffect(() => { almacenesApi.listar().then((r) => setTiendas((r.data || []).filter((a) => a.tipo === 'tienda'))); }, []);

  const abrirNuevo = () => { setForm(vacio); setEditId(null); setListaOriginal(1); setOpen(true); };
  const abrirEdit = (r) => {
    setForm({ ...vacio, ...r, id_tienda: r.id_tienda?._id || r.id_tienda || '', domicilio: { ...vacio.domicilio, ...(r.domicilio || {}) } });
    setEditId(r._id); setListaOriginal(Number(r.lista_precios) || 1); setOpen(true);
  };

  const guardar = async () => {
    const lista = Number(form.lista_precios);
    let pin;
    // Listas 4 y 5 requieren SIEMPRE el PIN de autorización (incluido el admin).
    if (lista >= 4 && lista !== listaOriginal) {
      const { value, isConfirmed } = await Swal.fire({
        title: 'PIN de autorización',
        html: `La <b>Lista ${lista}</b> requiere autorización. Ingresa el PIN.`,
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'PIN (4 a 6 dígitos)',
        inputAttributes: { maxlength: 6, inputmode: 'numeric', autocomplete: 'off' },
        showCancelButton: true,
        confirmButtonText: 'Autorizar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#d97706',
        preConfirm: (v) => {
          if (!v || !/^\d{4,6}$/.test(v)) {
            Swal.showValidationMessage('El PIN debe ser numérico de 4 a 6 dígitos');
            return false;
          }
          return v;
        },
      });
      if (!isConfirmed || !value) return;
      pin = value;
    }
    try {
      // los saldos no se editan aqui (solo cambian con movimientos) y el credito se da desde el boton "Crédito"
      const { saldo_credito: _sc, saldo_favor: _sf, ...datos } = form;
      const payload = { ...datos, id_tienda: form.id_tienda || undefined };
      if (pin) payload.pin = pin;
      if (editId) await clientesApi.actualizar(editId, payload); else await clientesApi.crear(payload);
      setOpen(false); cargar();
      aviso('Guardado');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const abrirPin = () => { setPinForm({ pin: '', pin2: '' }); setShowPin(true); };
  const guardarPin = async () => {
    if (!/^\d{4,6}$/.test(pinForm.pin)) {
      Swal.fire('PIN inválido', 'Debe ser numérico de 4 a 6 dígitos.', 'warning');
      return;
    }
    if (pinForm.pin !== pinForm.pin2) {
      Swal.fire('No coincide', 'La confirmación del PIN no coincide.', 'warning');
      return;
    }
    setSavingPin(true);
    try {
      const r = await configApi.cambiarPinListaAlta(pinForm.pin);
      setPinEstado(r.data);
      setShowPin(false);
      aviso('PIN actualizado');
    } catch (e) {
      Swal.fire('Error', e.message, 'error');
    } finally {
      setSavingPin(false);
    }
  };

  const autorizarCredito = async (r) => {
    const { value } = await Swal.fire({
      title: `${r.forma_pago === 'Credito' ? 'Cambiar crédito' : 'Autorizar crédito'} — ${r.nombre}`,
      html: `<input id="lim" class="swal2-input" placeholder="Límite de crédito" type="number" min="1" value="${r.forma_pago === 'Credito' ? Number(r.limite_credito || 0) : ''}">`
        + `<input id="plz" class="swal2-input" placeholder="Plazo (días)" type="number" min="0" value="${r.forma_pago === 'Credito' ? Number(r.plazo_dias || 0) : ''}">`,
      showCancelButton: true,
      preConfirm: () => {
        const lim = Number(document.getElementById('lim').value);
        if (!(lim > 0)) { Swal.showValidationMessage('El límite de crédito debe ser mayor a cero'); return false; }
        return { limite_credito: lim, plazo_dias: Number(document.getElementById('plz').value || 0) };
      },
    });
    if (value) { try { await clientesApi.autorizarCredito(r._id, value); cargar(); Swal.fire('Listo', 'Crédito autorizado', 'success'); } catch (e) { Swal.fire('Error', e.message, 'error'); } }
  };

  const verMonedero = async (r) => {
    try {
      const res = await monederoApi.estadoCuenta(r._id);
      const movs = res.data.movimientos || [];
      const filas = movs.slice(0, 10).map((m) => `<tr><td>${new Date(m.createdAt).toLocaleDateString('es-MX')}</td><td>${m.tipo}</td><td>${money(m.importe)}</td><td>${m.origen}</td></tr>`).join('');
      Swal.fire({ title: `Monedero — ${r.nombre}`, html: `<p>Saldo a favor: <b>${money(res.data.cliente.saldo_favor)}</b></p><table style="width:100%;font-size:12px;margin-top:8px"><tr><th>Fecha</th><th>Tipo</th><th>Importe</th><th>Origen</th></tr>${filas}</table>`, width: 600 });
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    { key: 'telefono', label: 'Teléfono' },
    { key: 'lista_precios', label: 'Lista', render: (r) => <span className="badge-primary">L{r.lista_precios}</span> },
    { key: 'forma_pago', label: 'Modalidad', render: (r) => <span className={`badge-${r.forma_pago === 'Credito' ? 'warning' : 'slate'}`}>{r.forma_pago}</span> },
    { key: 'saldo_credito', label: 'Saldo crédito', render: (r) => money(r.saldo_credito) },
    { key: 'saldo_favor', label: 'Monedero', render: (r) => money(r.saldo_favor) },
    { key: 'acc', label: '', render: (r) => (
      <div className="flex gap-1 flex-wrap">
        <button className="btn-ghost text-xs" onClick={() => abrirEdit(r)}>Editar</button>
        <button className="btn-ghost text-xs" onClick={() => setEstadoCli(r)}>Estado de cuenta</button>
        <button className="btn-ghost text-xs" onClick={() => verMonedero(r)}>Monedero</button>
        {puedeCredito && !r.es_publico_general && <button className="btn-ghost text-xs text-amber-600" onClick={() => autorizarCredito(r)}>Crédito</button>}
      </div>
    ) },
  ];

  const setDom = (k, v) => setForm((f) => ({ ...f, domicilio: { ...f.domicilio, [k]: v } }));

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Clientes</h1>
        <div className="flex items-center gap-2">
          {puedeCambiarPin && (
            <button className="btn-secondary flex items-center gap-1" onClick={abrirPin}>
              <KeyIcon className="w-4 h-4" /> PIN de autorización
            </button>
          )}
          <button className="btn-primary" onClick={abrirNuevo}><PlusIcon className="w-4 h-4" /> Nuevo</button>
        </div>
      </div>
      <div className="flex gap-2">
        <input className="input-base max-w-xs" placeholder="Buscar nombre / teléfono" value={search}
          onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && cargar()} />
        <button className="btn-secondary" onClick={cargar}>Buscar</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin clientes" exportName="clientes" exportTitle="Clientes" />

      <Modal open={open} onClose={() => setOpen(false)} size="lg" title={editId ? 'Editar cliente' : 'Nuevo cliente'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">Nombre *<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
          <label className="text-sm">Teléfono *<input className="input-base" value={form.telefono} onChange={(e) => setForm({ ...form, telefono: e.target.value })} /></label>
          <label className="text-sm">RFC<input className="input-base" value={form.rfc || ''} onChange={(e) => setForm({ ...form, rfc: e.target.value })} /></label>
          <label className="text-sm">Lista de precios
            <select className="input-base" value={form.lista_precios} onChange={(e) => setForm({ ...form, lista_precios: Number(e.target.value) })}>
              {[1, 2, 3, 4, 5].map((n) => (
                <option key={n} value={n}>
                  Lista {n}{n >= 4 ? ' (requiere PIN)' : ''}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm col-span-2">Calle<input className="input-base" value={form.domicilio.calle} onChange={(e) => setDom('calle', e.target.value)} /></label>
          <label className="text-sm">Colonia *<input className="input-base" value={form.domicilio.colonia} onChange={(e) => setDom('colonia', e.target.value)} /></label>
          <label className="text-sm">Ciudad *<input className="input-base" value={form.domicilio.ciudad} onChange={(e) => setDom('ciudad', e.target.value)} /></label>
          <label className="text-sm">Estado<input className="input-base" value={form.domicilio.estado} onChange={(e) => setDom('estado', e.target.value)} /></label>
          <label className="text-sm">C.P.<input className="input-base" value={form.domicilio.codigo_postal} onChange={(e) => setDom('codigo_postal', e.target.value)} /></label>
          <label className="text-sm col-span-2">Tienda
            <select className="input-base" value={form.id_tienda || ''} onChange={(e) => setForm({ ...form, id_tienda: e.target.value })}>
              <option value="">{user?.id_tienda ? 'Mi tienda' : '—'}</option>
              {tiendas.map((t) => <option key={t._id} value={t._id}>{t.nombre}</option>)}
            </select>
          </label>
        </div>
        <p className="text-xs text-slate-400 mt-2">El cliente nace en modalidad Contado. El crédito se autoriza por separado (admin).</p>
      </Modal>

      <EstadoCuentaCliente
        cliente={estadoCli ? { _id: estadoCli._id, nombre: estadoCli.nombre, saldo: estadoCli.saldo_credito } : null}
        onClose={() => setEstadoCli(null)}
        onChanged={cargar}
      />

      {/* Cambiar PIN de autorización Lista 4/5 */}
      <Modal
        open={showPin}
        onClose={() => setShowPin(false)}
        size="sm"
        title="PIN de autorización · Lista 4 y 5"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setShowPin(false)}>Cancelar</button>
            <button className="btn-primary" onClick={guardarPin} disabled={savingPin}>
              {savingPin ? 'Guardando…' : 'Guardar PIN'}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            Este PIN autoriza asignar Lista 4 o 5 a quien no tiene el permiso. Es numérico de 4 a 6 dígitos y puedes cambiarlo cuando quieras.
          </p>

          {pinEstado && (
            <div className="text-xs rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-slate-600">
              {pinEstado.configurado ? (
                <>
                  Estado: <b>configurado</b>
                  {pinEstado.actualizado_en && (() => {
                    const dias = Math.floor((Date.now() - new Date(pinEstado.actualizado_en).getTime()) / 86400000);
                    return (
                      <>
                        {' · '}último cambio: {new Date(pinEstado.actualizado_en).toLocaleDateString('es-MX')} ({dias} día{dias === 1 ? '' : 's'})
                        {dias >= 90 && (
                          <div className="mt-1 text-amber-600">⚠ Lleva más de 90 días sin cambiarse. Considera actualizarlo.</div>
                        )}
                      </>
                    );
                  })()}
                </>
              ) : (
                <>Estado: <b>sin configurar</b>. Define un PIN para poder autorizar Lista 4/5 sin permiso.</>
              )}
            </div>
          )}

          <label className="block text-sm">
            <span className="block text-xs font-medium text-slate-500 mb-1">Nuevo PIN</span>
            <input
              type="password"
              inputMode="numeric"
              maxLength={6}
              className="input-base"
              value={pinForm.pin}
              onChange={(e) => setPinForm({ ...pinForm, pin: e.target.value.replace(/\D/g, '') })}
            />
          </label>
          <label className="block text-sm">
            <span className="block text-xs font-medium text-slate-500 mb-1">Confirmar PIN</span>
            <input
              type="password"
              inputMode="numeric"
              maxLength={6}
              className="input-base"
              value={pinForm.pin2}
              onChange={(e) => setPinForm({ ...pinForm, pin2: e.target.value.replace(/\D/g, '') })}
            />
          </label>
        </div>
      </Modal>
    </div>
  );
}
