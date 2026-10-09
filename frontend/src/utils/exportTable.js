import * as XLSX from 'xlsx';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import { obtenerLogos } from './logos';

/**
 * Convierte un nodo de React (lo que devuelve `render` de una columna) a texto plano,
 * para poder exportarlo. Maneja strings, números, arreglos y elementos (badges, spans…).
 */
export function nodeToText(node) {
  if (node == null || node === false || node === true) return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(nodeToText).join('');
  if (typeof node === 'object' && node.props) return nodeToText(node.props.children);
  return '';
}

/** Texto exportable de una celda: usa exportValue si existe, si no el render/valor crudo. */
function cellText(col, row) {
  if (typeof col.exportValue === 'function') return col.exportValue(row);
  const v = col.render ? col.render(row) : row[col.key];
  return nodeToText(v).trim();
}

/** Columnas a exportar: omite acciones y las marcadas con noExport. */
function exportableColumns(columns) {
  return columns.filter((c) => !c.noExport && c.key !== 'acciones');
}

/** Construye { headers, rows } a partir de columns + data de una DataTable. */
export function buildExportData(columns, data) {
  const cols = exportableColumns(columns);
  const headers = cols.map((c) => c.label);
  const rows = (data || []).map((r) => cols.map((c) => cellText(c, r)));
  return { headers, rows };
}

const stamp = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}`;
};

export function exportToExcel(columns, data, name = 'reporte') {
  const { headers, rows } = buildExportData(columns, data);
  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
  ws['!cols'] = headers.map((h, i) => ({
    wch: Math.min(40, Math.max(h.length, ...rows.map((r) => String(r[i] ?? '').length)) + 2),
  }));
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Datos');
  XLSX.writeFile(wb, `${name}_${stamp()}.xlsx`);
}

/** Dibuja un logo dentro de una caja (ancho x alto maximos) conservando su proporcion */
function logoEn(doc, logo, x, y, maxW, maxH, alinear = 'izq') {
  if (!logo) return 0;
  const k = Math.min(maxW / logo.w, maxH / logo.h);
  const w = logo.w * k; const h = logo.h * k;
  try { doc.addImage(logo.src, 'PNG', alinear === 'der' ? x - w : x, y, w, h); } catch { return 0; }
  return w;
}

export async function exportToPdf(columns, data, name = 'reporte', title) {
  const { headers, rows } = buildExportData(columns, data);
  const doc = new jsPDF({ orientation: headers.length > 6 ? 'landscape' : 'portrait', unit: 'pt' });
  const ancho = doc.internal.pageSize.getWidth();
  // encabezado: logo de la tienda a la izquierda y el de la plataforma (LEVOTEK) a la derecha
  const logos = await obtenerLogos();
  const wTienda = logoEn(doc, logos.tienda, 40, 24, 110, 40);
  logoEn(doc, logos.plataforma, ancho - 40, 28, 90, 24, 'der');
  const x = wTienda ? 40 + wTienda + 12 : 40;
  doc.setFontSize(14);
  doc.text(title || name, x, 42);
  doc.setFontSize(9);
  doc.text(new Date().toLocaleString('es-MX'), x, 58);
  autoTable(doc, {
    head: [headers],
    body: rows,
    startY: 78,
    styles: { fontSize: 8, cellPadding: 4 },
    headStyles: { fillColor: [30, 41, 59] },
    margin: { left: 40, right: 40 },
  });
  doc.save(`${name}_${stamp()}.pdf`);
}
