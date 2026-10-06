import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import PropTypes from 'prop-types';
import { useAuth } from './contexts/AuthContext';

import AppLayout from './components/layout/AppLayout';
import LoginPage from './pages/auth/LoginPage';

// Cada pagina se descarga solo cuando se usa (el panel /admin nunca se descarga en una tienda)
const CambiarPasswordPage   = lazy(() => import('./pages/auth/CambiarPasswordPage'));
const AdminApp              = lazy(() => import('./pages/admin/AdminApp'));
const DashboardPage         = lazy(() => import('./pages/dashboard/DashboardPage'));
const ProductosDashboardPage = lazy(() => import('./pages/dashboard/ProductosDashboardPage'));
const POSPage               = lazy(() => import('./pages/pos/POSPage'));
const VentasPage            = lazy(() => import('./pages/ventas/VentasPage'));
const ApartadosPage         = lazy(() => import('./pages/apartados/ApartadosPage'));
const DevolucionesPage      = lazy(() => import('./pages/devoluciones/DevolucionesPage'));
const CortesPage            = lazy(() => import('./pages/cortes/CortesPage'));
const ArticulosPage         = lazy(() => import('./pages/articulos/ArticulosPage'));
const ClientesPage          = lazy(() => import('./pages/clientes/ClientesPage'));
const ProveedoresPage       = lazy(() => import('./pages/proveedores/ProveedoresPage'));
const EmpleadosPage         = lazy(() => import('./pages/empleados/EmpleadosPage'));
const CatalogosPage         = lazy(() => import('./pages/catalogos/CatalogosPage'));
const AlmacenesPage         = lazy(() => import('./pages/almacenes/AlmacenesPage'));
const InventarioPage        = lazy(() => import('./pages/inventario/InventarioPage'));
const TraspasosPage         = lazy(() => import('./pages/traspasos/TraspasosPage'));
const ComprasPage           = lazy(() => import('./pages/compras/ComprasPage'));
const BancosPage            = lazy(() => import('./pages/finanzas/BancosPage'));
const CuentasClientePage    = lazy(() => import('./pages/finanzas/CuentasClientePage'));
const CuentasProveedorPage  = lazy(() => import('./pages/finanzas/CuentasProveedorPage'));
const ComisionesPage        = lazy(() => import('./pages/comisiones/ComisionesPage'));
const FacturacionPage       = lazy(() => import('./pages/facturacion/FacturacionPage'));
const ReportesPage          = lazy(() => import('./pages/reportes/ReportesPage'));
const ConfigPage            = lazy(() => import('./pages/config/ConfigPage'));
const UsuariosPage          = lazy(() => import('./pages/usuarios/UsuariosPage'));
const BitacoraPage          = lazy(() => import('./pages/bitacora/BitacoraPage'));

function Cargando() {
  return (
    <div className="min-h-[40vh] flex items-center justify-center">
      <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600" />
    </div>
  );
}

/**
 * Guardia de rutas.
 *  - zona 'tienda': usuarios de tienda (o superadmin en modo soporte)
 *  - zona 'admin' : solo superadmin (sin soporte)
 *  - 'sesion'     : cualquiera con sesion (p. ej. cambiar contraseña)
 * Usuarios con contraseña temporal van primero a cambiarla.
 */
function Protegida({ children, permiso, zona = 'tienda' }) {
  const { isAuthenticated, loading, hasPermiso, user, esSuperadmin } = useAuth();
  if (loading) return <Cargando />;
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (zona !== 'sesion' && user?.debe_cambiar_password && !user?.soporte) return <Navigate to="/cambiar-password" replace />;
  if (zona === 'admin' && !esSuperadmin) return <Navigate to="/dashboard" replace />;
  if (zona === 'tienda' && esSuperadmin) return <Navigate to="/admin" replace />;
  if (permiso && !hasPermiso(permiso)) return <Navigate to="/dashboard" replace />;
  return <Suspense fallback={<Cargando />}>{children}</Suspense>;
}
Protegida.propTypes = { children: PropTypes.node.isRequired, permiso: PropTypes.string, zona: PropTypes.string };

const P = (permiso, el) => <Protegida permiso={permiso}>{el}</Protegida>;

function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/cambiar-password" element={<Protegida zona="sesion"><CambiarPasswordPage /></Protegida>} />

      {/* Panel de administracion general (superadmin) */}
      <Route path="/admin/*" element={<Protegida zona="admin"><AdminApp /></Protegida>} />

      {/* Panel de tienda */}
      <Route path="/" element={<Protegida><AppLayout /></Protegida>}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard" element={P(null, <DashboardPage />)} />
        <Route path="productos-dashboard" element={P('reportes.ver', <ProductosDashboardPage />)} />

        {/* Ventas */}
        <Route path="pos" element={P('ventas.crear', <POSPage />)} />
        <Route path="ventas" element={P('ventas.ver', <VentasPage />)} />
        <Route path="apartados" element={P('apartados.ver', <ApartadosPage />)} />
        <Route path="devoluciones" element={P('devoluciones.ver', <DevolucionesPage />)} />
        <Route path="cortes" element={P('cortes.ver', <CortesPage />)} />
        <Route path="mis-cuentas-cobrar" element={P('clientes.ver', <CuentasClientePage />)} />

        {/* Catálogos */}
        <Route path="articulos" element={P('catalogos.ver', <ArticulosPage />)} />
        <Route path="clientes" element={P('clientes.ver', <ClientesPage />)} />
        <Route path="proveedores" element={P('proveedores.ver', <ProveedoresPage />)} />
        <Route path="empleados" element={P('empleados.ver', <EmpleadosPage />)} />
        <Route path="catalogos" element={P('catalogos.ver', <CatalogosPage />)} />

        {/* Inventario */}
        <Route path="almacenes" element={P('almacen.ver', <AlmacenesPage />)} />
        <Route path="inventario" element={P('almacen.ver', <InventarioPage />)} />
        <Route path="traspasos" element={P('traspasos.ver', <TraspasosPage />)} />
        <Route path="compras" element={P('compras.ver', <ComprasPage />)} />

        {/* Finanzas */}
        <Route path="finanzas/bancos" element={P('finanzas.ver', <BancosPage />)} />
        <Route path="finanzas/cuentas-cliente" element={P('finanzas.ver', <CuentasClientePage />)} />
        <Route path="finanzas/cuentas-proveedor" element={P('finanzas.ver', <CuentasProveedorPage />)} />
        <Route path="comisiones" element={P('comisiones.ver', <ComisionesPage />)} />
        <Route path="facturacion" element={P('facturacion.ver', <FacturacionPage />)} />

        {/* Reportes */}
        <Route path="reportes" element={P('reportes.ver', <ReportesPage />)} />

        {/* Mi tienda */}
        <Route path="config" element={P('configuracion.ver', <ConfigPage />)} />
        <Route path="usuarios" element={P('usuarios.ver', <UsuariosPage />)} />
        <Route path="bitacora" element={P('bitacora.ver', <BitacoraPage />)} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
