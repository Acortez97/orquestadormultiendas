import { useState, useEffect, useCallback } from 'react';
import { BanknotesIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import { useAuth } from '../../contexts/AuthContext';
import { comisionesApi, empleadosApi } from '../../services/api/endpoints';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const fecha = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');

export default function ComisionesPage() {
  const { hasPermiso } = useAuth();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  const [empleados, setEmpleados] = useState([]);
  const [resumen, setResumen] = useState(null);

  const [filtros, setFiltros] = useState({ id_empleado: '', pagada: '' });

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtros.id_empleado) params.id_empleado = filtros.id_empleado;
      if (filtros.pagada) params.pagada = filtros.pagada;
      const res = await comisionesApi.listar(params);
      setRows(res.data || []);
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo cargar', 'error');
    } finally {
      setLoading(false);
    }
  }, [filtros]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    (async () => {
      try {
        const [emp, res] = await Promise.all([
          empleadosApi.listar(),
          comisionesApi.resumen().catch(() => null),
        ]);
        setEmpleados(emp.data?.docs ?? emp.data ?? []);
        if (res) setResumen(res.data ?? res);
      } catch (_) {
        /* noop */
      }
    })();
  }, []);

  async function pagar(row) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Marcar como pagada?',
      text: `Comisión de ${money(row.importe)} — venta ${row.folio_venta}`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Pagar',
    });
    if (!isConfirmed) return;
    try {
      const res = await comisionesApi.pagar(row._id);
      if (res.success === false) throw new Error(res.message);
      cargar();
      Swal.fire('Listo', 'Comisión pagada', 'success');
    } catch (e) {
      Swal.fire('Error', e.message || 'No se pudo pagar', 'error');
    }
  }

  const vendedor = (r) => {
    const e = r.id_empleado;
    if (!e) return '—';
    return `${e.nombre || ''} ${e.apellido || ''}`.trim() || '—';
  };

  const columns = [
    { key: 'fecha', label: 'Fecha', render: (r) => fecha(r.createdAt) },
    { key: 'vendedor', label: 'Vendedor', render: vendedor },
    { key: 'folio_venta', label: 'Venta' },
    { key: 'base', label: 'Base', render: (r) => money(r.base) },
    {
      key: 'porcentaje',
      label: '%',
      render: (r) => `${Number(r.porcentaje || 0)}%`,
    },
    { key: 'importe', label: 'Importe', render: (r) => money(r.importe) },
    {
      key: 'pagada',
      label: 'Pagada',
      render: (r) => (
        <span className={r.pagada === 'Si' ? 'badge-success' : 'badge-warning'}>
          {r.pagada === 'Si' ? 'Sí' : 'No'}
        </span>
      ),
    },
    {
      key: 'acciones',
      label: 'Acciones',
      render: (r) =>
        r.pagada === 'No' && hasPermiso('comisiones.pagar') ? (
          <button onClick={() => pagar(r)} className="btn-accent px-2 py-1 text-xs flex items-center gap-1">
            <BanknotesIcon className="w-4 h-4" /> Pagar
          </button>
        ) : (
          <span className="text-slate-400 text-xs">—</span>
        ),
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Comisiones</h1>
      </div>

      {resumen && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <Card label="Total comisiones" value={money(resumen.total ?? resumen.total_comisiones)} />
          <Card label="Pendiente de pago" value={money(resumen.pendiente ?? resumen.por_pagar)} />
          <Card label="Pagadas" value={money(resumen.pagadas ?? resumen.total_pagado)} />
          <Card label="# Comisiones" value={resumen.cantidad ?? resumen.num ?? rows.length} />
        </div>
      )}

      <div className="card p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Vendedor</label>
            <select
              className="input-base"
              value={filtros.id_empleado}
              onChange={(e) => setFiltros({ ...filtros, id_empleado: e.target.value })}
            >
              <option value="">Todos</option>
              {empleados.map((e) => (
                <option key={e._id} value={e._id}>
                  {`${e.nombre || ''} ${e.apellido || ''}`.trim()}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Estado</label>
            <select
              className="input-base"
              value={filtros.pagada}
              onChange={(e) => setFiltros({ ...filtros, pagada: e.target.value })}
            >
              <option value="">Todos</option>
              <option value="No">Pendientes</option>
              <option value="Si">Pagadas</option>
            </select>
          </div>
        </div>
      </div>

      <DataTable columns={columns} data={rows} loading={loading} empty="Sin comisiones" exportName="comisiones" exportTitle="Comisiones" />
    </div>
  );
}

function Card({ label, value }) {
  return (
    <div className="card p-4">
      <p className="text-xs text-slate-500 uppercase tracking-wide">{label}</p>
      <p className="text-xl font-bold text-slate-800 mt-1">{value}</p>
    </div>
  );
}
