import { useState, useRef } from 'react';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, XMarkIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

export default function ImportCsvModal({ titulo, onDownloadLayout, onImportar, onClose, onDone }) {
  const [file, setFile]       = useState(null);
  const [loading, setLoading] = useState(false);
  const [result, setResult]   = useState(null);
  const inputRef = useRef(null);

  async function handleImportar() {
    if (!file) return;
    setLoading(true);
    try {
      const csv = await file.text();
      const res = await onImportar(csv);
      setResult(res.data ?? res);
      onDone?.();
    } catch (e) {
      setResult({ error: e.message });
    } finally {
      setLoading(false);
    }
  }

  function handleFileChange(e) {
    setResult(null);
    setFile(e.target.files[0] ?? null);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-lg">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-200">
          <h2 className="font-semibold text-slate-900">Importar {titulo} desde CSV</h2>
          <button onClick={onClose} className="btn-ghost p-1"><XMarkIcon className="w-5 h-5" /></button>
        </div>

        <div className="px-6 py-5 space-y-5">

          {/* Step 1 */}
          <div className="space-y-1">
            <p className="text-sm font-medium text-slate-700">1. Descarga el layout</p>
            <p className="text-xs text-slate-500">El archivo incluye las columnas requeridas y una fila de ejemplo.</p>
            <button
              onClick={onDownloadLayout}
              className="btn-secondary flex items-center gap-2 text-sm mt-2"
            >
              <ArrowDownTrayIcon className="w-4 h-4" />
              Descargar layout.csv
            </button>
          </div>

          <div className="border-t border-slate-100" />

          {/* Step 2 */}
          <div className="space-y-2">
            <p className="text-sm font-medium text-slate-700">2. Llena el archivo y súbelo</p>
            <p className="text-xs text-slate-500">
              Los registros existentes se actualizan (por RFC o nombre en clientes; por código en productos).
              Los nuevos se crean automáticamente.
            </p>

            <div
              onClick={() => inputRef.current?.click()}
              className="border-2 border-dashed border-slate-300 rounded-lg p-6 text-center cursor-pointer hover:border-primary-400 hover:bg-primary-50 transition-colors"
            >
              <ArrowUpTrayIcon className="w-6 h-6 mx-auto text-slate-400 mb-2" />
              {file
                ? <p className="text-sm font-medium text-slate-700">{file.name}</p>
                : <p className="text-sm text-slate-500">Haz clic o arrastra un archivo <span className="font-semibold">.csv</span></p>
              }
              <input
                ref={inputRef}
                type="file"
                accept=".csv,text/csv"
                className="hidden"
                onChange={handleFileChange}
              />
            </div>
          </div>

          {/* Result */}
          {result && !result.error && (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-2">
              <div className="flex items-center gap-2 text-green-700 font-medium text-sm">
                <CheckCircleIcon className="w-5 h-5" />
                Importación completada
              </div>
              <div className="grid grid-cols-3 gap-2 text-sm text-center">
                <div className="bg-white rounded border border-slate-200 py-2">
                  <p className="text-lg font-bold text-slate-900">{result.creados ?? 0}</p>
                  <p className="text-xs text-slate-500">Creados</p>
                </div>
                <div className="bg-white rounded border border-slate-200 py-2">
                  <p className="text-lg font-bold text-slate-900">{result.actualizados ?? 0}</p>
                  <p className="text-xs text-slate-500">Actualizados</p>
                </div>
                <div className="bg-white rounded border border-slate-200 py-2">
                  <p className="text-lg font-bold text-red-600">{result.errores?.length ?? 0}</p>
                  <p className="text-xs text-slate-500">Errores</p>
                </div>
              </div>
              {result.errores?.length > 0 && (
                <div className="mt-2 space-y-1 max-h-40 overflow-y-auto">
                  {result.errores.map((e, i) => (
                    <div key={i} className="flex gap-2 text-xs text-red-700 bg-red-50 rounded px-2 py-1">
                      <ExclamationTriangleIcon className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                      <span><span className="font-semibold">Fila {e.fila}:</span> {e.error}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {result?.error && (
            <div className="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700">
              {result.error}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 px-6 py-4 border-t border-slate-200">
          <button onClick={onClose} className="btn-secondary">Cerrar</button>
          <button
            onClick={handleImportar}
            disabled={!file || loading}
            className="btn-primary flex items-center gap-2"
          >
            {loading ? <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" /> : <ArrowUpTrayIcon className="w-4 h-4" />}
            {loading ? 'Importando…' : 'Importar'}
          </button>
        </div>
      </div>
    </div>
  );
}
