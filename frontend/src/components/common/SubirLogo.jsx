import { useRef, useState } from 'react';
import PropTypes from 'prop-types';
import Swal from '../../utils/swal';
import { ImageUp, Trash2 } from 'lucide-react';
import { archivoALogo, invalidarLogos } from '../../utils/logos';
import { aviso } from '../../utils/avisos';

/**
 * Subir / quitar un logo (PNG, JPG o WEBP). Se ajusta a 600 px en el navegador antes de enviarlo.
 * onSubir(dataUrl) y onQuitar() hacen la llamada al API; src = logo actual (url o data URL).
 */
export default function SubirLogo({ src, onSubir, onQuitar, puede = true, alto = 'h-20' }) {
  const input = useRef(null);
  const [trabajando, setTrabajando] = useState(false);

  const elegir = async (file) => {
    if (!file) return;
    setTrabajando(true);
    try {
      await onSubir(await archivoALogo(file));
      invalidarLogos();
      aviso('Logo actualizado');
    } catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setTrabajando(false); }
  };

  const quitar = async () => {
    const c = await Swal.fire({ title: '¿Quitar el logo?', text: 'Los tickets y reportes saldrán sin él.', icon: 'question', showCancelButton: true, confirmButtonText: 'Quitar' });
    if (!c.isConfirmed) return;
    setTrabajando(true);
    try { await onQuitar(); invalidarLogos(); aviso('Logo quitado'); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setTrabajando(false); }
  };

  return (
    <div className="flex flex-wrap items-center gap-4">
      <div className={`flex ${alto} w-48 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white p-2`}>
        {src ? <img src={src} alt="Logo actual" className="max-h-full max-w-full object-contain" /> : <span className="text-sm text-slate-500">Sin logo</span>}
      </div>
      {puede && (
        <div className="flex flex-wrap gap-2">
          <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="hidden"
            onChange={(e) => { elegir(e.target.files?.[0]); e.target.value = ''; }} />
          <button type="button" className="btn-secondary" disabled={trabajando} onClick={() => input.current?.click()}>
            <ImageUp className="h-5 w-5" />{trabajando ? 'Subiendo…' : src ? 'Cambiar logo' : 'Subir logo'}
          </button>
          {src && <button type="button" className="btn-ghost text-danger-600" disabled={trabajando} onClick={quitar}><Trash2 className="h-5 w-5" />Quitar</button>}
        </div>
      )}
      <p className="w-full text-xs text-slate-600">PNG (de preferencia con fondo transparente), JPG o WEBP. Se ajusta solo a un tamaño ligero.</p>
    </div>
  );
}

SubirLogo.propTypes = {
  src: PropTypes.string,
  onSubir: PropTypes.func.isRequired,
  onQuitar: PropTypes.func.isRequired,
  puede: PropTypes.bool,
  alto: PropTypes.string,
};
