import { useEffect, useState } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { almacenesApi } from '../../services/api/endpoints';
import { aviso } from '../../utils/avisos';

const vacio = { codigo: '', nombre: '', tipo: 'tienda', vende_publico: true, serie_folio: '', direccion: '', telefono: '' };

export default function AlmacenesPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editId, setEditId] = useState(null);

  const cargar = async () => {
    setLoading(true);
    try {
      const res = await almacenesApi.listar();
      setRows(res.data || []);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { cargar(); }, []);

  const abrirNuevo = () => { setForm(vacio); setEditId(null); setOpen(true); };
  const abrirEdit = (r) => { setForm({ ...vacio, ...r }); setEditId(r._id); setOpen(true); };

  const guardar = async () => {
    try {
      if (editId) await almacenesApi.actualizar(editId, form);
      else await almacenesApi.crear(form);
      setOpen(false);
      cargar();
      aviso('Guardado');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const eliminar = async (r) => {
    const c = await Swal.fire({ title: `¿Desactivar ${r.nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (!c.isConfirmed) return;
    try { await almacenesApi.eliminar(r._id); cargar(); } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const columns = [
    { key: 'codigo', label: 'Código' },
    { key: 'nombre', label: 'Nombre' },
    { key: 'tipo', label: 'Tipo', render: (r) => <span className={`badge-${r.tipo === 'bodega' ? 'primary' : 'accent'}`}>{r.tipo}</span> },
    { key: 'vende_publico', label: 'Vende público', render: (r) => (r.vende_publico ? 'Sí' : 'No') },
    { key: 'serie_folio', label: 'Serie' },
    { key: 'acc', label: '', render: (r) => (
      <div className="flex gap-2">
        <button className="btn-ghost text-sm" onClick={() => abrirEdit(r)}>Editar</button>
        <button className="btn-ghost text-sm text-rose-600" onClick={() => eliminar(r)}>Eliminar</button>
      </div>
    ) },
  ];

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Almacenes</h1>
        <button className="btn-primary" onClick={abrirNuevo}><PlusIcon className="w-4 h-4" /> Nuevo</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin almacenes" exportName="almacenes" exportTitle="Almacenes" />

      <Modal open={open} onClose={() => setOpen(false)} title={editId ? 'Editar almacén' : 'Nuevo almacén'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">Código<input className="input-base" value={form.codigo} onChange={(e) => setForm({ ...form, codigo: e.target.value })} /></label>
          <label className="text-sm">Nombre<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
          <label className="text-sm">Tipo
            <select className="input-base" value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value })}>
              <option value="tienda">Tienda</option>
              <option value="bodega">Bodega</option>
            </select>
          </label>
          <label className="text-sm">Serie folio<input className="input-base" value={form.serie_folio || ''} onChange={(e) => setForm({ ...form, serie_folio: e.target.value })} /></label>
          <label className="text-sm col-span-2 flex items-center gap-2 mt-1">
            <input type="checkbox" checked={form.vende_publico} onChange={(e) => setForm({ ...form, vende_publico: e.target.checked })} /> Vende al público
          </label>
          <label className="text-sm col-span-2">Dirección<input className="input-base" value={form.direccion || ''} onChange={(e) => setForm({ ...form, direccion: e.target.value })} /></label>
          <label className="text-sm">Teléfono<input className="input-base" value={form.telefono || ''} onChange={(e) => setForm({ ...form, telefono: e.target.value })} /></label>
        </div>
      </Modal>
    </div>
  );
}
