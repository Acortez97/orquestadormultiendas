import { useEffect, useState } from 'react';
import Swal from '../../utils/swal';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { categoriasApi, atributosApi } from '../../services/api/endpoints';
import { aviso } from '../../utils/avisos';

const TIPOS_FICHA = [['texto', 'Texto'], ['numero', 'Número'], ['fecha', 'Fecha'], ['booleano', 'Sí/No']];
const vacio = { nombre: '', prefijo_sku: '', id_atributo_eje1: '', id_atributo_eje2: '', ficha_schema: [] };

/**
 * Alta/edición de categorías (MultiTienda): definen el prefijo del SKU, los 2 ejes de
 * variante (atributos) y la ficha de campos propios. Sin ejes = producto simple.
 */
export default function CategoriasTab() {
  const [rows, setRows] = useState([]);
  const [atributos, setAtributos] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editId, setEditId] = useState(null);

  const cargar = async () => {
    setLoading(true);
    try { const r = await categoriasApi.listar({ is_active: 'todos' }); setRows(r.data || []); } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); atributosApi.listar().then((r) => setAtributos(r.data || [])).catch(() => {}); }, []);

  const abrir = (r) => {
    if (r) {
      setForm({
        nombre: r.nombre || '', prefijo_sku: r.prefijo_sku || '',
        id_atributo_eje1: r.id_atributo_eje1?._id || '', id_atributo_eje2: r.id_atributo_eje2?._id || '',
        ficha_schema: (r.ficha_schema || []).map((c) => ({ ...c })),
      });
      setEditId(r._id);
    } else { setForm(vacio); setEditId(null); }
    setOpen(true);
  };

  const guardar = async () => {
    if (!form.nombre.trim()) { Swal.fire('Falta el nombre', '', 'warning'); return; }
    try {
      const payload = {
        nombre: form.nombre, prefijo_sku: form.prefijo_sku || null,
        id_atributo_eje1: form.id_atributo_eje1 || null, id_atributo_eje2: form.id_atributo_eje2 || null,
        ficha_schema: form.ficha_schema.filter((c) => (c.key || '').trim()),
      };
      if (editId) await categoriasApi.actualizar(editId, payload); else await categoriasApi.crear(payload);
      setOpen(false); cargar();
      aviso('Guardado');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };
  const eliminar = async (r) => {
    const c = await Swal.fire({ title: `¿Desactivar ${r.nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (c.isConfirmed) { await categoriasApi.eliminar(r._id); cargar(); }
  };

  const addCampo = () => setForm((f) => ({ ...f, ficha_schema: [...f.ficha_schema, { key: '', label: '', tipo: 'texto' }] }));
  const setCampo = (i, patch) => setForm((f) => ({ ...f, ficha_schema: f.ficha_schema.map((c, idx) => (idx === i ? { ...c, ...patch } : c)) }));
  const delCampo = (i) => setForm((f) => ({ ...f, ficha_schema: f.ficha_schema.filter((_, idx) => idx !== i) }));

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    { key: 'prefijo', label: 'Prefijo SKU', render: (r) => r.prefijo_sku || '—' },
    { key: 'eje1', label: 'Eje 1', render: (r) => r.id_atributo_eje1?.nombre || '—' },
    { key: 'eje2', label: 'Eje 2', render: (r) => r.id_atributo_eje2?.nombre || '—' },
    { key: 'ficha', label: 'Ficha', render: (r) => `${r.ficha_schema?.length || 0} campos` },
    { key: 'estado', label: 'Estado', render: (r) => <span className={r.is_active === 'Si' ? 'badge-success' : 'badge-slate'}>{r.is_active === 'Si' ? 'Activa' : 'Inactiva'}</span> },
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
        <button className="btn-primary" onClick={() => abrir(null)}><PlusIcon className="w-4 h-4" /> Nueva categoría</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin categorías" exportName="categorias" exportTitle="Categorías" />

      <Modal open={open} onClose={() => setOpen(false)} size="lg" title={editId ? 'Editar categoría' : 'Nueva categoría'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <label className="text-sm">Nombre *<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
            <label className="text-sm">Prefijo SKU <span className="text-slate-400">(ej. ROP)</span>
              <input className="input-base uppercase" maxLength={5} value={form.prefijo_sku} onChange={(e) => setForm({ ...form, prefijo_sku: e.target.value.toUpperCase() })} /></label>
            <label className="text-sm">Eje 1 (variante)
              <select className="input-base" value={form.id_atributo_eje1} onChange={(e) => setForm({ ...form, id_atributo_eje1: e.target.value })}>
                <option value="">— Sin eje —</option>
                {atributos.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </label>
            <label className="text-sm">Eje 2 (variante)
              <select className="input-base" value={form.id_atributo_eje2} onChange={(e) => setForm({ ...form, id_atributo_eje2: e.target.value })}>
                <option value="">— Sin eje —</option>
                {atributos.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </label>
          </div>

          <div>
            <div className="flex items-center justify-between mb-1">
              <p className="text-sm font-semibold text-slate-700">Ficha (campos propios)</p>
              <button type="button" className="btn-ghost text-xs text-sky-600 flex items-center gap-1" onClick={addCampo}><PlusIcon className="w-4 h-4" /> Campo</button>
            </div>
            {form.ficha_schema.length === 0 && <p className="text-xs text-slate-400">Sin campos (opcional).</p>}
            <div className="space-y-2">
              {form.ficha_schema.map((c, i) => (
                <div key={i} className="grid grid-cols-12 gap-2 items-center">
                  <input className="input-base col-span-4 text-sm" placeholder="clave (ej. garantia)" value={c.key} onChange={(e) => setCampo(i, { key: e.target.value })} />
                  <input className="input-base col-span-4 text-sm" placeholder="Etiqueta" value={c.label} onChange={(e) => setCampo(i, { label: e.target.value })} />
                  <select className="input-base col-span-3 text-sm" value={c.tipo} onChange={(e) => setCampo(i, { tipo: e.target.value })}>
                    {TIPOS_FICHA.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </select>
                  <button type="button" className="btn-ghost p-1 text-rose-600 col-span-1" onClick={() => delCampo(i)}><TrashIcon className="w-4 h-4" /></button>
                </div>
              ))}
            </div>
          </div>

          <p className="text-xs text-slate-400">
            Los ejes definen la matriz de variantes (ej. Ropa = Color × Talla). Sin ejes = producto simple.
            El prefijo se usa para el SKU interno automático. ¿Falta un eje? Créalo en la pestaña <b>Atributos</b>.
          </p>
        </div>
      </Modal>
    </div>
  );
}
