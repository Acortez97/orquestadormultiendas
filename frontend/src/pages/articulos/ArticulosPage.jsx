import { useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import { PlusIcon, PhotoIcon, XMarkIcon, QrCodeIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import { articulosApi, categoriasApi, atributosApi, familiasApi, lineasApi, cortesCatalogoApi, marcasApi } from '../../services/api/endpoints';
import { printEtiquetas } from '../../utils/labels';
import { aviso } from '../../utils/avisos';
import CargaMasiva from '../../components/common/CargaMasiva';
import { useAuth } from '../../contexts/AuthContext';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const vacio = {
  codigo: '', sku: '', descripcion: '', ean: '', id_categoria: '', id_familia: '', id_linea: '', id_corte: '', id_marca: '', costo: 0,
  precios: { lista1: 0, lista2: 0, lista3: 0, lista4: 0, lista5: 0 },
  es_oferta: false, precio_oferta: 0, colores: [], tallas: [], ficha: {}, fotos: [],
  es_kit: false, unidad: 'pieza', contenido_paquete: 1, componentes: [],
};
const UNIDADES = ['pieza', 'paquete', 'set', 'par', 'caja', 'kg', 'metro'];
// ¿El valor de un atributo trae un color hex en `extra`? (para pintar el swatch)
const esHex = (v) => typeof v === 'string' && /^#([0-9a-f]{3,8})$/i.test(v.trim());

const leerArchivoDataUrl = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

export default function ArticulosPage() {
  const { hasPermiso } = useAuth();
  const [carga, setCarga] = useState(false);   // carga masiva desde Excel
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(vacio);
  const [editId, setEditId] = useState(null);
  const [cat, setCat] = useState({ categorias: [], familias: [], lineas: [], cortes: [], marcas: [] });
  // Ejes (valores de variante) y ficha derivados de la categoría seleccionada.
  const [ejeInfo, setEjeInfo] = useState({ eje1: null, eje2: null, ficha: [] });
  const [search, setSearch] = useState('');
  const [subiendoFoto, setSubiendoFoto] = useState(false);
  // Etiquetas (F4): { art, data:{articulo,variantes}, cant:{`e1_e2`:n}, precioKey }
  const [etq, setEtq] = useState(null);

  const cargar = async () => {
    setLoading(true);
    try { const res = await articulosApi.listar(search ? { search } : {}); setRows(res.data || []); } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); /* eslint-disable-next-line */ }, []);
  useEffect(() => {
    Promise.all([
      categoriasApi.listar(), familiasApi.listar(),
      lineasApi.listar(), cortesCatalogoApi.listar(), marcasApi.listar(),
    ]).then(([cats, f, l, col, m]) =>
      setCat({
        categorias: cats.data || [], familias: f.data || [],
        lineas: l.data || [], cortes: col.data || [], marcas: m.data || [],
      }));
  }, []);

  // Al cambiar la categoría, carga los valores de sus ejes de variante y su ficha.
  useEffect(() => {
    const c = cat.categorias.find((x) => x._id === form.id_categoria);
    if (!c) { setEjeInfo({ eje1: null, eje2: null, ficha: [] }); return; }
    let cancel = false;
    const cargarVals = (atr) =>
      atr?._id ? atributosApi.valores(atr._id).then((r) => r.data || []).catch(() => []) : Promise.resolve([]);
    Promise.all([cargarVals(c.id_atributo_eje1), cargarVals(c.id_atributo_eje2)]).then(([v1, v2]) => {
      if (cancel) return;
      setEjeInfo({
        eje1: c.id_atributo_eje1 ? { nombre: c.id_atributo_eje1.nombre, valores: v1 } : null,
        eje2: c.id_atributo_eje2 ? { nombre: c.id_atributo_eje2.nombre, valores: v2 } : null,
        ficha: c.ficha_schema || [],
      });
    });
    return () => { cancel = true; };
  }, [form.id_categoria, cat.categorias]);

  const abrirNuevo = () => { setForm(vacio); setEditId(null); setOpen(true); };
  const abrirEdit = (r) => {
    setForm({
      ...vacio, ...r,
      sku: r.sku || '',
      id_categoria: r.id_categoria?._id || r.id_categoria || '',
      id_familia: r.id_familia?._id || r.id_familia || '',
      id_linea: r.id_linea?._id || r.id_linea || '',
      id_corte: r.id_corte?._id || r.id_corte || '',
      id_marca: r.id_marca?._id || r.id_marca || '',
      precios: { ...vacio.precios, ...(r.precios || {}) },
      colores: (r.colores || []).map((c) => c._id || c),
      tallas: (r.tallas || []).map((t) => t._id || t),
      ficha: r.ficha && !Array.isArray(r.ficha) ? r.ficha : {},
      es_kit: !!r.es_kit,
      unidad: r.unidad || 'pieza',
      contenido_paquete: r.contenido_paquete || 1,
      componentes: (r.componentes || []).map((c) => ({ id_componente: c._id || c.id_componente, codigo: c.codigo, descripcion: c.descripcion, cantidad: c.cantidad || 1 })),
      fotos: r.fotos || [],
    });
    setEditId(r._id); setOpen(true);
  };

  const toggle = (campo, id) => setForm((f) => ({
    ...f, [campo]: f[campo].includes(id) ? f[campo].filter((x) => x !== id) : [...f[campo], id],
  }));

  // Cambiar de categoría redefine los ejes y la ficha → limpia las variantes/ficha previas.
  const cambiarCategoria = (id) => setForm((f) => ({ ...f, id_categoria: id, colores: [], tallas: [], ficha: {} }));
  const setFicha = (k, v) => setForm((f) => ({ ...f, ficha: { ...f.ficha, [k]: v } }));

  // ---- Kit (F5): componentes ----
  const addComponente = (art) => setForm((f) => {
    if (art._id === editId || f.componentes.some((c) => c.id_componente === art._id)) return f;
    return { ...f, componentes: [...f.componentes, { id_componente: art._id, codigo: art.codigo, descripcion: art.descripcion, cantidad: 1 }] };
  });
  const setComponente = (i, patch) => setForm((f) => ({ ...f, componentes: f.componentes.map((c, idx) => (idx === i ? { ...c, ...patch } : c)) }));
  const removeComponente = (i) => setForm((f) => ({ ...f, componentes: f.componentes.filter((_, idx) => idx !== i) }));

  // ---- Etiquetas (F4) ----
  const abrirEtiquetas = async (row) => {
    try {
      const res = await articulosApi.variantes(row._id);
      const data = res.data;
      const cant = {};
      (data.variantes || []).forEach((v) => { cant[`${v.id_eje1}_${v.id_eje2}`] = 1; });
      setEtq({ art: row, data, cant, precioKey: data.articulo?.es_oferta ? 'oferta' : 'lista1' });
    } catch (e) { Swal.fire('Error', e.message || 'No se pudieron cargar las variantes', 'error'); }
  };
  const setEtqCant = (key, val) => setEtq((e) => ({ ...e, cant: { ...e.cant, [key]: val } }));
  const precioEtiqueta = () => {
    if (!etq) return '';
    const a = etq.data.articulo || {};
    if (etq.precioKey === 'ninguno') return '';
    if (etq.precioKey === 'oferta') return a.precio_oferta;
    return a.precios?.[etq.precioKey] ?? '';
  };
  const imprimirEtiquetas = () => {
    const a = etq.data.articulo || {};
    const precio = precioEtiqueta();
    const labels = [];
    for (const v of etq.data.variantes || []) {
      const n = Math.max(0, Math.floor(Number(etq.cant[`${v.id_eje1}_${v.id_eje2}`]) || 0));
      for (let k = 0; k < n; k += 1) {
        labels.push({ descripcion: a.descripcion, variante: v.nombre || undefined, precio: precio === '' ? undefined : precio, sku: v.sku, codigo: v.codigo_barras });
      }
    }
    if (labels.length === 0) { Swal.fire('Sin etiquetas', 'Indica al menos una cantidad.', 'warning'); return; }
    printEtiquetas(labels);
    setEtq(null);
  };

  const subirFotos = async (e) => {
    const files = Array.from(e.target.files || []);
    e.target.value = ''; // permite volver a elegir el mismo archivo
    if (!files.length) return;
    setSubiendoFoto(true);
    try {
      for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        if (file.size > 8 * 1024 * 1024) {
          Swal.fire('Imagen muy grande', `${file.name} supera 8 MB`, 'warning');
          continue;
        }
        const dataUrl = await leerArchivoDataUrl(file);
        const res = await articulosApi.subirFoto(dataUrl);
        const foto = { ...res.data, nombre: file.name };
        setForm((f) => ({ ...f, fotos: [...(f.fotos || []), foto] }));
      }
    } catch (err) {
      Swal.fire('Error', err.message || 'No se pudo subir la foto', 'error');
    } finally {
      setSubiendoFoto(false);
    }
  };

  const verFoto = (url, nombre) => {
    if (!url) return;
    Swal.fire({
      imageUrl: url,
      imageAlt: nombre || 'Foto del artículo',
      title: nombre || undefined,
      showConfirmButton: false,
      showCloseButton: true,
      width: 'auto',
      padding: '1rem',
    });
  };

  const quitarFoto = async (key) => {
    const { isConfirmed } = await Swal.fire({
      title: '¿Quitar esta foto?',
      text: 'La foto se eliminará del artículo al guardar.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e11d48',
      confirmButtonText: 'Quitar',
      cancelButtonText: 'Cancelar',
    });
    if (!isConfirmed) return;
    setForm((f) => ({ ...f, fotos: (f.fotos || []).filter((x) => x.key !== key) }));
  };

  const guardar = async () => {
    try {
      const payload = {
        ...form,
        // null (no undefined): asi el API sabe que se QUITO el catalogo; undefined omite la clave y conserva el anterior
        id_categoria: form.id_categoria || null,
        id_familia: form.id_familia || null,
        id_linea: form.id_linea || null,
        id_corte: form.id_corte || null,
        id_marca: form.id_marca || null,
      };
      if (editId) await articulosApi.actualizar(editId, payload); else await articulosApi.crear(payload);
      setOpen(false); cargar();
      aviso('Guardado');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };
  const eliminar = async (r) => {
    const c = await Swal.fire({ title: `¿Desactivar ${r.codigo}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e11d48' });
    if (!c.isConfirmed) return;
    try { await articulosApi.eliminar(r._id); cargar(); } catch (e) { Swal.fire('Error', e.message, 'error'); }
  };

  const columns = [
    {
      key: 'foto',
      label: '',
      render: (r) =>
        r.fotos?.[0]?.url ? (
          <img
            src={r.fotos[0].url}
            alt={r.codigo}
            title="Ver más grande"
            onClick={() => verFoto(r.fotos[0].url, r.codigo)}
            className="w-10 h-10 rounded-lg object-cover border border-slate-200 cursor-zoom-in"
          />
        ) : (
          <div className="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center">
            <PhotoIcon className="w-5 h-5 text-slate-300" />
          </div>
        ),
    },
    { key: 'codigo', label: 'Código' },
    { key: 'sku', label: 'SKU', render: (r) => r.sku || '—' },
    { key: 'descripcion', label: 'Descripción' },
    { key: 'categoria', label: 'Categoría', render: (r) => r.id_categoria?.nombre || '—' },
    { key: 'familia', label: 'Familia', render: (r) => r.id_familia?.nombre || '—' },
    { key: 'marca', label: 'Marca', render: (r) => r.id_marca?.nombre || '—' },
    { key: 'l1', label: 'L1', render: (r) => money(r.precios?.lista1) },
    { key: 'l5', label: 'L5', render: (r) => money(r.precios?.lista5) },
    { key: 'oferta', label: 'Oferta', render: (r) => r.es_oferta ? <span className="badge-warning">{money(r.precio_oferta)}</span> : '—' },
    { key: 'acc', label: '', render: (r) => (
      <div className="flex gap-2">
        <button className="btn-ghost text-sm inline-flex items-center gap-1" title="Imprimir etiquetas" onClick={() => abrirEtiquetas(r)}>
          <QrCodeIcon className="w-4 h-4" /> Etiquetas
        </button>
        <button className="btn-ghost text-sm" onClick={() => abrirEdit(r)}>Editar</button>
        <button className="btn-ghost text-sm text-rose-600" onClick={() => eliminar(r)}>Eliminar</button>
      </div>
    ) },
  ];

  const setPrecio = (k, v) => setForm((f) => ({ ...f, precios: { ...f.precios, [k]: Number(v) } }));

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Artículos</h1>
        <div className="flex flex-wrap gap-2">
          {hasPermiso('catalogos.crear') && <button className="btn-secondary" onClick={() => setCarga(true)}>Carga masiva (Excel)</button>}
          <button className="btn-primary" onClick={abrirNuevo}><PlusIcon className="w-4 h-4" /> Nuevo</button>
        </div>
      </div>
      <CargaMasiva tipo="articulos" open={carga} onClose={() => setCarga(false)} onDone={cargar} />
      <div className="flex gap-2">
        <input className="input-base max-w-xs" placeholder="Buscar código / descripción / EAN" value={search}
          onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && cargar()} />
        <button className="btn-secondary" onClick={cargar}>Buscar</button>
      </div>
      <DataTable columns={columns} data={rows} loading={loading} empty="Sin artículos" exportName="articulos" exportTitle="Artículos" />

      <Modal open={open} onClose={() => setOpen(false)} size="xl" title={editId ? 'Editar artículo' : 'Nuevo artículo'}
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" onClick={guardar}>Guardar</button></>}>
        <div className="grid grid-cols-3 gap-3">
          <label className="text-sm">Categoría
            <select className="input-base" value={form.id_categoria || ''} onChange={(e) => cambiarCategoria(e.target.value)}>
              <option value="">— Sin categoría —</option>
              {cat.categorias.map((c) => <option key={c._id} value={c._id}>{c.nombre}</option>)}
            </select>
          </label>
          <label className="text-sm">Código <span className="text-slate-400">(opcional)</span>
            <input className="input-base" placeholder="Vacío = usa el SKU" value={form.codigo} onChange={(e) => setForm({ ...form, codigo: e.target.value })} /></label>
          <label className="text-sm">SKU <span className="text-slate-400">(interno)</span>
            <input className="input-base bg-slate-100 text-slate-500" value={form.sku || ''} readOnly placeholder="Se genera solo" title="El SKU interno se genera automáticamente por la categoría" /></label>
          <label className="text-sm col-span-2">Descripción<input className="input-base" value={form.descripcion} onChange={(e) => setForm({ ...form, descripcion: e.target.value })} /></label>
          <label className="text-sm">EAN <span className="text-slate-400">(fábrica)</span><input className="input-base" value={form.ean || ''} onChange={(e) => setForm({ ...form, ean: e.target.value })} /></label>
          <label className="text-sm">Familia
            <select className="input-base" value={form.id_familia || ''} onChange={(e) => setForm({ ...form, id_familia: e.target.value })}>
              <option value="">—</option>
              {cat.familias.map((f) => <option key={f._id} value={f._id}>{f.nombre}</option>)}
            </select>
          </label>
          <label className="text-sm">Línea
            <select className="input-base" value={form.id_linea || ''} onChange={(e) => setForm({ ...form, id_linea: e.target.value })}>
              <option value="">—</option>
              {cat.lineas.map((l) => <option key={l._id} value={l._id}>{l.nombre}</option>)}
            </select>
          </label>
          <label className="text-sm">Corte
            <select className="input-base" value={form.id_corte || ''} onChange={(e) => setForm({ ...form, id_corte: e.target.value })}>
              <option value="">—</option>
              {cat.cortes.map((c) => <option key={c._id} value={c._id}>{c.nombre}</option>)}
            </select>
          </label>
          <label className="text-sm">Marca
            <select className="input-base" value={form.id_marca || ''} onChange={(e) => setForm({ ...form, id_marca: e.target.value })}>
              <option value="">—</option>
              {cat.marcas.map((m) => <option key={m._id} value={m._id}>{m.nombre}</option>)}
            </select>
          </label>
          <label className="text-sm">Costo<input type="number" className="input-base" value={form.costo} onChange={(e) => setForm({ ...form, costo: Number(e.target.value) })} /></label>
        </div>

        <div className="mt-4 flex items-center gap-3">
          <span className="text-xs font-medium text-slate-500 shrink-0">Foto</span>
          {(form.fotos || []).map((f) => (
            <div key={f.key} className="relative group shrink-0">
              <img
                src={f.url}
                alt={f.nombre || 'foto'}
                title="Ver más grande"
                onClick={() => verFoto(f.url, f.nombre)}
                className="w-14 h-14 rounded-lg object-cover border border-slate-200 cursor-zoom-in"
              />
              <button
                type="button"
                onClick={() => quitarFoto(f.key)}
                className="absolute -top-1.5 -right-1.5 bg-rose-600 text-white rounded-full p-0.5 shadow hover:bg-rose-700"
                title="Quitar foto"
              >
                <XMarkIcon className="w-3 h-3" />
              </button>
            </div>
          ))}
          <label className={`w-14 h-14 shrink-0 rounded-lg border border-dashed border-slate-300 flex items-center justify-center cursor-pointer text-slate-400 hover:border-primary-400 hover:text-primary-500 ${subiendoFoto ? 'opacity-50 pointer-events-none' : ''}`} title="Agregar foto">
            {subiendoFoto ? <span className="text-[10px]">…</span> : <PlusIcon className="w-5 h-5" />}
            <input type="file" accept="image/*" multiple className="hidden" onChange={subirFotos} disabled={subiendoFoto} />
          </label>
        </div>

        <div className={`mt-4 ${form.es_oferta ? 'opacity-50' : ''}`}>
          <p className="text-sm font-semibold text-slate-700 mb-1">Listas de precio (incluyen IVA) — L1 más cara (1 pza) … L5 más barata (24 pzas)</p>
          <div className="grid grid-cols-5 gap-2">
            {[1, 2, 3, 4, 5].map((n) => (
              <label key={n} className="text-xs text-slate-500">Lista {n}
                <input type="number" disabled={form.es_oferta} className="input-base disabled:bg-slate-100 disabled:cursor-not-allowed" value={form.precios[`lista${n}`]} onChange={(e) => setPrecio(`lista${n}`, e.target.value)} />
              </label>
            ))}
          </div>
        </div>

        <div className="mt-4">
          <div className="flex flex-wrap gap-4 items-center">
            <label className="text-sm flex items-center gap-2"><input type="checkbox" checked={form.es_oferta} onChange={(e) => setForm({ ...form, es_oferta: e.target.checked })} /> En oferta</label>
            {form.es_oferta && (
              <label className="text-sm">Precio oferta<input type="number" className="input-base w-32" value={form.precio_oferta} onChange={(e) => setForm({ ...form, precio_oferta: Number(e.target.value) })} /></label>
            )}
          </div>
          {form.es_oferta && (
            <p className="mt-2 text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              En oferta el artículo ignora las 5 listas y se vende siempre al <b>precio de oferta</b> (sin descuento por cantidad).
              Las prendas en oferta no cuentan para subir de nivel de lista en la venta. <b>Sí generan comisión</b> al vendedor.
            </p>
          )}
        </div>

        {/* Variantes: los ejes los define la categoría (Color×Talla, Color×Número, sin ejes…) */}
        <div className="mt-4">
          {!form.id_categoria ? (
            <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              Selecciona una categoría para definir las variantes (ejes) del producto.
            </p>
          ) : !ejeInfo.eje1 && !ejeInfo.eje2 ? (
            <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              Esta categoría no maneja variantes: el producto se maneja como pieza única.
            </p>
          ) : (
            <div className="grid grid-cols-2 gap-4">
              {ejeInfo.eje1 && (
                <div>
                  <p className="text-sm font-semibold text-slate-700 mb-1">{ejeInfo.eje1.nombre}</p>
                  <div className="flex flex-wrap gap-2">
                    {ejeInfo.eje1.valores.map((v) => (
                      <button key={v._id} type="button" onClick={() => toggle('colores', v._id)}
                        className={`px-2 py-1 rounded-lg text-xs border inline-flex items-center gap-1.5 ${form.colores.includes(v._id) ? 'bg-primary-50 border-primary-400 text-primary-700' : 'border-slate-200 text-slate-600'}`}>
                        {esHex(v.extra) && <span className="w-3 h-3 rounded-full border border-slate-300" style={{ background: v.extra }} />}
                        {v.nombre}
                      </button>
                    ))}
                    {ejeInfo.eje1.valores.length === 0 && <span className="text-xs text-slate-400">Sin valores en este atributo</span>}
                  </div>
                </div>
              )}
              {ejeInfo.eje2 && (
                <div>
                  <p className="text-sm font-semibold text-slate-700 mb-1">{ejeInfo.eje2.nombre}</p>
                  <div className="flex flex-wrap gap-2">
                    {ejeInfo.eje2.valores.map((v) => (
                      <button key={v._id} type="button" onClick={() => toggle('tallas', v._id)}
                        className={`px-2 py-1 rounded-lg text-xs border inline-flex items-center gap-1.5 ${form.tallas.includes(v._id) ? 'bg-primary-50 border-primary-400 text-primary-700' : 'border-slate-200 text-slate-600'}`}>
                        {esHex(v.extra) && <span className="w-3 h-3 rounded-full border border-slate-300" style={{ background: v.extra }} />}
                        {v.nombre}
                      </button>
                    ))}
                    {ejeInfo.eje2.valores.length === 0 && <span className="text-xs text-slate-400">Sin valores en este atributo</span>}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Ficha por categoría: campos propios definidos en ficha_schema */}
        {ejeInfo.ficha?.length > 0 && (
          <div className="mt-4">
            <p className="text-sm font-semibold text-slate-700 mb-1">Ficha ({cat.categorias.find((c) => c._id === form.id_categoria)?.nombre})</p>
            <div className="grid grid-cols-3 gap-3">
              {ejeInfo.ficha.map((campo) => (
                <label key={campo.key} className="text-xs text-slate-500">{campo.label || campo.key}
                  {campo.tipo === 'booleano' ? (
                    <input type="checkbox" className="ml-2 align-middle" checked={!!form.ficha?.[campo.key]} onChange={(e) => setFicha(campo.key, e.target.checked)} />
                  ) : (
                    <input
                      type={campo.tipo === 'numero' ? 'number' : campo.tipo === 'fecha' ? 'date' : 'text'}
                      className="input-base"
                      value={form.ficha?.[campo.key] ?? ''}
                      onChange={(e) => setFicha(campo.key, campo.tipo === 'numero' ? Number(e.target.value) : e.target.value)}
                    />
                  )}
                </label>
              ))}
            </div>
          </div>
        )}

        {/* Unidad / paquete / kit (F5) */}
        <div className="mt-4 border-t border-slate-100 pt-3">
          <div className="grid grid-cols-3 gap-3">
            <label className="text-sm">Unidad
              <select className="input-base" value={form.unidad} onChange={(e) => setForm({ ...form, unidad: e.target.value })}>
                {UNIDADES.map((u) => <option key={u} value={u}>{u}</option>)}
              </select>
            </label>
            <label className="text-sm">Contenido del paquete <span className="text-slate-400">(piezas)</span>
              <input type="number" min="1" className="input-base" value={form.contenido_paquete}
                onChange={(e) => setForm({ ...form, contenido_paquete: Math.max(1, Number(e.target.value) || 1) })} />
            </label>
            <label className="text-sm flex items-center gap-2 mt-6">
              <input type="checkbox" checked={form.es_kit} onChange={(e) => setForm({ ...form, es_kit: e.target.checked })} />
              Es kit (producto compuesto)
            </label>
          </div>

          {form.es_kit && (
            <div className="mt-3">
              <p className="text-sm font-semibold text-slate-700 mb-1">Componentes del kit</p>
              <p className="text-xs text-slate-400 mb-2">Al vender el kit se descuentan del inventario sus componentes (cantidad × pieza).</p>
              <ArticuloAutocomplete placeholder="Agregar componente…" onSelect={addComponente} />
              <div className="mt-2 space-y-2">
                {form.componentes.length === 0 && <p className="text-xs text-slate-400">Sin componentes.</p>}
                {form.componentes.map((c, i) => (
                  <div key={c.id_componente} className="flex items-center gap-2">
                    <span className="flex-1 text-sm text-slate-700 truncate"><b>{c.codigo}</b> — {c.descripcion}</span>
                    <input type="number" min="0" step="0.01" className="input-base w-24 text-center" value={c.cantidad}
                      onChange={(e) => setComponente(i, { cantidad: Number(e.target.value) })} />
                    <button type="button" className="btn-ghost p-1 text-rose-600" onClick={() => removeComponente(i)}><XMarkIcon className="w-4 h-4" /></button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </Modal>

      {/* Etiquetas con código de barras (F4) */}
      <Modal open={!!etq} onClose={() => setEtq(null)} size="lg"
        title={etq ? `Etiquetas · ${etq.data.articulo?.sku || ''}` : 'Etiquetas'}
        footer={<><button className="btn-secondary" onClick={() => setEtq(null)}>Cancelar</button><button className="btn-primary" onClick={imprimirEtiquetas}>Imprimir</button></>}>
        {etq && (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-sm text-slate-700">{etq.data.articulo?.descripcion}</span>
              <label className="text-xs text-slate-500 ml-auto">Precio en etiqueta
                <select className="input-base !py-1 ml-2 inline-block w-auto" value={etq.precioKey} onChange={(e) => setEtq({ ...etq, precioKey: e.target.value })}>
                  {etq.data.articulo?.es_oferta && <option value="oferta">Oferta</option>}
                  <option value="lista1">Lista 1</option>
                  <option value="lista5">Lista 5</option>
                  <option value="ninguno">Sin precio</option>
                </select>
              </label>
            </div>
            <div className="max-h-80 overflow-y-auto border border-slate-200 rounded-lg divide-y">
              {etq.data.variantes.map((v) => {
                const key = `${v.id_eje1}_${v.id_eje2}`;
                return (
                  <div key={key} className="flex items-center gap-2 px-3 py-1.5 text-sm">
                    <span className="flex-1 text-slate-700">{v.nombre || 'Único'} <span className="text-slate-400">· {v.sku}</span></span>
                    <input type="number" min="0" className="input-base w-20 text-center !py-1" value={etq.cant[key] ?? ''}
                      onChange={(e) => setEtqCant(key, e.target.value)} />
                  </div>
                );
              })}
            </div>
            <p className="text-xs text-slate-400">Se imprime una etiqueta por cada pieza indicada. El código de barras codifica el SKU (Code128).</p>
          </div>
        )}
      </Modal>
    </div>
  );
}
