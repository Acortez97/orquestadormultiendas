import PropTypes from 'prop-types';
import clsx from 'clsx';

// Colores sugeridos para una tienda, por familia: todos con contraste >= 4.5:1 para texto blanco encima
export const GRUPOS_COLOR = [
  ['Azules', [['Azul rey', '#1F4FD1'], ['Índigo', '#4338CA'], ['Cielo profundo', '#0369A1'], ['Azul acero', '#1D4E89'], ['Azul marino', '#1E3A8A']]],
  ['Verdes', [['Turquesa', '#0F766E'], ['Verde jade', '#0E7C66'], ['Verde esmeralda', '#047857'], ['Verde bosque', '#166534'], ['Verde olivo', '#4D7C0F']]],
  ['Morados y rosas', [['Violeta', '#6D28D9'], ['Morado', '#7A3E9D'], ['Uva', '#581C87'], ['Magenta', '#A21CAF'], ['Rosa mexicano', '#BE185D'], ['Frambuesa', '#9F1239']]],
  ['Rojos y tierras', [['Rojo', '#B91C1C'], ['Vino', '#7F1D1D'], ['Terracota', '#B4472A'], ['Naranja quemado', '#9A3412'], ['Ámbar oscuro', '#92400E'], ['Mostaza', '#854D0E'], ['Café', '#78350F']]],
  ['Neutros', [['Pizarra', '#334155'], ['Grafito', '#3F3F46'], ['Carbón', '#17181C']]],
];
export const COLORES_TIENDA = GRUPOS_COLOR.flatMap(([, l]) => l.map(([, c]) => c));
const NOMBRE_COLOR = Object.fromEntries(GRUPOS_COLOR.flatMap(([, l]) => l.map(([n, c]) => [c, n])));

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
      {GRUPOS_COLOR.map(([grupo, lista]) => (
        <div key={grupo}>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{grupo}</p>
          <div className="flex flex-wrap gap-2">
            {lista.map(([nombre, c]) => (
              <button key={c} type="button" onClick={() => onChange(c)} title={nombre} aria-label={nombre} aria-pressed={valor.toUpperCase() === c}
                className={clsx('h-10 w-10 rounded-[10px] border-2', valor.toUpperCase() === c ? 'border-slate-900 ring-2 ring-white ring-inset' : 'border-transparent')}
                style={{ background: c }} />
            ))}
          </div>
        </div>
      ))}
      <div className="flex flex-wrap items-center gap-2">
        <label className="flex h-10 items-center gap-2 rounded-[10px] border border-slate-300 px-2 text-sm">
          Otro color
          <input type="color" value={valor || '#1F4FD1'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-7 w-9 cursor-pointer border-0 bg-transparent p-0" />
        </label>
        <button type="button" className="btn-ghost" onClick={() => onChange('')}>Predeterminado</button>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <span className="inline-flex min-h-10 items-center rounded-[10px] px-4 text-sm font-semibold text-white" style={{ background: valor || '#1F4FD1' }}>Cobrar $499.00</span>
        <span className="font-mono text-sm text-slate-600">{valor ? `${NOMBRE_COLOR[valor.toUpperCase()] || 'Personalizado'} · ${valor}` : 'Predeterminado (#1F4FD1)'}</span>
      </div>
      {!legible && <p className="text-sm font-semibold text-danger-600">Ese color es muy claro: el texto blanco no se leería. Elige uno más oscuro.</p>}
    </div>
  );
}
SelectorColor.propTypes = { value: PropTypes.string, onChange: PropTypes.func.isRequired };
