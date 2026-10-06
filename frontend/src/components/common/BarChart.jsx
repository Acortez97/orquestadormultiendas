import PropTypes from 'prop-types';

/**
 * Gráfica de barras ligera (sin dependencias). Barras verticales escaladas al
 * valor máximo; etiqueta arriba (valor) y abajo (nombre). Scroll horizontal si
 * hay muchas barras.
 *
 * data: [{ label: string, value: number }]
 * format: fn(value) -> string (para la etiqueta del valor)
 */
export default function BarChart({ title, data, format = (n) => n, color = 'bg-primary-500', height = 200 }) {
  const filas = (data || []).filter((d) => d && Number.isFinite(Number(d.value)));
  const max = Math.max(1, ...filas.map((d) => Number(d.value)));

  return (
    <div className="card p-4">
      {title && <h3 className="text-sm font-semibold text-slate-700 mb-3">{title}</h3>}
      {filas.length === 0 ? (
        <div className="py-8 text-center text-slate-400 text-sm">Sin datos para graficar</div>
      ) : (
        <div className="overflow-x-auto">
          <div className="flex items-end gap-2" style={{ height }}>
            {filas.map((d, i) => {
              const v = Number(d.value);
              const h = `${Math.max(2, (v / max) * 100)}%`;
              return (
                <div key={i} className="flex flex-col items-center justify-end flex-1 min-w-[2.75rem] h-full">
                  <span className="text-[10px] text-slate-500 mb-1 whitespace-nowrap">{format(v)}</span>
                  <div
                    className={`w-full max-w-[3.5rem] rounded-t ${color} transition-all`}
                    style={{ height: h }}
                    title={`${d.label}: ${format(v)}`}
                  />
                  <span className="text-[10px] text-slate-500 mt-1 text-center truncate max-w-[4.5rem]" title={d.label}>
                    {d.label}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

BarChart.propTypes = {
  title: PropTypes.string,
  data: PropTypes.array.isRequired,
  format: PropTypes.func,
  color: PropTypes.string,
  height: PropTypes.number,
};
