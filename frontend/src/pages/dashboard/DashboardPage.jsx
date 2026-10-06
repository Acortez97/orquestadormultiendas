import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  BuildingStorefrontIcon, CubeIcon, UsersIcon, ReceiptPercentIcon,
  ArrowsRightLeftIcon, ArchiveBoxIcon, ClipboardDocumentListIcon, BookOpenIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import { ventasApi } from '../../services/api/endpoints';

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);

const ACCESOS = [
  { to: '/pos', label: 'Punto de Venta', icon: BuildingStorefrontIcon, permiso: 'ventas.crear' },
  { to: '/articulos', label: 'Artículos', icon: CubeIcon, permiso: 'catalogos.ver' },
  { to: '/clientes', label: 'Clientes', icon: UsersIcon, permiso: 'clientes.ver' },
  { to: '/ventas', label: 'Ventas', icon: ReceiptPercentIcon, permiso: 'ventas.ver' },
  { to: '/traspasos', label: 'Traspasos', icon: ArrowsRightLeftIcon, permiso: 'traspasos.ver' },
  { to: '/compras', label: 'Compras', icon: ArchiveBoxIcon, permiso: 'compras.ver' },
  { to: '/inventario', label: 'Inventario', icon: ClipboardDocumentListIcon, permiso: 'almacen.ver' },
  { to: '/cortes', label: 'Cortes', icon: BookOpenIcon, permiso: 'cortes.ver' },
];

export default function DashboardPage() {
  const { user, hasPermiso } = useAuth();
  const [hoy, setHoy] = useState({ count: 0, total: 0 });
  const verVentas = hasPermiso('ventas.ver');
  const accesos = ACCESOS.filter((a) => hasPermiso(a.permiso));

  useEffect(() => {
    if (!verVentas) return;
    const desde = new Date(); desde.setHours(0, 0, 0, 0);
    ventasApi.listar({ desde: desde.toISOString() }).then((r) => {
      const ventas = (r.data || []).filter((v) => v.estado === 'completada');
      setHoy({ count: ventas.length, total: ventas.reduce((a, v) => a + (v.total || 0), 0) });
    }).catch(() => {});
  }, [verVentas]);

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-800">Hola, {user?.nombre || 'usuario'} 👋</h1>
        <p className="text-slate-500">{user?.tienda?.nombre || ''}</p>
      </div>

      {verVentas && <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="card p-5">
          <p className="text-xs uppercase font-semibold text-slate-400">Ventas hoy</p>
          <p className="text-3xl font-bold text-primary-700">{hoy.count}</p>
        </div>
        <div className="card p-5">
          <p className="text-xs uppercase font-semibold text-slate-400">Total vendido hoy</p>
          <p className="text-3xl font-bold text-emerald-600">{money(hoy.total)}</p>
        </div>
      </div>}

      <div>
        <h2 className="text-sm font-semibold text-slate-500 uppercase mb-2">Accesos rápidos</h2>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {accesos.map((a) => (
            <Link key={a.to} to={a.to} className="card p-4 flex flex-col items-center gap-2 hover:shadow-md transition-shadow">
              <a.icon className="w-8 h-8 text-primary-600" />
              <span className="text-sm font-medium text-slate-700">{a.label}</span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}
