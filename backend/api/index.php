<?php
// ============================================================
// LEVOTEK API — Front controller
// Responde bajo /api/v1/...  (mismo dominio que el frontend)
// ============================================================

error_reporting(E_ALL);

$cfg = require __DIR__ . '/lib/config.php';
ini_set('display_errors', $cfg['debug'] ? '1' : '0');

require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Jwt.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Pricing.php';
require __DIR__ . '/lib/Ledger.php';

// ---- CORS ----
// Con withCredentials el navegador prohibe responder '*': hay que devolver el
// origen exacto de la peticion. Si la config es '*', reflejamos el Origin entrante.
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

// ---- Tabla de rutas: [metodo, patron, handler, publica?] ----
// :x = parametro; {tipo} = comodin de catalogo
$routes = [
    // Auth
    ['POST',   '/auth/login',                 'AuthController::login',          true],
    ['GET',    '/auth/me',                    'AuthController::me'],
    ['POST',   '/auth/change-password',       'AuthController::changePassword'],
    ['GET',    '/auth/usuarios',              'AuthController::listar'],
    ['POST',   '/auth/usuarios',              'AuthController::crear'],
    ['PUT',    '/auth/usuarios/:id',          'AuthController::actualizar'],
    ['DELETE', '/auth/usuarios/:id',          'AuthController::desactivar'],
    ['POST',   '/auth/usuarios/:id/reset-password', 'AuthController::resetPassword'],

    // Catalogos simples
    ['GET',    '/catalogos/{tipo}',           'CatalogoController::listar'],
    ['POST',   '/catalogos/{tipo}',           'CatalogoController::crear'],
    ['GET',    '/catalogos/{tipo}/:id',       'CatalogoController::obtener'],
    ['PUT',    '/catalogos/{tipo}/:id',       'CatalogoController::actualizar'],
    ['DELETE', '/catalogos/{tipo}/:id',       'CatalogoController::eliminar'],

    // Atributos de variante (MultiTienda)
    ['GET',    '/atributos',                  'AtributoController::listar'],
    ['POST',   '/atributos',                  'AtributoController::crear'],
    ['GET',    '/atributos/:id/valores',      'AtributoController::listarValores'],
    ['POST',   '/atributos/:id/valores',      'AtributoController::crearValor'],
    ['PUT',    '/atributos/valores/:id',      'AtributoController::actualizarValor'],
    ['DELETE', '/atributos/valores/:id',      'AtributoController::eliminarValor'],
    ['PUT',    '/atributos/:id',              'AtributoController::actualizar'],
    ['DELETE', '/atributos/:id',              'AtributoController::eliminar'],

    // Categorias (MultiTienda)
    ['GET',    '/categorias',                 'CategoriaController::listar'],
    ['POST',   '/categorias',                 'CategoriaController::crear'],
    ['GET',    '/categorias/:id',             'CategoriaController::obtener'],
    ['PUT',    '/categorias/:id',             'CategoriaController::actualizar'],
    ['DELETE', '/categorias/:id',             'CategoriaController::eliminar'],

    // Articulos
    ['GET',    '/articulos',                  'ArticuloController::listar'],
    ['GET',    '/articulos/buscar',           'ArticuloController::buscar'],
    ['GET',    '/articulos/scan',             'ArticuloController::scan'],
    ['POST',   '/articulos/foto',             'ArticuloController::subirFoto'],
    ['GET',    '/articulos/:id/variantes',    'ArticuloController::variantes'],
    ['GET',    '/articulos/:id',              'ArticuloController::obtener'],
    ['POST',   '/articulos',                  'ArticuloController::crear'],
    ['PUT',    '/articulos/:id',              'ArticuloController::actualizar'],
    ['DELETE', '/articulos/:id',              'ArticuloController::eliminar'],

    // Clientes
    ['GET',    '/clientes',                   'ClienteController::listar'],
    ['GET',    '/clientes/:id',               'ClienteController::obtener'],
    ['POST',   '/clientes',                   'ClienteController::crear'],
    ['PUT',    '/clientes/:id',               'ClienteController::actualizar'],
    ['PATCH',  '/clientes/:id/autorizar-credito', 'ClienteController::autorizarCredito'],
    ['DELETE', '/clientes/:id',               'ClienteController::eliminar'],

    // Empleados
    ['GET',    '/empleados',                  'EmpleadoController::listar'],
    ['POST',   '/empleados',                  'EmpleadoController::crear'],
    ['PUT',    '/empleados/:id',              'EmpleadoController::actualizar'],
    ['DELETE', '/empleados/:id',              'EmpleadoController::eliminar'],

    // Proveedores
    ['GET',    '/proveedores',                'ProveedorController::listar'],
    ['POST',   '/proveedores',                'ProveedorController::crear'],
    ['PUT',    '/proveedores/:id',            'ProveedorController::actualizar'],
    ['DELETE', '/proveedores/:id',            'ProveedorController::eliminar'],

    // Almacenes
    ['GET',    '/almacen/almacenes',          'AlmacenController::listar'],
    ['POST',   '/almacen/almacenes',          'AlmacenController::crear'],
    ['PUT',    '/almacen/almacenes/:id',      'AlmacenController::actualizar'],
    ['DELETE', '/almacen/almacenes/:id',      'AlmacenController::eliminar'],

    // Inventario
    ['GET',    '/almacen/inventario',         'InventarioController::existencias'],
    ['GET',    '/almacen/inventario/kardex',  'InventarioController::kardex'],
    ['POST',   '/almacen/inventario/ajuste',  'InventarioController::ajuste'],
    ['POST',   '/almacen/inventario/ajuste-lote', 'InventarioController::ajusteLote'],

    // Traspasos entre almacenes
    ['GET',    '/almacen/traspasos',          'TraspasoController::listar'],
    ['POST',   '/almacen/traspasos',          'TraspasoController::crear'],
    ['GET',    '/almacen/traspasos/:id',      'TraspasoController::obtener'],
    ['PUT',    '/almacen/traspasos/:id',      'TraspasoController::actualizar'],
    ['PATCH',  '/almacen/traspasos/:id/aceptar',  'TraspasoController::aceptar'],
    ['PATCH',  '/almacen/traspasos/:id/rechazar', 'TraspasoController::rechazar'],

    // Ventas (POS)
    ['GET',    '/ventas',                     'VentaController::listar'],
    ['POST',   '/ventas/cotizar',             'VentaController::cotizar'],
    ['POST',   '/ventas',                     'VentaController::crear'],
    ['GET',    '/ventas/:id',                 'VentaController::obtener'],
    ['PATCH',  '/ventas/:id/cancelar',        'VentaController::cancelar'],

    // Compras
    ['GET',    '/compras',                    'CompraController::listar'],
    ['POST',   '/compras',                    'CompraController::crear'],
    ['GET',    '/compras/:id',                'CompraController::obtener'],
    ['PUT',    '/compras/:id',                'CompraController::actualizar'],
    ['PATCH',  '/compras/:id/aprobar',        'CompraController::aprobar'],
    ['DELETE', '/compras/:id',                'CompraController::eliminar'],
    ['GET',    '/compras/:id/pagos',          'CompraController::pagos'],
    ['POST',   '/compras/:id/pagos',          'CompraController::registrarPago'],

    // Finanzas: Bancos
    ['GET',    '/finanzas/bancos',            'FinanzasController::bancosListar'],
    ['POST',   '/finanzas/bancos',            'FinanzasController::bancosCrear'],
    // Finanzas: Cuentas por cobrar (clientes)
    ['GET',    '/finanzas/cuentas-cliente',                  'FinanzasController::cxcListar'],
    ['POST',   '/finanzas/cuentas-cliente/abono',           'FinanzasController::cxcAbono'],
    ['GET',    '/finanzas/cuentas-cliente/:id/movimientos',   'FinanzasController::cxcMovimientos'],
    ['GET',    '/finanzas/cuentas-cliente/:id/estado-cuenta', 'FinanzasController::cxcEstadoCuenta'],
    // Finanzas: Cuentas por pagar (proveedores)
    ['GET',    '/finanzas/cuentas-proveedor',                'FinanzasController::cxpListar'],
    ['POST',   '/finanzas/cuentas-proveedor/pago',           'FinanzasController::cxpPago'],
    ['GET',    '/finanzas/cuentas-proveedor/:id/movimientos','FinanzasController::cxpMovimientos'],
    // Monedero
    ['POST',   '/monedero/ajuste',            'MonederoController::ajuste'],
    ['GET',    '/monedero/:id',               'MonederoController::estadoCuenta'],

    // Apartados
    ['GET',    '/apartados',                  'ApartadoController::listar'],
    ['POST',   '/apartados',                  'ApartadoController::crear'],
    ['GET',    '/apartados/:id',              'ApartadoController::obtener'],
    ['PUT',    '/apartados/:id',              'ApartadoController::editar'],
    ['PATCH',  '/apartados/:id/anticipo',     'ApartadoController::anticipo'],
    ['PATCH',  '/apartados/:id/liquidar',     'ApartadoController::liquidar'],
    ['PATCH',  '/apartados/:id/cancelar',     'ApartadoController::cancelar'],

    // Devoluciones y Cambios
    ['GET',    '/devoluciones/buscar-venta',  'DevolucionController::buscarVenta'],
    ['GET',    '/devoluciones/cambios',       'DevolucionController::listarCambios'],
    ['POST',   '/devoluciones/cambios',       'DevolucionController::crearCambio'],
    ['GET',    '/devoluciones',               'DevolucionController::listar'],
    ['POST',   '/devoluciones',               'DevolucionController::crear'],

    // Comisiones
    ['GET',    '/comisiones',                 'ComisionController::listar'],
    ['GET',    '/comisiones/resumen',         'ComisionController::resumen'],
    ['PATCH',  '/comisiones/:id/pagar',       'ComisionController::pagar'],

    // Reportes
    ['GET',    '/reportes/ventas',            'ReporteController::ventas'],
    ['GET',    '/reportes/utilidad',          'ReporteController::utilidad'],
    ['GET',    '/reportes/por-lista',         'ReporteController::porLista'],
    ['GET',    '/reportes/top-productos',     'ReporteController::topProductos'],
    ['GET',    '/reportes/cortes-periodo',    'ReporteController::cortesPeriodo'],
    ['GET',    '/reportes/cxc-antiguedad',    'ReporteController::cxcAntiguedad'],
    ['GET',    '/reportes/cxp-proveedores',   'ReporteController::cxpProveedores'],
    ['GET',    '/reportes/comisiones',        'ReporteController::comisiones'],
    ['GET',    '/reportes/existencias-valorizadas', 'ReporteController::existencias'],
    ['GET',    '/reportes/kardex',            'ReporteController::kardex'],
    ['GET',    '/reportes/compras',           'ReporteController::compras'],
    ['GET',    '/reportes/devoluciones',      'ReporteController::devoluciones'],
    ['GET',    '/reportes/dashboard-productos', 'ReporteController::dashboardProductos'],

    // Bitacora / auditoria
    ['GET',    '/audit-log',                  'AuditController::listar'],

    // Config del sistema (PIN listas 4/5)
    ['GET',    '/config-sistema/pin-lista-alta', 'ConfigController::estadoPin'],
    ['PUT',    '/config-sistema/pin-lista-alta', 'ConfigController::cambiarPin'],

    // Cortes
    ['GET',    '/cortes/preview',             'CorteController::preview'],
    ['POST',   '/cortes/cerrar',              'CorteController::cerrar'],
    ['GET',    '/cortes',                     'CorteController::listar'],
    ['GET',    '/cortes/:id',                 'CorteController::obtener'],
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

$handler = null; $params = []; $isPublic = false; $methodMismatch = false;
foreach ($routes as $r) {
    $m = match_route($r[1], $route);
    if ($m === null) continue;
    if ($r[0] !== $method) { $methodMismatch = true; continue; }
    $handler  = $r[2];
    $params   = $m;
    $isPublic = $r[3] ?? false;
    break;
}

if ($handler === null) {
    Http::fail($methodMismatch ? 'Metodo no permitido' : 'Ruta no encontrada: ' . $route,
               $methodMismatch ? 405 : 404, 'NOT_FOUND');
}

// ---- Autenticacion ----
$user = null;
if (!$isPublic) {
    $token = Http::bearerToken();
    $user = $token ? Jwt::decode($token, $cfg['jwt_secret']) : null;
    if (!$user) Http::fail('No autorizado', 401, 'UNAUTHORIZED');
}

// ---- Autorizacion (permisos por modulo; replica la logica del front; admin pasa todo) ----
/** Permiso requerido segun el prefijo de la ruta (null = cualquier usuario autenticado) */
function route_permiso(string $route): ?string
{
    $r = ltrim($route, '/');
    // pares [prefijo, permiso] en orden de mas especifico a mas general
    $map = [
        ['auth/usuarios', 'admin'],
        ['auth',          null],            // login/me/change-password
        ['catalogos',     'catalogos.ver'],
        ['atributos',     'catalogos.ver'],
        ['categorias',    'catalogos.ver'],
        ['articulos',     'catalogos.ver'],
        ['proveedores',   'catalogos.ver'],
        ['empleados',     'catalogos.ver'],
        ['clientes',      'clientes.ver'],
        ['monedero',      'clientes.ver'],
        ['almacen/traspasos', 'traspasos.ver'],
        ['almacen',       'almacen.ver'],
        ['compras',       'compras.ver'],
        ['ventas',        'ventas.ver'],
        ['apartados',     'apartados.ver'],
        ['devoluciones',  'devoluciones.ver'],
        ['cortes',        'cortes.ver'],
        ['finanzas',      'finanzas.ver'],
        ['comisiones',    'comisiones.ver'],
        ['reportes',      'reportes.ver'],
        ['audit-log',     'admin'],
        ['config-sistema','admin'],
    ];
    foreach ($map as [$prefijo, $permiso]) {
        if ($r === $prefijo || strpos($r, $prefijo . '/') === 0) return $permiso;
    }
    return null; // ruta sin modulo mapeado: basta estar autenticado
}

/** Evalua un permiso del usuario imitando hasPermiso() del frontend */
function user_has_permiso(array $user, string $permiso): bool
{
    $perms = $user['permisos'] ?? null;
    if (is_array($perms) && (($perms['admin'] ?? false) === true)) return true; // admin todo
    if (!is_array($perms)) return false;
    $val = $perms;
    foreach (explode('.', $permiso) as $k) {
        if ($val === null || is_bool($val)) break;
        $val = (is_array($val) && array_key_exists($k, $val)) ? $val[$k] : null;
    }
    if ($val === true) return true;                          // permiso plano
    if (is_array($val) && !empty($val['ver'])) return true;  // objeto modulo con .ver
    return false;
}

if (!$isPublic) {
    $permisoRuta = route_permiso($route);
    if ($permisoRuta !== null && !user_has_permiso($user, $permisoRuta)) {
        Http::fail('No tienes permiso para realizar esta accion', 403, 'FORBIDDEN');
    }
}

$ctx = ['user' => $user, 'cfg' => $cfg];

// ---- Despacho ----
try {
    [$class, $fn] = explode('::', $handler);
    call_user_func([$class, $fn], $params, $ctx);
} catch (ApiError $e) {
    Http::fail($e->getMessage(), $e->status, $e->code);
} catch (Throwable $e) {
    Http::fail($cfg['debug'] ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'Error interno del servidor', 500, 'SERVER_ERROR');
}
