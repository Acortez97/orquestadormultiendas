/**
 * Impresión / exportación a PDF del Corte de Caja.
 *
 * El corte es un reporte de varias secciones (no una tabla simple), así que en
 * vez de jspdf-autotable se arma un HTML tamaño carta y se manda al diálogo de
 * impresión en un iframe oculto; desde ahí el usuario puede "Guardar como PDF".
 *
 * printCorte(preview, { tienda, fecha }) donde `preview` es el objeto que
 * devuelve cortesApi.preview (resumen, detalle_notas, devoluciones, cambios,
 * anticipos).
 */

const money = (n) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n) || 0);

const fechaCorta = (d) => (d ? new Date(d).toLocaleDateString('es-MX') : '');
const fechaHora = () => new Date().toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });

const esc = (s) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const FORMA_LABEL = {
  cheque: 'Cheque',
  efectivo: 'Efectivo',
  tdc: 'T. Crédito',
  tdb: 'T. Débito',
  transferencia: 'Transferencia',
  monedero: 'Monedero',
};

const listaLabel = (k) => (String(k).toUpperCase() === 'OFERTA' ? 'Oferta' : `Lista ${k}`);
const ordenLista = (a, b) => {
  const na = Number(a);
  const nb = Number(b);
  if (Number.isNaN(na) && Number.isNaN(nb)) return String(a).localeCompare(String(b));
  if (Number.isNaN(na)) return 1;
  if (Number.isNaN(nb)) return -1;
  return na - nb;
};

/** Genera el HTML del corte (tamaño carta). */
function renderCorteHTML(preview, opts = {}) {
  const r = preview?.resumen || {};
  const tiendaNombre = opts.tienda || '';
  const fecha = opts.fecha || preview?.fecha;

  const card = (label, value, fuerte = false) =>
    `<div class="card ${fuerte ? 'fuerte' : ''}"><div class="lbl">${esc(label)}</div><div class="val">${esc(value)}</div></div>`;

  const cards = [
    card('Total vendido', money(r.total_vendido)),
    card('Efectivo esperado en caja', money(r.efectivo_esperado_caja), true),
    card('# Notas', r.num_notas ?? 0),
    card('Total crédito', money(r.total_credito)),
    card('Anticipos de apartado', money(r.total_anticipos)),
    card('Saldo a favor usado', money(r.saldo_favor_usado)),
    card('Saldo a favor generado', money(r.saldo_favor_generado)),
    card('Cambio devuelto (efvo.)', money(r.cambio_efectivo)),
    card('Devoluciones del día', money(r.total_devoluciones)),
    card('Efectivo por cambios', money(r.efectivo_cambios)),
    card('Abonos de clientes', money(r.total_abonos)),
    card('Salidas de efectivo', money(r.caja_salidas)),
    card('Cobrado en cuentas / terminales', money(r.cobrado_en_cuentas)),
  ].join('');

  const destinos = (preview?.por_destino || [])
    .map((d) => `<tr><td>${esc(d.cuenta)}</td><td>${esc(d.terminal || '—')}</td><td>${esc(FORMA_LABEL[d.forma] || d.forma)}</td><td class="right">${money(d.monto)}</td></tr>`)
    .join('');
  const caja = (preview?.caja_movimientos || [])
    .map((m) => `<tr><td>${esc(m.hora)}</td><td>${esc(m.concepto)}</td><td class="right">${m.tipo === 'ingreso' ? money(m.monto) : ''}</td><td class="right">${m.tipo === 'egreso' ? money(m.monto) : ''}</td></tr>`)
    .join('');

  const formas = Object.entries(r.por_forma_pago || {})
    .map(([k, v]) => `<tr><td>${esc(FORMA_LABEL[k] || k)}</td><td class="right">${money(v)}</td></tr>`)
    .join('');

  const anticiposForma = Object.entries(r.anticipos_por_forma_pago || {})
    .filter(([, v]) => Number(v) > 0)
    .map(([k, v]) => `<tr><td>${esc(FORMA_LABEL[k] || k)}</td><td class="right">${money(v)}</td></tr>`)
    .join('');

  const porLista = r.vendido_por_lista || {};
  const claves = Object.keys(porLista).sort(ordenLista);
  const listas = claves.length
    ? claves.map((k) => `<tr><td>${esc(listaLabel(k))}</td><td class="right">${money(porLista[k])}</td></tr>`).join('')
    : `<tr><td colspan="2" class="muted center">Sin ventas</td></tr>`;

  const detalle = (preview?.detalle_notas || [])
    .map((n) => {
      const lineas = (n.lineas || [])
        .map(
          (l) => `<tr>
            <td>${esc(l.codigo)}</td>
            <td>${esc(l.descripcion)}</td>
            <td class="right">${l.cantidad}</td>
            <td class="right">${money(l.precio_unitario)}</td>
            <td>${esc(listaLabel(l.lista_aplicada))}</td>
            <td class="right">${money(l.importe)}</td>
          </tr>`
        )
        .join('');
      return `<div class="nota">
        <div class="nota-head"><b>${esc(n.folio)}</b><span class="muted">${(n.formas_pago || [])
          .map((f) => FORMA_LABEL[f] || f)
          .join(', ')}</span><b>${money(n.total)}</b></div>
        <table class="tbl sm"><thead><tr><th>Código</th><th>Descripción</th><th class="right">Cant.</th><th class="right">P.Unit.</th><th>Lista</th><th class="right">Importe</th></tr></thead><tbody>${lineas}</tbody></table>
      </div>`;
    })
    .join('');

  const anticiposLista = (preview?.anticipos || [])
    .map(
      (a) => `<tr>
        <td>${esc(a.folio)}</td>
        <td>${esc(a.cliente || '—')}</td>
        <td>${esc(a.vendedor || '—')}</td>
        <td>${esc(FORMA_LABEL[a.forma] || a.forma || '—')}</td>
        <td class="right">${money(a.importe)}</td>
      </tr>`
    )
    .join('');

  const devoluciones = (preview?.devoluciones || [])
    .map(
      (d) => `<tr>
        <td>${esc(d.folio)}</td><td>${esc(d.folio_venta || '—')}</td>
        <td class="right">${money(d.total)}</td>
        <td>${d.destino_saldo === 'cxc' ? 'CxC' : 'Monedero'}</td>
      </tr>`
    )
    .join('');

  const cambios = (preview?.cambios || [])
    .map(
      (c) => `<tr>
        <td>${esc(c.folio)}</td><td>${esc(c.folio_venta || '—')}</td>
        <td class="right">${money(c.total_devuelto)}</td>
        <td class="right">${money(c.total_nuevo)}</td>
        <td class="right">${money(c.diferencia)}</td>
        <td class="right">${money(c.pago_diferencia)}</td>
      </tr>`
    )
    .join('');

  const seccion = (titulo, contenido) =>
    `<div class="sec"><div class="sec-tit">${esc(titulo)}</div>${contenido}</div>`;

  return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Corte ${esc(tiendaNombre)} ${esc(fechaCorta(fecha))}</title>
<style>
  @page { size: letter; margin: 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1e293b; margin: 0; }
  h1 { font-size: 18px; margin: 0; }
  .head { border-bottom: 2px solid #1e293b; padding-bottom: 6px; margin-bottom: 10px; }
  .head .meta { color: #475569; font-size: 11px; margin-top: 2px; }
  .cards { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
  .card { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; min-width: 140px; }
  .card.fuerte { border-color: #d97706; background: #fffbeb; }
  .card .lbl { font-size: 9px; text-transform: uppercase; color: #64748b; letter-spacing: .03em; }
  .card .val { font-size: 14px; font-weight: bold; margin-top: 2px; }
  .sec { margin-bottom: 12px; page-break-inside: avoid; }
  .sec-tit { font-weight: bold; font-size: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; margin-bottom: 5px; }
  table.tbl { width: 100%; border-collapse: collapse; }
  table.tbl th { background: #f1f5f9; text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; padding: 3px 6px; border-bottom: 1px solid #cbd5e1; }
  table.tbl td { padding: 3px 6px; border-bottom: 1px solid #e2e8f0; }
  table.tbl.sm th, table.tbl.sm td { font-size: 10px; padding: 2px 5px; }
  .right { text-align: right; }
  .center { text-align: center; }
  .muted { color: #94a3b8; }
  .nota { margin-bottom: 8px; }
  .nota-head { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; }
  .foot { margin-top: 14px; text-align: center; color: #94a3b8; font-size: 9px; }
</style></head><body>
  <div class="head">
    <h1>Corte de Caja</h1>
    <div class="meta">${esc(tiendaNombre)} · Fecha: ${esc(fechaCorta(fecha))} · Generado: ${esc(fechaHora())}</div>
  </div>
  <div class="cards">${cards}</div>
  ${seccion('Por forma de pago', `<table class="tbl"><thead><tr><th>Forma</th><th class="right">Monto</th></tr></thead><tbody>${formas || '<tr><td colspan="2" class="muted center">—</td></tr>'}</tbody></table>`)}
  ${seccion('Vendido por lista de precios', `<table class="tbl"><thead><tr><th>Lista</th><th class="right">Importe</th></tr></thead><tbody>${listas}</tbody></table>`)}
  ${
    (preview?.anticipos || []).length
      ? seccion(
          'Anticipos de apartado del día',
          `<table class="tbl"><thead><tr><th>Folio</th><th>Cliente</th><th>Vendedor</th><th>Forma</th><th class="right">Importe</th></tr></thead><tbody>${anticiposLista}</tbody></table>` +
            (anticiposForma
              ? `<table class="tbl" style="margin-top:6px"><thead><tr><th>Anticipos por forma</th><th class="right">Monto</th></tr></thead><tbody>${anticiposForma}</tbody></table>`
              : '')
        )
      : ''
  }
  ${seccion('Dónde quedó el dinero (tarjeta, transferencia, cheque)', `<table class="tbl"><thead><tr><th>Cuenta</th><th>Terminal</th><th>Forma</th><th class="right">Monto</th></tr></thead><tbody>${destinos || '<tr><td colspan="4" class="muted center">Sin cobros fuera de efectivo</td></tr>'}</tbody></table>`)}
  ${seccion('Movimientos de la caja (efectivo)', `<table class="tbl"><thead><tr><th>Hora</th><th>Concepto</th><th class="right">Entrada</th><th class="right">Salida</th></tr></thead><tbody>${caja || '<tr><td colspan="4" class="muted center">Sin movimientos</td></tr>'}</tbody></table>`)}
  ${seccion('Detalle de notas', detalle || '<div class="muted center">Sin notas</div>')}
  ${seccion('Devoluciones del día', `<table class="tbl"><thead><tr><th>Folio</th><th>Venta origen</th><th class="right">Total</th><th>Destino</th></tr></thead><tbody>${devoluciones || '<tr><td colspan="4" class="muted center">Sin devoluciones</td></tr>'}</tbody></table>`)}
  ${seccion('Cambios del día', `<table class="tbl"><thead><tr><th>Folio</th><th>Venta origen</th><th class="right">Devuelto</th><th class="right">Nuevo</th><th class="right">Diferencia</th><th class="right">Pago efvo.</th></tr></thead><tbody>${cambios || '<tr><td colspan="6" class="muted center">Sin cambios</td></tr>'}</tbody></table>`)}
  <div class="foot">MultiTienda · Corte de caja (reporte informativo)</div>
</body></html>`;
}

/** Imprime el corte en un iframe oculto (el usuario puede Guardar como PDF). */
export function printCorte(preview, opts = {}) {
  try {
    const html = renderCorteHTML(preview, opts);
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
    console.error('No se pudo imprimir el corte', e);
  }
}

export default printCorte;
