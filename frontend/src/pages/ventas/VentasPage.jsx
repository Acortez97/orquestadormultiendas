import { useState, useEffect, useCallback } from 'react';
import { FunnelIcon } from '@heroicons/react/24/outline';
import Swal from '../../utils/swal';
import DataTable from '../../components/common/DataTable';
import PanelDetalle from '../../components/common/PanelDetalle';
import { ventasApi, almacenesApi, clientesApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';
import { hoyLocal, aFecha } from '../../utils/fechas';
import { FORMA_LABEL } from '../../components/common/DestinoPago';
import { aviso } from '../../utils/avisos';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? aFecha(d).toLocaleDateString('es-MX') : '');

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
  const [verFiltros, setVerFiltros] = useState(false);   // en celular los filtros se abren con un boton

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtros.id_almacen) params.id_almacen = filtros.id_almacen;
      if (filtros.id_cliente) params.id_cliente = filtros.id_cliente;
      if (filtros.desde) params.desde = filtros.desde;   // dia local; el API cubre de 00:00 a 23:59:59
      if (filtros.hasta) params.hasta = filtros.hasta;
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
      aviso('Venta cancelada');
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
    { key: 'folio', label: 'Folio', className: 'font-medium text-slate-900', render: (r) => <span className="folio">{r.folio}</span> },
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
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Ventas</h1>
      </div>

      {/* Filtros (en celular, detras de un boton) */}
      <button type="button" className="btn-secondary w-full sm:hidden" aria-expanded={verFiltros} onClick={() => setVerFiltros((v) => !v)}>
        <FunnelIcon className="h-5 w-5" />
        {verFiltros ? 'Ocultar filtros' : `Filtros · ${[filtros.id_almacen, filtros.id_cliente].filter(Boolean).length + (filtros.desde || filtros.hasta ? 1 : 0)}`}
      </button>
      <div className={`card p-4 ${verFiltros ? '' : 'hidden sm:block'}`}>
        <div className="grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap">
          <div className="hidden items-center gap-2 text-slate-500 sm:flex">
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

      {/* Resumen del rango / filtros */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          ['Ventas', loading ? '…' : completadas.length, ''],
          ['Total vendido', loading ? '…' : money(montoVendido), ''],
          ['Ticket promedio', loading ? '…' : money(ticketPromedio), ''],
          ['Canceladas', loading ? '…' : canceladas.length, canceladas.length ? 'text-danger-600' : ''],
        ].map(([t, v, c]) => (
          <div key={t} className="card p-4">
            <p className="text-[13px] text-slate-600">{t}</p>
            <p className={`truncate text-xl font-bold sm:text-2xl ${c}`}>{v}</p>
          </div>
        ))}
      </div>

      {topeAlcanzado && (
        <p className="text-xs text-amber-600 -mt-2">Mostrando las primeras 200 ventas; afina el rango para un conteo exacto.</p>
      )}

      <div className="lg:flex lg:items-start lg:gap-4">
      <div className="min-w-0 flex-1">
      <DataTable
        columns={columns}
        data={rows}
        loading={loading}
        empty="Sin ventas"
        onRowClick={abrirDetalle}
        selectedId={detalle?._id}
        exportName="ventas"
        exportTitle="Ventas"
      />
      </div>

      {/* Detalle: panel a un lado (computadora) u hoja (celular) */}
      <PanelDetalle
        open={!!detalle}
        onClose={() => setDetalle(null)}
        title={detalle ? `Venta ${detalle.folio}` : 'Venta'}
        footer={
          detalle && detalle.estado === 'completada' && hasPermiso('ventas.cancelar') && (
            <button className="btn-danger" disabled={cancelando} onClick={cancelarVenta}>
              {cancelando ? 'Cancelando…' : 'Cancelar venta'}
            </button>
          )
        }
      >
        {detalle && (
          <div className="space-y-5">
            {detalleLoading && <div className="skeleton h-4 w-32" />}
            <div className="flex items-start justify-between gap-3">
              <div className="text-sm text-slate-600">
                <p>{fecha(detalle.fecha)} · {detalle.id_almacen?.nombre || '—'}</p>
                <p>Vendedor: {detalle.id_vendedor ? `${detalle.id_vendedor.nombre || ''} ${detalle.id_vendedor.apellido || ''}`.trim() || '—' : '—'}</p>
              </div>
              <span className={estadoBadge(detalle.estado)}>{detalle.estado}</span>
            </div>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.05em] text-slate-600">Cliente</p>
              <p className="font-semibold">{detalle.id_cliente?.nombre || '—'}</p>
            </div>

            <ul className="space-y-3 border-t border-slate-200 pt-4">
              {(detalle.lineas || []).map((l, i) => (
                <li key={i} className="flex justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-semibold">{l.cantidad} × {l.descripcion}</p>
                    <p className="text-[13px] text-slate-600">
                      <span className="folio">{l.codigo}</span>
                      {[l.color, l.talla].filter(Boolean).length ? ` · ${[l.color, l.talla].filter(Boolean).join(' / ')}` : ''}
                      {` · ${money(l.precio_unitario)} · Lista ${l.lista_aplicada || '—'}`}
                    </p>
                  </div>
                  <p className="shrink-0 font-semibold">{money(l.importe)}</p>
                </li>
              ))}
              {(!detalle.lineas || detalle.lineas.length === 0) && !detalleLoading && <li className="text-slate-600">Sin artículos</li>}
            </ul>

            <div className="space-y-1.5 border-t border-slate-200 pt-4 text-sm">
              <div className="flex justify-between text-xl font-bold"><span>Total</span><span>{money(detalle.total)}</span></div>
              {(detalle.pagos || []).map((p, i) => (
                <div key={i} className="flex justify-between text-slate-700">
                  <span>{FORMA_LABEL[p.forma] || p.forma}{p.referencia ? ` · ${p.referencia}` : ''}</span><span>{money(p.importe)}</span>
                </div>
              ))}
              {detalle.a_credito && <div className="flex justify-between"><span className="text-slate-600">A crédito</span><span>{money(detalle.monto_credito)}</span></div>}
              {!!detalle.saldo_favor_generado && <div className="flex justify-between"><span className="text-slate-600">Al monedero</span><span>{money(detalle.saldo_favor_generado)}</span></div>}
              {!!detalle.cambio_efectivo && <div className="flex justify-between"><span className="text-slate-600">Cambio entregado</span><span>{money(detalle.cambio_efectivo)}</span></div>}
            </div>
          </div>
        )}
      </PanelDetalle>
      </div>
    </div>
  );
}
