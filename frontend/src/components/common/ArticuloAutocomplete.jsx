import { useState, useEffect, useRef } from 'react';
import PropTypes from 'prop-types';
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { articulosApi } from '../../services/api/endpoints';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);

/**
 * Autocomplete de artículos: el usuario escribe y se ofrecen coincidencias por
 * código o nombre (el backend busca por código, EAN y descripción). Muestra
 * "código — nombre". Al elegir, llama onSelect(articulo) con el documento completo
 * (incluye colores y tallas poblados) y limpia el input.
 */
export default function ArticuloAutocomplete({ onSelect, placeholder = 'Buscar artículo por código o nombre…', autoFocus = false }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const [loading, setLoading] = useState(false);
  const boxRef = useRef(null);
  const timer = useRef(null);

  useEffect(() => {
    if (!q.trim()) { setResults([]); setOpen(false); return; }
    clearTimeout(timer.current);
    setLoading(true);
    timer.current = setTimeout(async () => {
      try {
        const res = await articulosApi.buscar(q.trim());
        setResults(res.data || []);
        setHighlight(0);
        setOpen(true);
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 250);
    return () => clearTimeout(timer.current);
  }, [q]);

  useEffect(() => {
    const handler = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const choose = (art) => {
    if (!art) return;
    onSelect(art);
    setQ(''); setResults([]); setOpen(false);
  };

  const onKey = (e) => {
    if (!open || results.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setHighlight((h) => Math.min(h + 1, results.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHighlight((h) => Math.max(h - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(results[highlight]); }
    else if (e.key === 'Escape') setOpen(false);
  };

  return (
    <div className="relative" ref={boxRef}>
      <div className="relative">
        <input
          className="input-base pl-9"
          placeholder={placeholder}
          value={q}
          autoFocus={autoFocus}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={onKey}
          onFocus={() => results.length && setOpen(true)}
        />
        <MagnifyingGlassIcon className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
      </div>

      {open && (
        <div className="absolute z-30 mt-1 w-full border border-slate-200 rounded-lg bg-white shadow-lg divide-y max-h-64 overflow-y-auto">
          {results.length > 0 ? (
            results.map((a, i) => (
              <button
                key={a._id}
                type="button"
                className={`w-full text-left px-3 py-2 text-sm flex justify-between gap-3 ${i === highlight ? 'bg-primary-50' : 'hover:bg-slate-50'}`}
                onMouseEnter={() => setHighlight(i)}
                onClick={() => choose(a)}
              >
                <span><b className="text-slate-800">{a.codigo}</b> — {a.descripcion}</span>
                <span className="text-slate-400 whitespace-nowrap">{a.es_oferta ? `Oferta ${money(a.precio_oferta)}` : money(a.precios?.lista1)}</span>
              </button>
            ))
          ) : (
            <div className="px-3 py-2 text-sm text-slate-400">{loading ? 'Buscando…' : 'Sin resultados'}</div>
          )}
        </div>
      )}
    </div>
  );
}

ArticuloAutocomplete.propTypes = {
  onSelect: PropTypes.func.isRequired,
  placeholder: PropTypes.string,
  autoFocus: PropTypes.bool,
};
