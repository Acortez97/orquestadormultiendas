import { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';
import DataTable from '../../components/common/DataTable';
import EstadoCuentaCliente from '../../components/common/EstadoCuentaCliente';
import { cuentasClienteApi } from '../../services/api/endpoints';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n || 0));

export default function CuentasClientePage() {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [cliente, setCliente] = useState(null);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await cuentasClienteApi.listar();
      setData(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudieron cargar las cuentas', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const columns = [
    { key: 'nombre', label: 'Cliente' },
    { key: 'rfc', label: 'RFC', render: (r) => r.rfc || '—' },
    { key: 'cargos', label: 'Cargos', render: (r) => <span className="badge-danger">{money(r.cargos)}</span>, exportValue: (r) => r.cargos },
    { key: 'abonos', label: 'Abonos', render: (r) => <span className="badge-success">{money(r.abonos)}</span>, exportValue: (r) => r.abonos },
    {
      key: 'saldo',
      label: 'Saldo',
      render: (r) => <span className={Number(r.saldo) > 0 ? 'badge-danger' : 'badge-success'}>{money(r.saldo)}</span>,
      exportValue: (r) => r.saldo,
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-bold text-slate-800">Cuentas por cobrar (clientes)</h1>
      <p className="text-sm text-slate-500">Haz clic en un cliente para ver su estado de cuenta y registrar un abono.</p>
      <DataTable
        columns={columns}
        data={data}
        loading={loading}
        empty="Sin cuentas de cliente"
        onRowClick={setCliente}
        exportName="cuentas_cliente"
        exportTitle="Cuentas por cobrar"
      />

      <EstadoCuentaCliente cliente={cliente} onClose={() => setCliente(null)} onChanged={cargar} />
    </div>
  );
}
