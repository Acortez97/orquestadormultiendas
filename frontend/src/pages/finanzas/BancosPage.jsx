import { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { bancosApi } from '../../services/api/endpoints';

const money = (n, c = 'MXN') =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: c }).format(Number(n || 0));

const emptyForm = { nombre: '', moneda: 'MXN', cuenta: '', clabe: '' };

export default function BancosPage() {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(emptyForm);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await bancosApi.listar();
      setData(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudieron cargar los bancos', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar]);

  const abrirCrear = () => {
    setForm(emptyForm);
    setOpen(true);
  };

  const guardar = async () => {
    if (!form.nombre.trim()) {
      Swal.fire('Atención', 'El nombre es obligatorio', 'warning');
      return;
    }
    setSaving(true);
    try {
      await bancosApi.crear({
        nombre: form.nombre.trim(),
        moneda: form.moneda,
        cuenta: form.cuenta || null,
        clabe: form.clabe || null,
      });
      setOpen(false);
      await cargar();
      Swal.fire('Listo', 'Banco creado', 'success');
    } catch (err) {
      Swal.fire('Error', err?.message || 'No se pudo guardar', 'error');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    {
      key: 'moneda',
      label: 'Moneda',
      render: (r) => <span className="badge-slate">{r.moneda}</span>,
    },
    { key: 'cuenta', label: 'Cuenta', render: (r) => r.cuenta || '—' },
    { key: 'clabe', label: 'CLABE', render: (r) => r.clabe || '—' },
    {
      key: 'saldo_actual',
      label: 'Saldo',
      render: (r) => money(r.saldo_actual, r.moneda),
    },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Bancos</h1>
        <button className="btn-primary flex items-center gap-2" onClick={abrirCrear}>
          <PlusIcon className="w-5 h-5" /> Nuevo banco
        </button>
      </div>

      <DataTable columns={columns} data={data} loading={loading} empty="Sin bancos" exportName="bancos" exportTitle="Bancos" />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Nuevo banco"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setOpen(false)}>
              Cancelar
            </button>
            <button className="btn-primary" onClick={guardar} disabled={saving}>
              {saving ? 'Guardando…' : 'Guardar'}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Nombre *</label>
            <input
              className="input-base"
              value={form.nombre}
              onChange={(e) => setForm({ ...form, nombre: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Moneda</label>
            <select
              className="input-base"
              value={form.moneda}
              onChange={(e) => setForm({ ...form, moneda: e.target.value })}
            >
              <option value="MXN">MXN</option>
              <option value="USD">USD</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">Cuenta</label>
            <input
              className="input-base"
              value={form.cuenta}
              onChange={(e) => setForm({ ...form, cuenta: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">CLABE</label>
            <input
              className="input-base"
              value={form.clabe}
              onChange={(e) => setForm({ ...form, clabe: e.target.value })}
            />
          </div>
        </div>
      </Modal>
    </div>
  );
}
