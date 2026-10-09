import { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { bancosApi, terminalesApi, cajasApi } from '../../services/api/endpoints';
import { FORMA_LABEL, invalidarDestinos } from '../../components/common/DestinoPago';
import { useAuth } from '../../contexts/AuthContext';

// Donde esta el dinero de la tienda:
//  - Cuentas bancarias (cada una con su libro de movimientos)
//  - Terminales de tarjeta (cada una deposita en una cuenta)
//  - Efectivo en la caja de cada tienda / almacen
const money = (n, c = 'MXN') => new Intl.NumberFormat('es-MX', { style: 'currency', currency: c }).format(Number(n || 0));
const fecha = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const err = (e) => Swal.fire('Error', e?.message || 'No se pudo completar', 'error');
const TABS = [{ k: 'cuentas', l: 'Cuentas bancarias' }, { k: 'terminales', l: 'Terminales' }, { k: 'cajas', l: 'Efectivo en tiendas' }];

function Libro({ titulo, saldo, movimientos, conCuenta }) {
  return (
    <div className="space-y-3">
      <p className="text-sm text-slate-600">{titulo} · Saldo: <b className="text-slate-900">{money(saldo)}</b></p>
      <DataTable data={movimientos} exportName="Movimientos" exportTitle={titulo} empty="Sin movimientos" columns={[
        { key: 'fecha', label: 'Fecha', render: (m) => fecha(m.fecha) },
        { key: 'concepto', label: 'Concepto' },
        ...(conCuenta ? [
          { key: 'forma', label: 'Forma', render: (m) => FORMA_LABEL[m.forma] || m.forma || '—' },
          { key: 'terminal', label: 'Terminal', render: (m) => m.terminal || '—' },
          { key: 'almacen', label: 'Tienda', render: (m) => m.almacen || '—' },
        ] : [{ key: 'usuario', label: 'Usuario', render: (m) => m.usuario || '—' }]),
        { key: 'ingreso', label: 'Entrada', render: (m) => (m.tipo === 'ingreso' ? <span className="text-emerald-600">{money(m.monto)}</span> : ''), exportValue: (m) => (m.tipo === 'ingreso' ? m.monto : '') },
        { key: 'egreso', label: 'Salida', render: (m) => (m.tipo === 'egreso' ? <span className="text-rose-600">{money(m.monto)}</span> : ''), exportValue: (m) => (m.tipo === 'egreso' ? m.monto : '') },
      ]} />
    </div>
  );
}

export default function BancosPage() {
  const { hasPermiso } = useAuth();
  const puedeAdministrar = hasPermiso('finanzas.editar');
  const puedeMover = hasPermiso('finanzas.crear');
  const [tab, setTab] = useState('cuentas');
  const [cuentas, setCuentas] = useState([]);
  const [terminales, setTerminales] = useState([]);
  const [cajas, setCajas] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modal, setModal] = useState(null);       // cuenta | terminal | movimiento | gasto | libro
  const [form, setForm] = useState({});
  const [libro, setLibro] = useState(null);
  const [guardando, setGuardando] = useState(false);   // evita registrar dos veces con doble clic

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const [b, t, c] = await Promise.all([bancosApi.listar({ is_active: 'todos' }), terminalesApi.listar({ is_active: 'todos' }), cajasApi.listar()]);
      setCuentas(b.data || []); setTerminales(t.data || []); setCajas(c.data || []);
      invalidarDestinos();
    } catch (e) { err(e); } finally { setLoading(false); }
  }, []);
  useEffect(() => { cargar(); }, [cargar]);

  const verLibroCuenta = async (c) => {
    try { const r = await bancosApi.movimientos(c._id); setLibro({ titulo: c.nombre, saldo: r.data.cuenta.saldo_actual, movimientos: r.data.movimientos, conCuenta: true }); setModal('libro'); }
    catch (e) { err(e); }
  };
  const verLibroCaja = async (a) => {
    try { const r = await cajasApi.movimientos(a._id); setLibro({ titulo: `Caja de ${a.nombre}`, saldo: r.data.saldo, movimientos: r.data.movimientos, conCuenta: false }); setModal('libro'); }
    catch (e) { err(e); }
  };

  const guardar = async () => {
    if (guardando) return;
    setGuardando(true);
    try {
      if (modal === 'cuenta') {
        const data = { nombre: form.nombre, moneda: form.moneda || 'MXN', cuenta: form.cuenta || null, clabe: form.clabe || null };
        if (form._id) await bancosApi.actualizar(form._id, { ...data, is_active: form.is_active });
        else await bancosApi.crear({ ...data, saldo_inicial: Number(form.saldo_inicial || 0) });
      } else if (modal === 'terminal') {
        const data = { nombre: form.nombre, proveedor: form.proveedor || null, id_banco: form.id_banco, comision_pct: Number(form.comision_pct || 0), is_active: form.is_active || 'Si' };
        if (form._id) await terminalesApi.actualizar(form._id, data); else await terminalesApi.crear(data);
      } else if (modal === 'movimiento') {
        await bancosApi.movimiento(form.id_banco, { tipo: form.tipo, monto: Number(form.monto), concepto: form.concepto || undefined, id_almacen: form.id_almacen || undefined });
      } else if (modal === 'gasto') {
        await cajasApi.movimiento(form.id_almacen, { tipo: form.tipo, monto: Number(form.monto), concepto: form.concepto });
      }
      setModal(null); cargar();
    } catch (e) { err(e); } finally { setGuardando(false); }
  };

  const campo = (k, label, props = {}) => (
    <label className="text-sm block">{label}
      <input className="input-base" value={form[k] ?? ''} {...props} onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
    </label>
  );
  const selector = (k, label, opciones) => (
    <label className="text-sm block">{label}
      <select className="input-base" value={form[k] ?? ''} onChange={(e) => setForm({ ...form, [k]: e.target.value })}>
        <option value="">—</option>
        {opciones.map((o) => <option key={o._id} value={o._id}>{o.nombre}</option>)}
      </select>
    </label>
  );
  // cada moneda por separado: nunca se suman dolares con pesos
  const totalCuentas = cuentas.filter((c) => c.is_active === 'Si' && (c.moneda || 'MXN') === 'MXN').reduce((a, c) => a + c.saldo_actual, 0);
  const totalCuentasUsd = cuentas.filter((c) => c.is_active === 'Si' && c.moneda === 'USD').reduce((a, c) => a + c.saldo_actual, 0);
  const hayUsd = cuentas.some((c) => c.is_active === 'Si' && c.moneda === 'USD');
  const totalCajas = cajas.reduce((a, c) => a + c.saldo, 0);

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Bancos y cajas</h1>
        <div className="flex gap-4 text-sm">
          <span className="card px-3 py-2">En cuentas: <b>{money(totalCuentas)}</b></span>
          {hayUsd && <span className="card px-3 py-2">En cuentas USD: <b>{new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'USD' }).format(totalCuentasUsd)}</b></span>}
          <span className="card px-3 py-2">Efectivo en tiendas: <b>{money(totalCajas)}</b></span>
        </div>
      </div>
      <div className="flex gap-2 border-b border-slate-200">
        {TABS.map((t) => (
          <button key={t.k} onClick={() => setTab(t.k)}
            className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${tab === t.k ? 'border-primary-600 text-primary-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
            {t.l}
          </button>
        ))}
      </div>

      {tab === 'cuentas' && (
        <div className="space-y-3">
          <div className="flex justify-end gap-2">
            {puedeMover && <button className="btn-secondary" onClick={() => { setForm({ tipo: 'deposito', id_banco: cuentas[0]?._id }); setModal('movimiento'); }}>Depósito / retiro</button>}
            {puedeAdministrar && <button className="btn-primary" onClick={() => { setForm({ moneda: 'MXN' }); setModal('cuenta'); }}><PlusIcon className="w-4 h-4" /> Nueva cuenta</button>}
          </div>
          <DataTable loading={loading} data={cuentas} exportName="Cuentas" exportTitle="Cuentas bancarias" columns={[
            { key: 'nombre', label: 'Cuenta' },
            { key: 'cuenta', label: 'No. de cuenta', render: (c) => c.cuenta || '—' },
            { key: 'terminales', label: 'Terminales' },
            { key: 'is_active', label: 'Estado', render: (c) => (c.is_active === 'Si' ? <span className="badge-success">Activa</span> : <span className="badge-slate">Inactiva</span>) },
            { key: 'saldo_actual', label: 'Saldo', render: (c) => <b>{money(c.saldo_actual, c.moneda)}</b> },
            { key: 'acc', label: '', noExport: true, render: (c) => (
              <div className="flex gap-1">
                <button className="btn-ghost text-sm" onClick={() => verLibroCuenta(c)}>Movimientos</button>
                {puedeAdministrar && <button className="btn-ghost text-sm" onClick={() => { setForm(c); setModal('cuenta'); }}>Editar</button>}
              </div>
            ) },
          ]} />
        </div>
      )}

      {tab === 'terminales' && (
        <div className="space-y-3">
          <div className="flex justify-between gap-2">
            <p className="text-sm text-slate-500">Al cobrar con tarjeta se elige la terminal; el dinero queda en la cuenta a la que deposita.</p>
            {puedeAdministrar && <button className="btn-primary" onClick={() => { setForm({ id_banco: cuentas[0]?._id }); setModal('terminal'); }}><PlusIcon className="w-4 h-4" /> Nueva terminal</button>}
          </div>
          <DataTable loading={loading} data={terminales} exportName="Terminales" exportTitle="Terminales" columns={[
            { key: 'nombre', label: 'Terminal' },
            { key: 'proveedor', label: 'Proveedor', render: (t) => t.proveedor || '—' },
            { key: 'id_banco', label: 'Deposita en', exportValue: (t) => t.id_banco?.nombre, render: (t) => t.id_banco?.nombre },
            { key: 'comision_pct', label: 'Comisión', render: (t) => `${t.comision_pct} %` },
            { key: 'is_active', label: 'Estado', render: (t) => (t.is_active === 'Si' ? <span className="badge-success">Activa</span> : <span className="badge-slate">Inactiva</span>) },
            { key: 'acc', label: '', noExport: true, render: (t) => puedeAdministrar && (
              <button className="btn-ghost text-sm" onClick={() => { setForm({ ...t, id_banco: t.id_banco?._id }); setModal('terminal'); }}>Editar</button>
            ) },
          ]} />
        </div>
      )}

      {tab === 'cajas' && (
        <div className="space-y-3">
          <p className="text-sm text-slate-500">Efectivo que debe haber en cada tienda: ventas, anticipos y abonos en efectivo, menos cambio entregado, pagos en efectivo, gastos y depósitos al banco.</p>
          <DataTable loading={loading} data={cajas} exportName="Cajas" exportTitle="Efectivo en tiendas" columns={[
            { key: 'nombre', label: 'Tienda / almacén' },
            { key: 'saldo', label: 'Efectivo', render: (a) => <b>{money(a.saldo)}</b> },
            { key: 'ultimo_corte', label: 'Último corte', render: (a) => a.ultimo_corte || '—' },
            { key: 'acc', label: '', noExport: true, render: (a) => (
              <div className="flex gap-1">
                <button className="btn-ghost text-sm" onClick={() => verLibroCaja(a)}>Movimientos</button>
                {puedeMover && <button className="btn-ghost text-sm" onClick={() => { setForm({ id_almacen: a._id, tipo: 'egreso' }); setModal('gasto'); }}>Entrada / salida</button>}
                {puedeMover && <button className="btn-ghost text-sm" onClick={() => { setForm({ tipo: 'deposito', id_almacen: a._id, id_banco: cuentas[0]?._id }); setModal('movimiento'); }}>Depositar al banco</button>}
              </div>
            ) },
          ]} />
        </div>
      )}

      <Modal open={['cuenta', 'terminal', 'movimiento', 'gasto'].includes(modal)} onClose={() => setModal(null)}
        title={{ cuenta: form._id ? 'Editar cuenta' : 'Nueva cuenta', terminal: form._id ? 'Editar terminal' : 'Nueva terminal',
                 movimiento: 'Movimiento de cuenta', gasto: 'Entrada / salida de efectivo' }[modal] || ''}
        footer={<><button className="btn-secondary" onClick={() => setModal(null)}>Cancelar</button><button className="btn-primary" onClick={guardar} disabled={guardando}>{guardando ? 'Guardando…' : 'Guardar'}</button></>}>
        <div className="space-y-3">
          {modal === 'cuenta' && (<>
            {campo('nombre', 'Nombre (p. ej. BBVA empresarial)')}
            {campo('cuenta', 'No. de cuenta')}
            {campo('clabe', 'CLABE')}
            {!form._id && campo('saldo_inicial', 'Saldo inicial', { type: 'number', min: 0 })}
            {form._id && (
              <label className="text-sm block">Estado
                <select className="input-base" value={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.value })}>
                  <option value="Si">Activa</option><option value="No">Inactiva</option>
                </select>
              </label>
            )}
          </>)}
          {modal === 'terminal' && (<>
            {campo('nombre', 'Nombre (p. ej. Terminal caja 1)')}
            {campo('proveedor', 'Proveedor (Clip, Getnet, Mercado Pago…)')}
            {selector('id_banco', 'Deposita en la cuenta', cuentas.filter((c) => c.is_active === 'Si'))}
            {campo('comision_pct', 'Comisión (%)', { type: 'number', min: 0, step: 0.01 })}
          </>)}
          {modal === 'movimiento' && (<>
            <label className="text-sm block">Tipo
              <select className="input-base" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}>
                <option value="deposito">Depósito de efectivo (caja → cuenta)</option>
                <option value="retiro">Retiro (cuenta → caja)</option>
                <option value="ingreso">Otro ingreso a la cuenta</option>
                <option value="egreso">Otro cargo de la cuenta (comisiones, etc.)</option>
              </select>
            </label>
            {selector('id_banco', 'Cuenta', cuentas.filter((c) => c.is_active === 'Si'))}
            {['deposito', 'retiro'].includes(form.tipo) && selector('id_almacen', 'Caja de la tienda', cajas)}
            {campo('monto', 'Monto', { type: 'number', min: 0, step: 0.01 })}
            {campo('concepto', 'Concepto (opcional)')}
          </>)}
          {modal === 'gasto' && (<>
            <label className="text-sm block">Tipo
              <select className="input-base" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}>
                <option value="egreso">Salida (gasto, retiro del dueño…)</option>
                <option value="ingreso">Entrada (fondo de cambio…)</option>
              </select>
            </label>
            {campo('monto', 'Monto', { type: 'number', min: 0, step: 0.01 })}
            {campo('concepto', 'Concepto')}
          </>)}
        </div>
      </Modal>

      <Modal open={modal === 'libro'} onClose={() => setModal(null)} size="xl" title="Movimientos">
        {libro && <Libro {...libro} />}
      </Modal>
    </div>
  );
}
