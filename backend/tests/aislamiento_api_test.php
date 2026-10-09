<?php
// ============================================================
// F3 — Aislamiento entre tiendas por API + flujo completo de una tienda.
//   php backend/api/reset-db.php --go --demo
//   php -S 127.0.0.1:8082 -t backend/api      (otra terminal)
//   php backend/tests/aislamiento_api_test.php
//
// 1) La tienda B opera TODO (compra, traspaso, venta, apartado, devolucion, cambio, CxC, CxP, corte...).
// 2) La tienda A intenta leer / modificar / usar cada dato de B: todo debe dar 404 y B no debe cambiar.
// 3) Saldos conciliados con sus movimientos, folios por tienda, reportes sin datos ajenos.
// ============================================================
require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);

$c = credenciales();
[$tA] = entrar('admin@demo1.levotek.com', $c['admin@demo1.levotek.com']);
[$tB] = entrar('admin@demo2.levotek.com', $c['admin@demo2.levotek.com']);
$empB = (int) Db::one("SELECT id FROM empresas WHERE slug = 'demo2'")['id'];

function ok(string $desc, array $r, int $st = 200): array
{
    status($desc, $r, $st);
    return $r['body']['data'] ?? [];
}
/** 404 de "registro no encontrado" (no de "ruta no encontrada", que indicaria una prueba mal escrita) */
function noEncontrado(string $desc, array $r): void
{
    $msg = $r['body']['error']['message'] ?? '';
    check($desc, $r['status'] === 404 && $msg !== 'Ruta no encontrada' && $msg !== '',
        "status {$r['status']}: " . substr($msg ?: $r['raw'], 0, 120));
}
function sinIdsCrudos(string $desc, $data): void
{
    $m = idsCrudos($data);
    check("$desc: sin ids internos expuestos", !$m, implode(', ', array_slice($m, 0, 4)));
}

// ------------------------------------------------------------------
seccion('1. Tienda B opera de punta a punta');
$almB = ok('B lista almacenes', api('GET', '/almacen/almacenes', null, $tB));
sinIdsCrudos('almacenes', $almB);
$principalB = array_values(array_filter($almB, fn($a) => $a['codigo'] === 'PRINCIPAL'))[0]['_id'];
$bodegaB    = array_values(array_filter($almB, fn($a) => $a['codigo'] === 'BODEGA'))[0]['_id'];
$artsB = ok('B lista articulos', api('GET', '/articulos', null, $tB));
sinIdsCrudos('articulos', $artsB);
$playeraB = array_values(array_filter($artsB, fn($a) => $a['codigo'] === 'ROP-00001'))[0];
$simpleB  = array_values(array_filter($artsB, fn($a) => $a['codigo'] === 'GEN-00001'))[0];
$colB = $playeraB['colores'][0]['_id']; $talB = $playeraB['tallas'][0]['_id'];
$bancoB = ok('B lista bancos', api('GET', '/finanzas/bancos', null, $tB))[0]['_id'];
$terminalB = ok('B lista terminales', api('GET', '/finanzas/terminales', null, $tB))[0]['_id'];
$cats = ok('B lista categorias', api('GET', '/categorias', null, $tB));
$catB = $cats[0]['_id'];
$atrB = ok('B lista atributos', api('GET', '/atributos', null, $tB))[0];
$marcaB = ok('B crea marca', api('POST', '/catalogos/marcas', ['nombre' => 'Marca B'], $tB), 201)['_id'];
$provB = ok('B crea proveedor', api('POST', '/proveedores', ['nombre' => 'Proveedor B'], $tB), 201)['_id'];
$emplB = ok('B crea vendedor', api('POST', '/empleados', ['nombre' => 'Vendedor B', 'es_vendedor' => true, 'comision_porcentaje' => 5], $tB), 201)['_id'];
$cliB = ok('B crea cliente con saldo inicial', api('POST', '/clientes', ['nombre' => 'Cliente B', 'forma_pago' => 'Credito', 'limite_credito' => 5000,
    'saldo_credito' => 100], $tB), 201);
check('Saldo inicial de cliente entra como movimiento (no directo)', $cliB['saldo_credito'] == 100
    && (int) Db::one("SELECT COUNT(*) n FROM cliente_movimientos WHERE id_empresa = ? AND tipo = 'saldo_inicial'", [$empB])['n'] === 1);
$cliB = $cliB['_id'];

$compra = ok('B crea compra', api('POST', '/compras', ['id_proveedor' => $provB, 'id_almacen' => $principalB, 'aplica_iva' => true,
    'lineas' => [['id_articulo' => $playeraB['_id'], 'id_color' => $colB, 'id_talla' => $talB, 'cantidad' => 5, 'costo_unitario' => 70]]], $tB), 201);
check('Folio de compra de B empieza en 1', $compra['folio'] === 'C-00001', $compra['folio'] ?? '');
ok('B paga anticipo de la compra antes de aprobar', api('POST', "/compras/{$compra['_id']}/pagos", ['importe' => 100, 'forma_pago' => 'transferencia', 'id_banco' => $bancoB], $tB), 201);
ok('B aprueba compra (entra stock + CxP)', api('PATCH', "/compras/{$compra['_id']}/aprobar", null, $tB));
$tras = ok('B crea traspaso a bodega', api('POST', '/almacen/traspasos', ['id_almacen_origen' => $principalB, 'id_almacen_destino' => $bodegaB,
    'lineas' => [['id_articulo' => $playeraB['_id'], 'id_color' => $colB, 'id_talla' => $talB, 'cantidad' => 2]]], $tB), 201);
ok('B acepta traspaso', api('PATCH', "/almacen/traspasos/{$tras['_id']}/aceptar", null, $tB));

$ventaB = ok('B vende a credito con vendedor', api('POST', '/ventas', ['id_almacen' => $principalB, 'id_cliente' => $cliB, 'id_vendedor' => $emplB,
    'a_credito' => true, 'lineas' => [['id_articulo' => $playeraB['_id'], 'id_color' => $colB, 'id_talla' => $talB, 'cantidad' => 3],
                                      ['id_articulo' => $simpleB['_id'], 'cantidad' => 2]], 'pagos' => []], $tB), 201);
sinIdsCrudos('venta', $ventaB);
$apt = ok('B crea apartado', api('POST', '/apartados', ['id_cliente' => $cliB, 'id_almacen' => $principalB,
    'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1]]], $tB), 201);
ok('B registra anticipo', api('PATCH', "/apartados/{$apt['_id']}/anticipo", ['importe' => 10, 'forma' => 'efectivo'], $tB));
$liq = ok('B liquida apartado (genera venta)', api('PATCH', "/apartados/{$apt['_id']}/liquidar", ['pagos' => [['forma' => 'efectivo', 'importe' => 100]], 'destino_cambio' => 'efectivo'], $tB));
check('La liquidacion genero una venta', !empty($liq['id_venta']));
status('Forma de pago interna "anticipo" rechazada en POS', api('POST', '/ventas', ['id_almacen' => $principalB,
    'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1]], 'pagos' => [['forma' => 'anticipo', 'importe' => 999]]], $tB), 400);

$dev = ok('B devuelve 1 pieza (precio lo pone el servidor)', api('POST', '/devoluciones', ['folio_venta' => $ventaB['folio'],
    'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1, 'precio_unitario' => 99999, 'importe' => 99999]]], $tB), 201);
check('La devolucion se valoro al precio vendido, no al enviado', abs($dev['total'] - $ventaB['lineas'][1]['precio_unitario']) < 0.01, (string) ($dev['total'] ?? ''));
status('No puede devolver mas de lo vendido', api('POST', '/devoluciones', ['folio_venta' => $ventaB['folio'],
    'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 5]]], $tB), 400);
$camb = ok('B registra cambio', api('POST', '/devoluciones/cambios', ['folio_venta' => $ventaB['folio'],
    'lineas_devueltas' => [['id_articulo' => $playeraB['_id'], 'id_color' => $colB, 'id_talla' => $talB, 'cantidad' => 1]],
    'lineas_nuevas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1, 'precio_unitario' => 0.01]]], $tB), 201);
check('Lo nuevo del cambio se cotizo en el servidor', $camb['total_nuevo'] > 1, (string) ($camb['total_nuevo'] ?? ''));
ok('B abona a CxC', api('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $cliB, 'monto' => 50, 'forma' => 'transferencia', 'id_banco' => $bancoB, 'id_almacen' => $principalB], $tB), 201);
ok('B paga a proveedor (CxP)', api('POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $provB, 'monto' => 20, 'forma' => 'transferencia', 'id_banco' => $bancoB], $tB), 201);
ok('B ajusta monedero', api('POST', '/monedero/ajuste', ['id_cliente' => $cliB, 'importe' => 15], $tB), 201);
$comis = ok('B lista comisiones', api('GET', '/comisiones', null, $tB));
// la venta genera la comision; la devolucion y el cambio la ajustan (mas reciente primero)
check('La venta con vendedor genero comision (y sus ajustes)', count($comis) === 3 && count(array_filter($comis, fn($c) => $c['folio_venta'] === $ventaB['folio'])) === 3
    && $comis[1]['importe'] < 0 && $comis[2]['importe'] > 0);
$comis = [end($comis)];
ok('B paga comision', api('PATCH', "/comisiones/{$comis[0]['_id']}/pagar", null, $tB));
$corte = ok('B cierra corte del dia', api('POST', '/cortes/cerrar', ['id_almacen' => $principalB, 'fondo' => 500, 'efectivo_contado' => 600], $tB), 201);
check('El folio del corte no usa ids internos', $corte['folio'] === 'CORTE-' . date('Y-m-d') . '-PRINCIPAL', $corte['folio'] ?? '');
status('No se puede cerrar dos veces el mismo corte', api('POST', '/cortes/cerrar', ['id_almacen' => $principalB], $tB), 409);
$catNueva = ok('B crea categoria', api('POST', '/categorias', ['nombre' => 'Cat B', 'id_atributo_eje1' => $atrB['_id']], $tB), 201)['_id'];
$valB = $atrB['valores'][0]['_id'];
$usrB = ok('B crea usuario', api('POST', '/auth/usuarios', ['usuario' => 'empleadob', 'nombre' => 'Emp B', 'password' => 'segura1234'], $tB), 201)['_id'];

foreach (['/reportes/ventas', '/reportes/utilidad', '/reportes/top-productos', '/reportes/cortes-periodo', '/reportes/cxc-antiguedad',
          '/reportes/cxp-proveedores', '/reportes/comisiones', '/reportes/existencias-valorizadas', '/reportes/compras', '/reportes/devoluciones',
          '/reportes/dashboard-productos', '/almacen/inventario', '/almacen/inventario/kardex', '/ventas', '/compras', '/almacen/traspasos',
          '/apartados', '/devoluciones', '/devoluciones/cambios', '/finanzas/cuentas-cliente', '/finanzas/cuentas-proveedor', '/cortes',
          '/audit-log', '/clientes', '/empleados', '/proveedores', '/catalogos/marcas'] as $ruta) {
    $r = api('GET', $ruta, null, $tB);
    if (status("B consulta $ruta", $r, 200)) sinIdsCrudos($ruta, $r['body']['data']);
}

// ------------------------------------------------------------------
seccion('2. Conciliacion de saldos de B (saldo = suma de movimientos)');
$cli = Db::one('SELECT saldo_credito, saldo_favor FROM clientes WHERE id_empresa = ? AND nombre = ?', [$empB, 'Cliente B']);
$cxc = Db::one("SELECT COALESCE(SUM(CASE efecto WHEN 'cargo' THEN monto WHEN 'abono' THEN -monto ELSE 0 END), 0) s
                FROM cliente_movimientos m JOIN clientes c ON c.id_empresa = m.id_empresa AND c.id = m.id_cliente
                WHERE m.id_empresa = ? AND c.nombre = ? AND m.anulado = 0", [$empB, 'Cliente B'])['s'];
$mon = Db::one('SELECT COALESCE(SUM(importe), 0) s FROM monedero_movimientos m JOIN clientes c ON c.id_empresa = m.id_empresa AND c.id = m.id_cliente
                WHERE m.id_empresa = ? AND c.nombre = ?', [$empB, 'Cliente B'])['s'];
check('CxC del cliente cuadra con sus movimientos', abs((float) $cli['saldo_credito'] - (float) $cxc) < 0.01, "{$cli['saldo_credito']} vs $cxc");
check('Monedero del cliente cuadra con sus movimientos', abs((float) $cli['saldo_favor'] - (float) $mon) < 0.01, "{$cli['saldo_favor']} vs $mon");
$cxp = (float) Db::one("SELECT SUM(CASE tipo WHEN 'cargo' THEN monto ELSE -monto END) s FROM proveedor_movimientos WHERE id_empresa = ?", [$empB])['s'];
$compraTotal = (float) Db::one('SELECT total FROM compras WHERE id_empresa = ?', [$empB])['total'];
check('CxP = total compra - anticipo - pago directo', abs($cxp - ($compraTotal - 100 - 20)) < 0.01, "$cxp");
$inv = Db::one('SELECT SUM(cantidad) c FROM inventario WHERE id_empresa = ?', [$empB])['c'];
$kdx = Db::one('SELECT SUM(cantidad) c FROM inventario_movimientos WHERE id_empresa = ?', [$empB])['c'];
check('Inventario cuadra con el kardex (85 iniciales + movimientos)', abs((float) $inv - (85 + (float) $kdx)) < 0.01, "$inv vs 85+$kdx");

// ------------------------------------------------------------------
seccion('3. Foto de B antes del ataque');
function fotoB(int $emp): string
{
    $partes = [];
    foreach (['almacenes', 'articulos', 'inventario', 'inventario_movimientos', 'clientes', 'proveedores', 'empleados', 'ventas', 'venta_lineas',
              'venta_pagos', 'compras', 'compra_lineas', 'traspasos', 'apartados', 'devoluciones', 'cambios', 'cliente_movimientos',
              'monedero_movimientos', 'proveedor_movimientos', 'bancos', 'banco_movimientos', 'caja_movimientos', 'terminales', 'comisiones', 'cortes', 'users', 'user_permisos', 'categorias',
              'atributos', 'atributo_valores', 'marcas', 'folio_series'] as $t) {
        $rows = Db::all("SELECT * FROM `$t` WHERE id_empresa = ?", [$emp]);
        foreach ($rows as &$r) { unset($r['updated_at'], $r['ultimo_acceso']); } unset($r);
        $partes[] = $t . ':' . md5(json_encode($rows));
    }
    return md5(implode('|', $partes));
}
ok('B crea un articulo con SKU propio', api('POST', '/articulos', ['descripcion' => 'Solo en B', 'sku' => 'SOLO-B-1'], $tB), 201);
$antes = fotoB($empB);
check('Foto tomada', strlen($antes) === 32);

// ------------------------------------------------------------------
seccion('4. A intenta LEER datos de B (todo 404)');
$lecturas = [
    "/clientes/$cliB", "/articulos/{$playeraB['_id']}", "/articulos/{$playeraB['_id']}/variantes", "/ventas/{$ventaB['_id']}",
    "/compras/{$compra['_id']}", "/compras/{$compra['_id']}/pagos", "/almacen/traspasos/{$tras['_id']}", "/apartados/{$apt['_id']}",
    "/cortes/{$corte['_id']}", "/categorias/$catB", "/catalogos/marcas/$marcaB", "/atributos/{$atrB['_id']}/valores",
    "/finanzas/cuentas-cliente/$cliB/movimientos", "/finanzas/cuentas-cliente/$cliB/estado-cuenta",
    "/finanzas/cuentas-proveedor/$provB/movimientos", "/monedero/$cliB",
    "/ventas?id_almacen=$principalB", "/almacen/inventario?id_almacen=$principalB", "/reportes/kardex?id_articulo={$playeraB['_id']}",
    "/apartados?id_cliente=$cliB", "/comisiones?id_empleado=$emplB",
];
foreach ($lecturas as $ruta) {
    if (str_contains($ruta, '?')) { noEncontrado("A lee $ruta", api('GET', $ruta, null, $tA)); continue; }
    status("(control) B si puede leer $ruta", api('GET', $ruta, null, $tB), 200);
    noEncontrado("A lee $ruta", api('GET', $ruta, null, $tA));
}
$idInternoCli = (int) Db::one('SELECT id FROM clientes WHERE id_empresa = ? AND nombre = ?', [$empB, 'Cliente B'])['id'];
noEncontrado('A usa el id interno numerico de un cliente de B', api('GET', "/clientes/$idInternoCli", null, $tA));
noEncontrado('A busca la venta de B por folio para devolver', api('GET', '/devoluciones/buscar-venta?folio=' . urlencode($ventaB['folio']), null, $tA));
noEncontrado('A escanea un SKU que solo existe en B', api('GET', '/articulos/scan?code=SOLO-B-1', null, $tA));

seccion('5. A intenta MODIFICAR datos de B (todo 404)');
$escrituras = [
    ['PUT', "/clientes/$cliB", ['nombre' => 'X']], ['DELETE', "/clientes/$cliB", null], ['PATCH', "/clientes/$cliB/autorizar-credito", ['limite_credito' => 1]],
    ['PUT', "/articulos/{$playeraB['_id']}", ['descripcion' => 'X']], ['DELETE', "/articulos/{$playeraB['_id']}", null],
    ['PUT', "/almacen/almacenes/$principalB", ['nombre' => 'X']], ['DELETE', "/almacen/almacenes/$bodegaB", null],
    ['PUT', "/empleados/$emplB", ['nombre' => 'X']], ['PUT', "/proveedores/$provB", ['nombre' => 'X']],
    ['PUT', "/categorias/$catNueva", ['nombre' => 'X']], ['PUT', "/atributos/{$atrB['_id']}", ['nombre' => 'X']],
    ['PUT', "/atributos/valores/$valB", ['nombre' => 'X']], ['POST', "/atributos/{$atrB['_id']}/valores", ['nombre' => 'X']],
    ['PUT', "/catalogos/marcas/$marcaB", ['nombre' => 'X']], ['DELETE', "/catalogos/marcas/$marcaB", null],
    ['PATCH', "/ventas/{$ventaB['_id']}/cancelar", null], ['PUT', "/compras/{$compra['_id']}", ['notas' => 'X']],
    ['DELETE', "/compras/{$compra['_id']}", null], ['POST', "/compras/{$compra['_id']}/pagos", ['importe' => 1]],
    ['PATCH', "/almacen/traspasos/{$tras['_id']}/rechazar", null], ['PATCH', "/apartados/{$apt['_id']}/cancelar", null],
    ['PATCH', "/comisiones/{$comis[0]['_id']}/pagar", null], ['PUT', "/auth/usuarios/$usrB", ['nombre' => 'X']],
];
foreach ($escrituras as [$m, $ruta, $body]) noEncontrado("A $m $ruta", api($m, $ruta, $body, $tA));

seccion('6. A intenta USAR datos de B dentro de sus propias operaciones (todo 404)');
$almA = api('GET', '/almacen/almacenes', null, $tA)['body']['data'];
$principalA = array_values(array_filter($almA, fn($a) => $a['codigo'] === 'PRINCIPAL'))[0]['_id'];
$artsA = api('GET', '/articulos', null, $tA)['body']['data'];
$simpleA = array_values(array_filter($artsA, fn($a) => $a['codigo'] === 'GEN-00001'))[0]['_id'];
$playeraA = array_values(array_filter($artsA, fn($a) => $a['codigo'] === 'ROP-00001'))[0];
$lineaA = [['id_articulo' => $simpleA, 'cantidad' => 1]];
$pagoA = [['forma' => 'efectivo', 'importe' => 1000]];
$usos = [
    'Venta en almacen de B'          => ['POST', '/ventas', ['id_almacen' => $principalB, 'lineas' => $lineaA, 'pagos' => $pagoA, 'destino_cambio' => 'efectivo']],
    'Venta con cliente de B'         => ['POST', '/ventas', ['id_almacen' => $principalA, 'id_cliente' => $cliB, 'lineas' => $lineaA, 'pagos' => $pagoA]],
    'Venta con vendedor de B'        => ['POST', '/ventas', ['id_almacen' => $principalA, 'id_vendedor' => $emplB, 'lineas' => $lineaA, 'pagos' => $pagoA, 'destino_cambio' => 'efectivo']],
    'Venta de articulo de B'         => ['POST', '/ventas', ['id_almacen' => $principalA, 'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1]], 'pagos' => $pagoA, 'destino_cambio' => 'efectivo']],
    'Venta con variante de B'        => ['POST', '/ventas', ['id_almacen' => $principalA, 'lineas' => [['id_articulo' => $playeraA['_id'], 'id_color' => $colB, 'id_talla' => $talB, 'cantidad' => 1]], 'pagos' => $pagoA, 'destino_cambio' => 'efectivo']],
    'Transferencia a cuenta de B'    => ['POST', '/ventas', ['id_almacen' => $principalA, 'lineas' => $lineaA, 'pagos' => [['forma' => 'transferencia', 'importe' => 1000, 'id_banco' => $bancoB]], 'destino_cambio' => 'efectivo']],
    'Cobro con terminal de B'        => ['POST', '/ventas', ['id_almacen' => $principalA, 'lineas' => $lineaA, 'pagos' => [['forma' => 'tdc', 'importe' => 1000, 'id_terminal' => $terminalB]], 'destino_cambio' => 'efectivo']],
    'Deposito a cuenta de B'         => ['POST', "/finanzas/bancos/$bancoB/movimientos", ['tipo' => 'deposito', 'monto' => 1, 'id_almacen' => $principalA]],
    'Leer la caja de un almacen de B' => ['GET', "/finanzas/cajas/$principalB/movimientos", null],
    'Cotizar con cliente de B'       => ['POST', '/ventas/cotizar', ['id_cliente' => $cliB, 'lineas' => $lineaA]],
    'Compra a proveedor de B'        => ['POST', '/compras', ['id_proveedor' => $provB, 'id_almacen' => $principalA, 'lineas' => $lineaA]],
    'Traspaso hacia almacen de B'    => ['POST', '/almacen/traspasos', ['id_almacen_origen' => $principalA, 'id_almacen_destino' => $principalB, 'lineas' => $lineaA]],
    'Apartado para cliente de B'     => ['POST', '/apartados', ['id_cliente' => $cliB, 'id_almacen' => $principalA, 'lineas' => $lineaA]],
    'Abono CxC a cliente de B'       => ['POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $cliB, 'monto' => 1]],
    'Pago CxP a proveedor de B'      => ['POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $provB, 'monto' => 1]],
    'Pago CxP desde banco de B'      => ['POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $provB, 'monto' => 1, 'id_banco' => $bancoB]],
    'Ajuste de monedero a cliente B' => ['POST', '/monedero/ajuste', ['id_cliente' => $cliB, 'importe' => 1]],
    'Ajuste de inventario en almacen B' => ['POST', '/almacen/inventario/ajuste', ['id_almacen' => $principalB, 'id_articulo' => $simpleA, 'delta' => 5]],
    'Ajuste de inventario de articulo B' => ['POST', '/almacen/inventario/ajuste', ['id_almacen' => $principalA, 'id_articulo' => $simpleB['_id'], 'delta' => 5]],
    'Articulo en categoria de B'     => ['POST', '/articulos', ['descripcion' => 'X', 'id_categoria' => $catB]],
    'Articulo con marca de B'        => ['POST', '/articulos', ['descripcion' => 'X', 'id_marca' => $marcaB]],
    'Kit con componente de B'        => ['POST', '/articulos', ['descripcion' => 'X', 'es_kit' => true, 'componentes' => [['id_componente' => $simpleB['_id'], 'cantidad' => 1]]]],
    'Categoria con eje de B'         => ['POST', '/categorias', ['nombre' => 'X', 'id_atributo_eje1' => $atrB['_id']]],
    'Empleado en almacen de B'       => ['POST', '/empleados', ['nombre' => 'X', 'id_tienda' => $principalB]],
    'Cliente con vendedor de B'      => ['POST', '/clientes', ['nombre' => 'X', 'id_vendedor' => $emplB]],
    'Usuario con almacen de B'       => ['POST', '/auth/usuarios', ['usuario' => 'x1', 'nombre' => 'X', 'password' => 'segura1234', 'id_tienda' => $principalB]],
    'Corte de almacen de B'          => ['POST', '/cortes/cerrar', ['id_almacen' => $principalB]],
    'Devolucion de la venta de B'    => ['POST', '/devoluciones', ['folio_venta' => $ventaB['folio'], 'lineas' => [['id_articulo' => $simpleB['_id'], 'cantidad' => 1]]]],
];
foreach ($usos as $desc => [$m, $ruta, $body]) noEncontrado("A: $desc", api($m, $ruta, $body, $tA));

seccion('7. B quedo intacta y A no se contamino');
check('Los datos de B no cambiaron en NADA tras los ataques', fotoB($empB) === $antes);
$repA = api('GET', '/reportes/ventas', null, $tA)['body']['data'];
check('Reporte de ventas de A no incluye ventas de B', $repA['totales']['num_notas'] === 0, (string) $repA['totales']['num_notas']);
$cxcA = api('GET', '/finanzas/cuentas-cliente', null, $tA)['body']['data'];
check('CxC de A no incluye clientes de B', !array_filter($cxcA, fn($r) => $r['nombre'] === 'Cliente B'));
$audA = api('GET', '/audit-log', null, $tA)['body']['data'];
check('Bitacora de A no muestra acciones de B', !array_filter($audA, fn($r) => str_contains((string) $r['descripcion'], 'Cliente B') || str_contains((string) $r['descripcion'], 'Compra C-')));
$vA = ok('A hace su primera venta', api('POST', '/ventas', ['id_almacen' => $principalA, 'lineas' => $lineaA, 'pagos' => $pagoA, 'destino_cambio' => 'efectivo'], $tA), 201);
check('Folios independientes: la primera venta de A tambien es A-00001', $vA['folio'] === 'A-00001', $vA['folio'] ?? '');
check('El mismo folio no revela la venta de la otra tienda', $vA['_id'] !== $ventaB['_id']);

fin();
