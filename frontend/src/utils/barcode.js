/**
 * Generador de código de barras Code128 (subset B/C automático) como SVG, 100% en el
 * navegador y sin dependencias externas (F4). Sirve para imprimir etiquetas a partir del
 * SKU interno cuando el producto no trae código de fábrica.
 *
 * Uso: code128SVG('ROP-00001-1-6', { height: 40 }) -> string con <svg>…</svg>
 */

// Patrones de módulos (anchos de barra/espacio) para los valores 0..106 de Code128.
// Índices: 0..102 datos, 103=StartA, 104=StartB, 105=StartC, 106=Stop.
const PATTERNS = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const START_B = 104;
const START_C = 105;
const STOP = 106;

// ¿Los siguientes `count` caracteres desde `i` son todos dígitos?
const digitsAhead = (s, i, count) => {
  if (i + count > s.length) return false;
  for (let k = 0; k < count; k += 1) if (s[i + k] < '0' || s[i + k] > '9') return false;
  return true;
};

/** Codifica el texto a la lista de valores Code128 (con start y checksum), subset B/C auto. */
function encode(text) {
  const codes = [];
  let mode = ''; // 'B' | 'C'
  let i = 0;
  // Elegir start: C si arranca con ≥4 dígitos (par), si no B.
  if (digitsAhead(text, 0, 4)) { codes.push(START_C); mode = 'C'; }
  else { codes.push(START_B); mode = 'B'; }

  while (i < text.length) {
    if (mode === 'C') {
      if (digitsAhead(text, i, 2)) {
        codes.push(parseInt(text.substr(i, 2), 10));
        i += 2;
      } else {
        codes.push(100); // Code B
        mode = 'B';
      }
    } else {
      // En B, si vienen ≥6 dígitos conviene cambiar a C (par de dígitos restantes)
      const remaining = text.length - i;
      const evenChunk = remaining >= 6 && digitsAhead(text, i, 6);
      if (evenChunk) {
        codes.push(99); // Code C
        mode = 'C';
      } else {
        const c = text.charCodeAt(i);
        // Code128B cubre ASCII 32..126 -> valor = code - 32
        codes.push((c >= 32 && c <= 126 ? c : 63) - 32);
        i += 1;
      }
    }
  }

  // Checksum: start + Σ(valor_k * posición_k), mod 103
  let sum = codes[0];
  for (let k = 1; k < codes.length; k += 1) sum += codes[k] * k;
  codes.push(sum % 103);
  codes.push(STOP);
  return codes;
}

/**
 * Devuelve un string SVG con el código de barras.
 * @param {string} text
 * @param {{height?:number, moduleWidth?:number, margin?:number, displayValue?:boolean}} opts
 */
export function code128SVG(text, opts = {}) {
  const value = String(text ?? '').trim() || ' ';
  const height = opts.height ?? 44;
  const mw = opts.moduleWidth ?? 1.6; // ancho de un módulo en px
  const margin = opts.margin ?? 10;
  const displayValue = opts.displayValue !== false;
  const fontSize = opts.fontSize ?? 11;

  const codes = encode(value);
  // Construye la secuencia de anchos: cada patrón alterna barra(negro)/espacio empezando por barra.
  const rects = [];
  let x = margin;
  for (const code of codes) {
    const pattern = PATTERNS[code];
    for (let p = 0; p < pattern.length; p += 1) {
      const w = Number(pattern[p]) * mw;
      if (p % 2 === 0) rects.push(`<rect x="${x.toFixed(2)}" y="0" width="${w.toFixed(2)}" height="${height}" />`);
      x += w;
    }
  }
  const totalWidth = x + margin;
  const textH = displayValue ? fontSize + 4 : 0;
  const svgH = height + textH;
  const label = displayValue
    ? `<text x="${(totalWidth / 2).toFixed(2)}" y="${svgH - 2}" text-anchor="middle" font-family="monospace" font-size="${fontSize}" fill="#000">${escapeXml(value)}</text>`
    : '';

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${totalWidth.toFixed(2)}" height="${svgH}" viewBox="0 0 ${totalWidth.toFixed(2)} ${svgH}" shape-rendering="crispEdges"><rect x="0" y="0" width="${totalWidth.toFixed(2)}" height="${svgH}" fill="#fff"/><g fill="#000">${rects.join('')}</g>${label}</svg>`;
}

function escapeXml(s) {
  return String(s).replace(/[<>&"']/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c]));
}

export default code128SVG;
