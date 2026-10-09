// ============================================================
// Menu de la tienda: un solo lugar para el menu lateral (computadora), el riel (tablet),
// la barra inferior y la hoja «Más» (celular) y el buscador Ctrl+K.
// Cada usuario solo ve lo que sus permisos le dejan usar.
// ============================================================
import {
  House, ScanBarcode, Receipt, PackageCheck, Undo2, Calculator, HandCoins, Tag, Boxes, ArrowLeftRight,
  ShoppingCart, Warehouse, Shapes, Landmark, CircleDollarSign, CreditCard, Percent, FileText, ChartColumn,
  ChartPie, Users, Truck, IdCard, UserCog, Settings, ScrollText,
} from 'lucide-react';

// Iconos Lucide (trazo fino); `corto` = nombre en el riel de tablet y la barra del celular
export const INICIO = { label: 'Inicio', path: '/dashboard', icon: House, permiso: null, corto: 'Inicio', titulo: 'Inicio' };
export const POS = { label: 'Punto de venta', path: '/pos', icon: ScanBarcode, permiso: 'ventas.crear', corto: 'Vender' };

export const GRUPOS = [
  {
    titulo: 'Mostrador',
    items: [
      POS,
      { label: 'Ventas', path: '/ventas', icon: Receipt, permiso: 'ventas.ver', corto: 'Ventas' },
      { label: 'Apartados', path: '/apartados', icon: PackageCheck, permiso: 'apartados.ver' },
      { label: 'Devoluciones y cambios', path: '/devoluciones', icon: Undo2, permiso: 'devoluciones.ver', corto: 'Cambios' },
      { label: 'Corte de caja', path: '/cortes', icon: Calculator, permiso: 'cortes.ver', corto: 'Corte' },
      { label: 'Cobranza', path: '/mis-cuentas-cobrar', icon: HandCoins, permiso: 'clientes.ver' },
    ],
  },
  {
    titulo: 'Inventario',
    items: [
      { label: 'Artículos', path: '/articulos', icon: Tag, permiso: 'catalogos.ver' },
      { label: 'Existencias', path: '/inventario', icon: Boxes, permiso: 'almacen.ver', corto: 'Stock' },
      { label: 'Traspasos', path: '/traspasos', icon: ArrowLeftRight, permiso: 'traspasos.ver' },
      { label: 'Compras', path: '/compras', icon: ShoppingCart, permiso: 'compras.ver' },
      { label: 'Almacenes', path: '/almacenes', icon: Warehouse, permiso: 'almacen.ver' },
      { label: 'Catálogos', path: '/catalogos', icon: Shapes, permiso: 'catalogos.ver' },
    ],
  },
  {
    titulo: 'Finanzas',
    items: [
      { label: 'Bancos y cajas', path: '/finanzas/bancos', icon: Landmark, permiso: 'finanzas.ver', corto: 'Bancos' },
      { label: 'Por cobrar', path: '/finanzas/cuentas-cliente', icon: CircleDollarSign, permiso: 'finanzas.ver' },
      { label: 'Por pagar', path: '/finanzas/cuentas-proveedor', icon: CreditCard, permiso: 'finanzas.ver' },
      { label: 'Comisiones', path: '/comisiones', icon: Percent, permiso: 'comisiones.ver' },
      { label: 'Facturación', path: '/facturacion', icon: FileText, permiso: 'facturacion.ver' },
      { label: 'Reportes', path: '/reportes', icon: ChartColumn, permiso: 'reportes.ver' },
      { label: 'Análisis de productos', path: '/productos-dashboard', icon: ChartPie, permiso: 'reportes.ver', corto: 'Análisis' },
    ],
  },
  {
    titulo: 'Mi negocio',
    items: [
      { label: 'Clientes', path: '/clientes', icon: Users, permiso: 'clientes.ver' },
      { label: 'Proveedores', path: '/proveedores', icon: Truck, permiso: 'proveedores.ver' },
      { label: 'Vendedores y empleados', path: '/empleados', icon: IdCard, permiso: 'empleados.ver', corto: 'Empleados' },
      { label: 'Usuarios y permisos', path: '/usuarios', icon: UserCog, permiso: 'usuarios.ver', corto: 'Usuarios' },
      { label: 'Configuración', path: '/config', icon: Settings, permiso: 'configuracion.ver', corto: 'Ajustes' },
      { label: 'Bitácora', path: '/bitacora', icon: ScrollText, permiso: 'bitacora.ver' },
    ],
  },
];

/** Pantalla (y su grupo) de una ruta, para el encabezado */
export function pantallaDe(pathname) {
  if (pathname.startsWith(INICIO.path)) return { ...INICIO, grupo: null };
  for (const g of GRUPOS) {
    const it = g.items.find((x) => pathname === x.path || pathname.startsWith(`${x.path}/`));
    if (it) return { ...it, grupo: g.titulo };
  }
  return null;
}

/** Grupos con solo las pantallas que el usuario puede abrir (grupos vacios fuera) */
export function gruposVisibles(hasPermiso) {
  return GRUPOS
    .map((g) => ({ ...g, items: g.items.filter((it) => !it.permiso || hasPermiso(it.permiso)) }))
    .filter((g) => g.items.length > 0);
}

/** Todas las pantallas visibles en una lista plana (para el buscador) */
export function pantallasVisibles(hasPermiso) {
  return [INICIO, ...gruposVisibles(hasPermiso).flatMap((g) => g.items.map((it) => ({ ...it, grupo: g.titulo })))];
}

/** Las primeras pantallas visibles de una lista de preferencia (riel de tablet / barra de celular) */
export function principales(hasPermiso, preferencia, cuantas) {
  const todas = pantallasVisibles(hasPermiso);
  const out = [];
  for (const p of preferencia) {
    const it = todas.find((x) => x.path === p);
    if (it && !out.includes(it)) out.push(it);
    if (out.length >= cuantas) break;
  }
  for (const it of todas) {          // completa con lo que haya si faltan
    if (out.length >= cuantas) break;
    if (!out.includes(it)) out.push(it);
  }
  return out;
}

export const PREFERENCIA_RIEL = ['/dashboard', '/pos', '/ventas', '/apartados', '/inventario', '/cortes', '/articulos', '/reportes'];
export const PREFERENCIA_BARRA = ['/dashboard', '/pos', '/ventas', '/apartados', '/inventario', '/articulos', '/reportes'];
