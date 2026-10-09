import { useEffect, useState } from 'react';
import PropTypes from 'prop-types';
import { bancosApi, terminalesApi } from '../../services/api/endpoints';
import session from '../../services/session';

// Formas de pago de un cobro y a donde va el dinero:
//   efectivo -> caja de la tienda | tdc/tdb -> terminal (y su cuenta) | transferencia/cheque -> cuenta
export const FORMAS_COBRO = [
  { v: 'efectivo', l: 'Efectivo' },
  { v: 'tdc', l: 'T. Crédito' },
  { v: 'tdb', l: 'T. Débito' },
  { v: 'transferencia', l: 'Transferencia' },
  { v: 'cheque', l: 'Cheque' },
];
export const FORMA_LABEL = {
  efectivo: 'Efectivo', tdc: 'T. Crédito', tdb: 'T. Débito', transferencia: 'Transferencia', cheque: 'Cheque',
  monedero: 'Saldo a favor', anticipo: 'Anticipo',
};
export const requiereTerminal = (forma) => forma === 'tdc' || forma === 'tdb';
export const requiereCuenta = (forma) => forma === 'transferencia' || forma === 'cheque';

// Cuentas y terminales se cargan una vez por SESION: la cache va ligada al token, asi que al cerrar
// sesion, entrar con otra tienda o entrar/salir de soporte se vuelven a pedir (nunca se ven las de otra tienda).
// Los cobros y pagos de la tienda son en pesos: solo se ofrecen cuentas en MXN (y terminales que depositan en ellas).
let cache = null;   // { token, cuentas, terminales }
const vigente = () => (cache && cache.token === session.token() ? cache : null);
const VACIO = { cuentas: [], terminales: [] };
export function useDestinos() {
  const [d, setD] = useState(vigente() || VACIO);
  useEffect(() => {
    if (vigente()) { setD(cache); return undefined; }
    let vivo = true;
    const token = session.token();
    Promise.all([bancosApi.listar().catch(() => ({ data: [] })), terminalesApi.listar().catch(() => ({ data: [] }))])
      .then(([b, t]) => {
        if (session.token() !== token) return;   // la sesion cambio mientras cargaba
        const cuentas = (b.data || []).filter((c) => (c.moneda || 'MXN') === 'MXN');
        const enPesos = new Set(cuentas.map((c) => c._id));
        cache = { token, cuentas, terminales: (t.data || []).filter((x) => enPesos.has(x.id_banco?._id)) };
        if (vivo) setD(cache);
      });
    return () => { vivo = false; };
  }, []);
  return d;
}
export function invalidarDestinos() { cache = null; }

/** Valida que un pago traiga su destino. Devuelve un mensaje de error o null. */
export function faltaDestino(pago) {
  if (!(Number(pago.importe) > 0)) return null;
  if (requiereTerminal(pago.forma) && !pago.id_terminal) return 'Selecciona la terminal con la que se cobró con tarjeta.';
  if (requiereCuenta(pago.forma) && !pago.id_banco) return 'Selecciona la cuenta a la que llegó la transferencia / cheque.';
  return null;
}

/** Datos del destino para enviar al API */
export function destinoPayload(pago) {
  if (requiereTerminal(pago.forma)) return { id_terminal: pago.id_terminal };
  if (requiereCuenta(pago.forma)) return { id_banco: pago.id_banco, referencia: pago.referencia || undefined };
  return {};
}

/** Selector de terminal o cuenta segun la forma de pago (no muestra nada para efectivo / saldo a favor) */
export default function DestinoPago({ forma, value, onChange, className = '' }) {
  const { cuentas, terminales } = useDestinos();
  if (requiereTerminal(forma)) {
    return (
      <select className={`input-base !py-1 text-sm ${className}`} value={value?.id_terminal || ''}
        onChange={(e) => onChange({ ...value, id_terminal: e.target.value, id_banco: '' })}>
        <option value="">— Terminal —</option>
        {terminales.map((t) => <option key={t._id} value={t._id}>{t.nombre} → {t.id_banco?.nombre}</option>)}
      </select>
    );
  }
  if (requiereCuenta(forma)) {
    return (
      <select className={`input-base !py-1 text-sm ${className}`} value={value?.id_banco || ''}
        onChange={(e) => onChange({ ...value, id_banco: e.target.value, id_terminal: '' })}>
        <option value="">— Cuenta destino —</option>
        {cuentas.map((c) => <option key={c._id} value={c._id}>{c.nombre}</option>)}
      </select>
    );
  }
  return null;
}

DestinoPago.propTypes = {
  forma: PropTypes.string.isRequired,
  value: PropTypes.object,
  onChange: PropTypes.func.isRequired,
  className: PropTypes.string,
};
