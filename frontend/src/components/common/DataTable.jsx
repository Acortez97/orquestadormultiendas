import { useState, useMemo } from 'react';
import PropTypes from 'prop-types';
import { ChevronUpIcon, ChevronDownIcon, ChevronUpDownIcon } from '@heroicons/react/24/outline';
import ExportButtons from './ExportButtons';

/**
 * Tabla genérica con estilos del sistema.
 * columns: [{ key, label, render?(row), className?, noExport?, exportValue?(row),
 *            sortValue?(row), noSort?, movil? }]
 *  - className: clases del <td> (p.ej. 'text-right'); su alineación se replica en el <th>.
 *  - El encabezado es clicable para ordenar (asc → desc → original), salvo noSort.
 *  - En celular (< 640 px) cada fila es una tarjeta: la primera columna es el título, la primera
 *    columna alineada a la derecha es el importe destacado, la columna `acciones` va abajo y el
 *    resto se muestra como «etiqueta: valor». `movil: false` oculta una columna en la tarjeta.
 * Si se pasa `exportName`, muestra un botón Exportar (Excel/PDF) arriba de la tabla.
 */
export default function DataTable({ columns, data, loading, empty = 'Sin registros', onRowClick, exportName, exportTitle, selectedId }) {
  const [sort, setSort] = useState({ key: null, dir: null });

  const alignOf = (c) =>
    c.className?.includes('text-right') ? 'text-right'
      : c.className?.includes('text-center') ? 'text-center'
        : 'text-left';

  const sortVal = (c, row) => (c.sortValue ? c.sortValue(row) : row[c.key]);

  const toggleSort = (c) => {
    if (c.noSort) return;
    setSort((s) => {
      if (s.key !== c.key) return { key: c.key, dir: 'asc' };
      if (s.dir === 'asc') return { key: c.key, dir: 'desc' };
      return { key: null, dir: null }; // tercer clic: orden original
    });
  };

  const sortedData = useMemo(() => {
    if (!sort.key || !sort.dir) return data;
    const col = columns.find((c) => c.key === sort.key);
    if (!col) return data;
    const factor = sort.dir === 'asc' ? 1 : -1;
    return [...data].sort((ra, rb) => {
      const a = sortVal(col, ra);
      const b = sortVal(col, rb);
      if (a == null && b == null) return 0;
      if (a == null) return 1;
      if (b == null) return -1;
      if (typeof a === 'number' && typeof b === 'number') return (a - b) * factor;
      if (typeof a === 'boolean' && typeof b === 'boolean') return (Number(a) - Number(b)) * factor;
      return String(a).localeCompare(String(b), 'es', { numeric: true }) * factor;
    });
  }, [data, sort, columns]); // eslint-disable-line react-hooks/exhaustive-deps

  // tarjeta de celular
  const titulo = columns[0];
  const monto = columns.find((c, i) => i > 0 && c.key !== 'acciones' && alignOf(c) === 'text-right');
  const acciones = columns.find((c) => c.key === 'acciones');
  const resto = columns.filter((c) => c !== titulo && c !== monto && c !== acciones && c.movil !== false && c.label);
  const celda = (c, row) => (c.render ? c.render(row) : row[c.key]);
  const ordenables = columns.filter((c) => !c.noSort && c.label && c.key !== 'acciones');

  return (
    <div className="card overflow-hidden">
      {(exportName || ordenables.length > 0) && (
        <div className="flex items-center justify-end gap-2 border-b border-slate-200 bg-white px-3 py-2">
          {ordenables.length > 0 && (
            <label className="mr-auto flex items-center gap-2 text-sm text-slate-600 sm:hidden">
              Ordenar
              <select className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                value={sort.key ? `${sort.key}:${sort.dir}` : ''}
                onChange={(e) => { const [key, dir] = e.target.value.split(':'); setSort(key ? { key, dir } : { key: null, dir: null }); }}>
                <option value="">Original</option>
                {ordenables.map((c) => [
                  <option key={`${c.key}:asc`} value={`${c.key}:asc`}>{c.label} ↑</option>,
                  <option key={`${c.key}:desc`} value={`${c.key}:desc`}>{c.label} ↓</option>,
                ])}
              </select>
            </label>
          )}
          {exportName && <ExportButtons columns={columns} data={sortedData} name={exportName} title={exportTitle} />}
        </div>
      )}

      {/* Celular: tarjetas */}
      <div className="divide-y divide-slate-100 sm:hidden">
        {loading && [0, 1, 2].map((i) => <div key={i} className="p-4"><div className="skeleton h-12" /></div>)}
        {!loading && sortedData.length === 0 && <p className="px-4 py-8 text-center text-slate-600">{empty}</p>}
        {!loading && sortedData.map((row, i) => {
          const contenido = (
            <>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 font-semibold text-slate-900">{celda(titulo, row)}</div>
                {monto && <div className="shrink-0 text-right text-base font-bold">{celda(monto, row)}</div>}
              </div>
              {resto.length > 0 && (
                <dl className="mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[13px]">
                  {resto.map((c) => (
                    <div key={c.key} className="contents">
                      <dt className="text-slate-600">{c.label}</dt>
                      <dd className="min-w-0 text-slate-900">{celda(c, row)}</dd>
                    </div>
                  ))}
                </dl>
              )}
            </>
          );
          return (
            <div key={row._id || i} className={selectedId && row._id === selectedId ? 'bg-primary-50' : ''}>
              {onRowClick
                ? <button type="button" onClick={() => onRowClick(row)} className="block w-full px-4 py-3 text-left active:bg-slate-50">{contenido}</button>
                : <div className="px-4 py-3">{contenido}</div>}
              {acciones && <div className="flex flex-wrap gap-1.5 px-4 pb-3" onClick={(e) => e.stopPropagation()}>{celda(acciones, row)}</div>}
            </div>
          );
        })}
      </div>

      {/* Tablet y computadora: tabla */}
      <div className="hidden overflow-x-auto sm:block">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-white">
            <tr>
              {columns.map((c) => {
                const active = sort.key === c.key && sort.dir;
                const align = alignOf(c);
                return (
                  <th
                    key={c.key}
                    onClick={() => toggleSort(c)}
                    className={`px-4 py-3 text-xs font-bold text-slate-600 uppercase tracking-[0.04em] whitespace-nowrap ${align} ${
                      c.noSort ? '' : 'cursor-pointer select-none hover:text-slate-900'
                    }`}
                  >
                    <span className={`inline-flex items-center gap-1 ${align === 'text-right' ? 'flex-row-reverse' : ''}`}>
                      {c.label}
                      {!c.noSort && (
                        active
                          ? (sort.dir === 'asc' ? <ChevronUpIcon className="w-3.5 h-3.5" /> : <ChevronDownIcon className="w-3.5 h-3.5" />)
                          : <ChevronUpDownIcon className="w-3.5 h-3.5 text-slate-300" />
                      )}
                    </span>
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 bg-white">
            {loading ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-4"><div className="space-y-3">{[0, 1, 2].map((i) => <div key={i} className="skeleton h-6" />)}</div></td>
              </tr>
            ) : sortedData.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-8 text-center text-slate-600">{empty}</td>
              </tr>
            ) : (
              sortedData.map((row, i) => (
                <tr
                  key={row._id || i}
                  className={`${onRowClick ? 'cursor-pointer hover:bg-slate-50' : 'hover:bg-slate-50'} ${selectedId && row._id === selectedId ? 'bg-primary-50 shadow-[inset_3px_0_0_var(--acento)]' : ''}`}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                >
                  {columns.map((c) => (
                    <td key={c.key} className={`px-4 py-3 text-slate-800 ${c.className || ''}`}>
                      {c.render ? c.render(row) : row[c.key]}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

DataTable.propTypes = {
  columns: PropTypes.array.isRequired,
  data: PropTypes.array.isRequired,
  loading: PropTypes.bool,
  empty: PropTypes.string,
  onRowClick: PropTypes.func,
  exportName: PropTypes.string,
  exportTitle: PropTypes.string,
  selectedId: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
};
