import PropTypes from 'prop-types';

/**
 * Matriz de captura de variantes (eje1 = filas, eje2 = columnas) para un artículo.
 * Cada celda guarda un valor en `cant`, mapeado por `${eje1Id}_${eje2Id}`.
 * Se usa en Compras y Traspasos (cantidades ≥ 0) y en Ajustes de inventario
 * (deltas +/−, con `allowNegative`).
 *
 * Los ejes ya NO son "Color"/"Talla" fijos: la categoría del artículo define su
 * Eje 1 y Eje 2 (Color, Talla, Número, Material…). Los valores viven en
 * `atributo_valores` y llegan en `art.colores` (eje1) y `art.tallas` (eje2).
 * Si el artículo no maneja variantes se captura una sola cantidad (celda 0,0).
 */

export const coloresDe = (art) => (art?.colores?.length ? art.colores : [{ _id: '', nombre: 'Único' }]);
export const tallasDe = (art) => (art?.tallas?.length ? art.tallas : [{ _id: '', nombre: 'Única' }]);
export const celdaKey = (cId, tId) => `${cId}_${tId}`;
export const sumarMatriz = (cant) => Object.values(cant || {}).reduce((a, n) => a + (Number(n) || 0), 0);

// Nombre del eje 1 / eje 2 de la categoría del artículo (fallback "Color"/"Talla").
export const nombreEje1 = (art) => art?.id_categoria?.eje1?.nombre || 'Color';
export const nombreEje2 = (art) => art?.id_categoria?.eje2?.nombre || 'Talla';
// ¿El artículo tiene valores para ese eje?
export const tieneEje1 = (art) => !!art?.colores?.length;
export const tieneEje2 = (art) => !!art?.tallas?.length;
// Producto simple: sin ningún eje de variante (celda única 0,0).
export const esSimple = (art) => !tieneEje1(art) && !tieneEje2(art);

// Swatch de color: solo si el valor trae hex (viene de atributo_valores.extra).
const swatch = (hex) =>
  typeof hex === 'string' && /^#([0-9a-f]{3,8})$/i.test(hex.trim()) ? (
    <span
      className="inline-block w-3 h-3 rounded-full border border-slate-300 align-middle mr-1.5"
      style={{ background: hex.trim() }}
    />
  ) : null;

/**
 * Expande la matriz `cant` de un artículo en líneas { id_color, id_talla, valor }.
 * Sólo incluye celdas distintas de cero. `incluirCero=true` las incluye también.
 */
export function expandirMatriz(art, cant, { incluirCero = false } = {}) {
  const out = [];
  for (const c of coloresDe(art)) {
    for (const t of tallasDe(art)) {
      const valor = Number(cant?.[celdaKey(c._id, t._id)]) || 0;
      if (!incluirCero && valor === 0) continue;
      out.push({ id_color: c._id || undefined, id_talla: t._id || undefined, valor });
    }
  }
  return out;
}

/**
 * Selects en línea para elegir la variante (eje1/eje2) de un artículo en carritos
 * (POS, apartados, cambios). Muestra el nombre del eje según la categoría y oculta
 * el eje que el artículo no maneja. Producto simple → "Sin variante".
 */
export function VariantesInline({ art, id_color, id_talla, onChange, size = 'sm' }) {
  const e1 = tieneEje1(art);
  const e2 = tieneEje2(art);
  if (!e1 && !e2) return <span className="text-xs text-slate-400">Sin variante</span>;
  const cls = `input-base ${size === 'sm' ? '!py-1 text-xs' : ''}`;
  return (
    <div className="flex gap-1">
      {e1 && (
        <select className={cls} title={nombreEje1(art)} value={id_color || ''} onChange={(e) => onChange({ id_color: e.target.value })}>
          {art.colores.map((c) => (
            <option key={c._id} value={c._id}>{c.nombre}</option>
          ))}
        </select>
      )}
      {e2 && (
        <select className={cls} title={nombreEje2(art)} value={id_talla || ''} onChange={(e) => onChange({ id_talla: e.target.value })}>
          {art.tallas.map((t) => (
            <option key={t._id} value={t._id}>{t.nombre}</option>
          ))}
        </select>
      )}
    </div>
  );
}

VariantesInline.propTypes = {
  art: PropTypes.object,
  id_color: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  id_talla: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  onChange: PropTypes.func.isRequired,
  size: PropTypes.oneOf(['sm', 'md']),
};

export default function MatrizColorTalla({ art, cant, onCell, allowNegative = false }) {
  // Producto sin variantes: una sola cantidad (celda 0,0 → clave "_").
  if (esSimple(art)) {
    const key = celdaKey('', '');
    return (
      <div className="flex items-center gap-3">
        <span className="text-xs font-medium text-slate-500">Cantidad</span>
        <input
          type="number"
          {...(allowNegative ? {} : { min: '0' })}
          inputMode="numeric"
          className="input-base w-32 text-center"
          value={cant?.[key] ?? ''}
          onChange={(e) => onCell(key, e.target.value)}
        />
        {allowNegative && <span className="text-xs text-slate-400">(+ entra / − sale)</span>}
      </div>
    );
  }

  const colores = coloresDe(art);
  const tallas = tallasDe(art);
  const total = sumarMatriz(cant);

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full border-collapse text-sm">
        <thead>
          <tr>
            <th className="sticky left-0 z-10 bg-slate-50 border border-slate-200 px-3 py-1.5 text-left text-xs font-semibold text-slate-500">
              {nombreEje1(art)} \ {nombreEje2(art)}
            </th>
            {tallas.map((t) => (
              <th
                key={t._id || 'unica'}
                className="border border-slate-200 bg-slate-50 px-2 py-1.5 text-center text-xs font-semibold text-slate-500 min-w-[64px]"
              >
                {t.nombre}
              </th>
            ))}
            <th className="border border-slate-200 bg-slate-50 px-2 py-1.5 text-center text-xs font-semibold text-slate-500">
              Total
            </th>
          </tr>
        </thead>
        <tbody>
          {colores.map((c) => {
            const totalFila = tallas.reduce((a, t) => a + (Number(cant?.[celdaKey(c._id, t._id)]) || 0), 0);
            return (
              <tr key={c._id || 'unico'}>
                <td className="sticky left-0 z-10 bg-white border border-slate-200 px-3 py-1 font-medium text-slate-700">
                  {swatch(c.hex)}
                  {c.nombre}
                </td>
                {tallas.map((t) => {
                  const key = celdaKey(c._id, t._id);
                  return (
                    <td key={key} className="border border-slate-200 p-0.5">
                      <input
                        type="number"
                        {...(allowNegative ? {} : { min: '0' })}
                        inputMode="numeric"
                        className="w-full text-center px-1 py-1 rounded outline-none focus:ring-2 focus:ring-primary-200"
                        value={cant?.[key] ?? ''}
                        onChange={(e) => onCell(key, e.target.value)}
                      />
                    </td>
                  );
                })}
                <td className="border border-slate-200 px-2 py-1 text-center font-medium text-slate-600">
                  {totalFila || ''}
                </td>
              </tr>
            );
          })}
        </tbody>
        <tfoot>
          <tr>
            <td className="sticky left-0 z-10 bg-slate-50 border border-slate-200 px-3 py-1 text-right text-xs font-semibold text-slate-500">
              Total
            </td>
            {tallas.map((t) => {
              const totalCol = colores.reduce((a, c) => a + (Number(cant?.[celdaKey(c._id, t._id)]) || 0), 0);
              return (
                <td
                  key={t._id || 'unica'}
                  className="border border-slate-200 bg-slate-50 px-2 py-1 text-center text-xs font-semibold text-slate-600"
                >
                  {totalCol || ''}
                </td>
              );
            })}
            <td className="border border-slate-200 bg-slate-50 px-2 py-1 text-center text-xs font-bold text-slate-700">
              {total || ''}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

MatrizColorTalla.propTypes = {
  art: PropTypes.object,
  cant: PropTypes.object,
  onCell: PropTypes.func.isRequired,
  allowNegative: PropTypes.bool,
};
