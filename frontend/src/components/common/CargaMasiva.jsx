import { useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';
import clsx from 'clsx';
import Swal from 'sweetalert2';
import { Download, FileSpreadsheet, CircleCheck, TriangleAlert, Upload } from 'lucide-react';
import Modal from './Modal';
import { almacenesApi, importarApi } from '../../services/api/endpoints';
import { descargarPlantilla, leerArchivo } from '../../utils/cargaMasiva';
import { aviso } from '../../utils/avisos';

const num = (n) => new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(n || 0);
const TITULOS = { articulos: 'Carga masiva de artículos y existencias', existencias: 'Cargar existencias' };
const NOMBRES_NUEVOS = { categorias: 'Categorías', marcas: 'Marcas', familias: 'Familias', lineas: 'Líneas', atributos: 'Atributos', valores: 'Colores / tallas' };

function Paso({ n, titulo, activo, hecho, children }) {
  return (
    <section className={clsx('rounded-2xl border p-4', activo ? 'border-primary-600 bg-white' : 'border-slate-200 bg-slate-50')}>
      <h3 className="mb-2 flex items-center gap-2 font-bold">
        <span className={clsx('flex h-7 w-7 items-center justify-center rounded-full text-sm', hecho ? 'bg-success-600 text-white' : activo ? 'bg-primary-600 text-white' : 'bg-slate-200 text-slate-700')}>
          {hecho ? <CircleCheck className="h-4 w-4" /> : n}
        </span>
        {titulo}
      </h3>
      {children}
    </section>
  );
}
Paso.propTypes = { n: PropTypes.number, titulo: PropTypes.string, activo: PropTypes.bool, hecho: PropTypes.bool, children: PropTypes.node };

/**
 * Carga masiva en 3 pasos: 1) descargar plantilla, 2) subir y revisar (no guarda nada), 3) confirmar (todo o nada).
 * tipo: 'articulos' (carga inicial con existencias) | 'existencias' (conteo fisico o sumar)
 */
export default function CargaMasiva({ tipo, open, onClose, onDone }) {
  const [almacenes, setAlmacenes] = useState([]);
  const [archivo, setArchivo] = useState(null);
  const [filas, setFilas] = useState([]);
  const [desconocidas, setDesconocidas] = useState([]);
  const [revision, setRevision] = useState(null);
  const [trabajando, setTrabajando] = useState('');
  const [modo, setModo] = useState('reemplazar');
  const input = useRef(null);

  useEffect(() => { if (open) almacenesApi.listar().then((r) => setAlmacenes(r.data || [])).catch(() => {}); }, [open]);
  useEffect(() => { if (!open) { setArchivo(null); setFilas([]); setRevision(null); setDesconocidas([]); } }, [open]);

  const enviar = (aplicar, fs = filas) => {
    const body = { aplicar, filas: fs, ...(tipo === 'existencias' ? { modo } : {}) };
    return tipo === 'articulos' ? importarApi.articulos(body) : importarApi.existencias(body);
  };

  const revisar = async (fs = filas) => {
    setTrabajando('revisar');
    try { setRevision((await enviar(false, fs)).data); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setTrabajando(''); }
  };

  const elegir = async (file) => {
    if (!file) return;
    setArchivo(file); setRevision(null);
    try {
      const r = await leerArchivo(file, tipo);
      setFilas(r.filas); setDesconocidas(r.desconocidas);
      if (!r.filas.length) { Swal.fire('Sin renglones', 'El archivo no tiene renglones con datos.', 'warning'); return; }
      await revisar(r.filas);
    } catch (e) { setFilas([]); Swal.fire('No pude leer el archivo', e.message, 'error'); }
  };

  const aplicar = async () => {
    setTrabajando('aplicar');
    try {
      const r = (await enviar(true)).data;
      setRevision(r);
      if (r.aplicado) { aviso('Carga aplicada'); onDone?.(); }
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setTrabajando(''); }
  };

  const res = revision?.resumen;
  const sinErrores = revision && !revision.total_errores;
  const nuevos = Object.entries(revision?.nuevos || {}).filter(([, l]) => l?.length);

  return (
    <Modal open={open} onClose={onClose} size="xl" title={TITULOS[tipo]}
      footer={(
        <>
          <button type="button" className="btn-secondary" onClick={onClose}>{revision?.aplicado ? 'Listo' : 'Cancelar'}</button>
          {!revision?.aplicado && (
            <button type="button" className="btn-primary" disabled={!sinErrores || !!trabajando} onClick={aplicar}>
              {trabajando === 'aplicar' ? 'Guardando…' : 'Confirmar y guardar'}
            </button>
          )}
        </>
      )}>
      <div className="space-y-3">
        <Paso n={1} titulo="Descarga la plantilla y llénala" activo={!archivo} hecho={!!archivo}>
          <p className="mb-3 text-sm text-slate-700">
            {tipo === 'articulos'
              ? 'Un renglón por artículo (o por cada color/talla). Lo que no exista —categorías, marcas, colores, tallas— se crea solo. Si el código ya existe, se actualizan costo y precios y se suma la existencia.'
              : 'Un renglón por artículo y almacén. Puedes usar el código del artículo o su código de barras.'}
          </p>
          <button type="button" className="btn-secondary" onClick={() => descargarPlantilla(tipo, almacenes)}>
            <Download className="h-5 w-5" />Descargar plantilla de Excel
          </button>
        </Paso>

        <Paso n={2} titulo="Sube tu archivo y revisa" activo={!!archivo && !sinErrores} hecho={!!sinErrores}>
          {tipo === 'existencias' && (
            <div className="mb-3 grid gap-2 sm:grid-cols-2">
              {[['reemplazar', 'Conteo físico', 'La cantidad es lo que hay; se ajusta la diferencia.'], ['sumar', 'Sumar', 'La cantidad se agrega a lo que ya hay.']].map(([v, t, d]) => (
                <button key={v} type="button" aria-pressed={modo === v} onClick={() => { setModo(v); setRevision(null); }}
                  className={clsx('rounded-xl border p-3 text-left', modo === v ? 'border-primary-600 bg-primary-50' : 'border-slate-300 bg-white')}>
                  <span className="block font-semibold">{t}</span><span className="text-sm text-slate-600">{d}</span>
                </button>
              ))}
            </div>
          )}
          <input ref={input} type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(e) => { elegir(e.target.files?.[0]); e.target.value = ''; }} />
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" className="btn-primary" disabled={!!trabajando} onClick={() => input.current?.click()}>
              <Upload className="h-5 w-5" />{archivo ? 'Subir otro archivo' : 'Elegir archivo (.xlsx o .csv)'}
            </button>
            {archivo && <span className="flex items-center gap-1.5 text-sm text-slate-700"><FileSpreadsheet className="h-4 w-4" />{archivo.name} · {num(filas.length)} renglones</span>}
            {archivo && tipo === 'existencias' && !revision && !trabajando && <button type="button" className="btn-ghost" onClick={() => revisar()}>Revisar</button>}
          </div>
          {trabajando === 'revisar' && <p className="mt-3 text-sm text-slate-600">Revisando {num(filas.length)} renglones…</p>}
          {desconocidas.length > 0 && <p className="mt-2 text-sm text-warning-700">Columnas que no se usan: {desconocidas.join(', ')}</p>}

          {res && (
            <div className="mt-4 space-y-3">
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(tipo === 'articulos'
                  ? [['Artículos nuevos', res.articulos_nuevos], ['Se actualizan', res.articulos_actualizados], ['Variantes nuevas', res.variantes_nuevas], ['Piezas a inventario', res.existencias_piezas]]
                  : [['Renglones', res.renglones], ['Con cambio', res.con_cambio], ['Piezas que entran', res.piezas_entrada], ['Piezas que salen', res.piezas_salida]]
                ).map(([t, v]) => (
                  <div key={t} className="rounded-xl bg-slate-100 p-3"><p className="text-xs text-slate-600">{t}</p><p className="text-xl font-bold">{num(v)}</p></div>
                ))}
              </div>
              {nuevos.length > 0 && (
                <div className="rounded-xl border border-slate-200 p-3 text-sm">
                  <p className="mb-1 font-semibold">{revision.aplicado ? 'Se crearon' : 'Se van a crear'}</p>
                  {nuevos.map(([k, l]) => (
                    <p key={k} className="text-slate-700"><b>{NOMBRES_NUEVOS[k] || k}:</b> {l.slice(0, 12).join(', ')}{l.length > 12 ? ` y ${l.length - 12} más` : ''}</p>
                  ))}
                </div>
              )}
              {revision.total_errores > 0 ? (
                <div className="rounded-xl border border-danger-200 bg-danger-50 p-3">
                  <p className="mb-2 flex items-center gap-2 font-semibold text-danger-600">
                    <TriangleAlert className="h-5 w-5" />{num(revision.total_errores)} {revision.total_errores === 1 ? 'renglón con error' : 'renglones con error'}: corrígelos en tu archivo y vuelve a subirlo. No se guardó nada.
                  </p>
                  <ul className="max-h-56 space-y-1 overflow-y-auto text-sm">
                    {revision.errores.map((e, i) => <li key={i}><b className="font-mono">Renglón {e.fila}:</b> {e.mensaje}</li>)}
                  </ul>
                </div>
              ) : !revision.aplicado && (
                <p className="flex items-center gap-2 rounded-xl bg-success-50 p-3 font-semibold text-success-700"><CircleCheck className="h-5 w-5" />Todo está correcto. Revisa el resumen y confirma.</p>
              )}
            </div>
          )}
        </Paso>

        <Paso n={3} titulo="Confirma" activo={!!sinErrores && !revision?.aplicado} hecho={!!revision?.aplicado}>
          <p className="text-sm text-slate-700">
            {revision?.aplicado
              ? 'Listo: la carga quedó guardada. Las existencias aparecen en el kárdex como «Carga masiva».'
              : 'Se guarda todo o nada: si algo falla, no queda una carga a medias.'}
          </p>
        </Paso>
      </div>
    </Modal>
  );
}

CargaMasiva.propTypes = {
  tipo: PropTypes.oneOf(['articulos', 'existencias']).isRequired,
  open: PropTypes.bool,
  onClose: PropTypes.func.isRequired,
  onDone: PropTypes.func,
};
