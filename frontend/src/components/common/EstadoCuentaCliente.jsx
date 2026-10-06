import { useState, useEffect, useCallback } from 'react';
import PropTypes from 'prop-types';
import Swal from 'sweetalert2';
import Modal from './Modal';
import { cuentasClienteApi, bancosApi } from '../../services/api/endpoints';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n || 0));
const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');

// Etiqueta y color del badge por tipo de movimiento.
const TIPO_META = {
  venta: { label: 'Venta', cls: 'badge-primary' },
  abono: { label: 'Abono', cls: 'badge-success' },
  bonificacion: { label: 'Bonificación', cls: 'badge-success' },
  cargo: { label: 'Cargo', cls: 'badge-warning' },
  devolucion: { label: 'Devolución', cls: 'badge-warning' },
  cambio: { label: 'Cambio', cls: 'badge-accent' },
  monedero: { label: 'Monedero', cls: 'badge-accent' },
};
// Color del importe según su efecto contable.
const EFECTO_CLS = { cargo: 'text-rose-600', abono: 'text-emerald-600', info: 'text-slate-500' };

/**
 * Modal de estado de cuenta de un cliente: movimientos (cargos/abonos) + registrar abono.
 * Reutilizable en Cuentas por cobrar y en la página de Clientes.
 * Props:
 *  - cliente: { _id, nombre, saldo? | saldo_credito? } | null  (abre cuando no es null)
 *  - onClose()
 *  - onChanged()  se llama tras registrar un abono (para refrescar la lista externa)
 *  - puedeCobrar: si false, oculta el formulario de abono (solo consulta)
 */
export default function EstadoCuentaCliente({ cliente, onClose, onChanged, puedeCobrar = true }) {
  const [movs, setMovs] = useState([]);
  const [saldos, setSaldos] = useState({ saldo_credito: 0, saldo_favor: 0 });
  const [loading, setLoading] = useState(false);
  const [bancos, setBancos] = useState([]);
  const [abono, setAbono] = useState({ monto: '', concepto: 'Abono a cuenta', id_banco: '' });
  const [saving, setSaving] = useState(false);

  useEffect(() => { bancosApi.listar().then((r) => setBancos(r.data?.docs ?? r.data ?? [])).catch(() => {}); }, []);

  const cargarMovs = useCallback(async (id) => {
    setLoading(true);
    setMovs([]);
    try {
      const res = await cuentasClienteApi.estadoCuenta(id);
      setMovs(res.data?.movimientos || []);
      setSaldos({
        saldo_credito: res.data?.cliente?.saldo_credito || 0,
        saldo_favor: res.data?.cliente?.saldo_favor || 0,
      });
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudieron cargar los movimientos', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (cliente?._id) {
      setAbono({ monto: '', concepto: 'Abono a cuenta', id_banco: '' });
      cargarMovs(cliente._id);
    }
  }, [cliente, cargarMovs]);

  const saldoCredito = saldos.saldo_credito ?? cliente?.saldo ?? cliente?.saldo_credito ?? 0;
  const saldoFavor = saldos.saldo_favor ?? cliente?.saldo_favor ?? 0;

  async function registrarAbono(e) {
    e.preventDefault();
    if (!(Number(abono.monto) > 0)) return Swal.fire('Importe inválido', 'Captura un abono mayor a cero.', 'warning');
    setSaving(true);
    try {
      await cuentasClienteApi.abono({
        id_cliente: cliente._id,
        monto: Number(abono.monto),
        concepto: abono.concepto || 'Abono a cuenta',
        id_banco: abono.id_banco || undefined,
      });
      await cargarMovs(cliente._id);
      setAbono({ monto: '', concepto: 'Abono a cuenta', id_banco: '' });
      onChanged?.();
      Swal.fire({ icon: 'success', title: 'Abono registrado', timer: 1400, showConfirmButton: false });
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo registrar el abono', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={!!cliente}
      onClose={onClose}
      title={cliente ? `Estado de cuenta · ${cliente.nombre}` : 'Estado de cuenta'}
      size="xl"
      footer={<button className="btn-secondary" onClick={onClose}>Cerrar</button>}
    >
      {cliente && (
        <div className="space-y-5">
          <div className="flex flex-wrap gap-6 text-sm">
            <span className="text-slate-500">Saldo CxC (debe): <b className={Number(saldoCredito) > 0 ? 'text-rose-600' : 'text-emerald-600'}>{money(saldoCredito)}</b></span>
            <span className="text-slate-500">Saldo a favor (monedero): <b className={Number(saldoFavor) > 0 ? 'text-emerald-600' : 'text-slate-800'}>{money(saldoFavor)}</b></span>
            <span className="text-slate-500">Movimientos: <b className="text-slate-800">{movs.length}</b></span>
          </div>

          {puedeCobrar && (
            <form onSubmit={registrarAbono} className="card p-4 space-y-3">
              <p className="text-sm font-semibold text-slate-700">Registrar abono (pago del cliente)</p>
              <div className="grid gap-3 md:grid-cols-4">
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Importe *</label>
                  <input type="number" min="0" step="0.01" className="input-base" value={abono.monto}
                    onChange={(e) => setAbono({ ...abono, monto: e.target.value })} />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-xs font-medium text-slate-500 mb-1">Concepto</label>
                  <input className="input-base" value={abono.concepto}
                    onChange={(e) => setAbono({ ...abono, concepto: e.target.value })} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-500 mb-1">Banco (opcional)</label>
                  <select className="input-base" value={abono.id_banco}
                    onChange={(e) => setAbono({ ...abono, id_banco: e.target.value })}>
                    <option value="">Sin banco</option>
                    {bancos.map((b) => <option key={b._id} value={b._id}>{b.nombre}</option>)}
                  </select>
                </div>
              </div>
              <div className="flex justify-end">
                <button type="submit" className="btn-primary" disabled={saving}>{saving ? 'Guardando…' : 'Registrar abono'}</button>
              </div>
            </form>
          )}

          <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-700">Movimientos (todos)</div>
            <div className="overflow-x-auto max-h-[28rem] overflow-y-auto">
              <table className="min-w-full text-sm divide-y divide-slate-200">
                <thead className="bg-slate-50 sticky top-0">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Fecha</th>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Tipo</th>
                    <th className="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Concepto</th>
                    <th className="px-4 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {loading ? (
                    <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Cargando…</td></tr>
                  ) : movs.length === 0 ? (
                    <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Sin movimientos</td></tr>
                  ) : (
                    movs.map((m, i) => {
                      const meta = TIPO_META[m.tipo] || { label: m.tipo, cls: 'badge-primary' };
                      const signo = m.efecto === 'cargo' ? '+' : m.efecto === 'abono' ? '−' : '';
                      return (
                        <tr key={i} className={m.anulado ? 'opacity-50 line-through' : ''}>
                          <td className="px-4 py-2 text-slate-600 whitespace-nowrap">{fecha(m.fecha)}</td>
                          <td className="px-4 py-2"><span className={meta.cls}>{meta.label}</span></td>
                          <td className="px-4 py-2 text-slate-700">{m.concepto}</td>
                          <td className={`px-4 py-2 text-right font-medium ${EFECTO_CLS[m.efecto] || 'text-slate-600'}`}>{signo}{money(m.monto)}</td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}

EstadoCuentaCliente.propTypes = {
  cliente: PropTypes.object,
  onClose: PropTypes.func.isRequired,
  onChanged: PropTypes.func,
  puedeCobrar: PropTypes.bool,
};
