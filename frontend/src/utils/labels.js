/**
 * Impresión de etiquetas de producto con código de barras (F4).
 * Cada etiqueta lleva: descripción, variante (opcional), precio (opcional), el SKU y su
 * código de barras Code128 (render SVG en el navegador). Se imprime en un iframe oculto.
 *
 * label = { descripcion, variante?, precio?, sku, codigo? }
 *   - `codigo` es el valor a codificar en barras (por defecto = sku).
 */
import { code128SVG } from './barcode';

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);

const esc = (s) =>
  String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function renderLabelsHTML(labels, opts = {}) {
  const ancho = opts.ancho ?? 50; // mm
  const alto = opts.alto ?? 30; // mm
  const celdas = labels
    .map((l) => {
      const codigo = l.codigo || l.sku || '';
      const svg = code128SVG(codigo, { height: 34, moduleWidth: 1.3, margin: 6, displayValue: true, fontSize: 10 });
      return `
      <div class="lbl">
        <div class="desc">${esc(l.descripcion || '')}</div>
        ${l.variante ? `<div class="var">${esc(l.variante)}</div>` : ''}
        <div class="bc">${svg}</div>
        ${l.precio != null && l.precio !== '' ? `<div class="price">${money(l.precio)}</div>` : ''}
      </div>`;
    })
    .join('');

  return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Etiquetas</title>
<style>
  @page { margin: 4mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, sans-serif; }
  .grid { display: flex; flex-wrap: wrap; gap: 2mm; }
  .lbl {
    width: ${ancho}mm; height: ${alto}mm;
    border: 1px dashed #bbb; border-radius: 2px;
    padding: 1.5mm; display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; overflow: hidden; page-break-inside: avoid;
  }
  .desc { font-size: 9px; font-weight: 700; line-height: 1.1; max-height: 22px; overflow: hidden; }
  .var { font-size: 8px; color: #333; }
  .bc { margin: 1px 0; }
  .bc svg { max-width: 100%; height: auto; }
  .price { font-size: 11px; font-weight: 700; }
  @media print { .lbl { border-color: transparent; } }
</style></head><body>
  <div class="grid">${celdas}</div>
</body></html>`;
}

/** Imprime las etiquetas en un iframe oculto y abre el diálogo de impresión. */
export function printEtiquetas(labels, opts = {}) {
  try {
    if (!Array.isArray(labels) || labels.length === 0) return;
    const html = renderLabelsHTML(labels, opts);
    const iframe = document.createElement('iframe');
    Object.assign(iframe.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });
    document.body.appendChild(iframe);
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
    const imprimir = () => {
      try {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
      } finally {
        setTimeout(() => iframe.remove(), 1500);
      }
    };
    iframe.onload = () => setTimeout(imprimir, 150);
    if (doc.readyState === 'complete') setTimeout(imprimir, 200);
  } catch (e) {
    console.error('No se pudo imprimir las etiquetas', e);
  }
}

export default printEtiquetas;
