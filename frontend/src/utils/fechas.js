// Fechas en hora LOCAL con formato yyyy-mm-dd (inputs date y filtros del API).
// El backend toma "desde" a partir de las 00:00 y "hasta" hasta las 23:59:59 de ese día, en la hora de la tienda;
// por eso nunca se envían instantes ISO en UTC (toISOString), que corren el día después de las 18:00 en México.
const dos = (n) => String(n).padStart(2, '0');

export const fechaLocal = (d = new Date()) => `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;

export const hoyLocal = () => fechaLocal(new Date());

/** Fecha local de hace n días */
export const haceDias = (n) => fechaLocal(new Date(Date.now() - n * 86400000));

/**
 * Convierte una fecha del API a Date en hora LOCAL. El API manda 'yyyy-mm-dd' (DATE) o 'yyyy-mm-dd hh:mm:ss'
 * (DATETIME, hora de la tienda). new Date('yyyy-mm-dd') la toma como UTC y en México muestra el día anterior;
 * y Safari no entiende el espacio del DATETIME. Aquí ambas se leen como hora local.
 */
export const aFecha = (d) => {
  if (d instanceof Date) return d;
  const s = String(d ?? '');
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(s)) return new Date(s.replace(' ', 'T'));
  return new Date(s);
};
