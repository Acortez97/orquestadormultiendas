import PropTypes from 'prop-types';
import { useAuth } from '../../contexts/AuthContext';

/** Iniciales de la tienda (p. ej. "Tienda Demo Uno" -> "TD") */
export const iniciales = (nombre = '') =>
  nombre.split(/\s+/).filter((p) => p.length > 2 || /^[A-ZÁÉÍÓÚÑ]/.test(p)).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || 'T';

/** Logo de la tienda, o sus iniciales sobre su color */
export function LogoTienda({ size = 36 }) {
  const { user } = useAuth();
  const t = user?.tienda;
  if (t?.logo_url) {
    return <img src={t.logo_url} alt="" style={{ width: size, height: size }} className="shrink-0 rounded-[10px] object-contain bg-white" />;
  }
  return (
    <span aria-hidden="true" style={{ width: size, height: size }}
      className="shrink-0 rounded-[10px] bg-primary-600 text-white font-bold flex items-center justify-center text-sm">
      {iniciales(t?.nombre)}
    </span>
  );
}
LogoTienda.propTypes = { size: PropTypes.number };

/** Logo + nombre de la tienda + almacen del usuario */
export default function Marca({ compacta = false }) {
  const { user } = useAuth();
  return (
    <div className="flex min-w-0 items-center gap-2.5">
      <LogoTienda />
      {!compacta && (
        <div className="min-w-0 leading-tight">
          <p className="truncate font-bold text-slate-900">{user?.tienda?.nombre || 'Mi tienda'}</p>
          <p className="truncate text-xs text-slate-600">{user?.nombre}{user?.soporte ? ' · soporte' : ''}</p>
        </div>
      )}
    </div>
  );
}
Marca.propTypes = { compacta: PropTypes.bool };
