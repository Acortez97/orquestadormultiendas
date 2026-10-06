import { NavLink, useNavigate } from 'react-router-dom';
import PropTypes from 'prop-types';
import {
  Squares2X2Icon, BuildingStorefrontIcon, ReceiptPercentIcon, BookmarkIcon,
  ArrowUturnLeftIcon, CalculatorIcon, BanknotesIcon, CubeIcon, UsersIcon,
  TruckIcon, UserGroupIcon, TagIcon, BuildingOffice2Icon, ClipboardDocumentListIcon,
  ArrowsRightLeftIcon, ShoppingCartIcon, BuildingLibraryIcon, CurrencyDollarIcon,
  CreditCardIcon, DocumentTextIcon, ChartBarIcon, Cog6ToothIcon, ShieldCheckIcon,
  XMarkIcon, ChevronLeftIcon, ChevronRightIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';
import clsx from 'clsx';

const NAV = [
  { label: 'Dashboard',       path: '/dashboard',     icon: Squares2X2Icon,            permiso: null },
  { label: 'Ventas',          tipo: 'section' },
  { label: 'Punto de Venta',  path: '/pos',           icon: BuildingStorefrontIcon,    permiso: 'ventas.crear' },
  { label: 'Ventas',          path: '/ventas',        icon: ReceiptPercentIcon,        permiso: 'ventas.ver' },
  { label: 'Apartados',       path: '/apartados',     icon: BookmarkIcon,              permiso: 'apartados.ver' },
  { label: 'Devoluciones',    path: '/devoluciones',  icon: ArrowUturnLeftIcon,        permiso: 'devoluciones.ver' },
  { label: 'Cortes de Tienda',path: '/cortes',        icon: CalculatorIcon,            permiso: 'cortes.ver' },
  { label: 'Mis cuentas x cobrar', path: '/mis-cuentas-cobrar', icon: BanknotesIcon,    permiso: 'clientes.ver' },
  { label: 'Catálogos',       tipo: 'section' },
  { label: 'Artículos',       path: '/articulos',     icon: CubeIcon,                  permiso: 'catalogos.ver' },
  { label: 'Clientes',        path: '/clientes',      icon: UsersIcon,                 permiso: 'clientes.ver' },
  { label: 'Proveedores',     path: '/proveedores',   icon: TruckIcon,                 permiso: 'proveedores.ver' },
  { label: 'Empleados',       path: '/empleados',     icon: UserGroupIcon,             permiso: 'empleados.ver' },
  { label: 'Catálogos base',  path: '/catalogos',     icon: TagIcon,                   permiso: 'catalogos.ver' },
  { label: 'Inventario',      tipo: 'section' },
  { label: 'Almacenes',       path: '/almacenes',     icon: BuildingOffice2Icon,       permiso: 'almacen.ver' },
  { label: 'Existencias',     path: '/inventario',    icon: ClipboardDocumentListIcon, permiso: 'almacen.ver' },
  { label: 'Traspasos',       path: '/traspasos',     icon: ArrowsRightLeftIcon,       permiso: 'traspasos.ver' },
  { label: 'Compras',         path: '/compras',       icon: ShoppingCartIcon,          permiso: 'compras.ver' },
  { label: 'Finanzas',        tipo: 'section' },
  { label: 'Bancos y cajas',  path: '/finanzas/bancos',            icon: BuildingLibraryIcon, permiso: 'finanzas.ver' },
  { label: 'Cuentas x Cobrar',path: '/finanzas/cuentas-cliente',   icon: CurrencyDollarIcon,  permiso: 'finanzas.ver' },
  { label: 'Cuentas x Pagar', path: '/finanzas/cuentas-proveedor', icon: CreditCardIcon,      permiso: 'finanzas.ver' },
  { label: 'Comisiones',      path: '/comisiones',    icon: ReceiptPercentIcon,        permiso: 'comisiones.ver' },
  { label: 'Facturación',     path: '/facturacion',   icon: DocumentTextIcon,          permiso: 'facturacion.ver' },
  { label: 'Reportes',        tipo: 'section' },
  { label: 'Dashboard productos', path: '/productos-dashboard', icon: Squares2X2Icon,     permiso: 'reportes.ver' },
  { label: 'Reportes',        path: '/reportes',      icon: ChartBarIcon,              permiso: 'reportes.ver' },
  { label: 'Mi tienda',       tipo: 'section' },
  { label: 'Configuración',   path: '/config',        icon: Cog6ToothIcon,             permiso: 'configuracion.ver' },
  { label: 'Usuarios',        path: '/usuarios',      icon: UsersIcon,                 permiso: 'usuarios.ver' },
  { label: 'Bitácora',        path: '/bitacora',      icon: ShieldCheckIcon,           permiso: 'bitacora.ver' },
];

function Sidebar({ isOpen, isCollapsed, onClose, onToggleCollapse }) {
  const { hasPermiso } = useAuth();
  const navigate = useNavigate();

  // 1) Filtrar por permisos. 2) Ocultar encabezados de sección que queden sin items.
  const conPermiso = NAV.filter((item) =>
    item.tipo === 'section' || !item.permiso || hasPermiso(item.permiso)
  );
  const visible = conPermiso.filter((item, i) => {
    if (item.tipo !== 'section') return true;
    const next = conPermiso[i + 1];
    return next && next.tipo !== 'section'; // mantener sólo si tiene al menos un item
  });

  return (
    <>
      {/* Desktop sidebar */}
      <aside className={clsx(
        'fixed inset-y-0 left-0 z-50 flex flex-col bg-gradient-to-b from-primary-950 to-primary-900 border-r border-white/10 transition-all duration-300',
        'hidden lg:flex',
        isCollapsed ? 'w-20' : 'w-64'
      )}>
        <SidebarContent
          items={visible}
          isCollapsed={isCollapsed}
          onToggleCollapse={onToggleCollapse}
          navigate={navigate}
        />
      </aside>

      {/* Mobile sidebar */}
      <aside className={clsx(
        'fixed inset-y-0 left-0 z-50 flex flex-col w-64 bg-gradient-to-b from-primary-950 to-primary-900 border-r border-white/10 transition-transform duration-300 lg:hidden',
        isOpen ? 'translate-x-0' : '-translate-x-full'
      )}>
        <div className="flex items-center justify-between p-4 border-b border-white/10">
          <LogoMarca />
          <button onClick={onClose} className="p-1.5 rounded-lg text-primary-200 transition-colors hover:bg-white/10 hover:text-white">
            <XMarkIcon className="w-5 h-5" />
          </button>
        </div>
        <nav className="flex-1 overflow-y-auto p-3 space-y-0.5">
          {visible.map((item, i) => <NavItem key={i} item={item} isCollapsed={false} />)}
        </nav>
      </aside>
    </>
  );
}

function SidebarContent({ items, isCollapsed, onToggleCollapse }) {
  return (
    <>
      <div className={clsx(
        'flex items-center border-b border-white/10 h-16 px-4',
        isCollapsed ? 'justify-center' : 'justify-between'
      )}>
        {!isCollapsed && <LogoMarca />}
        <button onClick={onToggleCollapse} className="p-1.5 rounded-lg text-primary-200 transition-colors hover:bg-white/10 hover:text-white">
          {isCollapsed
            ? <ChevronRightIcon className="w-5 h-5" />
            : <ChevronLeftIcon  className="w-5 h-5" />
          }
        </button>
      </div>
      <nav className="flex-1 overflow-y-auto p-3 space-y-0.5">
        {items.map((item, i) => <NavItem key={i} item={item} isCollapsed={isCollapsed} />)}
      </nav>
    </>
  );
}

function LogoMarca() {
  const { user } = useAuth();
  return (
    <div className="min-w-0">
      <img src={user?.tienda?.logo_url || '/logo-light.svg'} alt="" className="h-7 object-contain" />
      {user?.tienda?.nombre && <p className="mt-1 truncate text-xs font-medium text-primary-200">{user.tienda.nombre}</p>}
    </div>
  );
}

function NavItem({ item, isCollapsed }) {
  if (item.tipo === 'section') {
    if (isCollapsed) return <div className="my-2 border-t border-white/10" />;
    return (
      <p className="px-3 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wider text-primary-300/70">
        {item.label}
      </p>
    );
  }

  const Icon = item.icon;
  return (
    <NavLink
      to={item.path}
      className={({ isActive }) => clsx(
        'group flex items-center gap-3 rounded-lg py-2 text-sm font-medium transition-all duration-150',
        isCollapsed ? 'justify-center px-2' : 'px-3',
        isActive
          ? 'bg-primary-600 text-white shadow-sm shadow-primary-950/40'
          : 'text-primary-100/75 hover:bg-white/10 hover:text-white'
      )}
      title={isCollapsed ? item.label : undefined}
    >
      {({ isActive }) => (
        <>
          <Icon className={clsx(
            'h-5 w-5 shrink-0 transition-colors',
            isActive ? 'text-white' : 'text-primary-300/70 group-hover:text-white'
          )} />
          {!isCollapsed && <span>{item.label}</span>}
        </>
      )}
    </NavLink>
  );
}

Sidebar.propTypes = {
  isOpen: PropTypes.bool.isRequired,
  isCollapsed: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
  onToggleCollapse: PropTypes.func.isRequired,
};

export default Sidebar;
