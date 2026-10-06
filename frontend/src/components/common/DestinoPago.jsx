import { useEffect, useState } from 'react';
import PropTypes from 'prop-types';
import { bancosApi, terminalesApi } from '../../services/api/endpoints';

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

// Cuentas y terminales se cargan una vez por sesion de pagina
let cache = null;
export function useDestinos() {
  const [d, setD] = useState(cache || { cuentas: [], terminales: [] });
  useEffect(() => {
    if (cache) return;
    Promise.all([bancosApi.listar().catch(() => ({ data: [] })), terminalesApi.listar().catch(() => ({ data: [] }))])
      .then(([b, t]) => { cache = { cuentas: b.data || [], terminales: t.data || [] }; setD(cache); });
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
