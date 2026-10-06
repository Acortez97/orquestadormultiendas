import { useEffect, useState, useMemo } from 'react';
import Swal from 'sweetalert2';
import { FunnelIcon } from '@heroicons/react/24/outline';
import DataTable from '../../components/common/DataTable';
import Modal from '../../components/common/Modal';
import ArticuloAutocomplete from '../../components/common/ArticuloAutocomplete';
import MatrizColorTalla, { expandirMatriz } from '../../components/common/MatrizColorTalla';
import { inventarioApi, almacenesApi, marcasApi } from '../../services/api/endpoints';
import { useAuth } from '../../contexts/AuthContext';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);
const ajusteVacio = () => ({ art: null, id_articulo: '', codigo: '', descripcion: '', id_almacen: '', motivo: '', cant: {} });

// Total físico = lo que hay en piso = disponible (cantidad) + apartado (reservado).
const fisico = (r) => (Number(r.cantidad) || 0) + (Number(r.reservado) || 0);

// Etiquetas legibles para los tipos de movimiento del kardex (fallback: el valor crudo).
const KARDEX_TIPO_LABEL = {
  liberacion_apartado: 'Liberación apartado',
};

export default function InventarioPage() {
  const { hasPermiso } = useAuth();
  const [tab, setTab] = useState('existencias');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [almacenes, setAlmacenes] = useState([]);
  const [marcas, setMarcas] = useState([]);
  const [filtroAlmacen, setFiltroAlmacen] = useState('');
  const [filtroArticulo, setFiltroArticulo] = useState(null);
  const [filtroMarca, setFiltroMarca] = useState('');
  const [costoMin, setCostoMin] = useState('');
  const [costoMax, setCostoMax] = useState('');
  const [showFiltros, setShowFiltros] = useState(false);

  // Filtros del Kardex (se aplican en cliente sobre los movimientos ya cargados).
  const [filtroTipo, setFiltroTipo] = useState('');
  const [filtroColorK, setFiltroColorK] = useState('');
  const [filtroTallaK, setFiltroTallaK] = useState('');

  // Solo cuenta los filtros que viven detrás del botón "Más filtros" (marca y costo).
  const filtrosActivos =
    (filtroMarca ? 1 : 0) + (costoMin !== '' ? 1 : 0) + (costoMax !== '' ? 1 : 0);

  const limpiarFiltros = () => {
    setFiltroMarca('');
    setCostoMin('');
    setCostoMax('');
  };
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(ajusteVacio());

  const cargar = async () => {
    setLoading(true);
    try {
      const params = {};
      if (filtroAlmacen) params.id_almacen = filtroAlmacen;
      if (filtroArticulo) params.id_articulo = filtroArticulo._id;
      const res = tab === 'existencias' ? await inventarioApi.existencias(params) : await inventarioApi.kardex(params);
      setRows(res.data || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { cargar(); /* eslint-disable-next-line */ }, [tab, filtroAlmacen, filtroArticulo]);
  useEffect(() => { almacenesApi.listar().then((r) => setAlmacenes(r.data || [])); }, []);
  useEffect(() => { marcasApi.listar().then((r) => setMarcas(r.data || [])).catch(() => {}); }, []);
  // Al cambiar de artículo/almacén o de pestaña cambian los movimientos: limpia los filtros del kardex
  // para que no queden apuntando a un color/talla que ya no aparece.
  useEffect(() => { setFiltroTipo(''); setFiltroColorK(''); setFiltroTallaK(''); }, [filtroArticulo, filtroAlmacen, tab]);

  // Opciones de los filtros del Kardex, derivadas de los movimientos cargados:
  // así sólo se ofrecen los tipos/colores/tallas que de verdad aparecen.
  const kardexOpciones = useMemo(() => {
    if (tab !== 'kardex') return { tipos: [], colores: [], tallas: [] };
    const tipos = new Map(), colores = new Map(), tallas = new Map();
    for (const r of rows) {
      if (r.tipo) tipos.set(r.tipo, KARDEX_TIPO_LABEL[r.tipo] || r.tipo);
      if (r.id_color?._id) colores.set(String(r.id_color._id), r.id_color.nombre);
      if (r.id_talla?._id) tallas.set(String(r.id_talla._id), r.id_talla.nombre);
    }
    return { tipos: [...tipos], colores: [...colores], tallas: [...tallas] };
  }, [tab, rows]);

  const limpiarFiltrosKardex = () => { setFiltroTipo(''); setFiltroColorK(''); setFiltroTallaK(''); };

  // Filtro por marca y costo — se aplica en cliente sobre las existencias ya cargadas,
  // por lo que abarca todas las tiendas y bodega (según el filtro de almacén elegido).
  const rowsMostrados = tab === 'existencias'
    ? rows.filter((r) => {
        const marcaId = r.id_articulo?.id_marca?._id || r.id_articulo?.id_marca || '';
        if (filtroMarca && String(marcaId) !== filtroMarca) return false;
        const costo = Number(r.id_articulo?.costo) || 0;
        if (costoMin !== '' && costo < Number(costoMin)) return false;
        if (costoMax !== '' && costo > Number(costoMax)) return false;
        return true;
      })
    : rows.filter((r) => {
        if (filtroTipo && r.tipo !== filtroTipo) return false;
        if (filtroColorK && String(r.id_color?._id || '') !== filtroColorK) return false;
        if (filtroTallaK && String(r.id_talla?._id || '') !== filtroTallaK) return false;
        return true;
      });

  const guardarAjuste = async () => {
    if (!form.id_articulo || !form.id_almacen) {
      Swal.fire('Datos incompletos', 'Selecciona artículo y almacén.', 'warning');
      return;
    }
    // Cada celda con delta distinto de cero se convierte en un ajuste.
    const lineas = expandirMatriz(form.art, form.cant).map((c) => ({
      id_articulo: form.id_articulo,
      id_color: c.id_color,
      id_talla: c.id_talla,
      delta: c.valor,
    }));
    if (lineas.length === 0) {
      Swal.fire('Sin cambios', 'Captura al menos una cantidad distinta de cero.', 'warning');
      return;
    }
    setSaving(true);
    try {
      await inventarioApi.ajusteLote({ id_almacen: form.id_almacen, motivo: form.motivo, lineas });
      setOpen(false); cargar();
      Swal.fire({ icon: 'success', title: 'Ajuste aplicado', timer: 1200, showConfirmButton: false });
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setSaving(false); }
  };

  const colsExist = [
    {
      key: 'art',
      label: 'Artículo',
      render: (r) => `${r.id_articulo?.codigo || ''} ${r.id_articulo?.descripcion || ''}`,
      // El encabezado ordena; sin sortValue el orden usaría row['art'] (inexistente) y no haría nada.
      sortValue: (r) => `${r.id_articulo?.codigo || ''} ${r.id_articulo?.descripcion || ''}`.trim(),
    },
    {
      key: 'marca',
      label: 'Marca',
      render: (r) => r.id_articulo?.id_marca?.nombre || '—',
      sortValue: (r) => r.id_articulo?.id_marca?.nombre || '',
      exportValue: (r) => r.id_articulo?.id_marca?.nombre || '',
    },
    {
      key: 'variante',
      label: 'Variante',
      render: (r) => [r.id_color?.nombre, r.id_talla?.nombre].filter(Boolean).join(' / ') || '—',
      sortValue: (r) => [r.id_color?.nombre, r.id_talla?.nombre].filter(Boolean).join(' / '),
      exportValue: (r) => [r.id_color?.nombre, r.id_talla?.nombre].filter(Boolean).join(' / '),
    },
    { key: 'almacen', label: 'Almacén', render: (r) => r.id_almacen?.nombre, sortValue: (r) => r.id_almacen?.nombre || '' },
    {
      key: 'costo',
      label: 'Costo',
      className: 'text-right',
      render: (r) => money(r.id_articulo?.costo),
      sortValue: (r) => Number(r.id_articulo?.costo) || 0,
      exportValue: (r) => Number(r.id_articulo?.costo) || 0,
    },
    // Total físico = lo que hay en piso; de eso, Disponible se puede vender y Apartado está comprometido.
    { key: 'total', label: 'Total', render: (r) => fisico(r), sortValue: fisico, exportValue: fisico },
    { key: 'cantidad', label: 'Disponible', render: (r) => <b>{r.cantidad}</b>, sortValue: (r) => Number(r.cantidad) || 0 },
    { key: 'reservado', label: 'Apartado', sortValue: (r) => Number(r.reservado) || 0 },
  ];
  const colsKardex = [
    // Sin sortValue el encabezado ordenaría por row[key] (createdAt/art/cv/saldo no existen como tal) y no haría nada.
    { key: 'fecha', label: 'Fecha', render: (r) => new Date(r.createdAt).toLocaleString('es-MX'), sortValue: (r) => new Date(r.createdAt).getTime() || 0 },
    { key: 'tipo', label: 'Tipo', render: (r) => <span className="badge-slate">{KARDEX_TIPO_LABEL[r.tipo] || r.tipo}</span>, sortValue: (r) => KARDEX_TIPO_LABEL[r.tipo] || r.tipo || '' },
    { key: 'art', label: 'Artículo', render: (r) => r.id_articulo?.codigo, sortValue: (r) => r.id_articulo?.codigo || '' },
    { key: 'cv', label: 'Variante', render: (r) => [r.id_color?.nombre, r.id_talla?.nombre].filter(Boolean).join('/') || '—', sortValue: (r) => [r.id_color?.nombre, r.id_talla?.nombre].filter(Boolean).join('/') },
    { key: 'almacen', label: 'Almacén', render: (r) => r.id_almacen?.nombre, sortValue: (r) => r.id_almacen?.nombre || '' },
    { key: 'cantidad', label: 'Cantidad', render: (r) => <span className={r.cantidad < 0 ? 'text-rose-600' : 'text-emerald-600'}>{r.cantidad}</span>, sortValue: (r) => Number(r.cantidad) || 0 },
    { key: 'saldo', label: 'Saldo', render: (r) => r.saldo_resultante, sortValue: (r) => Number(r.saldo_resultante) || 0 },
    { key: 'motivo', label: 'Motivo' },
  ];

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Inventario</h1>
        {hasPermiso('almacen.ajustar') && <button className="btn-primary" onClick={() => { setForm(ajusteVacio()); setOpen(true); }}>Ajuste manual</button>}
      </div>
      <div className="flex flex-wrap gap-3 items-center">
        <div className="flex gap-2 border-b border-slate-200">
          {['existencias', 'kardex'].map((t) => (
            <button key={t} onClick={() => setTab(t)} className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${tab === t ? 'border-primary-600 text-primary-700' : 'border-transparent text-slate-500'}`}>
              {t === 'existencias' ? 'Existencias' : 'Kardex'}
            </button>
          ))}
        </div>
        <select className="input-base max-w-xs" value={filtroAlmacen} onChange={(e) => setFiltroAlmacen(e.target.value)}>
          <option value="">Todos los almacenes</option>
          {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
        </select>
        <div className="min-w-[16rem]">
          {filtroArticulo ? (
            <div className="input-base flex items-center justify-between gap-2">
              <span className="truncate" title={filtroArticulo.descripcion}><b>{filtroArticulo.codigo}</b> — {filtroArticulo.descripcion}</span>
              <button type="button" className="text-xs text-sky-600 shrink-0" onClick={() => setFiltroArticulo(null)}>Quitar</button>
            </div>
          ) : (
            <ArticuloAutocomplete placeholder="Filtrar por artículo…" onSelect={(art) => setFiltroArticulo(art)} />
          )}
        </div>
        {tab === 'existencias' && (
          <button
            type="button"
            className={`btn-secondary flex items-center gap-2 ${showFiltros ? 'ring-2 ring-primary-200' : ''}`}
            onClick={() => setShowFiltros((v) => !v)}
          >
            <FunnelIcon className="w-4 h-4" />
            Más filtros
            {filtrosActivos > 0 && (
              <span className="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-primary-600 text-white text-xs font-semibold">
                {filtrosActivos}
              </span>
            )}
          </button>
        )}

        {tab === 'kardex' && (
          <>
            <select className="input-base max-w-[11rem]" value={filtroTipo} onChange={(e) => setFiltroTipo(e.target.value)}>
              <option value="">Todos los tipos</option>
              {kardexOpciones.tipos.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <select className="input-base max-w-[10rem]" value={filtroColorK} onChange={(e) => setFiltroColorK(e.target.value)}>
              <option value="">Todos los colores</option>
              {kardexOpciones.colores.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <select className="input-base max-w-[9rem]" value={filtroTallaK} onChange={(e) => setFiltroTallaK(e.target.value)}>
              <option value="">Todas las tallas</option>
              {kardexOpciones.tallas.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            {(filtroTipo || filtroColorK || filtroTallaK) && (
              <button type="button" className="text-xs text-sky-600 shrink-0" onClick={limpiarFiltrosKardex}>
                Limpiar filtros
              </button>
            )}
          </>
        )}
      </div>

      {tab === 'existencias' && showFiltros && (
        <div className="card p-4">
          <div className="flex flex-wrap gap-3 items-center">
            <select className="input-base max-w-[12rem]" value={filtroMarca} onChange={(e) => setFiltroMarca(e.target.value)}>
              <option value="">Todas las marcas</option>
              {marcas.map((m) => <option key={m._id} value={m._id}>{m.nombre}</option>)}
            </select>
            <input
              type="number"
              min="0"
              className="input-base w-28"
              placeholder="Costo mín."
              value={costoMin}
              onChange={(e) => setCostoMin(e.target.value)}
            />
            <input
              type="number"
              min="0"
              className="input-base w-28"
              placeholder="Costo máx."
              value={costoMax}
              onChange={(e) => setCostoMax(e.target.value)}
            />
            {filtrosActivos > 0 && (
              <button type="button" className="text-xs text-sky-600 shrink-0" onClick={limpiarFiltros}>
                Limpiar filtros
              </button>
            )}
          </div>
        </div>
      )}
      <DataTable columns={tab === 'existencias' ? colsExist : colsKardex} data={rowsMostrados} loading={loading} empty="Sin movimientos" exportName={tab === 'existencias' ? 'existencias' : 'kardex'} exportTitle={tab === 'existencias' ? 'Existencias' : 'Kardex'} />

      <Modal open={open} onClose={() => setOpen(false)} title="Ajuste de inventario" size="xl"
        footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancelar</button><button className="btn-primary" disabled={saving} onClick={guardarAjuste}>{saving ? 'Aplicando…' : 'Aplicar'}</button></>}>
        <div className="space-y-4">
          <div className="grid md:grid-cols-2 gap-3">
            <div className="text-sm">
              <span className="block text-xs font-medium text-slate-500 mb-1">Artículo</span>
              {form.art ? (
                <div className="input-base flex items-center justify-between gap-2">
                  <span className="truncate" title={form.descripcion}><b>{form.codigo}</b> — {form.descripcion}</span>
                  <button type="button" className="text-xs text-sky-600 shrink-0" onClick={() => setForm({ ...form, art: null, id_articulo: '', codigo: '', descripcion: '', cant: {} })}>Cambiar</button>
                </div>
              ) : (
                <ArticuloAutocomplete
                  placeholder="Buscar artículo…"
                  onSelect={(art) => setForm({ ...form, art, id_articulo: art._id, codigo: art.codigo, descripcion: art.descripcion, cant: {} })}
                />
              )}
            </div>
            <label className="text-sm">
              <span className="block text-xs font-medium text-slate-500 mb-1">Almacén</span>
              <select className="input-base" value={form.id_almacen} onChange={(e) => setForm({ ...form, id_almacen: e.target.value })}>
                <option value="">—</option>
                {almacenes.map((a) => <option key={a._id} value={a._id}>{a.nombre}</option>)}
              </select>
            </label>
          </div>

          {form.art && (
            <div>
              <p className="text-xs text-slate-500 mb-1">Captura el ajuste por celda (positivo entra, negativo sale).</p>
              <MatrizColorTalla
                art={form.art}
                cant={form.cant}
                allowNegative
                onCell={(key, value) => setForm((f) => ({ ...f, cant: { ...f.cant, [key]: value } }))}
              />
            </div>
          )}

          <label className="text-sm block">
            <span className="block text-xs font-medium text-slate-500 mb-1">Motivo</span>
            <input className="input-base" value={form.motivo} onChange={(e) => setForm({ ...form, motivo: e.target.value })} />
          </label>
        </div>
      </Modal>
    </div>
  );
}
