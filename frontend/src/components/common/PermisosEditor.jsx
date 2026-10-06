import PropTypes from 'prop-types';

/**
 * Editor de permisos por modulo y accion.
 * - modulos: [{ clave, nombre, acciones: [{ clave, nombre }] }]  (solo los habilitados en la tienda)
 * - value:   { ventas: { ver: true, crear: true }, ... }
 * El modulo "usuarios" no se muestra: administrar usuarios es exclusivo del administrador de la tienda.
 */
export default function PermisosEditor({ modulos, value, onChange, disabled }) {
  const lista = modulos.filter((m) => m.clave !== 'usuarios');
  const tiene = (m, a) => !!value?.[m]?.[a];

  const cambiar = (m, a, on) => {
    const mod = { ...(value?.[m] || {}) };
    if (on) mod[a] = true; else delete mod[a];
    // sin "ver" no tiene sentido ninguna otra accion; marcar cualquier accion implica "ver"
    if (on && a !== 'ver' && mod.ver === undefined && modulos.find((x) => x.clave === m)?.acciones.some((x) => x.clave === 'ver')) mod.ver = true;
    if (!on && a === 'ver') Object.keys(mod).forEach((k) => delete mod[k]);
    const next = { ...(value || {}) };
    if (Object.keys(mod).length) next[m] = mod; else delete next[m];
    onChange(next);
  };

  const todo = (m, on) => {
    const next = { ...(value || {}) };
    if (on) next[m.clave] = Object.fromEntries(m.acciones.map((a) => [a.clave, true]));
    else delete next[m.clave];
    onChange(next);
  };

  if (!lista.length) return <p className="text-sm text-slate-500">La tienda no tiene módulos habilitados.</p>;

  return (
    <div className="divide-y divide-slate-100 rounded-lg border border-slate-200">
      {lista.map((m) => {
        const completos = m.acciones.every((a) => tiene(m.clave, a.clave));
        return (
          <div key={m.clave} className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start">
            <label className="flex w-52 shrink-0 items-center gap-2 text-sm font-semibold text-slate-700">
              <input type="checkbox" disabled={disabled} checked={completos} onChange={(e) => todo(m, e.target.checked)} />
              {m.nombre}
            </label>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {m.acciones.map((a) => (
                <label key={a.clave} className="flex items-center gap-1.5 text-sm text-slate-600">
                  <input type="checkbox" disabled={disabled} checked={tiene(m.clave, a.clave)}
                    onChange={(e) => cambiar(m.clave, a.clave, e.target.checked)} />
                  {a.nombre}
                </label>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

PermisosEditor.propTypes = {
  modulos: PropTypes.array.isRequired,
  value: PropTypes.object,
  onChange: PropTypes.func.isRequired,
  disabled: PropTypes.bool,
};
