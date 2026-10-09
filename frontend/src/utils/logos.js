import { configApi, plataformaApi } from '../services/api/endpoints';
import session from '../services/session';

// ============================================================
// Logos para imprimir (tickets, cortes, PDF): el de la tienda y el de la plataforma (LEVOTEK).
// El API los entrega como data URL (sin problemas de origen); aqui se pasan a PNG con su tamano,
// que es lo que necesita jsPDF. Se cachean por sesion; al cambiar un logo se llama invalidarLogos().
// ============================================================
let cache = null;   // { token, promesa }: ligado a la sesion, asi otra tienda nunca imprime el logo anterior

/** Convierte una imagen (data URL o URL del mismo origen) a PNG; devuelve { src, w, h } o null */
function aPng(src, maxLado = 600) {
  return new Promise((resolve) => {
    if (!src) { resolve(null); return; }
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, maxLado / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
      const w = Math.max(1, Math.round((img.naturalWidth || maxLado) * k));
      const h = Math.max(1, Math.round((img.naturalHeight || maxLado / 4) * k));
      try {
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        resolve({ src: c.toDataURL('image/png'), w, h });
      } catch { resolve(null); }   // imagen de otro origen sin permiso: se omite
    };
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

/** { tienda: {src,w,h}|null, plataforma: {src,w,h}|null } — nunca falla (imprimir no debe romperse) */
export function obtenerLogos() {
  if (!cache || cache.token !== session.token()) {
    cache = { token: session.token(), promesa: null };
    cache.promesa = configApi.logos()
      .then((r) => r.data || {})
      // panel de plataforma (sin tienda): solo el logo de la plataforma
      .catch(() => plataformaApi.logo().then((r) => ({ plataforma: r.data?.logo })).catch(() => ({})))
      .then(async (d) => ({
        tienda: await aPng(d.tienda || d.tienda_url || null),
        plataforma: await aPng(d.plataforma || '/logo.svg'),   // sin logo subido: el de la plataforma incluido
      }));
  }
  return cache.promesa;
}

export function invalidarLogos() { cache = null; }

/** Ajusta un archivo elegido por el usuario a un logo ligero (PNG, max. 600 px) como data URL */
export function archivoALogo(file) {
  return new Promise((resolve, reject) => {
    if (!/^image\/(png|jpeg|webp)$/.test(file?.type || '')) { reject(new Error('El logo debe ser PNG, JPG o WEBP.')); return; }
    const lector = new FileReader();
    lector.onload = async () => {
      const r = await aPng(lector.result, 600);
      if (r) resolve(r.src); else reject(new Error('No se pudo leer la imagen.'));
    };
    lector.onerror = () => reject(new Error('No se pudo leer la imagen.'));
    lector.readAsDataURL(file);
  });
}
