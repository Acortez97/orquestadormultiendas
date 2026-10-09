import { useEffect, useState } from 'react';
import PropTypes from 'prop-types';
import { XMarkIcon } from '@heroicons/react/24/outline';
import Modal from './Modal';

const ANCHO = '(min-width: 1024px)';

/** true en pantallas de computadora (>= 1024 px) */
export function useEsAncho() {
  const [ancho, setAncho] = useState(() => typeof window !== 'undefined' && window.matchMedia(ANCHO).matches);
  useEffect(() => {
    const mq = window.matchMedia(ANCHO);
    const cambio = () => setAncho(mq.matches);
    mq.addEventListener('change', cambio);
    return () => mq.removeEventListener('change', cambio);
  }, []);
  return ancho;
}

/**
 * Detalle de un registro sin perder la lista:
 *  - computadora: panel a un lado de la lista (va dentro de un contenedor flex junto a la tabla)
 *  - celular y tablet: hoja / ventana sobre la pantalla
 */
export default function PanelDetalle({ open, onClose, title, children, footer }) {
  const ancho = useEsAncho();
  if (!ancho) return <Modal open={open} onClose={onClose} title={title} footer={footer} size="lg">{children}</Modal>;
  if (!open) return null;
  return (
    <aside aria-label={title} className="card sticky top-20 flex max-h-[calc(100dvh-6rem)] w-[420px] shrink-0 flex-col animate-fade-in">
      <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
        <h2 className="text-lg font-bold">{title}</h2>
        <button type="button" onClick={onClose} className="btn-ghost w-10 px-0" aria-label="Cerrar detalle"><XMarkIcon className="h-5 w-5" /></button>
      </div>
      <div className="overflow-y-auto p-5">{children}</div>
      {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-5 py-3.5">{footer}</div>}
    </aside>
  );
}

PanelDetalle.propTypes = {
  open: PropTypes.bool,
  onClose: PropTypes.func.isRequired,
  title: PropTypes.string,
  children: PropTypes.node,
  footer: PropTypes.node,
};
