import { useState, useMemo } from 'react';
import PropTypes from 'prop-types';
import { ChevronUpIcon, ChevronDownIcon, ChevronUpDownIcon } from '@heroicons/react/24/outline';
import ExportButtons from './ExportButtons';

/**
 * Tabla genérica con estilos Tailwind del sistema.
 * columns: [{ key, label, render?(row), className?, noExport?, exportValue?(row),
 *            sortValue?(row), noSort? }]
 *  - className: clases del <td> (p.ej. 'text-right'); su alineación se replica en el <th>.
 *  - El encabezado es clicable para ordenar (asc → desc → original), salvo noSort.
 * Si se pasa `exportName`, muestra un botón Exportar (Excel/PDF) arriba de la tabla.
 */
export default function DataTable({ columns, data, loading, empty = 'Sin registros', onRowClick, exportName, exportTitle }) {
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

  return (
    <div className="card overflow-hidden">
      {exportName && (
        <div className="flex justify-end px-3 py-2 border-b border-slate-200 bg-white">
          <ExportButtons columns={columns} data={sortedData} name={exportName} title={exportTitle} />
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              {columns.map((c) => {
                const active = sort.key === c.key && sort.dir;
                const align = alignOf(c);
                return (
                  <th
                    key={c.key}
                    onClick={() => toggleSort(c)}
                    className={`px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider whitespace-nowrap ${align} ${
                      c.noSort ? '' : 'cursor-pointer select-none hover:text-slate-700'
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
                <td colSpan={columns.length} className="px-4 py-8 text-center text-slate-400">Cargando…</td>
              </tr>
            ) : sortedData.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-8 text-center text-slate-400">{empty}</td>
              </tr>
            ) : (
              sortedData.map((row, i) => (
                <tr
                  key={row._id || i}
                  className={onRowClick ? 'hover:bg-slate-50 cursor-pointer' : 'hover:bg-slate-50'}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                >
                  {columns.map((c) => (
                    <td key={c.key} className={`px-4 py-3 text-slate-700 ${c.className || ''}`}>
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
};
