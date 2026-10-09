import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import PropTypes from 'prop-types';
import clsx from 'clsx';
import { Search as MagnifyingGlassIcon } from 'lucide-react';
import { useAuth } from '../../contexts/AuthContext';
import { pantallasVisibles } from './navConfig';

const normal = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

/** Buscador universal (Ctrl+K): ir a cualquier pantalla con el teclado */
export default function BuscadorGlobal({ abierto, onCerrar }) {
  const { hasPermiso } = useAuth();
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const input = useRef(null);
  const todas = useMemo(() => pantallasVisibles(hasPermiso), [hasPermiso]);

  const filtro = normal(q.trim());
  const resultados = todas.filter((p) => !filtro || normal(`${p.label} ${p.grupo || ''}`).includes(filtro)).slice(0, 12);

  useEffect(() => { if (abierto) { setQ(''); setSel(0); setTimeout(() => input.current?.focus(), 0); } }, [abierto]);
  useEffect(() => { setSel(0); }, [q]);

  if (!abierto) return null;
  const ir = (p) => { onCerrar(); navigate(p.path); };
  const tecla = (e) => {
    if (e.key === 'Escape') onCerrar();
    else if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(s + 1, resultados.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(s - 1, 0)); }
    else if (e.key === 'Enter' && resultados[sel]) ir(resultados[sel]);
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 p-4 pt-[12vh] animate-fade-in" onMouseDown={onCerrar}>
      <div role="dialog" aria-modal="true" aria-label="Buscar pantalla" onMouseDown={(e) => e.stopPropagation()}
        className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
        <label className="flex items-center gap-3 border-b border-slate-200 px-4">
          <MagnifyingGlassIcon className="h-5 w-5 text-slate-500" />
          <span className="sr-only">Buscar</span>
          <input ref={input} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={tecla}
            placeholder="¿A dónde quieres ir? (ventas, corte, existencias…)"
            className="min-h-14 flex-1 bg-transparent text-base outline-none" />
          <kbd className="hidden sm:inline rounded-md border border-slate-300 px-1.5 font-mono text-xs text-slate-600">Esc</kbd>
        </label>
        <ul className="max-h-[50vh] overflow-y-auto p-2">
          {resultados.map((p, i) => {
            const Icon = p.icon;
            return (
              <li key={p.path}>
                <button type="button" onMouseEnter={() => setSel(i)} onClick={() => ir(p)}
                  className={clsx('flex w-full min-h-11 items-center gap-3 rounded-[10px] px-3 text-left', i === sel ? 'bg-slate-100' : '')}>
                  <Icon className="h-5 w-5 text-slate-600" />
                  <span className="flex-1 font-medium">{p.label}</span>
                  <span className="text-xs text-slate-600">{p.grupo || ''}</span>
                </button>
              </li>
            );
          })}
          {!resultados.length && <li className="px-3 py-6 text-center text-slate-600">Sin resultados para «{q}».</li>}
        </ul>
      </div>
    </div>
  );
}
BuscadorGlobal.propTypes = { abierto: PropTypes.bool, onCerrar: PropTypes.func.isRequired };
