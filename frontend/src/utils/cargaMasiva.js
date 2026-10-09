import * as XLSX from 'xlsx';

// ============================================================
// Plantillas de carga masiva y lectura del archivo (Excel o CSV) en el navegador.
// El servidor recibe renglones ya leidos: { fila, codigo, descripcion, ... }.
// ============================================================

export const PLANTILLAS = {
  articulos: {
    archivo: 'plantilla_articulos_y_existencias',
    columnas: [
      ['Código*', 'codigo', 'Obligatorio. El mismo código en varios renglones = variantes del mismo artículo.'],
      ['Descripción*', 'descripcion', 'Obligatorio en artículos nuevos.'],
      ['Categoría', 'categoria', 'Si no existe se crea. Obligatoria si el artículo lleva color o talla.'],
      ['Marca', 'marca', 'Opcional. Si no existe se crea.'],
      ['Familia', 'familia', 'Opcional. Si no existe se crea.'],
      ['Línea', 'linea', 'Opcional. Si no existe se crea.'],
      ['Color / variante 1', 'variante1', 'Opcional. Ej. Negro. Deja vacío si el artículo no tiene variantes.'],
      ['Talla / variante 2', 'variante2', 'Opcional. Ej. M, 26, 1 L.'],
      ['Costo', 'costo', 'Lo que te cuesta cada pieza.'],
      ['Precio lista 1*', 'precio1', 'Obligatorio en artículos nuevos. Precio al público (1 pieza).'],
      ['Precio lista 2', 'precio2', 'Opcional (mayoreo desde 3 piezas).'],
      ['Precio lista 3', 'precio3', 'Opcional (desde 6 piezas).'],
      ['Precio lista 4', 'precio4', 'Opcional (desde 12 piezas).'],
      ['Precio lista 5', 'precio5', 'Opcional (desde 24 piezas).'],
      ['Código de barras', 'codigo_barras', 'Opcional. Uno por variante.'],
      ['Almacén', 'almacen', 'Obligatorio si pones existencia. Nombre o código del almacén.'],
      ['Existencia', 'existencia', 'Piezas que tienes hoy en ese almacén.'],
    ],
    ejemplo: (alm) => [
      ['PLY-001', 'Playera básica', 'Ropa', 'Hanes', '', '', 'Negro', 'M', 80, 199, 189, 179, '', '', '7501000000017', alm, 12],
      ['PLY-001', 'Playera básica', 'Ropa', 'Hanes', '', '', 'Negro', 'G', 80, 199, 189, 179, '', '', '7501000000024', alm, 8],
      ['CUA-100', 'Cuaderno profesional 100 hojas', 'Papelería', 'Scribe', '', '', '', '', 25, 45, 42, 39, 36, 32, '7501000000031', alm, 200],
    ],
    notas: [
      'Un renglón por artículo; si tiene variantes, un renglón por cada combinación (color + talla).',
      'Si el código ya existe, se actualizan su costo y sus precios y se SUMA la existencia.',
      'Primero se revisa todo y ves qué se va a crear; nada se guarda hasta que confirmes.',
      'Máximo 3,000 renglones por archivo.',
    ],
  },
  existencias: {
    archivo: 'plantilla_existencias',
    columnas: [
      ['Código*', 'codigo', 'Código del artículo, su SKU o su código de barras.'],
      ['Color / variante 1', 'variante1', 'Solo si el artículo tiene variantes y no usaste el código de barras de la variante.'],
      ['Talla / variante 2', 'variante2', 'Igual que la anterior.'],
      ['Almacén*', 'almacen', 'Nombre o código del almacén.'],
      ['Cantidad*', 'cantidad', 'Piezas (contadas o a sumar, según lo que elijas al subir el archivo).'],
    ],
    ejemplo: (alm) => [
      ['PLY-001', 'Negro', 'M', alm, 15],
      ['7501000000031', '', '', alm, 180],
    ],
    notas: [
      'Los artículos deben existir (cárgalos antes con la plantilla de artículos).',
      '«Conteo físico»: la cantidad es la existencia real y el sistema ajusta la diferencia.',
      '«Sumar»: la cantidad se agrega a lo que ya hay (p. ej. mercancía que llegó).',
    ],
  },
};

/** Descarga la plantilla en Excel: hoja «Carga» con ejemplos + hoja «Instrucciones» */
export function descargarPlantilla(tipo, almacenes = []) {
  const p = PLANTILLAS[tipo];
  const alm = almacenes[0]?.nombre || 'Tienda principal';
  const wb = XLSX.utils.book_new();
  const carga = XLSX.utils.aoa_to_sheet([p.columnas.map((c) => c[0]), ...p.ejemplo(alm)]);
  carga['!cols'] = p.columnas.map((c) => ({ wch: Math.max(12, c[0].length + 2) }));
  XLSX.utils.book_append_sheet(wb, carga, 'Carga');
  const instr = [
    ['Cómo llenar la plantilla'], [],
    ...p.notas.map((n) => [`• ${n}`]), [],
    ['Columna', 'Qué va'],
    ...p.columnas.map((c) => [c[0], c[2]]), [],
    ['Las columnas con * son obligatorias. Borra los renglones de ejemplo antes de subir tu archivo.'],
    [`Almacenes de tu tienda: ${almacenes.map((a) => a.nombre).join(', ') || alm}`],
  ];
  const wsI = XLSX.utils.aoa_to_sheet(instr);
  wsI['!cols'] = [{ wch: 26 }, { wch: 90 }];
  XLSX.utils.book_append_sheet(wb, wsI, 'Instrucciones');
  XLSX.writeFile(wb, `${p.archivo}.xlsx`);
}

const normal = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/\*/g, '').replace(/\s+/g, ' ').trim();

/** A que campo corresponde un encabezado (acepta variantes comunes: «Precio», «Stock», «EAN»...) */
function campoDe(encabezado, tipo) {
  const h = normal(encabezado);
  const exacto = PLANTILLAS[tipo].columnas.find((c) => normal(c[0]) === h);
  if (exacto) return exacto[1];
  if (/^(codigo|clave|sku)$/.test(h)) return 'codigo';
  if (/^(descripcion|nombre|articulo|producto)$/.test(h)) return 'descripcion';
  if (/^categoria/.test(h)) return 'categoria';
  if (/^marca/.test(h)) return 'marca';
  if (/^familia/.test(h)) return 'familia';
  if (/^linea/.test(h)) return 'linea';
  if (/variante 1|^color/.test(h)) return 'variante1';
  if (/variante 2|^talla|^numero|^medida/.test(h)) return 'variante2';
  if (/^costo/.test(h)) return 'costo';
  const lista = h.match(/(?:precio|lista)\D*([1-5])$/);
  if (lista) return `precio${lista[1]}`;
  if (/^(precio|precio publico|precio venta)$/.test(h)) return 'precio1';
  if (/barras|^ean/.test(h)) return 'codigo_barras';
  if (/^(almacen|sucursal|tienda)/.test(h)) return 'almacen';
  if (/^(existencia|stock|cantidad|piezas|inventario)/.test(h)) return tipo === 'existencias' ? 'cantidad' : 'existencia';
  return null;
}

/** Lee el archivo y devuelve { filas, columnas, desconocidas } (fila = numero de renglon en Excel) */
export async function leerArchivo(file, tipo) {
  const buf = await file.arrayBuffer();
  let wb;
  if (/\.(csv|txt)$/i.test(file.name)) {
    // CSV: UTF-8 (con o sin BOM) o el que guarda Excel en Windows (windows-1252), para no perder acentos
    let texto;
    try { texto = new TextDecoder('utf-8', { fatal: true }).decode(buf); } catch { texto = new TextDecoder('windows-1252').decode(buf); }
    wb = XLSX.read(texto.replace(/^\uFEFF/, ''), { type: 'string', raw: true });
  } else {
    wb = XLSX.read(buf, { type: 'array' });
  }
  const hoja = wb.Sheets.Carga || wb.Sheets[wb.SheetNames[0]];
  const renglones = XLSX.utils.sheet_to_json(hoja, { header: 1, raw: true, defval: '' });
  const iEnc = renglones.findIndex((r) => r.some((c) => String(c).trim() !== ''));
  if (iEnc < 0) throw new Error('El archivo está vacío.');
  const enc = renglones[iEnc];
  const mapa = enc.map((h) => campoDe(h, tipo));
  if (!mapa.includes('codigo')) throw new Error('No encontré la columna «Código». Usa la plantilla descargada.');
  const filas = [];
  for (let i = iEnc + 1; i < renglones.length; i += 1) {
    const r = renglones[i];
    if (!r.some((c) => String(c).trim() !== '')) continue;
    const f = { fila: i + 1 };
    mapa.forEach((campo, j) => { if (campo) f[campo] = typeof r[j] === 'number' ? String(r[j]) : String(r[j] ?? '').trim(); });
    filas.push(f);
  }
  return { filas, columnas: mapa.filter(Boolean), desconocidas: enc.filter((h, j) => !mapa[j] && String(h).trim()) };
}
