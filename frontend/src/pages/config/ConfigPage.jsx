import { Cog6ToothIcon, BuildingOffice2Icon, UserCircleIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';

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
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-bold text-slate-800">Configuración</h1>

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
            <Campo label="Email" value={user?.email} />
            <Campo label="Rol" value={user?.rol} />
            <Campo label="Estado" value={user?.is_active === 'No' ? 'Inactivo' : 'Activo'} />
          </div>
        </div>

        {/* Empresa */}
        <div className="card p-6">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-xl bg-primary-50 p-2.5">
              <BuildingOffice2Icon className="w-6 h-6 text-primary-600" />
            </div>
            <h2 className="text-lg font-semibold text-slate-800">Empresa</h2>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Campo label="Empresa" value={user?.empresa?.nombre || user?.empresa?.razon_social} />
            <Campo label="RFC" value={user?.empresa?.rfc} />
          </div>
        </div>
      </div>

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
