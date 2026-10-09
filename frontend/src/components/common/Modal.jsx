import { useEffect } from 'react';
import PropTypes from 'prop-types';
import { XMarkIcon } from '@heroicons/react/24/outline';

/**
 * Ventana sobre la pantalla. size: sm | md | lg | xl
 * En celular sale como hoja desde abajo (ocupa el ancho y se alcanza con el pulgar); Esc la cierra.
 */
export default function Modal({ open, onClose, title, children, footer, size = 'md' }) {
  useEffect(() => {
    if (!open) return undefined;
    const esc = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', esc);
    return () => document.removeEventListener('keydown', esc);
  }, [open, onClose]);

  if (!open) return null;
  const sizes = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' };
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
      <div className="absolute inset-0 bg-slate-900/45 animate-fade-in" onClick={onClose} />
      <div role="dialog" aria-modal="true" aria-label={title}
        className={`relative flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-white shadow-xl animate-slide-in-up sm:max-h-[90vh] sm:rounded-2xl ${sizes[size] || sizes.md}`}>
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
          <h3 className="text-lg font-bold text-slate-900">{title}</h3>
          <button type="button" onClick={onClose} className="btn-ghost w-11 px-0" aria-label="Cerrar">
            <XMarkIcon className="h-5 w-5 text-slate-600" />
          </button>
        </div>
        <div className="overflow-y-auto p-5">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-5 py-3.5 pb-safe">{footer}</div>}
      </div>
    </div>
  );
}

Modal.propTypes = {
  open: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
  title: PropTypes.string,
  children: PropTypes.node,
  footer: PropTypes.node,
  size: PropTypes.string,
};
