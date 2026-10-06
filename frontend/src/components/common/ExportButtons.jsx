import { useState, useRef, useEffect } from 'react';
import PropTypes from 'prop-types';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
// Las librerias de Excel/PDF (pesadas) se descargan solo al exportar
const exportador = () => import('../../utils/exportTable');

/**
 * Botón "Exportar" con menú Excel / PDF para una tabla (columns + data).
 * Omite la columna de acciones y las columnas con `noExport`.
 */
export default function ExportButtons({ columns, data, name = 'reporte', title }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);

  const disabled = !data || data.length === 0;

  const run = async (tipo) => {
    setOpen(false);
    const mod = await exportador();
    if (tipo === 'excel') mod.exportToExcel(columns, data, name, title || name);
    else mod.exportToPdf(columns, data, name, title || name);
  };

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className="btn-secondary flex items-center gap-1.5"
        disabled={disabled}
        onClick={() => setOpen((o) => !o)}
        title={disabled ? 'Sin datos para exportar' : 'Exportar tabla'}
      >
        <ArrowDownTrayIcon className="w-4 h-4" /> Exportar
      </button>
      {open && (
        <div className="absolute right-0 z-30 mt-1 w-40 rounded-lg border border-slate-200 bg-white shadow-lg py-1">
          <button
            type="button"
            className="w-full text-left px-3 py-2 text-sm hover:bg-slate-50"
            onClick={() => run('excel')}
          >
            Excel (.xlsx)
          </button>
          <button
            type="button"
            className="w-full text-left px-3 py-2 text-sm hover:bg-slate-50"
            onClick={() => run('pdf')}
          >
            PDF (.pdf)
          </button>
        </div>
      )}
    </div>
  );
}

ExportButtons.propTypes = {
  columns: PropTypes.array.isRequired,
  data: PropTypes.array.isRequired,
  name: PropTypes.string,
  title: PropTypes.string,
};
