import { useState } from 'react';
import Swal from '../../utils/swal';
import { Cog6ToothIcon, BuildingOffice2Icon, UserCircleIcon, SwatchIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import { configApi } from '../../services/api/endpoints';
import SelectorColor, { colorLegible } from '../../components/common/SelectorColor';
import { aviso } from '../../utils/avisos';
import SubirLogo from '../../components/common/SubirLogo';

/** Color de la tienda en toda la interfaz (lo ven todos sus usuarios) */
function Apariencia() {
  const { user, hasPermiso, refreshUser } = useAuth();
  const [color, setColor] = useState(user?.tienda?.color || '');
  const [guardando, setGuardando] = useState(false);
  const puede = hasPermiso('configuracion.editar');
  const cambio = (color || '') !== (user?.tienda?.color || '');
  const guardar = async () => {
    setGuardando(true);
    try { await configApi.cambiarColor(color || ''); await refreshUser(); aviso('Color de la tienda actualizado'); }
    catch (e) { Swal.fire('Error', e.message, 'error'); }
    finally { setGuardando(false); }
  };
  return (
    <div className="card p-5 sm:p-6">
      <div className="mb-4 flex items-center gap-3">
        <div className="rounded-xl bg-primary-50 p-2.5"><SwatchIcon className="h-6 w-6 text-primary-600" /></div>
        <div>
          <h2 className="text-lg font-bold">Logo y color de la tienda</h2>
          <p className="text-sm text-slate-600">Tu marca en el sistema, los tickets y los reportes. Lo ven todos tus usuarios.</p>
        </div>
      </div>
      <div className="mb-6">
        <h3 className="mb-1 font-semibold">Logo de la tienda</h3>
        <p className="mb-3 text-sm text-slate-600">Sale en el menú, en los tickets, en el corte de caja y en los reportes que exportes a PDF.</p>
        <SubirLogo src={user?.tienda?.logo_url || null} puede={puede}
          onSubir={async (dataUrl) => { await configApi.subirLogo(dataUrl); await refreshUser(); }}
          onQuitar={async () => { await configApi.quitarLogo(); await refreshUser(); }} />
      </div>
      <h3 className="mb-1 font-semibold">Color</h3>
      {puede ? (
        <div className="space-y-4">
          <SelectorColor value={color} onChange={setColor} />
          <button type="button" className="btn-primary" disabled={!cambio || guardando || (color && !colorLegible(color))} onClick={guardar}>
            {guardando ? 'Guardando…' : 'Guardar color'}
          </button>
        </div>
      ) : <p className="text-sm text-slate-600">Solo quien puede editar la configuración cambia el color.</p>}
    </div>
  );
}

function Campo({ label, value }) {
  return (
    <div className="flex flex-col">
      <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">{label}</span>
      <span className="text-sm text-slate-800">{value || '—'}</span>
    </div>
  );
}

export default function ConfigPage() {
  const { user } = useAuth();
  const nombreCompleto = user ? `${user.nombre || ''} ${user.apellido || ''}`.trim() : '—';

  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Configuración</h1>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Usuario actual */}
        <div className="card p-6">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-xl bg-primary-50 p-2.5">
              <UserCircleIcon className="w-6 h-6 text-primary-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Mi cuenta</h2>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Campo label="Nombre" value={nombreCompleto} />
            <Campo label="Correo de acceso" value={user?.login} />
            <Campo label="Rol" value={user?.rol === 'admin_tienda' ? 'Administrador de la tienda' : 'Usuario'} />
            <Campo label="Estado" value={user?.is_active === 'No' ? 'Inactivo' : 'Activo'} />
          </div>
        </div>

        {/* Empresa */}
        <div className="card p-6">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-xl bg-primary-50 p-2.5">
              <BuildingOffice2Icon className="w-6 h-6 text-primary-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Tienda</h2>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Campo label="Tienda" value={user?.tienda?.nombre} />
            <Campo label="Subdominio de acceso" value={user?.tienda?.slug} />
          </div>
        </div>
      </div>

      <Apariencia />

      {/* Configuración del sistema (placeholder) */}
      <div className="card p-6">
        <div className="flex items-center gap-3 mb-3">
          <div className="rounded-xl bg-slate-100 p-2.5">
            <Cog6ToothIcon className="w-6 h-6 text-slate-600" />
          </div>
          <h2 className="text-lg font-semibold text-slate-800">Configuración del sistema</h2>
        </div>
        <p className="text-sm text-slate-600">
          Aquí se gestionarán los parámetros generales del sistema (folios, impuestos, listas de
          precios, integraciones). Esta sección está en construcción.
        </p>
        <p className="mt-3">
          <span className="badge-slate">Próximamente</span>
        </p>
      </div>
    </div>
  );
}
