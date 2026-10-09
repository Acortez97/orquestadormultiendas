import PropTypes from 'prop-types';
import clsx from 'clsx';

// Colores sugeridos para una tienda: todos con buen contraste para texto blanco encima
export const COLORES_TIENDA = ['#1F4FD1', '#0E7C66', '#7A3E9D', '#B4472A', '#9A3412', '#1E3A8A', '#047857', '#17181C'];

/** Luminancia relativa (WCAG) de un #RRGGBB */
function luminancia(hex) {
  const v = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((s) => (s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4));
  return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
}
/** true si el texto blanco se lee bien sobre el color (contraste >= 4.5:1, la misma regla que el servidor) */
export const colorLegible = (hex) => /^#[0-9a-f]{6}$/i.test(hex || '') && 1.05 / (luminancia(hex) + 0.05) >= 4.5;

/** Elegir el color de la tienda: sugeridos, uno propio o el predeterminado (vacio) */
export default function SelectorColor({ value, onChange }) {
  const valor = value || '';
  const legible = !valor || colorLegible(valor);
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-2">
        {COLORES_TIENDA.map((c) => (
          <button key={c} type="button" onClick={() => onChange(c)} aria-label={`Color ${c}`} aria-pressed={valor.toUpperCase() === c}
            className={clsx('h-10 w-10 rounded-[10px] border-2', valor.toUpperCase() === c ? 'border-slate-900 ring-2 ring-white ring-inset' : 'border-transparent')}
            style={{ background: c }} />
        ))}
        <label className="flex h-10 items-center gap-2 rounded-[10px] border border-slate-300 px-2 text-sm">
          Otro
          <input type="color" value={valor || '#1F4FD1'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-7 w-9 cursor-pointer border-0 bg-transparent p-0" />
        </label>
        <button type="button" className="btn-ghost" onClick={() => onChange('')}>Predeterminado</button>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <span className="inline-flex min-h-10 items-center rounded-[10px] px-4 text-sm font-semibold text-white" style={{ background: valor || '#1F4FD1' }}>Cobrar $499.00</span>
        <span className="font-mono text-sm text-slate-600">{valor || 'predeterminado (#1F4FD1)'}</span>
      </div>
      {!legible && <p className="text-sm font-semibold text-danger-600">Ese color es muy claro: el texto blanco no se leería. Elige uno más oscuro.</p>}
    </div>
  );
}
SelectorColor.propTypes = { value: PropTypes.string, onChange: PropTypes.func.isRequired };
