import { useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { familiasApi, lineasApi, cortesCatalogoApi, marcasApi, conceptosGastoApi } from '../../services/api/endpoints';
import CategoriasTab from './CategoriasTab';
import AtributosTab from './AtributosTab';

const TABS = [
  { key: 'categorias', label: 'Categorías', component: CategoriasTab },
  { key: 'atributos', label: 'Atributos (colores, tallas...)', component: AtributosTab },
  { key: 'familias', label: 'Familias', api: familiasApi, extra: [] },
  { key: 'lineas', label: 'Líneas', api: lineasApi, extra: [] },
  { key: 'cortes', label: 'Cortes', api: cortesCatalogoApi, extra: [] },
  { key: 'marcas', label: 'Marcas', api: marcasApi, extra: [] },
  { key: 'conceptos', label: 'Conceptos de gasto', api: conceptosGastoApi, extra: [] },
];

function CatalogoCRUD({ tab }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ nombre: '' });
  const [editId, setEditId] = useState(null);

  const cargar = async () => {
    setLoading(true);
    try { const res = await tab.api.listar(); setRows(res.data || []); } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); /* eslint-disable-next-line */ }, [tab.key]);

  const abrir = (r) => { setForm(r ? { ...r } : { nombre: '' }); setEditId(r?._id || null); setOpen(true); };
  const guardar = async () => {
    try {
      if (editId) await tab.api.actualizar(editId, form); else await tab.api.crear(form);
      setOpen(false); cargar();
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };
  const eliminar = async (r) => {
    const c = await Swal.fire({ title: `¿Eliminar ${r.nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (c.isConfirmed) { await tab.api.eliminar(r._id); cargar(); }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    ...tab.extra.map((f) => ({ key: f.name, label: f.label, render: (r) => f.name === 'hex' && r.hex
      ? <span className="inline-flex items-center gap-2"><span className="w-4 h-4 rounded-full border" style={{ background: r.hex }} />{r.hex}</span>
      : r[f.name] })),
    { key: 'acc', label: '', render: (r) => (
      <div className="flex gap-2">
        <button className="btn-ghost text-sm" onClick={() => abrir(r)}>Editar</button>
        <button className="btn-ghost text-sm text-rose-600" onClick={() => eliminar(r)}>Eliminar</button>
      </div>
    ) },
  ];

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button className="btn-primary" onClick={() => abrir(null)}><PlusIcon className="w-4 h-4" /> Nuevo</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty={`Sin ${tab.label.toLowerCase()}`} exportName={tab.label} exportTitle={tab.label} />
      <Modal open={open} onClose={() => setOpen(false)} title={editId ? `Editar ${tab.label}` : `Nuevo ${tab.label}`}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="space-y-3">
          <label className="text-sm block">Nombre<input className="input-base" value={form.nombre || ''} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
          {tab.extra.map((f) => (
            <label key={f.name} className="text-sm block">{f.label}
              <input type={f.type} className="input-base" value={form[f.name] ?? ''} onChange={(e) => setForm({ ...form, [f.name]: f.type === 'number' ? Number(e.target.value) : e.target.value })} />
            </label>
          ))}
        </div>
      </Modal>
    </div>
  );
}

export default function CatalogosPage() {
  const [active, setActive] = useState(TABS[0]);
  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Catálogos</h1>
      <div className="flex flex-wrap gap-2 border-b border-slate-200">
        {TABS.map((t) => (
          <button key={t.key} onClick={() => setActive(t)}
            className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${active.key === t.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
            {t.label}
          </button>
        ))}
      </div>
      {active.component ? <active.component /> : <CatalogoCRUD tab={active} />}
    </div>
  );
}
