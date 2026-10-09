import { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { cuentasProveedorApi, bancosApi, almacenesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { aFecha } from '../../utils/fechas';
import { aviso } from '../../utils/avisos';

const money = (n, c = 'MXN') =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: c }).format(Number(n || 0));
const fecha = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');

export default function CuentasProveedorPage() {
  const { hasPermiso } = useAuth();
  const puedePagar = hasPermiso('finanzas.crear');
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [bancos, setBancos] = useState([]);
  const [almacenes, setAlmacenes] = useState([]);
  // el pago sale de la caja de un almacen (efectivo) o de una cuenta (transferencia / cheque)
  const pagoVacio = { monto: '', moneda: 'MXN', concepto: 'Pago a proveedor', forma: 'transferencia', id_banco: '', id_almacen: '', tipo_cambio: '' };

  const [prov, setProv] = useState(null);
  const [movs, setMovs] = useState([]);
  const [movLoading, setMovLoading] = useState(false);
  const [pago, setPago] = useState(pagoVacio);
  const [saving, setSaving] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await cuentasProveedorApi.listar();
      const lista = Array.isArray(res?.data) ? res.data : [];
      setData(lista);
      return lista;
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudieron cargar las cuentas', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);
  useEffect(() => {
    bancosApi.listar().then((r) => setBancos(r.data?.docs ?? r.data ?? [])).catch(() => {});
    almacenesApi.listar().then((r) => setAlmacenes(r.data || [])).catch(() => {});
  }, []);

  async function abrirEstado(row) {
    setProv(row);
    setPago(pagoVacio);
    setMovLoading(true);
    setMovs([]);
    try {
      const res = await cuentasProveedorApi.movimientos(row._id);
      setMovs(res.data || []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudieron cargar los movimientos', 'error');
    } finally {
      setMovLoading(false);
    }
  }

  // moneda de la caja / cuenta de donde sale el dinero; si no coincide con la del pago se pide el tipo de cambio
  const monedaOrigen = pago.forma === 'efectivo' ? 'MXN' : (bancos.find((b) => b._id === pago.id_banco)?.moneda || 'MXN');
  const pideTC = (pago.forma === 'efectivo' || !!pago.id_banco) && monedaOrigen !== pago.moneda;
  const montoSalida = pideTC && Number(pago.tipo_cambio) > 0
    ? (pago.moneda === 'USD' ? Number(pago.monto || 0) * Number(pago.tipo_cambio) : Number(pago.monto || 0) / Number(pago.tipo_cambio))
    : null;

  async function registrarPago(e) {
    e.preventDefault();
    if (!(Number(pago.monto) > 0)) return Swal.fire('Importe inválido', 'Captura un pago mayor a cero.', 'warning');
    if (pideTC && !(Number(pago.tipo_cambio) > 0)) return Swal.fire('Falta el tipo de cambio', `El pago es en ${pago.moneda} y sale de ${pago.forma === 'efectivo' ? 'la caja (pesos)' : `una cuenta en ${monedaOrigen}`}.`, 'warning');
    if (pago.forma === 'efectivo' && !pago.id_almacen) return Swal.fire('Falta la caja', 'Indica de qué tienda sale el efectivo.', 'warning');
    if (pago.forma !== 'efectivo' && !pago.id_banco) return Swal.fire('Falta la cuenta', 'Indica de qué cuenta sale el pago.', 'warning');
    setSaving(true);
    try {
      await cuentasProveedorApi.pago({
        id_proveedor: prov._id,
        monto: Number(pago.monto),
        moneda: pago.moneda,
        concepto: pago.concepto || 'Pago a proveedor',
        forma: pago.forma,
        ...(pago.forma === 'efectivo' ? { id_almacen: pago.id_almacen } : { id_banco: pago.id_banco }),
        ...(pideTC ? { tipo_cambio: Number(pago.tipo_cambio) } : {}),
      });
      const res = await cuentasProveedorApi.movimientos(prov._id);
      setMovs(res.data || []);
      setPago(pagoVacio);
      // refresca la lista y el saldo del encabezado del proveedor abierto
      const lista = await cargar();
      const actual = (lista || []).find((x) => x._id === prov._id);
      if (actual) setProv(actual);
      aviso('Pago registrado');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo registrar el pago', 'error');
    } finally {
      setSaving(false);
    }
  }

  const saldoBadge = (n) => (Number(n) > 0 ? 'badge-danger' : 'badge-success');

  const columns = [
    { key: 'nombre', label: 'Proveedor' },
    { key: 'rfc', label: 'RFC', render: (r) => r.rfc || '—' },
    { key: 'cargos_mxn', label: 'Cargos MXN', render: (r) => <span className="badge-danger">{money(r?.saldos?.MXN?.cargos)}</span>, exportValue: (r) => r?.saldos?.MXN?.cargos || 0 },
    { key: 'pagos_mxn', label: 'Pagos MXN', render: (r) => <span className="badge-success">{money(r?.saldos?.MXN?.pagos)}</span>, exportValue: (r) => r?.saldos?.MXN?.pagos || 0 },
    { key: 'saldo_mxn', label: 'Saldo MXN', render: (r) => <span className={saldoBadge(r?.saldos?.MXN?.saldo)}>{money(r?.saldos?.MXN?.saldo)}</span>, exportValue: (r) => r?.saldos?.MXN?.saldo || 0 },
    { key: 'saldo_usd', label: 'Saldo USD', render: (r) => <span className={saldoBadge(r?.saldos?.USD?.saldo)}>{money(r?.saldos?.USD?.saldo, 'USD')}</span>, exportValue: (r) => r?.saldos?.USD?.saldo || 0 },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Cuentas por pagar</h1>
      <p className="text-sm text-slate-500">Haz clic en un proveedor para ver su estado de cuenta y registrar un pago.</p>
      <DataTable
        columns={columns}
        data={data}
        loading={loading}
        empty="Sin cuentas de proveedor"
        onRowClick={abrirEstado}
        exportName="cuentas_proveedor"
        exportTitle="Cuentas por pagar"
      />

      <Modal
        open={!!prov}
        onClose={() => setProv(null)}
        title={prov ? `Estado de cuenta · ${prov.nombre}` : 'Estado de cuenta'}
        size="xl"
        footer={<button className="btn-secondary" onClick={() => setProv(null)}>Cerrar</button>}
      >
        {prov && (
          <div className="space-y-5">
            <div className="flex flex-wrap gap-6 text-sm">
              <span className="text-slate-500">Saldo MXN: <b className={Number(prov?.saldos?.MXN?.saldo) > 0 ? 'text-rose-600' : 'text-emerald-600'}>{money(prov?.saldos?.MXN?.saldo)}</b></span>
              <span className="text-slate-500">Saldo USD: <b className={Number(prov?.saldos?.USD?.saldo) > 0 ? 'text-rose-600' : 'text-emerald-600'}>{money(prov?.saldos?.USD?.saldo, 'USD')}</b></span>
            </div>

            {puedePagar && (
            <form onSubmit={registrarPago} className="card p-4 space-y-3">
              <p className="text-sm font-semibold text-slate-700">Registrar pago a proveedor</p>
              <div className="grid gap-3 md:grid-cols-4">
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Importe *</label>
                  <input type="number" min="0" step="0.01" className="input-base" value={pago.monto}
                    onChange={(e) => setPago({ ...pago, monto: e.target.value })} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Moneda</label>
                  <select className="input-base" value={pago.moneda} onChange={(e) => setPago({ ...pago, moneda: e.target.value })}>
                    <option value="MXN">MXN</option>
                    <option value="USD">USD</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Concepto</label>
                  <input className="input-base" value={pago.concepto} onChange={(e) => setPago({ ...pago, concepto: e.target.value })} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Forma de pago</label>
                  <select className="input-base" value={pago.forma} onChange={(e) => setPago({ ...pago, forma: e.target.value })}>
                    <option value="transferencia">Transferencia</option>
                    <option value="cheque">Cheque</option>
                    <option value="efectivo">Efectivo (caja de una tienda)</option>
                  </select>
                </div>
                <div>
                  {pago.forma === 'efectivo' ? (
                    <>
                      <label className="block text-xs font-medium text-slate-500 mb-1">Sale de la caja de</label>
                      <select className="input-base" value={pago.id_almacen} onChange={(e) => setPago({ ...pago, id_almacen: e.target.value })}>
                        <option value="">—</option>
                        {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
                      </select>
                    </>
                  ) : (
                    <>
                      <label className="block text-xs font-medium text-slate-500 mb-1">Sale de la cuenta</label>
                      <select className="input-base" value={pago.id_banco} onChange={(e) => setPago({ ...pago, id_banco: e.target.value })}>
                        <option value="">—</option>
                        {bancos.map((b) => <option key={b._id} value={b._id}>{b.nombre} ({b.moneda || 'MXN'})</option>)}
                      </select>
                    </>
                  )}
                </div>
                {pideTC && (
                  <div>
                    <label className="block text-xs font-medium text-slate-500 mb-1">Tipo de cambio (pesos por dólar) *</label>
                    <input type="number" min="0" step="0.0001" className="input-base" value={pago.tipo_cambio}
                      onChange={(e) => setPago({ ...pago, tipo_cambio: e.target.value })} />
                    {montoSalida !== null && (
                      <p className="text-xs text-slate-500 mt-1">Saldrán {money(montoSalida, monedaOrigen)} de {pago.forma === 'efectivo' ? 'la caja' : 'la cuenta'}</p>
                    )}
                  </div>
                )}
              </div>
              <div className="flex justify-end">
                <button type="submit" className="btn-primary" disabled={saving}>{saving ? 'Guardando…' : 'Registrar pago'}</button>
              </div>
            </form>
            )}

            <div className="card overflow-hidden">
              <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Movimientos</div>
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm divide-y divide-slate-200">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Fecha</th>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Concepto</th>
                      <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Moneda</th>
                      <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Cargo</th>
                      <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Pago</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {movLoading ? (
                      <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Cargando…</td></tr>
                    ) : movs.length === 0 ? (
                      <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Sin movimientos</td></tr>
                    ) : (
                      movs.map((m) => (
                        <tr key={m._id}>
                          <td className="px-4 py-2 text-slate-600">{fecha(m.fecha)}</td>
                          <td className="px-4 py-2 text-slate-700">{m.concepto}{m.tipo_cambio ? ` (TC ${m.tipo_cambio})` : ''}</td>
                          <td className="px-4 py-2 text-slate-600">{m.moneda || 'MXN'}</td>
                          <td className="px-4 py-2 text-right text-rose-600">{m.tipo === 'cargo' ? money(m.monto, m.moneda) : ''}</td>
                          <td className="px-4 py-2 text-right text-emerald-600">{m.tipo !== 'cargo' ? money(m.monto, m.moneda) : ''}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
