/**
 * Impresión de tickets de 80mm para movimientos de cliente (venta, devolución, cambio).
 * Genera el HTML del ticket y lo manda a imprimir en un iframe oculto (diálogo automático).
 *
 * Estructura del ticket:
 * {
 *   tipo: 'NOTA DE VENTA' | 'DEVOLUCIÓN' | 'CAMBIO',
 *   tienda: { nombre, direccion, telefono },
 *   folio, fecha (Date|string),
 *   cliente: string,
 *   vendedor: string,
 *   secciones: [{ titulo?, lineas: [{ cant?, texto, detalle?, importe? }] }],
 *   totales: [{ label, value, fuerte? }],
 *   pagos: [{ forma, importe }],
 *   saldoMonedero, saldoCredito,  // opcionales (number)
 *   nota,                          // pie opcional
 * }
 */

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);

const fechaHora = (d) => {
  const date = d ? new Date(d) : new Date();
  return date.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
};

const esc = (s) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const FORMA_LABEL = {
  efectivo: 'Efectivo',
  tdc: 'T. Crédito',
  tdb: 'T. Débito',
  transferencia: 'Transferencia',
  monedero: 'Monedero',
};

function renderTicketHTML(t) {
  const tienda = t.tienda || {};
  const secciones = (t.secciones || [])
    .map((s) => {
      const filas = (s.lineas || [])
        .map(
          (l) => `
        <tr>
          <td>${l.cant ? `${esc(l.cant)} ` : ''}${esc(l.texto)}${
            l.detalle ? `<div class="sm muted">${esc(l.detalle)}</div>` : ''
          }</td>
          <td class="right">${l.importe != null ? money(l.importe) : ''}</td>
        </tr>`
        )
        .join('');
      return `
      ${s.titulo ? `<div class="b" style="margin-top:4px">${esc(s.titulo)}</div>` : ''}
      <table class="items">${filas}</table>`;
    })
    .join('');

  const totales = (t.totales || [])
    .map(
      (x) =>
        `<div class="row ${x.fuerte ? 'b' : ''}"><span>${esc(x.label)}</span><span>${money(x.value)}</span></div>`
    )
    .join('');

  const pagos = (t.pagos || []).filter((p) => Number(p.importe) > 0);
  const pagosHtml = pagos.length
    ? `<hr><div class="b">Pagos</div>${pagos
        .map(
          (p) =>
            `<div class="row"><span>${esc(FORMA_LABEL[p.forma] || p.forma)}</span><span>${money(p.importe)}</span></div>`
        )
        .join('')}`
    : '';

  const saldos = [];
  if (t.saldoMonedero != null)
    saldos.push(`<div class="row"><span>Saldo monedero</span><span>${money(t.saldoMonedero)}</span></div>`);
  if (t.saldoCredito != null)
    saldos.push(`<div class="row"><span>Saldo crédito (debe)</span><span>${money(t.saldoCredito)}</span></div>`);
  const saldosHtml = saldos.length ? `<hr>${saldos.join('')}` : '';

  return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ticket ${esc(t.folio || '')}</title>
<style>
  @page { size: 80mm auto; margin: 4mm; }
  * { box-sizing: border-box; }
  body { width: 72mm; margin: 0 auto; font-family: 'Courier New', monospace; font-size: 11px; color: #000; }
  .center { text-align: center; }
  .right { text-align: right; }
  .b { font-weight: bold; }
  .sm { font-size: 10px; }
  .row { display: flex; justify-content: space-between; gap: 6px; }
  h1 { font-size: 14px; margin: 0; }
  hr { border: none; border-top: 1px dashed #000; margin: 6px 0; }
  table.items { width: 100%; border-collapse: collapse; }
  .items td { vertical-align: top; padding: 1px 0; }
</style></head><body>
  <div class="center">
    <h1>${esc(tienda.nombre || 'Ticket')}</h1>
    ${tienda.direccion ? `<div class="sm">${esc(tienda.direccion)}</div>` : ''}
    ${tienda.telefono ? `<div class="sm">Tel. ${esc(tienda.telefono)}</div>` : ''}
    <div class="b" style="margin-top:4px">${esc(t.tipo || '')}</div>
  </div>
  <hr>
  <div class="row"><span>Folio</span><span class="b">${esc(t.folio || '—')}</span></div>
  <div class="row"><span>Fecha</span><span>${esc(fechaHora(t.fecha))}</span></div>
  <div class="row"><span>Cliente</span><span>${esc(t.cliente || '—')}</span></div>
  ${t.vendedor ? `<div class="row"><span>Vendedor</span><span>${esc(t.vendedor)}</span></div>` : ''}
  <hr>
  ${secciones}
  <hr>
  ${totales}
  ${pagosHtml}
  ${saldosHtml}
  <hr>
  <div class="center sm">${esc(t.nota || '¡Gracias por su compra!')}</div>
</body></html>`;
}

/** Imprime el ticket en un iframe oculto y abre el diálogo de impresión. */
export function printTicket(t) {
  try {
    const html = renderTicketHTML(t);
    const iframe = document.createElement('iframe');
    Object.assign(iframe.style, {
      position: 'fixed',
      right: '0',
      bottom: '0',
      width: '0',
      height: '0',
      border: '0',
    });
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
    // Espera a que el contenido esté listo antes de imprimir.
    iframe.onload = () => setTimeout(imprimir, 150);
    if (doc.readyState === 'complete') setTimeout(imprimir, 200);
  } catch (e) {
    // La impresión no debe romper el flujo de guardado.
    console.error('No se pudo imprimir el ticket', e);
  }
}

export default printTicket;
