import { useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import { atributosApi } from '../../services/api/endpoints';

const TIPOS = [['texto', 'Texto'], ['color', 'Color'], ['numero', 'Número']];
const TIPO_LABEL = { texto: 'Texto', color: 'Color', numero: 'Número' };

/**
 * Alta/edición de atributos de variante (Color, Talla, Número, Material…) y sus valores.
 * Un atributo se usa como Eje 1 / Eje 2 de una categoría. Tipo "color" guarda un hex por valor.
 */
export default function AtributosTab() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ nombre: '', tipo_valor: 'texto' });
  const [editId, setEditId] = useState(null);
  // Sub-CRUD de valores
  const [valOpen, setValOpen] = useState(null);
  const [valores, setValores] = useState([]);
  const [nuevoVal, setNuevoVal] = useState({ nombre: '', extra: '' });

  const cargar = async () => {
    setLoading(true);
    try { const r = await atributosApi.listar(); setRows(r.data || []); } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); }, []);

  const abrir = (r) => {
    if (r) { setForm({ nombre: r.nombre, tipo_valor: r.tipo_valor }); setEditId(r._id); }
    else { setForm({ nombre: '', tipo_valor: 'texto' }); setEditId(null); }
    setOpen(true);
  };
  const guardar = async () => {
    if (!form.nombre.trim()) { Swal.fire('Falta el nombre', '', 'warning'); return; }
    try {
      if (editId) await atributosApi.actualizar(editId, form); else await atributosApi.crear(form);
      setOpen(false); cargar();
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };
  const eliminar = async (r) => {
    const c = await Swal.fire({ title: `¿Desactivar ${r.nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (c.isConfirmed) { await atributosApi.eliminar(r._id); cargar(); }
  };

  const abrirValores = async (r) => {
    setValOpen(r); setNuevoVal({ nombre: '', extra: r.tipo_valor === 'color' ? '#2563eb' : '' });
    try { const res = await atributosApi.valores(r._id); setValores(res.data || []); } catch { setValores([]); }
  };
  const addValor = async () => {
    if (!nuevoVal.nombre.trim()) return;
    try {
      await atributosApi.crearValor(valOpen._id, { nombre: nuevoVal.nombre, extra: nuevoVal.extra || null, orden: valores.length });
      const res = await atributosApi.valores(valOpen._id);
      setValores(res.data || []);
      setNuevoVal({ nombre: '', extra: valOpen.tipo_valor === 'color' ? '#2563eb' : '' });
      cargar();
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };
  const delValor = async (v) => {
    try { await atributosApi.eliminarValor(v._id); setValores((vs) => vs.filter((x) => x._id !== v._id)); cargar(); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const columns = [
    { key: 'nombre', label: 'Nombre' },
    { key: 'tipo', label: 'Tipo', render: (r) => TIPO_LABEL[r.tipo_valor] || r.tipo_valor },
    { key: 'vals', label: 'Valores', render: (r) => (r.valores?.length || 0) },
    { key: 'acc', label: '', render: (r) => (
      <div className="flex gap-2">
        <button className="btn-ghost text-sm text-sky-600" onClick={() => abrirValores(r)}>Valores</button>
        <button className="btn-ghost text-sm" onClick={() => abrir(r)}>Editar</button>
        <button className="btn-ghost text-sm text-rose-600" onClick={() => eliminar(r)}>Eliminar</button>
      </div>
    ) },
  ];

  const esColor = valOpen?.tipo_valor === 'color';

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button className="btn-primary" onClick={() => abrir(null)}><PlusIcon className="w-4 h-4" /> Nuevo atributo</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin atributos" exportName="atributos" exportTitle="Atributos" />

      {/* Atributo */}
      <Modal open={open} onClose={() => setOpen(false)} title={editId ? 'Editar atributo' : 'Nuevo atributo'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="space-y-3">
          <label className="text-sm block">Nombre *<input className="input-base" value={form.nombre} onChange={(e) => setForm({ ...form, nombre: e.target.value })} /></label>
          <label className="text-sm block">Tipo de valor
            <select className="input-base" value={form.tipo_valor} onChange={(e) => setForm({ ...form, tipo_valor: e.target.value })}>
              {TIPOS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </label>
          <p className="text-xs text-slate-400">"Color" guarda un hex por valor (se muestra swatch). Carga los valores con el botón <b>Valores</b>.</p>
        </div>
      </Modal>

      {/* Valores del atributo */}
      <Modal open={!!valOpen} onClose={() => setValOpen(null)} title={valOpen ? `Valores de ${valOpen.nombre}` : 'Valores'}
        footer={<button className="btn-secondary" onClick={() => setValOpen(null)}>Cerrar</button>}>
        {valOpen && (
          <div className="space-y-3">
            <div className="flex gap-2 items-end">
              <label className="text-sm flex-1">Nuevo valor
                <input className="input-base" value={nuevoVal.nombre} onChange={(e) => setNuevoVal({ ...nuevoVal, nombre: e.target.value })}
                  onKeyDown={(e) => e.key === 'Enter' && addValor()} placeholder={esColor ? 'ej. Rojo' : 'ej. M / 26 / Plata'} /></label>
              {esColor && (
                <label className="text-sm">Color
                  <input type="color" className="input-base h-10 w-16 p-1" value={nuevoVal.extra || '#000000'} onChange={(e) => setNuevoVal({ ...nuevoVal, extra: e.target.value })} /></label>
              )}
              <button className="btn-primary" onClick={addValor}>Agregar</button>
            </div>
            <div className="border border-slate-200 rounded-lg divide-y max-h-72 overflow-y-auto">
              {valores.length === 0 && <p className="px-3 py-4 text-center text-slate-400 text-sm">Sin valores</p>}
              {valores.map((v) => (
                <div key={v._id} className="flex items-center justify-between px-3 py-2 text-sm">
                  <span className="flex items-center gap-2">
                    {esColor && v.extra && <span className="w-4 h-4 rounded-full border border-slate-300" style={{ background: v.extra }} />}
                    {v.nombre}{esColor && v.extra ? <span className="text-slate-400">· {v.extra}</span> : ''}
                  </span>
                  <button className="btn-ghost p-1 text-rose-600" onClick={() => delValor(v)}><TrashIcon className="w-4 h-4" /></button>
                </div>
              ))}
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
