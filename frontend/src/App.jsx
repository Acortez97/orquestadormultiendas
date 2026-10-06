import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuth } from './contexts/AuthContext';

import AppLayout from './components/layout/AppLayout';
import LoginPage from './pages/auth/LoginPage';
import DashboardPage from './pages/dashboard/DashboardPage';
import ProductosDashboardPage from './pages/dashboard/ProductosDashboardPage';

// Ventas
import POSPage from './pages/pos/POSPage';
import VentasPage from './pages/ventas/VentasPage';
import ApartadosPage from './pages/apartados/ApartadosPage';
import DevolucionesPage from './pages/devoluciones/DevolucionesPage';
import CortesPage from './pages/cortes/CortesPage';

// Catálogos
import ArticulosPage from './pages/articulos/ArticulosPage';
import ClientesPage from './pages/clientes/ClientesPage';
import ProveedoresPage from './pages/proveedores/ProveedoresPage';
import EmpleadosPage from './pages/empleados/EmpleadosPage';
import CatalogosPage from './pages/catalogos/CatalogosPage';

// Inventario
import AlmacenesPage from './pages/almacenes/AlmacenesPage';
import InventarioPage from './pages/inventario/InventarioPage';
import TraspasosPage from './pages/traspasos/TraspasosPage';
import ComprasPage from './pages/compras/ComprasPage';

// Finanzas
import BancosPage from './pages/finanzas/BancosPage';
import CuentasClientePage from './pages/finanzas/CuentasClientePage';
import CuentasProveedorPage from './pages/finanzas/CuentasProveedorPage';
import ComisionesPage from './pages/comisiones/ComisionesPage';
import FacturacionPage from './pages/facturacion/FacturacionPage';

// Reportes
import ReportesPage from './pages/reportes/ReportesPage';

// Admin
import ConfigPage from './pages/config/ConfigPage';
import UsuariosPage from './pages/usuarios/UsuariosPage';
import BitacoraPage from './pages/bitacora/BitacoraPage';

function ProtectedRoute({ children, permiso }) {
  const { isAuthenticated, loading, hasPermiso } = useAuth();
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600" />
      </div>
    );
  }
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (permiso && !hasPermiso(permiso)) return <Navigate to="/dashboard" replace />;
  return children;
}

function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route path="/" element={<ProtectedRoute><AppLayout /></ProtectedRoute>}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard" element={<DashboardPage />} />
        <Route path="productos-dashboard" element={<ProductosDashboardPage />} />

        {/* Ventas */}
        <Route path="pos" element={<ProtectedRoute permiso="ventas.vender"><POSPage /></ProtectedRoute>} />
        <Route path="ventas" element={<ProtectedRoute permiso="ventas.ver"><VentasPage /></ProtectedRoute>} />
        <Route path="apartados" element={<ProtectedRoute permiso="apartados.ver"><ApartadosPage /></ProtectedRoute>} />
        <Route path="devoluciones" element={<ProtectedRoute permiso="devoluciones.ver"><DevolucionesPage /></ProtectedRoute>} />
        <Route path="cortes" element={<ProtectedRoute permiso="cortes.ver"><CortesPage /></ProtectedRoute>} />
        {/* Reporte de CxC para tiendas (solo sus clientes; el backend lo filtra por tienda) */}
        <Route path="mis-cuentas-cobrar" element={<ProtectedRoute permiso="clientes.ver"><CuentasClientePage /></ProtectedRoute>} />

        {/* Catálogos */}
        <Route path="articulos" element={<ProtectedRoute permiso="catalogos.ver"><ArticulosPage /></ProtectedRoute>} />
        <Route path="clientes" element={<ProtectedRoute permiso="clientes.ver"><ClientesPage /></ProtectedRoute>} />
        <Route path="proveedores" element={<ProtectedRoute permiso="catalogos.ver"><ProveedoresPage /></ProtectedRoute>} />
        <Route path="empleados" element={<ProtectedRoute permiso="catalogos.ver"><EmpleadosPage /></ProtectedRoute>} />
        <Route path="catalogos" element={<ProtectedRoute permiso="catalogos.ver"><CatalogosPage /></ProtectedRoute>} />

        {/* Inventario */}
        <Route path="almacenes" element={<ProtectedRoute permiso="almacen.ver"><AlmacenesPage /></ProtectedRoute>} />
        <Route path="inventario" element={<ProtectedRoute permiso="almacen.ver"><InventarioPage /></ProtectedRoute>} />
        <Route path="traspasos" element={<ProtectedRoute permiso="traspasos.ver"><TraspasosPage /></ProtectedRoute>} />
        <Route path="compras" element={<ProtectedRoute permiso="compras.ver"><ComprasPage /></ProtectedRoute>} />

        {/* Finanzas */}
        <Route path="finanzas/bancos" element={<ProtectedRoute permiso="finanzas.ver"><BancosPage /></ProtectedRoute>} />
        <Route path="finanzas/cuentas-cliente" element={<ProtectedRoute permiso="finanzas.ver"><CuentasClientePage /></ProtectedRoute>} />
        <Route path="finanzas/cuentas-proveedor" element={<ProtectedRoute permiso="finanzas.ver"><CuentasProveedorPage /></ProtectedRoute>} />
        <Route path="comisiones" element={<ProtectedRoute permiso="comisiones.ver"><ComisionesPage /></ProtectedRoute>} />
        <Route path="facturacion" element={<ProtectedRoute permiso="facturacion.ver"><FacturacionPage /></ProtectedRoute>} />

        {/* Reportes */}
        <Route path="reportes" element={<ProtectedRoute permiso="reportes.ver"><ReportesPage /></ProtectedRoute>} />

        {/* Admin */}
        <Route path="config" element={<ProtectedRoute permiso="config_sistema.ver"><ConfigPage /></ProtectedRoute>} />
        <Route path="admin/usuarios" element={<ProtectedRoute permiso="admin"><UsuariosPage /></ProtectedRoute>} />
        <Route path="admin/bitacora" element={<ProtectedRoute permiso="admin"><BitacoraPage /></ProtectedRoute>} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
