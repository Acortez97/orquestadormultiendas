import { useState, useEffect, useCallback } from 'react';
import { FunnelIcon, ShoppingBagIcon, BanknotesIcon, CalculatorIcon, XCircleIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { ventasApi, almacenesApi, clientesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');

// Fecha de hoy (local) en formato yyyy-mm-dd para los inputs date.
const hoyLocal = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
// Convierte un yyyy-mm-dd a instante ISO de inicio / fin de ese día en hora local.
const inicioDiaISO = (d) => (d ? new Date(`${d}T00:00:00`).toISOString() : '');
const finDiaISO = (d) => (d ? new Date(`${d}T23:59:59.999`).toISOString() : '');
const filtrosIniciales = () => ({ id_almacen: '', id_cliente: '', desde: hoyLocal(), hasta: hoyLocal() });

const estadoBadge = (estado) =>
  estado === 'completada' ? 'badge-success' : estado === 'cancelada' ? 'badge-danger' : 'badge-slate';

export default function VentasPage() {
  const { hasPermiso } = useAuth();

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [almacenes, setAlmacenes] = useState([]);
  const [clientes, setClientes] = useState([]);
  const [filtros, setFiltros] = useState(filtrosIniciales);

  const [detalle, setDetalle] = useState(null);
  const [detalleLoading, setDetalleLoading] = useState(false);
  const [cancelando, setCancelando] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtros.id_almacen) params.id_almacen = filtros.id_almacen;
      if (filtros.id_cliente) params.id_cliente = filtros.id_cliente;
      if (filtros.desde) params.desde = inicioDiaISO(filtros.desde);
      if (filtros.hasta) params.hasta = finDiaISO(filtros.hasta);
      const res = await ventasApi.listar(params);
      setRows(res.data?.docs ?? res.data ?? []);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filtros]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    almacenesApi
      .listar()
      .then((res) => setAlmacenes(res.data?.docs ?? res.data ?? []))
      .catch(() => {});
    clientesApi
      .listar()
      .then((res) => setClientes(res.data?.docs ?? res.data ?? []))
      .catch(() => {});
  }, []);

  async function abrirDetalle(row) {
    setDetalle(row);
    setDetalleLoading(true);
    try {
      const res = await ventasApi.obtener(row._id);
      setDetalle(res.data ?? row);
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setDetalleLoading(false);
    }
  }

  async function cancelarVenta() {
    if (!detalle) return;
    const { isConfirmed } = await Swal.fire({
      title: '¿Cancelar venta?',
      text: `Folio ${detalle.folio}. Esta acción revierte la venta.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e11d48',
      confirmButtonText: 'Cancelar venta',
      cancelButtonText: 'Cerrar',
    });
    if (!isConfirmed) return;
    setCancelando(true);
    try {
      await ventasApi.cancelar(detalle._id);
      Swal.fire({ icon: 'success', title: 'Venta cancelada', timer: 1600, showConfirmButton: false });
      setDetalle(null);
      cargar();
    } catch (err) {
      Swal.fire('Error', err.message, 'error');
    } finally {
      setCancelando(false);
    }
  }

  // Resumen del rango/filtros actuales (sobre las ventas cargadas).
  const completadas = rows.filter((r) => r.estado === 'completada');
  const canceladas = rows.filter((r) => r.estado === 'cancelada');
  const montoVendido = completadas.reduce((s, r) => s + (Number(r.total) || 0), 0);
  const ticketPromedio = completadas.length ? montoVendido / completadas.length : 0;
  const topeAlcanzado = rows.length >= 200;

  const columns = [
    { key: 'folio', label: 'Folio', className: 'font-medium text-slate-900' },
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.fecha) },
    { key: 'cliente', label: 'Cliente', render: (r) => r.id_cliente?.nombre || '—' },
    { key: 'tienda', label: 'Tienda', render: (r) => r.id_almacen?.nombre || '—' },
    { key: 'total', label: 'Total', className: 'text-right font-medium', render: (r) => money(r.total) },
    {
      key: 'estado',
      label: 'Estado',
      render: (r) => <span className={estadoBadge(r.estado)}>{r.estado}</span>,
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Ventas</h1>
      </div>

      {/* Filtros */}
      <div className="card p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex items-center gap-2 text-slate-500">
            <FunnelIcon className="w-4 h-4" />
            <span className="text-sm font-medium">Filtros</span>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Tienda</label>
            <select
              className="input-base"
              value={filtros.id_almacen}
              onChange={(e) => setFiltros({ ...filtros, id_almacen: e.target.value })}
            >
              <option value="">Todas</option>
              {almacenes.map((a) => (
                <option key={a._id} value={a._id}>
                  {a.nombre}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Cliente</label>
            <select
              className="input-base"
              value={filtros.id_cliente}
              onChange={(e) => setFiltros({ ...filtros, id_cliente: e.target.value })}
            >
              <option value="">Todos</option>
              {clientes.map((c) => (
                <option key={c._id} value={c._id}>
                  {c.nombre}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Desde</label>
            <input
              type="date"
              className="input-base"
              value={filtros.desde}
              onChange={(e) => setFiltros({ ...filtros, desde: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">Hasta</label>
            <input
              type="date"
              className="input-base"
              value={filtros.hasta}
              onChange={(e) => setFiltros({ ...filtros, hasta: e.target.value })}
            />
          </div>
          <button
            className="btn-ghost"
            onClick={() => setFiltros(filtrosIniciales())}
            title="Volver al filtro de hoy"
          >
            Hoy
          </button>
          {(filtros.id_almacen || filtros.id_cliente || filtros.desde || filtros.hasta) && (
            <button
              className="btn-ghost"
              onClick={() => setFiltros({ id_almacen: '', id_cliente: '', desde: '', hasta: '' })}
            >
              Limpiar
            </button>
          )}
        </div>
      </div>

      {/* Dashboard del rango/filtros seleccionados */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div className="card p-4 flex items-center gap-3">
          <div className="w-10 h-10 shrink-0 rounded-lg bg-primary-50 flex items-center justify-center">
            <ShoppingBagIcon className="w-5 h-5 text-primary-600" />
          </div>
          <div className="min-w-0">
            <p className="text-xs text-slate-500">N.º de ventas</p>
            <p className="text-xl font-bold text-slate-800">{loading ? '…' : completadas.length}</p>
          </div>
        </div>

        <div className="card p-4 flex items-center gap-3">
          <div className="w-10 h-10 shrink-0 rounded-lg bg-emerald-50 flex items-center justify-center">
            <BanknotesIcon className="w-5 h-5 text-emerald-600" />
          </div>
          <div className="min-w-0">
            <p className="text-xs text-slate-500">Total vendido</p>
            <p className="text-xl font-bold text-slate-800 truncate">{loading ? '…' : money(montoVendido)}</p>
          </div>
        </div>

        <div className="card p-4 flex items-center gap-3">
          <div className="w-10 h-10 shrink-0 rounded-lg bg-indigo-50 flex items-center justify-center">
            <CalculatorIcon className="w-5 h-5 text-indigo-600" />
          </div>
          <div className="min-w-0">
            <p className="text-xs text-slate-500">Ticket promedio</p>
            <p className="text-xl font-bold text-slate-800 truncate">{loading ? '…' : money(ticketPromedio)}</p>
          </div>
        </div>

        <div className="card p-4 flex items-center gap-3">
          <div className={`w-10 h-10 shrink-0 rounded-lg flex items-center justify-center ${canceladas.length ? 'bg-rose-50' : 'bg-slate-100'}`}>
            <XCircleIcon className={`w-5 h-5 ${canceladas.length ? 'text-rose-600' : 'text-slate-400'}`} />
          </div>
          <div className="min-w-0">
            <p className="text-xs text-slate-500">Canceladas</p>
            <p className={`text-xl font-bold ${canceladas.length ? 'text-rose-600' : 'text-slate-800'}`}>{loading ? '…' : canceladas.length}</p>
          </div>
        </div>
      </div>

      {topeAlcanzado && (
        <p className="text-xs text-amber-600 -mt-2">Mostrando las primeras 200 ventas; afina el rango para un conteo exacto.</p>
      )}

      <DataTable
        columns={columns}
        data={rows}
        loading={loading}
        empty="Sin ventas"
        onRowClick={abrirDetalle}
        exportName="ventas"
        exportTitle="Ventas"
      />

      {/* Detalle */}
      <Modal
        open={!!detalle}
        onClose={() => setDetalle(null)}
        title={detalle ? `Venta ${detalle.folio}` : 'Venta'}
        size="xl"
        footer={
          detalle && (
            <>
              <button className="btn-secondary" onClick={() => setDetalle(null)}>
                Cerrar
              </button>
              {detalle.estado === 'completada' && hasPermiso('ventas.cancelar') && (
                <button className="btn-danger" disabled={cancelando} onClick={cancelarVenta}>
                  {cancelando ? 'Cancelando…' : 'Cancelar venta'}
                </button>
              )}
            </>
          )
        }
      >
        {detalle && (
          <div className="space-y-5">
            {detalleLoading && <p className="text-sm text-slate-400">Cargando detalle…</p>}

            {/* Encabezado */}
            <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-3">
              <div>
                <p className="text-xs text-slate-500">Fecha</p>
                <p className="font-medium text-slate-800">{fecha(detalle.fecha)}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Cliente</p>
                <p className="font-medium text-slate-800">{detalle.id_cliente?.nombre || '—'}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Vendedor</p>
                <p className="font-medium text-slate-800">
                  {detalle.id_vendedor
                    ? `${detalle.id_vendedor.nombre || ''} ${detalle.id_vendedor.apellido || ''}`.trim() || '—'
                    : '—'}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Tienda</p>
                <p className="font-medium text-slate-800">{detalle.id_almacen?.nombre || '—'}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Nivel cantidad</p>
                <p className="font-medium text-slate-800">{detalle.nivel_cantidad ?? '—'}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Estado</p>
                <span className={estadoBadge(detalle.estado)}>{detalle.estado}</span>
              </div>
            </div>

            {/* Líneas */}
            <div>
              <p className="text-sm font-semibold text-slate-700 mb-2">Artículos</p>
              <div className="card overflow-hidden">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Código</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Descripción</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Cant.</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">P. Unit.</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Lista</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-500 uppercase">Importe</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {(detalle.lineas || []).map((l, i) => (
                      <tr key={i}>
                        <td className="px-3 py-2 text-slate-700">{l.codigo}</td>
                        <td className="px-3 py-2 text-slate-700">{l.descripcion}</td>
                        <td className="px-3 py-2 text-right text-slate-700">{l.cantidad}</td>
                        <td className="px-3 py-2 text-right text-slate-700">{money(l.precio_unitario)}</td>
                        <td className="px-3 py-2 text-slate-700">{l.lista_aplicada || '—'}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-800">{money(l.importe)}</td>
                      </tr>
                    ))}
                    {(!detalle.lineas || detalle.lineas.length === 0) && (
                      <tr>
                        <td colSpan={6} className="px-3 py-6 text-center text-slate-400">
                          Sin artículos
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Pagos + totales */}
            <div className="grid gap-5 md:grid-cols-2">
              <div>
                <p className="text-sm font-semibold text-slate-700 mb-2">Pagos</p>
                <div className="card divide-y divide-slate-100">
                  {(detalle.pagos || []).map((p, i) => (
                    <div key={i} className="flex justify-between px-3 py-2 text-sm">
                      <span className="text-slate-600 capitalize">{p.forma}</span>
                      <span className="font-medium text-slate-800">{money(p.importe)}</span>
                    </div>
                  ))}
                  {(!detalle.pagos || detalle.pagos.length === 0) && (
                    <p className="px-3 py-4 text-center text-sm text-slate-400">Sin pagos</p>
                  )}
                </div>
              </div>

              <div className="card p-4 space-y-2 text-sm self-start">
                <div className="flex justify-between">
                  <span className="text-slate-500">Total</span>
                  <span className="font-semibold text-slate-800">{money(detalle.total)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Total pagado</span>
                  <span className="text-slate-800">{money(detalle.total_pagado)}</span>
                </div>
                {detalle.a_credito && (
                  <div className="flex justify-between">
                    <span className="text-slate-500">A crédito</span>
                    <span className="text-slate-800">{money(detalle.monto_credito)}</span>
                  </div>
                )}
                {!!detalle.saldo_favor_generado && (
                  <div className="flex justify-between">
                    <span className="text-slate-500">Saldo a favor generado</span>
                    <span className="text-slate-800">{money(detalle.saldo_favor_generado)}</span>
                  </div>
                )}
                {!!detalle.cambio_efectivo && (
                  <div className="flex justify-between">
                    <span className="text-slate-500">Cambio (efectivo)</span>
                    <span className="text-slate-800">{money(detalle.cambio_efectivo)}</span>
                  </div>
                )}
                {detalle.a_credito && <span className="badge-warning">Venta a crédito</span>}
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
