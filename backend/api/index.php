<?php
// ============================================================
// Orquestador MultiTiendas API — Front controller
// Responde bajo /api/v1/...  (mismo dominio que el frontend)
//
// Flujo de cada peticion:
//   1) ruta -> permiso declarado (sin permiso declarado = denegado)
//   2) JWT -> usuario recargado de la BD (activo + token_version)
//   3) tienda activa: la del usuario, o la de "entrar como tienda" del superadmin
//   4) ids opacos de ruta/query/body -> ids internos (Tenant)
//   5) permiso efectivo (modulos de la tienda ∩ permisos del usuario)
// ============================================================

error_reporting(E_ALL);

$cfg = require __DIR__ . '/lib/config.php';
ini_set('display_errors', $cfg['debug'] ? '1' : '0');

require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Jwt.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Tenant.php';
require __DIR__ . '/lib/LoginId.php';
require __DIR__ . '/lib/Permisos.php';
require __DIR__ . '/lib/Pricing.php';
require __DIR__ . '/lib/Ledger.php';
require __DIR__ . '/lib/Seeder.php';
require __DIR__ . '/lib/Usuarios.php';
require __DIR__ . '/lib/Variantes.php';
require __DIR__ . '/lib/Cobros.php';
require __DIR__ . '/lib/Logos.php';

// ---- CORS ----
$origin = $cfg['cors_origin'];
if ($origin === '*') {
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($reqOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $reqOrigin);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
} elseif ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Correlation-ID');
header('Access-Control-Allow-Credentials: true');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Configuracion minima de seguridad ----
if (strlen((string) $cfg['jwt_secret']) < 32) {
    Http::fail('Servidor sin configurar: falta jwt_secret (ver lib/config.local.example.php)', 500, 'CONFIG_ERROR');
}
Tenant::configurar($cfg);

// ---- Conexion BD ----
try {
    Db::init($cfg['db']);
} catch (Throwable $e) {
    Http::fail($cfg['debug'] ? ('DB: ' . $e->getMessage()) : 'Error de conexion a la base de datos', 500, 'DB_ERROR');
}

// ---- Resolver ruta (todo lo que va despues de /v1) ----
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = trim($path, '/');
$segs = $path === '' ? [] : explode('/', $path);
$iV1  = array_search('v1', $segs, true);
if ($iV1 !== false) {
    $segs = array_slice($segs, $iV1 + 1);
} else {
    $iApi = array_search('api', $segs, true);
    if ($iApi !== false) $segs = array_slice($segs, $iApi + 1);
}
$route  = '/' . implode('/', $segs);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- Cargar controladores ----
foreach (glob(__DIR__ . '/lib/controllers/*.php') as $f) require $f;

// ---- Tabla de rutas: [metodo, patron, handler, permiso] ----
// Permiso:
//   'publico'          sin sesion
//   'sesion'           cualquier usuario autenticado (tienda o superadmin)
//   'tienda'           cualquier usuario de la tienda (o superadmin entrando como tienda)
//   'modulo.accion'    permiso efectivo; un array = basta cualquiera de ellos
//   'admin:modulo'     solo admin_tienda (o superadmin entrando como tienda) y con el modulo activo
//   'plataforma'       solo superadmin (panel de administracion general)
// :x = parametro (ids opacos); {tipo} = comodin de catalogo
$INV_VER = ['almacen.ver', 'ventas.crear', 'apartados.crear', 'traspasos.crear', 'compras.crear', 'devoluciones.crear'];
// clientes (datos personales y saldos) y empleados (contacto y % de comision): solo pantallas que los usan
$CLI_VER = ['clientes.ver', 'ventas.ver', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'devoluciones.ver', 'devoluciones.crear',
            'finanzas.ver', 'reportes.ver', 'cortes.ver'];
$EMP_VER = ['empleados.ver', 'ventas.crear', 'apartados.ver', 'apartados.crear', 'comisiones.ver', 'reportes.ver',
            'clientes.crear', 'clientes.editar'];
$routes = [
    // Auth / sesion
    ['POST',   '/auth/login',                        'AuthController::login',          'publico'],
    ['GET',    '/auth/me',                           'AuthController::me',             'sesion'],
    ['POST',   '/auth/change-password',              'AuthController::changePassword', 'sesion'],
    // Usuarios de la tienda (los administra el admin_tienda)
    ['GET',    '/auth/modulos-tienda',               'AuthController::modulosTienda',  'admin:usuarios'],
    ['GET',    '/auth/usuarios',                     'AuthController::listar',         'admin:usuarios'],
    ['POST',   '/auth/usuarios',                     'AuthController::crear',          'admin:usuarios'],
    ['PUT',    '/auth/usuarios/:id',                 'AuthController::actualizar',     'admin:usuarios'],
    ['DELETE', '/auth/usuarios/:id',                 'AuthController::desactivar',     'admin:usuarios'],
    ['POST',   '/auth/usuarios/:id/reset-password',  'AuthController::resetPassword',  'admin:usuarios'],

    // Catalogos simples
    ['GET',    '/catalogos/{tipo}',           'CatalogoController::listar',     'tienda'],
    ['POST',   '/catalogos/{tipo}',           'CatalogoController::crear',      'catalogos.crear'],
    ['GET',    '/catalogos/{tipo}/:id',       'CatalogoController::obtener',    'tienda'],
    ['PUT',    '/catalogos/{tipo}/:id',       'CatalogoController::actualizar', 'catalogos.editar'],
    ['DELETE', '/catalogos/{tipo}/:id',       'CatalogoController::eliminar',   'catalogos.eliminar'],

    // Atributos de variante
    ['GET',    '/atributos',                  'AtributoController::listar',         'tienda'],
    ['POST',   '/atributos',                  'AtributoController::crear',          'catalogos.crear'],
    ['GET',    '/atributos/:id/valores',      'AtributoController::listarValores',  'tienda'],
    ['POST',   '/atributos/:id/valores',      'AtributoController::crearValor',     'catalogos.crear'],
    ['PUT',    '/atributos/valores/:id',      'AtributoController::actualizarValor','catalogos.editar'],
    ['DELETE', '/atributos/valores/:id',      'AtributoController::eliminarValor',  'catalogos.eliminar'],
    ['PUT',    '/atributos/:id',              'AtributoController::actualizar',     'catalogos.editar'],
    ['DELETE', '/atributos/:id',              'AtributoController::eliminar',       'catalogos.eliminar'],

    // Categorias
    ['GET',    '/categorias',                 'CategoriaController::listar',     'tienda'],
    ['POST',   '/categorias',                 'CategoriaController::crear',      'catalogos.crear'],
    ['GET',    '/categorias/:id',             'CategoriaController::obtener',    'tienda'],
    ['PUT',    '/categorias/:id',             'CategoriaController::actualizar', 'catalogos.editar'],
    ['DELETE', '/categorias/:id',             'CategoriaController::eliminar',   'catalogos.eliminar'],

    // Articulos
    ['GET',    '/articulos',                  'ArticuloController::listar',     'tienda'],
    ['GET',    '/articulos/buscar',           'ArticuloController::buscar',     'tienda'],
    ['GET',    '/articulos/scan',             'ArticuloController::scan',       'tienda'],
    ['POST',   '/articulos/foto',             'ArticuloController::subirFoto',  ['catalogos.crear', 'catalogos.editar']],
    ['GET',    '/articulos/:id/variantes',    'ArticuloController::variantes',  'tienda'],
    ['GET',    '/articulos/:id',              'ArticuloController::obtener',    'tienda'],
    ['POST',   '/articulos',                  'ArticuloController::crear',      'catalogos.crear'],
    ['PUT',    '/articulos/:id',              'ArticuloController::actualizar', 'catalogos.editar'],
    ['DELETE', '/articulos/:id',              'ArticuloController::eliminar',   'catalogos.eliminar'],

    // Clientes
    ['GET',    '/clientes',                   'ClienteController::listar',           $CLI_VER],
    ['GET',    '/clientes/:id',               'ClienteController::obtener',          $CLI_VER],
    ['POST',   '/clientes',                   'ClienteController::crear',            'clientes.crear'],
    ['PUT',    '/clientes/:id',               'ClienteController::actualizar',       'clientes.editar'],
    ['PATCH',  '/clientes/:id/autorizar-credito', 'ClienteController::autorizarCredito', 'clientes.autorizar_credito'],
    ['DELETE', '/clientes/:id',               'ClienteController::eliminar',         'clientes.eliminar'],

    // Empleados
    ['GET',    '/empleados',                  'EmpleadoController::listar',     $EMP_VER],
    ['POST',   '/empleados',                  'EmpleadoController::crear',      'empleados.crear'],
    ['PUT',    '/empleados/:id',              'EmpleadoController::actualizar', 'empleados.editar'],
    ['DELETE', '/empleados/:id',              'EmpleadoController::eliminar',   'empleados.eliminar'],

    // Proveedores
    ['GET',    '/proveedores',                'ProveedorController::listar',     ['proveedores.ver', 'compras.ver', 'finanzas.ver']],
    ['POST',   '/proveedores',                'ProveedorController::crear',      'proveedores.crear'],
    ['PUT',    '/proveedores/:id',            'ProveedorController::actualizar', 'proveedores.editar'],
    ['DELETE', '/proveedores/:id',            'ProveedorController::eliminar',   'proveedores.eliminar'],

    // Almacenes
    ['GET',    '/almacen/almacenes',          'AlmacenController::listar',     'tienda'],
    ['POST',   '/almacen/almacenes',          'AlmacenController::crear',      'almacen.crear'],
    ['PUT',    '/almacen/almacenes/:id',      'AlmacenController::actualizar', 'almacen.editar'],
    ['DELETE', '/almacen/almacenes/:id',      'AlmacenController::eliminar',   'almacen.eliminar'],

    // Inventario
    ['GET',    '/almacen/inventario',             'InventarioController::existencias', $INV_VER],
    ['GET',    '/almacen/inventario/kardex',      'InventarioController::kardex',      'almacen.ver'],
    ['POST',   '/almacen/inventario/ajuste',      'InventarioController::ajuste',      'almacen.ajustar'],
    ['POST',   '/almacen/inventario/ajuste-lote', 'InventarioController::ajusteLote',  'almacen.ajustar'],

    // Traspasos
    ['GET',    '/almacen/traspasos',              'TraspasoController::listar',     'traspasos.ver'],
    ['POST',   '/almacen/traspasos',              'TraspasoController::crear',      'traspasos.crear'],
    ['GET',    '/almacen/traspasos/:id',          'TraspasoController::obtener',    'traspasos.ver'],
    ['PUT',    '/almacen/traspasos/:id',          'TraspasoController::actualizar', 'traspasos.editar'],
    ['PATCH',  '/almacen/traspasos/:id/aceptar',  'TraspasoController::aceptar',    'traspasos.aprobar'],
    ['PATCH',  '/almacen/traspasos/:id/rechazar', 'TraspasoController::rechazar',   'traspasos.aprobar'],

    // Ventas (POS)
    ['GET',    '/ventas',                     'VentaController::listar',   ['ventas.ver', 'cortes.ver', 'devoluciones.ver']],
    ['POST',   '/ventas/cotizar',             'VentaController::cotizar',  ['ventas.crear', 'apartados.crear', 'devoluciones.crear']],
    ['POST',   '/ventas',                     'VentaController::crear',    'ventas.crear'],
    ['GET',    '/ventas/:id',                 'VentaController::obtener',  ['ventas.ver', 'cortes.ver', 'devoluciones.ver']],
    ['PATCH',  '/ventas/:id/cancelar',        'VentaController::cancelar', 'ventas.cancelar'],

    // Compras
    ['GET',    '/compras',                    'CompraController::listar',        'compras.ver'],
    ['POST',   '/compras',                    'CompraController::crear',         'compras.crear'],
    ['GET',    '/compras/:id',                'CompraController::obtener',       'compras.ver'],
    ['PUT',    '/compras/:id',                'CompraController::actualizar',    'compras.editar'],
    ['PATCH',  '/compras/:id/aprobar',        'CompraController::aprobar',       'compras.aprobar'],
    ['DELETE', '/compras/:id',                'CompraController::eliminar',      'compras.eliminar'],
    ['GET',    '/compras/:id/pagos',          'CompraController::pagos',         ['compras.ver', 'finanzas.ver']],
    ['POST',   '/compras/:id/pagos',          'CompraController::registrarPago', ['compras.pagar', 'finanzas.crear']],

    // Finanzas
    ['GET',    '/finanzas/bancos',                           'FinanzasController::bancosListar',   ['finanzas.ver', 'ventas.crear', 'apartados.crear', 'devoluciones.crear', 'compras.pagar', 'clientes.ver']],
    ['POST',   '/finanzas/bancos',                           'FinanzasController::bancosCrear',    'finanzas.editar'],
    ['PUT',    '/finanzas/bancos/:id',                       'FinanzasController::bancosEditar',   'finanzas.editar'],
    ['GET',    '/finanzas/bancos/:id/movimientos',           'FinanzasController::bancoMovimientos', 'finanzas.ver'],
    ['POST',   '/finanzas/bancos/:id/movimientos',           'FinanzasController::bancoMovimiento',  'finanzas.crear'],
    ['GET',    '/finanzas/terminales',                       'FinanzasController::terminalesListar', ['finanzas.ver', 'ventas.crear', 'apartados.crear', 'devoluciones.crear', 'clientes.ver']],
    ['POST',   '/finanzas/terminales',                       'FinanzasController::terminalesCrear',  'finanzas.editar'],
    ['PUT',    '/finanzas/terminales/:id',                   'FinanzasController::terminalesEditar', 'finanzas.editar'],
    ['GET',    '/finanzas/cajas',                            'FinanzasController::cajas',            ['finanzas.ver', 'cortes.ver']],
    ['GET',    '/finanzas/cajas/:id/movimientos',            'FinanzasController::cajaMovimientos',  ['finanzas.ver', 'cortes.ver']],
    ['POST',   '/finanzas/cajas/:id/movimientos',            'FinanzasController::cajaMovimiento',   'finanzas.crear'],
    ['GET',    '/finanzas/cuentas-cliente',                  'FinanzasController::cxcListar',      ['finanzas.ver', 'clientes.ver']],
    ['POST',   '/finanzas/cuentas-cliente/abono',            'FinanzasController::cxcAbono',       'finanzas.crear'],
    ['GET',    '/finanzas/cuentas-cliente/:id/movimientos',  'FinanzasController::cxcMovimientos', ['finanzas.ver', 'clientes.ver']],
    ['GET',    '/finanzas/cuentas-cliente/:id/estado-cuenta','FinanzasController::cxcEstadoCuenta',['finanzas.ver', 'clientes.ver']],
    ['GET',    '/finanzas/cuentas-proveedor',                'FinanzasController::cxpListar',      'finanzas.ver'],
    ['POST',   '/finanzas/cuentas-proveedor/pago',           'FinanzasController::cxpPago',        'finanzas.crear'],
    ['GET',    '/finanzas/cuentas-proveedor/:id/movimientos','FinanzasController::cxpMovimientos', 'finanzas.ver'],

    // Monedero
    ['POST',   '/monedero/ajuste',            'MonederoController::ajuste',       'clientes.monedero'],
    ['GET',    '/monedero/:id',               'MonederoController::estadoCuenta', ['clientes.ver', 'ventas.crear']],

    // Apartados
    ['GET',    '/apartados',                  'ApartadoController::listar',   'apartados.ver'],
    ['POST',   '/apartados',                  'ApartadoController::crear',    'apartados.crear'],
    ['GET',    '/apartados/:id',              'ApartadoController::obtener',  'apartados.ver'],
    ['PUT',    '/apartados/:id',              'ApartadoController::editar',   'apartados.editar'],
    ['PATCH',  '/apartados/:id/anticipo',     'ApartadoController::anticipo', 'apartados.editar'],
    ['PATCH',  '/apartados/:id/liquidar',     'ApartadoController::liquidar', 'apartados.editar'],
    ['PATCH',  '/apartados/:id/cancelar',     'ApartadoController::cancelar', 'apartados.cancelar'],

    // Devoluciones y cambios
    ['GET',    '/devoluciones/buscar-venta',  'DevolucionController::buscarVenta',   ['devoluciones.crear', 'devoluciones.ver']],
    ['GET',    '/devoluciones/cambios',       'DevolucionController::listarCambios', 'devoluciones.ver'],
    ['POST',   '/devoluciones/cambios',       'DevolucionController::crearCambio',   'devoluciones.crear'],
    ['GET',    '/devoluciones',               'DevolucionController::listar',        'devoluciones.ver'],
    ['POST',   '/devoluciones',               'DevolucionController::crear',         'devoluciones.crear'],

    // Comisiones
    ['GET',    '/comisiones',                 'ComisionController::listar',  'comisiones.ver'],
    ['GET',    '/comisiones/resumen',         'ComisionController::resumen', 'comisiones.ver'],
    ['PATCH',  '/comisiones/:id/pagar',       'ComisionController::pagar',   'comisiones.pagar'],

    // Reportes
    ['GET',    '/reportes/ventas',                  'ReporteController::ventas',            'reportes.ver'],
    ['GET',    '/reportes/utilidad',                'ReporteController::utilidad',          'reportes.costos'],
    ['GET',    '/reportes/por-lista',               'ReporteController::porLista',          'reportes.ver'],
    ['GET',    '/reportes/top-productos',           'ReporteController::topProductos',      'reportes.ver'],
    ['GET',    '/reportes/cortes-periodo',          'ReporteController::cortesPeriodo',     'reportes.ver'],
    ['GET',    '/reportes/cxc-antiguedad',          'ReporteController::cxcAntiguedad',     'reportes.ver'],
    ['GET',    '/reportes/cxp-proveedores',         'ReporteController::cxpProveedores',    'reportes.ver'],
    ['GET',    '/reportes/comisiones',              'ReporteController::comisiones',        'reportes.ver'],
    ['GET',    '/reportes/existencias-valorizadas', 'ReporteController::existencias',       'reportes.costos'],
    ['GET',    '/reportes/kardex',                  'ReporteController::kardex',            'reportes.ver'],
    ['GET',    '/reportes/compras',                 'ReporteController::compras',           'reportes.ver'],
    ['GET',    '/reportes/devoluciones',            'ReporteController::devoluciones',      'reportes.ver'],
    ['GET',    '/reportes/dashboard-productos',     'ReporteController::dashboardProductos','reportes.ver'],

    // Bitacora de la tienda
    ['GET',    '/audit-log',                  'AuditController::listar', 'bitacora.ver'],

    // Configuracion de la tienda (PIN listas 4/5)
    ['GET',    '/config-sistema/pin-lista-alta', 'ConfigController::estadoPin',  ['configuracion.ver', 'clientes.crear', 'clientes.editar']],
    ['PUT',    '/config-sistema/pin-lista-alta', 'ConfigController::cambiarPin', 'configuracion.editar'],
    ['PUT',    '/config-sistema/color',          'ConfigController::cambiarColor', 'configuracion.editar'],
    ['PUT',    '/config-sistema/logo',           'ConfigController::subirLogo',    'configuracion.editar'],
    ['DELETE', '/config-sistema/logo',           'ConfigController::quitarLogo',   'configuracion.editar'],
    ['GET',    '/tienda/logos',                  'ConfigController::logos',        'tienda'],
    // Carga masiva (Excel / CSV): revisar y aplicar
    ['POST',   '/importar/articulos',            'ImportController::articulos',   'catalogos.crear'],
    ['POST',   '/importar/existencias',          'ImportController::existencias', 'almacen.ajustar'],
    // Inicio de la tienda: resumen del dia y pendientes (cada dato segun permisos)
    ['GET',    '/tienda/hoy',                    'TiendaController::hoy',        'tienda'],
    ['GET',    '/tienda/pendientes',             'TiendaController::pendientes', 'tienda'],

    // Cortes de caja
    ['GET',    '/cortes/preview',             'CorteController::preview', ['cortes.ver', 'cortes.crear']],
    ['POST',   '/cortes/cerrar',              'CorteController::cerrar',  'cortes.crear'],
    ['GET',    '/cortes',                     'CorteController::listar',  'cortes.ver'],
    ['GET',    '/cortes/:id',                 'CorteController::obtener', 'cortes.ver'],

    // ===== Plataforma (solo superadmin) =====
    ['GET',    '/plataforma/dashboard',              'PlataformaController::dashboard',      'plataforma'],
    ['GET',    '/plataforma/modulos',                'PlataformaController::modulos',        'plataforma'],
    ['GET',    '/plataforma/logo',                   'PlataformaController::logo',           'plataforma'],
    ['PUT',    '/plataforma/logo',                   'PlataformaController::subirLogo',      'plataforma'],
    ['DELETE', '/plataforma/logo',                   'PlataformaController::quitarLogo',     'plataforma'],
    ['GET',    '/plataforma/tiendas',                'PlataformaController::tiendas',        'plataforma'],
    ['POST',   '/plataforma/tiendas',                'PlataformaController::crearTienda',    'plataforma'],
    ['GET',    '/plataforma/tiendas/:id',            'PlataformaController::tienda',         'plataforma'],
    ['PUT',    '/plataforma/tiendas/:id',            'PlataformaController::editarTienda',   'plataforma'],
    ['PUT',    '/plataforma/tiendas/:id/logo',       'PlataformaController::subirLogoTienda', 'plataforma'],
    ['DELETE', '/plataforma/tiendas/:id/logo',       'PlataformaController::quitarLogoTienda','plataforma'],
    ['PATCH',  '/plataforma/tiendas/:id/estado',     'PlataformaController::estadoTienda',   'plataforma'],
    ['PUT',    '/plataforma/tiendas/:id/aviso-pago', 'PlataformaController::avisoPago',      'plataforma'],
    ['GET',    '/plataforma/tiendas/:id/modulos',    'PlataformaController::modulosTienda',  'plataforma'],
    ['PUT',    '/plataforma/tiendas/:id/modulos',    'PlataformaController::guardarModulos', 'plataforma'],
    ['POST',   '/plataforma/tiendas/:id/entrar',     'PlataformaController::entrar',         'plataforma'],
    ['GET',    '/plataforma/tiendas/:id/conciliar',  'PlataformaController::conciliar',      'plataforma'],
    ['GET',    '/plataforma/tiendas/:id/export',     'PlataformaController::exportar',       'plataforma'],
    ['GET',    '/plataforma/tiendas/:id/usuarios',   'PlataformaController::usuariosTienda', 'plataforma'],
    ['POST',   '/plataforma/tiendas/:id/usuarios',   'PlataformaController::crearUsuario',   'plataforma'],
    ['GET',    '/plataforma/usuarios',               'PlataformaController::usuarios',       'plataforma'],
    ['PUT',    '/plataforma/usuarios/:id',           'PlataformaController::editarUsuario',  'plataforma'],
    ['DELETE', '/plataforma/usuarios/:id',           'PlataformaController::desactivarUsuario', 'plataforma'],
    ['POST',   '/plataforma/usuarios/:id/reset-password', 'PlataformaController::resetPassword', 'plataforma'],
    ['GET',    '/plataforma/audit-log',              'PlataformaController::auditLog',       'plataforma'],
];

// ---- Matching ----
function match_route(string $pattern, string $route): ?array
{
    $pp = explode('/', trim($pattern, '/'));
    $rp = explode('/', trim($route, '/'));
    if (count($pp) !== count($rp)) return null;
    $params = [];
    foreach ($pp as $i => $seg) {
        if ($seg === '' && $rp[$i] === '') continue;
        if (strlen($seg) > 1 && $seg[0] === ':') {
            $params[substr($seg, 1)] = urldecode($rp[$i]);
        } elseif (strlen($seg) > 1 && $seg[0] === '{') {
            $params['tipo'] = urldecode($rp[$i]);
        } elseif ($seg !== $rp[$i]) {
            return null;
        }
    }
    return $params;
}

$handler = null; $params = []; $permiso = null; $methodMismatch = false;
foreach ($routes as $r) {
    $m = match_route($r[1], $route);
    if ($m === null) continue;
    if ($r[0] !== $method) { $methodMismatch = true; continue; }
    [, , $handler, $permiso] = $r + [3 => null];
    $params = $m;
    break;
}
if ($handler === null) {
    Http::fail($methodMismatch ? 'Metodo no permitido' : 'Ruta no encontrada', $methodMismatch ? 405 : 404, 'NOT_FOUND');
}
if ($permiso === null) Http::fail('Ruta no encontrada', 404, 'NOT_FOUND'); // ruta sin permiso declarado = cerrada

$ctx = ['user' => null, 'cfg' => $cfg, 'route' => $route];

try {
    if ($permiso !== 'publico') {
        // ---- Autenticacion: el JWT solo trae ids cifrados; todo se recarga de la BD ----
        $token  = Http::bearerToken();
        $claims = $token ? Jwt::decode($token, $cfg['jwt_secret']) : null;
        $uid    = ($claims && isset($claims['u'])) ? Tenant::decSesion((string) $claims['u']) : null;
        $u      = $uid ? Db::one('SELECT * FROM users WHERE id = ?', [$uid]) : null;
        if (!$u || $u['is_active'] !== 'Si' || (int) $u['token_version'] !== (int) ($claims['v'] ?? -1)) {
            throw new ApiError('Sesion expirada, vuelve a iniciar sesion', 401, 'UNAUTHORIZED');
        }

        $actAs = null; // id de tienda cuando el superadmin "entra como tienda"
        if ($u['rol'] === 'superadmin') {
            if (isset($claims['t'])) {
                $actAs = Tenant::decSesion((string) $claims['t']);
                if (!$actAs) throw new ApiError('Sesion expirada, vuelve a iniciar sesion', 401, 'UNAUTHORIZED');
            }
            if ($permiso === 'plataforma') {
                if ($actAs) throw new ApiError('Ruta no encontrada', 404, 'NOT_FOUND');
                Tenant::activarPlataforma();
            } elseif ($actAs) {
                $emp = Db::one('SELECT * FROM empresas WHERE id = ?', [$actAs]);
                if (!$emp) throw new ApiError('Ruta no encontrada', 404, 'NOT_FOUND');
                Tenant::activarTienda($emp);
            } elseif ($permiso === 'sesion') {
                Tenant::activarPlataforma();
            } else {
                throw new ApiError('Ruta no encontrada', 404, 'NOT_FOUND'); // superadmin sin tienda activa
            }
        } else {
            if ($permiso === 'plataforma') throw new ApiError('Ruta no encontrada', 404, 'NOT_FOUND');
            $emp = Db::one('SELECT * FROM empresas WHERE id = ?', [(int) $u['id_empresa']]);
            if (!$emp) throw new ApiError('Sesion expirada, vuelve a iniciar sesion', 401, 'UNAUTHORIZED');
            if ($emp['is_active'] !== 'Si') throw new ApiError('Cuenta suspendida, contacta al administrador', 403, 'SUSPENDED');
            Tenant::activarTienda($emp);
        }

        // Usuario de la peticion para los controladores.
        // Si el superadmin opera como tienda, 'id' = null (no pertenece a la tienda) y 'act_as' = su id.
        $ctx['user'] = [
            'id'          => $actAs ? null : (int) $u['id'],
            'id_empresa'  => Tenant::hayTienda() ? Tenant::id() : null,
            'rol'         => $actAs ? 'admin_tienda' : $u['rol'],
            'nombre'      => $u['nombre'],
            'apellido'    => $u['apellido'],
            'login'       => $u['login'],
            'id_tienda'   => $actAs ? null : ($u['id_almacen_default'] !== null ? (int) $u['id_almacen_default'] : null),
            'act_as'      => $actAs ? (int) $u['id'] : null,
            'es_superadmin' => $u['rol'] === 'superadmin',
            'token_version' => (int) $u['token_version'],
        ];
        // contrasena temporal (alta o reset): hasta cambiarla solo puede ver su sesion y cambiarla.
        // Asi un admin que la reseteo no puede operar como ese usuario. (No aplica al superadmin en soporte.)
        if ((int) $u['debe_cambiar_password'] === 1 && !$actAs
            && !in_array($handler, ['AuthController::me', 'AuthController::changePassword'], true)) {
            throw new ApiError('Debes cambiar tu contrasena antes de continuar', 403, 'DEBE_CAMBIAR_PASSWORD');
        }
        $ctx['permisos'] = Tenant::hayTienda() ? Permisos::efectivos($ctx['user']) : [];
        Permisos::$verCostos = Permisos::verCostos($ctx);

        // ---- Ids opacos de la ruta y del query string -> ids internos ----
        if (isset($params['id'])) $params['id'] = Tenant::decEntrada($params['id']);
        $_GET = Tenant::entrada($_GET);

        // ---- Autorizacion ----
        if (!Permisos::autoriza($permiso, $ctx)) {
            throw new ApiError('No tienes permiso para realizar esta accion', 403, 'FORBIDDEN');
        }
    }

    // ---- Modo soporte: toda operacion que modifica datos queda en la bitacora de la tienda ----
    if (!empty($ctx['user']['act_as']) && $method !== 'GET') {
        Ledger::audit($ctx, 'soporte', 'api', null, "$method $route");
    }

    // ---- Despacho ----
    [$class, $fn] = explode('::', $handler);
    call_user_func([$class, $fn], $params, $ctx);
} catch (ApiError $e) {
    Db::rollbackSiAbierta();
    Http::fail($e->getMessage(), $e->status, $e->code);
} catch (PDOException $e) {
    Db::rollbackSiAbierta();
    // Regla de integridad de la BD (FK compuesta, UNIQUE o CHECK): nunca se exponen detalles internos
    if ($e->getCode() === '23000') {
        Http::fail($cfg['debug'] ? 'Datos invalidos: ' . $e->getMessage() : 'Datos invalidos o duplicados', 400, 'INTEGRITY');
    }
    Http::fail($cfg['debug'] ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'Error interno del servidor', 500, 'SERVER_ERROR');
} catch (Throwable $e) {
    Db::rollbackSiAbierta();
    Http::fail($cfg['debug'] ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'Error interno del servidor', 500, 'SERVER_ERROR');
}
