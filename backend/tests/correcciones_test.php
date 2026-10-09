<?php
// ============================================================
// Regresion de la revision del 2026-10-08: dinero, existencias, credito, kits, comisiones y USD.
//   php backend/api/reset-db.php --go --demo
//   php -S 127.0.0.1:8082 -t backend/api      (otra terminal)
//   php backend/tests/correcciones_test.php
// Cada seccion reproduce un bug encontrado y verifica el comportamiento correcto.
// ============================================================
require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);

$c = credenciales();
[$t] = entrar('admin@demo1.levotek.com', $c['admin@demo1.levotek.com']);
function d(array $r) { return $r['body']['data'] ?? []; }
function igual(string $desc, float $esperado, float $real): void { check($desc, abs($esperado - $real) < 0.01, "esperado $esperado, real $real"); }

$emp = (int) Db::one("SELECT id FROM empresas WHERE slug = 'demo1'")['id'];
$alm = array_values(array_filter(d(api('GET', '/almacen/almacenes', null, $t)), fn($a) => $a['codigo'] === 'PRINCIPAL'))[0]['_id'];
$cuentas = []; foreach (d(api('GET', '/finanzas/bancos', null, $t)) as $b) $cuentas[$b['nombre']] = $b['_id'];
$C1 = $cuentas['Cuenta principal'];
$caja = fn() => (float) array_values(array_filter(d(api('GET', '/finanzas/cajas', null, $t)), fn($a) => $a['_id'] === $alm))[0]['saldo'];
$cliente = fn($id) => d(api('GET', "/clientes/$id", null, $t));
$nuevoCliente = function (string $nombre, array $extra = []) use ($t) {
    $r = api('POST', '/clientes', ['nombre' => $nombre] + $extra, $t);
    if ($r['status'] !== 201) { fwrite(STDERR, "No se pudo crear cliente $nombre: {$r['raw']}\n"); exit(2); }
    return d($r)['_id'];
};

// Articulo de precio fijo (100 en todas las listas) con 200 piezas via compra aprobada
$prov = d(api('POST', '/proveedores', ['nombre' => 'Proveedor pruebas'], $t))['_id'];
$crearArt = function (string $codigo, float $precio, array $extra = []) use ($t) {
    $r = api('POST', '/articulos', ['codigo' => $codigo, 'descripcion' => "Articulo $codigo", 'costo' => 40,
        'precios' => ['lista1' => $precio, 'lista2' => $precio, 'lista3' => $precio, 'lista4' => $precio, 'lista5' => $precio]] + $extra, $t);
    if ($r['status'] !== 201) { fwrite(STDERR, "No se pudo crear articulo $codigo: {$r['raw']}\n"); exit(2); }
    return d($r)['_id'];
};
$surtir = function (array $arts) use ($t, $prov, $alm) {
    $cp = d(api('POST', '/compras', ['id_proveedor' => $prov, 'id_almacen' => $alm,
        'lineas' => array_map(fn($a) => ['id_articulo' => $a, 'cantidad' => 200, 'costo_unitario' => 40], $arts)], $t));
    api('PATCH', "/compras/{$cp['_id']}/aprobar", null, $t);
};
$A = $crearArt('PRU-A', 100);
$surtir([$A]);
$lin = fn($n, $art = null) => [['id_articulo' => $art ?? $A, 'cantidad' => $n]];
$vender = fn(array $body) => api('POST', '/ventas', ['id_almacen' => $alm] + $body, $t);

// ------------------------------------------------------------
seccion('D1: credito validado en el servidor');
$contado = $nuevoCliente('Cliente contado');
status('Cliente de contado no compra a credito', $vender(['id_cliente' => $contado, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => []]), 400);
$pg = $nuevoCliente('Publico General pruebas', ['es_publico_general' => true, 'forma_pago' => 'Credito', 'limite_credito' => 1000]);
status('Publico General no compra a credito', $vender(['id_cliente' => $pg, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => []]), 400);
status('Credito sin limite -> 400', api('POST', '/clientes', ['nombre' => 'Sin limite', 'forma_pago' => 'Credito', 'limite_credito' => 0], $t), 400);
$cred = $nuevoCliente('Cliente credito 250', ['forma_pago' => 'Credito', 'limite_credito' => 250]);
status('Venta a credito que rebasa el limite -> 400', $vender(['id_cliente' => $cred, 'a_credito' => true, 'lineas' => $lin(3), 'pagos' => []]), 400);
status('Venta a credito dentro del limite', $vender(['id_cliente' => $cred, 'a_credito' => true, 'lineas' => $lin(2), 'pagos' => []]), 201);
status('Segunda venta que ya no cabe en el limite -> 400', $vender(['id_cliente' => $cred, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => []]), 400);
status('Con anticipo si cabe (solo 50 a credito)', $vender(['id_cliente' => $cred, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 50]]]), 201);
igual('Saldo del cliente = 250 (justo el limite)', 250, $cliente($cred)['saldo_credito']);
$inact = $nuevoCliente('Cliente inactivo', ['forma_pago' => 'Credito', 'limite_credito' => 1000]);
api('DELETE', "/clientes/$inact", null, $t);
status('Cliente inactivo no compra a credito', $vender(['id_cliente' => $inact, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => []]), 400);

// usuario con clientes.crear/editar pero sin autorizar_credito
$u = api('POST', '/auth/usuarios', ['usuario' => 'capturista', 'nombre' => 'Capturista', 'password' => 'segura1234',
    'permisos' => ['clientes' => ['ver' => true, 'crear' => true, 'editar' => true], 'ventas' => ['ver' => true, 'crear' => true]]], $t);
status('Alta de usuario capturista', $u, 201);
$login = d($u)['login'] ?? 'capturista@demo1.levotek.com';
[$tU] = entrar($login, 'segura1234');
$cp = api('POST', '/auth/change-password', ['currentPassword' => 'segura1234', 'newPassword' => 'segura12345'], $tU);
if (!empty(d($cp)['token'])) $tU = d($cp)['token'];
status('Sin autorizar_credito no da credito al crear', api('POST', '/clientes', ['nombre' => 'X', 'forma_pago' => 'Credito', 'limite_credito' => 999], $tU), 403);
status('Sin autorizar_credito no sube el limite', api('PUT', "/clientes/$cred", ['limite_credito' => 99999], $tU), 403);
$cc = $cliente($cred);
status('Sin autorizar_credito si edita otros datos (credito igual)', api('PUT', "/clientes/$cred", ['nombre' => 'Cliente credito 250', 'telefono' => '555',
    'forma_pago' => $cc['forma_pago'], 'limite_credito' => $cc['limite_credito'], 'plazo_dias' => $cc['plazo_dias']], $tU), 200);

// ------------------------------------------------------------
seccion('D4: el saldo a favor no se carga al crear cliente');
status('Cliente con saldo_favor inicial -> 400', api('POST', '/clientes', ['nombre' => 'Con saldo', 'saldo_favor' => 100000], $tU), 400);
status('Ni siquiera el admin', api('POST', '/clientes', ['nombre' => 'Con saldo', 'saldo_favor' => 50], $t), 400);

// ------------------------------------------------------------
seccion('D3: sobrepago en venta a credito');
$cred2 = $nuevoCliente('Cliente credito 1000', ['forma_pago' => 'Credito', 'limite_credito' => 1000]);
$c0 = $caja();
$v = d($vender(['id_cliente' => $cred2, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 200]], 'destino_cambio' => 'efectivo']));
igual('Credito con sobrepago: cambio en efectivo', 100, $v['cambio_efectivo'] ?? -1);
igual('Credito con sobrepago: nada a credito', 0, $v['monto_credito'] ?? -1);
igual('A la caja entra solo el total', 100, $caja() - $c0);
$v = d($vender(['id_cliente' => $cred2, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 130]], 'destino_cambio' => 'monedero']));
igual('Credito con sobrepago: excedente al monedero', 30, $v['saldo_favor_generado'] ?? -1);
igual('Monedero del cliente', 30, $cliente($cred2)['saldo_favor']);

// ------------------------------------------------------------
seccion('D2: el apartado se liquida al precio pactado');
$apCli = $nuevoCliente('Cliente apartado');
$apt = d(api('POST', '/apartados', ['id_cliente' => $apCli, 'id_almacen' => $alm, 'lineas' => $lin(2)], $t));
igual('Apartado de 2 a 100', 200, $apt['total']);
status('Anticipo 80 en efectivo', api('PATCH', "/apartados/{$apt['_id']}/anticipo", ['importe' => 80, 'forma' => 'efectivo'], $t), 200);
status('El articulo sube a 150', api('PUT', "/articulos/$A", ['precios' => ['lista1' => 150, 'lista2' => 150, 'lista3' => 150, 'lista4' => 150, 'lista5' => 150]], $t), 200);
$c0 = $caja();
$liq = api('PATCH', "/apartados/{$apt['_id']}/liquidar", ['pagos' => [['forma' => 'efectivo', 'importe' => 120]]], $t);
status('Liquida pagando el restante del precio pactado (120)', $liq, 200);
check('La respuesta no trae el id interno de la venta', !array_key_exists('generatedVentaId', d($liq)) && is_string(d($liq)['id_venta'] ?? null));
$vl = d(api('GET', '/ventas/' . d($liq)['id_venta'], null, $t));
igual('La venta se hizo por el total pactado', 200, $vl['total'] ?? 0);
igual('Precio unitario pactado', 100, $vl['lineas'][0]['precio_unitario'] ?? 0);
igual('Monedero sin excedente', 0, $cliente($apCli)['saldo_favor']);
api('PUT', "/articulos/$A", ['precios' => ['lista1' => 100, 'lista2' => 100, 'lista3' => 100, 'lista4' => 100, 'lista5' => 100]], $t);

seccion('Cancelar la venta de un apartado devuelve el anticipo al monedero');
$c0 = $caja();
status('Cancela la venta del apartado', api('PATCH', '/ventas/' . d($liq)['id_venta'] . '/cancelar', null, $t), 200);
igual('El anticipo (80) regresa al monedero', 80, $cliente($apCli)['saldo_favor']);
igual('De la caja sale solo lo cobrado al liquidar (120)', -120, $caja() - $c0);

// ------------------------------------------------------------
seccion('D6: cancelacion de ventas con saldo ya usado');
$d6 = $nuevoCliente('Cliente D6', ['forma_pago' => 'Credito', 'limite_credito' => 1000]);
$vc = d($vender(['id_cliente' => $d6, 'a_credito' => true, 'lineas' => $lin(2), 'pagos' => []]));
status('Abono de 50 a la cuenta', api('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $d6, 'monto' => 50, 'forma' => 'efectivo', 'id_almacen' => $alm], $t), 201);
status('No se cancela una venta a credito ya abonada', api('PATCH', "/ventas/{$vc['_id']}/cancelar", null, $t), 400);
igual('El saldo del cliente no cambio', 150, $cliente($d6)['saldo_credito']);
$vc2 = d($vender(['id_cliente' => $d6, 'a_credito' => true, 'lineas' => $lin(1), 'pagos' => []]));
status('Venta a credito sin abonos si se cancela', api('PATCH', "/ventas/{$vc2['_id']}/cancelar", null, $t), 200);
igual('Saldo regresa a 150', 150, $cliente($d6)['saldo_credito']);
$vm = d($vender(['id_cliente' => $d6, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 160]], 'destino_cambio' => 'monedero']));
status('Gasta el monedero generado (60)', $vender(['id_cliente' => $d6, 'lineas' => $lin(1), 'pagos' => [['forma' => 'monedero', 'importe' => 60], ['forma' => 'efectivo', 'importe' => 40]]]), 201);
status('No se cancela si ya gasto el saldo a favor que genero', api('PATCH', "/ventas/{$vm['_id']}/cancelar", null, $t), 400);
check('Monedero nunca negativo', $cliente($d6)['saldo_favor'] >= 0);

// ------------------------------------------------------------
seccion('Devolucion de venta mixta (efectivo + credito)');
$mx = $nuevoCliente('Cliente mixto', ['forma_pago' => 'Credito', 'limite_credito' => 1000]);
$vmx = d($vender(['id_cliente' => $mx, 'a_credito' => true, 'lineas' => $lin(3), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]));
igual('Venta de 300: 200 a credito', 200, $vmx['monto_credito']);
$dv = d(api('POST', '/devoluciones', ['folio_venta' => $vmx['folio'], 'lineas' => $lin(3)], $t));
check('Destino mixto', ($dv['destino_saldo'] ?? '') === 'mixto', $dv['destino_saldo'] ?? '');
igual('La deuda queda en 0 (no negativa)', 0, $cliente($mx)['saldo_credito']);
igual('Lo pagado en efectivo va al monedero', 100, $cliente($mx)['saldo_favor']);
$mx2 = $nuevoCliente('Cliente mixto 2', ['forma_pago' => 'Credito', 'limite_credito' => 1000]);
$vmx2 = d($vender(['id_cliente' => $mx2, 'a_credito' => true, 'lineas' => $lin(2), 'pagos' => []]));
api('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $mx2, 'monto' => 200, 'forma' => 'efectivo', 'id_almacen' => $alm], $t);
api('POST', '/devoluciones', ['folio_venta' => $vmx2['folio'], 'lineas' => $lin(1)], $t);
igual('Credito ya pagado: la devolucion va al monedero', 100, $cliente($mx2)['saldo_favor']);
igual('Y la deuda no queda negativa', 0, $cliente($mx2)['saldo_credito']);

// ------------------------------------------------------------
seccion('Cambios: plazo y credito');
$vp = d($vender(['lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]));
Db::run('UPDATE ventas SET fecha = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE folio = ? AND id_empresa = ?', [$vp['folio'], $emp]);
status('Cambio fuera de plazo -> 400', api('POST', '/devoluciones/cambios', ['folio_venta' => $vp['folio'], 'lineas_devueltas' => $lin(1), 'lineas_nuevas' => $lin(1)], $t), 400);
$vk = d($vender(['id_cliente' => $contado, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]));
status('Cambio con diferencia a credito para cliente de contado -> 400', api('POST', '/devoluciones/cambios', ['folio_venta' => $vk['folio'],
    'lineas_devueltas' => $lin(1), 'lineas_nuevas' => $lin(2)], $t), 400);

// ------------------------------------------------------------
seccion('D7: comisiones con devoluciones y cancelaciones');
$vend = d(api('POST', '/empleados', ['nombre' => 'Vendedor pruebas', 'es_vendedor' => true, 'comision_porcentaje' => 10], $t))['_id'];
$comVenta = fn($folio) => array_values(array_filter(d(api('GET', "/comisiones?id_empleado=$vend", null, $t)), fn($x) => $x['folio_venta'] === $folio));
$vv = d($vender(['id_vendedor' => $vend, 'lineas' => $lin(4), 'pagos' => [['forma' => 'efectivo', 'importe' => 400]]]));
igual('Comision de la venta (10% de 400)', 40, array_sum(array_column($comVenta($vv['folio']), 'importe')));
api('POST', '/devoluciones', ['folio_venta' => $vv['folio'], 'lineas' => $lin(1)], $t);
igual('La devolucion descuenta su parte (40 - 10)', 30, array_sum(array_column($comVenta($vv['folio']), 'importe')));
$vv2 = d($vender(['id_vendedor' => $vend, 'lineas' => $lin(2), 'pagos' => [['forma' => 'efectivo', 'importe' => 200]]]));
$orig = $comVenta($vv2['folio'])[0];
status('Paga la comision', api('PATCH', "/comisiones/{$orig['_id']}/pagar", null, $t), 200);
status('Cancela la venta con comision pagada', api('PATCH', "/ventas/{$vv2['_id']}/cancelar", null, $t), 200);
$despues = $comVenta($vv2['folio']);
check('La comision pagada sigue visible', count(array_filter($despues, fn($x) => $x['_id'] === $orig['_id'])) === 1);
igual('Se registra un descuento pendiente por lo pagado', -20, array_sum(array_column(array_filter($despues, fn($x) => $x['pagada'] === 'No'), 'importe')));
$vv3 = d($vender(['id_vendedor' => $vend, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]));
api('PATCH', "/ventas/{$vv3['_id']}/cancelar", null, $t);
check('Comision no pagada de una venta cancelada se anula', count($comVenta($vv3['folio'])) === 0);

// ------------------------------------------------------------
seccion('Compras: no se edita a un total menor que lo pagado');
$cmp = d(api('POST', '/compras', ['id_proveedor' => $prov, 'id_almacen' => $alm, 'lineas' => [['id_articulo' => $A, 'cantidad' => 10, 'costo_unitario' => 40]]], $t));
status('Paga 300 de la compra (por aprobar)', api('POST', "/compras/{$cmp['_id']}/pagos", ['importe' => 300, 'forma_pago' => 'transferencia', 'id_banco' => $C1], $t), 201);
status('Editar a 200 (menos de lo pagado) -> 400', api('PUT', "/compras/{$cmp['_id']}", ['lineas' => [['id_articulo' => $A, 'cantidad' => 5, 'costo_unitario' => 40]]], $t), 400);
status('Editar a 400 si', api('PUT', "/compras/{$cmp['_id']}", ['lineas' => [['id_articulo' => $A, 'cantidad' => 10, 'costo_unitario' => 40]]], $t), 200);

// ------------------------------------------------------------
seccion('D5: kits');
$comp = $crearArt('PRU-COMP', 10);
$comp2 = $crearArt('PRU-COMP2', 10);
$surtir([$comp, $comp2]);
$playera = array_values(array_filter(d(api('GET', '/articulos', null, $t)), fn($a) => $a['codigo'] === 'ROP-00001'))[0]['_id'];
status('Kit con componente con variantes -> 400', api('POST', '/articulos', ['codigo' => 'KIT-X', 'descripcion' => 'Kit X', 'es_kit' => true,
    'componentes' => [['id_componente' => $playera, 'cantidad' => 1]], 'precios' => ['lista1' => 50]], $t), 400);
$kit = $crearArt('PRU-KIT', 50, ['es_kit' => true, 'componentes' => [['id_componente' => $comp, 'cantidad' => 2]]]);
status('Kit dentro de kit -> 400', api('POST', '/articulos', ['codigo' => 'KIT-Y', 'descripcion' => 'Kit Y', 'es_kit' => true,
    'componentes' => [['id_componente' => $kit, 'cantidad' => 1]], 'precios' => ['lista1' => 50]], $t), 400);
status('Kit sin ventas: se puede cambiar su contenido', api('PUT', "/articulos/$kit", ['componentes' => [['id_componente' => $comp, 'cantidad' => 3]]], $t), 200);
$vkit = d($vender(['lineas' => $lin(1, $kit), 'pagos' => [['forma' => 'efectivo', 'importe' => 50]]]));
status('Kit vendido: no se cambia su contenido', api('PUT', "/articulos/$kit", ['componentes' => [['id_componente' => $comp2, 'cantidad' => 1]]], $t), 400);
status('Kit vendido: no deja de ser kit', api('PUT', "/articulos/$kit", ['es_kit' => false], $t), 400);
status('Kit vendido: si se cambia su precio', api('PUT', "/articulos/$kit", ['precios' => ['lista1' => 55]], $t), 200);
status('Cancela la venta del kit', api('PATCH', "/ventas/{$vkit['_id']}/cancelar", null, $t), 200);
$movs = Db::all("SELECT m.id_articulo, SUM(m.cantidad) s FROM inventario_movimientos m WHERE m.id_empresa = ? AND m.motivo LIKE ? GROUP BY m.id_articulo",
    [$emp, '%' . $vkit['folio'] . '%']);
check('Venta y cancelacion del kit se anulan pieza por pieza', count($movs) === 1 && abs((float) $movs[0]['s']) < 0.001, json_encode($movs));

// ------------------------------------------------------------
seccion('D8: pago a proveedor en USD');
$c0 = $caja();
status('USD desde caja sin tipo de cambio -> 400', api('POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $prov, 'monto' => 10, 'moneda' => 'USD', 'forma' => 'efectivo', 'id_almacen' => $alm], $t), 400);
$r = api('POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $prov, 'monto' => 10, 'moneda' => 'USD', 'forma' => 'efectivo', 'id_almacen' => $alm, 'tipo_cambio' => 18.5], $t);
status('USD desde caja con tipo de cambio 18.5', $r, 201);
igual('Al proveedor se le abonan 10 USD', 10, d($r)['monto'] ?? 0);
igual('De la caja salen 185 pesos', -185, $caja() - $c0);
$usd = d(api('POST', '/finanzas/bancos', ['nombre' => 'Cuenta dolares', 'moneda' => 'USD'], $t))['_id'];
status('Cobro de venta a una cuenta en USD -> 400', $vender(['lineas' => $lin(1), 'pagos' => [['forma' => 'transferencia', 'importe' => 100, 'id_banco' => $usd]]]), 400);

// ------------------------------------------------------------
seccion('Apartados vencidos');
$av = d(api('POST', '/apartados', ['id_cliente' => $apCli, 'id_almacen' => $alm, 'lineas' => $lin(1)], $t));
Db::run('UPDATE apartados SET fecha_limite = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE folio = ? AND id_empresa = ?', [$av['folio'], $emp]);
$folios = fn($estado) => array_column(d(api('GET', "/apartados?estado=$estado", null, $t)), 'folio');
check('Filtro vencido lo incluye', in_array($av['folio'], $folios('vencido'), true));
check('Filtro vigente ya no lo incluye', !in_array($av['folio'], $folios('vigente'), true));
status('Un apartado vencido se puede cancelar', api('PATCH', "/apartados/{$av['_id']}/cancelar", null, $t), 200);

// ------------------------------------------------------------
seccion('Zona horaria: PHP y MySQL con la misma hora');
$vh = d($vender(['lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]));
check('La fecha de la venta es la hora local', abs(strtotime($vh['fecha']) - time()) < 120, $vh['fecha'] . ' vs ' . date('Y-m-d H:i:s'));

// ------------------------------------------------------------
seccion('Reportes: netos de devoluciones y cambios');
$R = $crearArt('PRU-R', 100);
$R2 = $crearArt('PRU-R2', 100);
$surtir([$R, $R2]);
$hoy = date('Y-m-d');
$vr = d($vender(['lineas' => [['id_articulo' => $R, 'cantidad' => 3]], 'pagos' => [['forma' => 'cheque', 'importe' => 300, 'id_banco' => $C1]]]));
api('POST', '/devoluciones', ['folio_venta' => $vr['folio'], 'lineas' => [['id_articulo' => $R, 'cantidad' => 1]]], $t);
api('POST', '/devoluciones/cambios', ['folio_venta' => $vr['folio'], 'lineas_devueltas' => [['id_articulo' => $R, 'cantidad' => 1]],
    'lineas_nuevas' => [['id_articulo' => $R2, 'cantidad' => 1]]], $t);
$fila = fn($filas, $cod) => array_values(array_filter($filas, fn($f) => $f['codigo'] === $cod))[0] ?? null;
$top = d(api('GET', "/reportes/top-productos?desde=$hoy&hasta=$hoy&limit=200", null, $t))['filas'] ?? [];
igual('Mas vendidos: 3 vendidas - 1 devuelta - 1 cambiada = 1', 1, $fila($top, 'PRU-R')['cantidad'] ?? -1);
igual('Mas vendidos: la prenda nueva del cambio cuenta', 1, $fila($top, 'PRU-R2')['cantidad'] ?? -1);
$ut = d(api('GET', "/reportes/utilidad?desde=$hoy&hasta=$hoy", null, $t))['filas'] ?? [];
igual('Utilidad: costo neto de PRU-R (1 x 40)', 40, $fila($ut, 'PRU-R')['costo'] ?? -1);
$rv = d(api('GET', "/reportes/ventas?desde=$hoy&hasta=$hoy", null, $t))['totales'] ?? [];
check('Reporte de ventas trae devoluciones, cambios y neto', isset($rv['devoluciones'], $rv['cambios'], $rv['neto'])
    && abs($rv['neto'] - ($rv['total'] - $rv['devoluciones'] + $rv['cambios'])) < 0.01 && $rv['devoluciones'] >= 100);
$cp = d(api('GET', "/reportes/cortes-periodo?desde=$hoy&hasta=$hoy", null, $t))['totales'] ?? [];
check('Cortes por periodo: el cheque tiene su columna', ($cp['cheque'] ?? 0) >= 300);
$sumVentas = (float) Db::one("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE id_empresa = ? AND estado = 'completada' AND DATE(fecha) = CURDATE()", [$emp])['s'];
igual('Cortes por periodo: total = total de las notas', $sumVentas, $cp['total'] ?? -1);
check('Cortes por periodo: los anticipos no se cuentan como efectivo', isset($cp['anticipo']));
$cx = d(api('POST', '/compras', ['id_proveedor' => $prov, 'id_almacen' => $alm, 'lineas' => [['id_articulo' => $R, 'cantidad' => 1, 'costo_unitario' => 1000]]], $t));
$antes = d(api('GET', "/reportes/compras?desde=$hoy&hasta=$hoy", null, $t))['totales']['total'] ?? 0;
status('Cancela una compra por aprobar', api('DELETE', "/compras/{$cx['_id']}", null, $t), 200);
$rc = d(api('GET', "/reportes/compras?desde=$hoy&hasta=$hoy", null, $t))['totales'] ?? [];
igual('Reporte de compras: la cancelada ya no suma', $antes - 1000, $rc['total'] ?? -1);

// ------------------------------------------------------------
seccion('Seguridad: ids, costos, lecturas, PIN y contrasena temporal');
status('Id numerico (7.0) en lugar de id opaco -> 404', $vender(['id_cliente' => 7.0, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]), 404);
status('Id booleano -> 404', $vender(['id_cliente' => true, 'lineas' => $lin(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]]]), 404);
$artU = $fila(d(api('GET', '/articulos', null, $tU)), 'PRU-A');
check('Sin permiso de costos no ve el costo del articulo', $artU !== null && $artU['costo'] === null);
check('El admin si ve el costo', $fila(d(api('GET', '/articulos', null, $t)), 'PRU-A')['costo'] == 40);
$cotU = d(api('POST', '/ventas/cotizar', ['lineas' => $lin(1)], $tU));
check('La cotizacion no trae el costo sin permiso', array_key_exists('costo_unitario', $cotU['lineas'][0] ?? []) && $cotU['lineas'][0]['costo_unitario'] === null);
$bU = d(api('GET', '/finanzas/bancos', null, $tU));
check('El cajero ve cuentas sin saldo ni CLABE', count($bU) > 0 && !array_key_exists('saldo_actual', $bU[0]) && !array_key_exists('clabe', $bU[0]));
$u2 = api('POST', '/auth/usuarios', ['usuario' => 'catalogo1', 'nombre' => 'Solo catalogo', 'password' => 'segura1234',
    'permisos' => ['catalogos' => ['ver' => true]]], $t);
$tCat = api('POST', '/auth/login', ['email' => d($u2)['login'] ?? 'catalogo1@demo1.levotek.com', 'password' => 'segura1234'])['body']['data']['token'] ?? '';
status('Contrasena temporal: no puede operar', api('GET', '/articulos', null, $tCat), 403);
status('Contrasena temporal: si puede ver su sesion', api('GET', '/auth/me', null, $tCat), 200);
[$tCat] = entrar(d($u2)['login'] ?? 'catalogo1@demo1.levotek.com', 'segura1234');
status('Sin permiso de clientes no los lista', api('GET', '/clientes', null, $tCat), 403);
status('Sin permiso de empleados no los lista', api('GET', '/empleados', null, $tCat), 403);
status('Si lista articulos (su modulo)', api('GET', '/articulos', null, $tCat), 200);

status('Configura PIN de listas 4/5', api('PUT', '/config-sistema/pin-lista-alta', ['pin' => '4321'], $t), 200);
for ($i = 0; $i < 5; $i++) api('POST', '/clientes', ['nombre' => "Pin $i", 'lista_precios' => 5, 'pin' => '000' . $i], $t);
status('Tras 5 PIN incorrectos se bloquea (aun con el correcto)', api('POST', '/clientes', ['nombre' => 'Pin ok', 'lista_precios' => 5, 'pin' => '4321'], $t), 429);
Db::run("DELETE FROM login_intentos WHERE login = ?", ['pin:' . $emp]);
status('Pasada la ventana, el PIN correcto funciona', api('POST', '/clientes', ['nombre' => 'Pin ok', 'lista_precios' => 5, 'pin' => '4321'], $t), 201);

seccion('Usuarios: permisos de modulos apagados y sesion');
$c2 = credenciales();
[$tSA] = entrar('admin@levotek.com', $c2['admin@levotek.com']);
$tienda = array_values(array_filter(d(api('GET', '/plataforma/tiendas', null, $tSA)), fn($x) => $x['slug'] === 'demo1'))[0];
$mods = array_column(array_filter(d(api('GET', "/plataforma/tiendas/{$tienda['_id']}/modulos", null, $tSA)), fn($m) => $m['activo']), 'clave');
$uCom = d(api('POST', '/auth/usuarios', ['usuario' => 'comis1', 'nombre' => 'Comisiones', 'password' => 'segura1234',
    'permisos' => ['comisiones' => ['ver' => true], 'catalogos' => ['ver' => true]]], $t));
[$tCom] = entrar($uCom['login'], 'segura1234');
status('Apaga el modulo de comisiones', api('PUT', "/plataforma/tiendas/{$tienda['_id']}/modulos", ['modulos' => array_values(array_diff($mods, ['comisiones']))], $tSA), 200);
check('Se quitaron los permisos de comisiones', (int) Db::one("SELECT COUNT(*) n FROM user_permisos WHERE id_empresa = ? AND modulo = 'comisiones'", [$emp])['n'] === 0);
api('PUT', "/plataforma/tiendas/{$tienda['_id']}/modulos", ['modulos' => $mods], $tSA);
$uLista = array_values(array_filter(d(api('GET', '/auth/usuarios', null, $t)), fn($x) => $x['_id'] === $uCom['_id']))[0];
[$tCom] = entrar($uCom['login'], 'segura1234');
status('Se puede editar al usuario despues de apagar el modulo', api('PUT', "/auth/usuarios/{$uCom['_id']}", ['nombre' => 'Comisiones 2', 'permisos' => $uLista['permisos']], $t), 200);
status('Editar solo el nombre no cierra su sesion', api('GET', '/articulos', null, $tCom), 200);
status('Cambiar sus permisos si la cierra', api('PUT', "/auth/usuarios/{$uCom['_id']}", ['permisos' => ['ventas' => ['ver' => true]]], $t), 200);
status('...y su token anterior ya no sirve', api('GET', '/auth/me', null, $tCom), 401);

seccion('Limites de la plataforma al reactivar');
$nActivos = (int) Db::one("SELECT COUNT(*) n FROM users WHERE id_empresa = ? AND is_active = 'Si'", [$emp])['n'];
api('DELETE', "/auth/usuarios/{$uCom['_id']}", null, $t);
status('Limite de usuarios = los activos', api('PUT', "/plataforma/tiendas/{$tienda['_id']}", ['max_usuarios' => $nActivos - 1], $tSA), 200);
status('El admin de tienda no reactiva por encima del limite', api('PUT', "/auth/usuarios/{$uCom['_id']}", ['is_active' => 'Si'], $t), 400);
api('PUT', "/plataforma/tiendas/{$tienda['_id']}", ['max_usuarios' => null], $tSA);

// ------------------------------------------------------------
seccion('Rediseño: color de la tienda y resumen del día');
status('Color oscuro válido', api('PUT', '/config-sistema/color', ['color' => '#0e7c66'], $t), 200);
check('La sesión trae el color de la tienda', (d(api('GET', '/auth/me', null, $t))['tienda']['color'] ?? '') === '#0E7C66');
status('Color demasiado claro -> 400', api('PUT', '/config-sistema/color', ['color' => '#FFE08A'], $t), 400);
status('Color con formato inválido -> 400', api('PUT', '/config-sistema/color', ['color' => 'azul'], $t), 400);
status('Sin permiso de configuración no cambia el color', api('PUT', '/config-sistema/color', ['color' => '#1F4FD1'], $tCat), 403);
status('Volver al color predeterminado', api('PUT', '/config-sistema/color', ['color' => ''], $t), 200);
$tdemo2 = Db::one("SELECT id FROM empresas WHERE slug = 'demo2'")['id'];
status('El superadmin pone color a otra tienda', api('PUT', "/plataforma/tiendas/{$tienda['_id']}", ['color' => '#7A3E9D'], $tSA), 200);
check('Solo cambió esa tienda', Db::one('SELECT color FROM empresas WHERE id = ?', [$tdemo2])['color'] === null);
api('PUT', "/plataforma/tiendas/{$tienda['_id']}", ['color' => ''], $tSA);

$hoyA = d(api('GET', '/tienda/hoy', null, $t));
check('Resumen del día con ventas, caja y por cobrar (admin)', isset($hoyA['ventas'], $hoyA['efectivo_caja'], $hoyA['por_cobrar'], $hoyA['por_hora']) && count($hoyA['por_hora']) === 24);
$sumHoy = (float) Db::one("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE id_empresa = ? AND estado = 'completada' AND DATE(fecha) = CURDATE()", [$emp])['s'];
igual('Vendido hoy = suma de las notas completadas de hoy', $sumHoy, $hoyA['ventas']['total'] ?? -1);
$hoyC = d(api('GET', '/tienda/hoy', null, $tCat));
check('Sin permisos de ventas/finanzas el resumen no trae dinero', !isset($hoyC['ventas']) && !isset($hoyC['efectivo_caja']) && !isset($hoyC['por_cobrar']));
$pend = d(api('GET', '/tienda/pendientes', null, $t));
check('Pendientes: compras por aprobar', ($pend['/compras']['n'] ?? 0) >= 1);
check('Pendientes de otra pantalla no se ven sin su permiso', !array_key_exists('/compras', d(api('GET', '/tienda/pendientes', null, $tCat))));

// ------------------------------------------------------------
seccion('Carga masiva: artículos, catálogos y existencias');
$cuenta = fn($tabla) => (int) Db::one("SELECT COUNT(*) n FROM $tabla WHERE id_empresa = ?", [$emp])['n'];
$cuentaB = fn($tabla) => (int) Db::one("SELECT COUNT(*) n FROM $tabla WHERE id_empresa = ?", [$tdemo2])['n'];
$antes = ['articulos' => $cuenta('articulos'), 'categorias' => $cuenta('categorias'), 'marcas' => $cuenta('marcas'), 'mov' => $cuenta('inventario_movimientos')];
$antesB = ['articulos' => $cuentaB('articulos'), 'categorias' => $cuentaB('categorias')];
$filasCarga = [
    ['fila' => 2, 'codigo' => 'MAS-001', 'descripcion' => 'Sudadera carga', 'categoria' => 'Sudaderas Carga', 'marca' => 'Marca Carga', 'variante1' => 'Verde', 'variante2' => 'CH', 'costo' => '150', 'precio1' => '$349.00', 'precio2' => '329', 'codigo_barras' => '7509990000011', 'almacen' => 'Tienda principal', 'existencia' => '5'],
    ['fila' => 3, 'codigo' => 'MAS-001', 'descripcion' => 'Sudadera carga', 'categoria' => 'Sudaderas Carga', 'marca' => 'Marca Carga', 'variante1' => 'Verde', 'variante2' => 'GDE', 'costo' => '150', 'precio1' => '349', 'codigo_barras' => '7509990000028', 'almacen' => 'PRINCIPAL', 'existencia' => '3'],
    ['fila' => 4, 'codigo' => 'mas-002', 'descripcion' => 'Lapicero carga', 'categoria' => 'Papelería Carga', 'costo' => '4', 'precio1' => '9.5', 'codigo_barras' => '7509990000035', 'almacen' => 'Bodega', 'existencia' => '1,200'],
    ['fila' => 5],
];
$rv = d(api('POST', '/importar/articulos', ['aplicar' => false, 'filas' => $filasCarga], $t));
check('Revisión: 2 artículos nuevos, 2 variantes y 1,208 piezas', ($rv['resumen']['articulos_nuevos'] ?? 0) === 2 && ($rv['resumen']['existencias_piezas'] ?? 0) == 1208 && !$rv['total_errores'], json_encode($rv['resumen'] ?? $rv));
check('Revisión: avisa qué catálogos se crearán', in_array('Sudaderas Carga', $rv['nuevos']['categorias'] ?? [], true) && in_array('Marca Carga', $rv['nuevos']['marcas'] ?? [], true));
check('Revisar no guarda nada', $cuenta('articulos') === $antes['articulos'] && $cuenta('categorias') === $antes['categorias'] && $cuenta('inventario_movimientos') === $antes['mov']);
$ra = d(api('POST', '/importar/articulos', ['aplicar' => true, 'filas' => $filasCarga], $t));
check('Aplicar guarda la carga', !empty($ra['aplicado']));
$sud = $fila(d(api('GET', '/articulos?search=MAS-001', null, $t)), 'MAS-001');
check('Artículo con variantes creado con su categoría, marca y precios', $sud && $sud['precios']['lista1'] == 349 && $sud['precios']['lista2'] == 329 && count($sud['tallas'] ?? []) === 2, json_encode($sud['precios'] ?? null));
igual('Existencias cargadas por almacén (bodega)', 1200, (float) Db::one("SELECT SUM(i.cantidad) s FROM inventario i JOIN articulos a ON a.id = i.id_articulo JOIN almacenes al ON al.id = i.id_almacen
    WHERE a.id_empresa = ? AND a.codigo = 'MAS-002' AND al.codigo = 'BODEGA'", [$emp])['s']);
check('El inventario inicial queda en el kárdex', (int) Db::one("SELECT COUNT(*) n FROM inventario_movimientos WHERE id_empresa = ? AND tipo = 'carga_inicial'", [$emp])['n'] === 3);
check('La carga no tocó otra tienda', $cuentaB('articulos') === $antesB['articulos'] && $cuentaB('categorias') === $antesB['categorias']);

$r2 = d(api('POST', '/importar/articulos', ['aplicar' => true, 'filas' => [
    ['fila' => 2, 'codigo' => 'MAS-002', 'precio1' => '11', 'costo' => '5', 'almacen' => 'Bodega', 'existencia' => '100'],
    ['fila' => 3, 'codigo' => 'MAS-003', 'descripcion' => 'Sin precio'],
    ['fila' => 4, 'codigo' => 'MAS-004', 'descripcion' => 'Almacén falso', 'precio1' => '10', 'almacen' => 'No existe', 'existencia' => '1'],
]], $t));
check('Con errores no se guarda nada (todo o nada)', empty($r2['aplicado']) && $r2['total_errores'] === 2
    && (float) Db::one("SELECT lista1 FROM articulos WHERE id_empresa = ? AND codigo = 'MAS-002'", [$emp])['lista1'] == 9.5, json_encode($r2['errores'] ?? null));
check('Los errores dicen el renglón', ($r2['errores'][0]['fila'] ?? 0) === 3 && ($r2['errores'][1]['fila'] ?? 0) === 4);
$r3 = d(api('POST', '/importar/articulos', ['aplicar' => true, 'filas' => [['fila' => 2, 'codigo' => 'MAS-002', 'precio1' => '11', 'costo' => '5', 'almacen' => 'Bodega', 'existencia' => '100']]], $t));
check('Artículo existente: actualiza precio y costo y suma existencia', !empty($r3['aplicado'])
    && (float) Db::one("SELECT lista1 FROM articulos WHERE id_empresa = ? AND codigo = 'MAS-002'", [$emp])['lista1'] == 11
    && (float) Db::one("SELECT SUM(i.cantidad) s FROM inventario i JOIN articulos a ON a.id = i.id_articulo WHERE a.id_empresa = ? AND a.codigo = 'MAS-002'", [$emp])['s'] == 1300);

$re = d(api('POST', '/importar/existencias', ['aplicar' => true, 'modo' => 'reemplazar', 'filas' => [
    ['fila' => 2, 'codigo' => '7509990000011', 'almacen' => 'Tienda principal', 'cantidad' => '2'],
    ['fila' => 3, 'codigo' => 'MAS-001', 'variante1' => 'Verde', 'variante2' => 'GDE', 'almacen' => 'Tienda principal', 'cantidad' => '10'],
]], $t));
$celda = fn($talla) => (float) Db::one("SELECT i.cantidad FROM inventario i JOIN articulos a ON a.id = i.id_articulo JOIN atributo_valores t ON t.id = i.id_valor2
    WHERE a.id_empresa = ? AND a.codigo = 'MAS-001' AND t.nombre = ?", [$emp, $talla])['cantidad'];
check('Conteo físico: por código de barras de la variante y por color/talla', !empty($re['aplicado']) && $celda('CH') == 2 && $celda('GDE') == 10, json_encode($re['errores'] ?? null));
$rs = d(api('POST', '/importar/existencias', ['aplicar' => true, 'modo' => 'sumar', 'filas' => [['fila' => 2, 'codigo' => '7509990000011', 'almacen' => 'Tienda principal', 'cantidad' => '4']]], $t));
check('Sumar: agrega a lo que hay', !empty($rs['aplicado']) && $celda('CH') == 6);
status('Sin permiso de catálogos no hay carga masiva', api('POST', '/importar/articulos', ['aplicar' => false, 'filas' => $filasCarga], $tCat), 403);
status('Sin permiso de inventario no se cargan existencias', api('POST', '/importar/existencias', ['aplicar' => false, 'filas' => [['codigo' => 'MAS-002']]], $tCat), 403);

// ------------------------------------------------------------
seccion('Logos de la tienda y de la plataforma (tickets y reportes)');
$png = 'data:image/png;base64,' . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$rl = api('PUT', '/config-sistema/logo', ['dataUrl' => $png], $t);
status('La tienda sube su logo (PNG)', $rl, 200);
check('La sesión trae la URL del logo', str_contains((string) (d(api('GET', '/auth/me', null, $t))['tienda']['logo_url'] ?? ''), '/uploads/'));
status('SVG no se acepta como logo', api('PUT', '/config-sistema/logo', ['dataUrl' => 'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"></svg>')], $t), 400);
status('Texto disfrazado de imagen no se acepta', api('PUT', '/config-sistema/logo', ['dataUrl' => 'data:image/png;base64,' . base64_encode('<?php echo 1; ?>')], $t), 400);
status('Sin permiso de configuración no sube logo', api('PUT', '/config-sistema/logo', ['dataUrl' => $png], $tCat), 403);
check('Los logos para imprimir traen el de la tienda', str_starts_with((string) (d(api('GET', '/tienda/logos', null, $t))['tienda'] ?? ''), 'data:image/png;base64,'));
$c3 = credenciales();
[$tB2] = entrar('admin@demo2.levotek.com', $c3['admin@demo2.levotek.com']);
check('Otra tienda no recibe ese logo', (d(api('GET', '/tienda/logos', null, $tB2))['tienda'] ?? null) === null);
status('El superadmin sube el logo de la plataforma', api('PUT', '/plataforma/logo', ['dataUrl' => $png], $tSA), 200);
check('Todas las tiendas imprimen el logo de la plataforma', str_starts_with((string) (d(api('GET', '/tienda/logos', null, $tB2))['plataforma'] ?? ''), 'data:image/png;base64,'));
status('Una tienda no puede cambiar el logo de la plataforma', api('PUT', '/plataforma/logo', ['dataUrl' => $png], $t), 404);
status('Quitar el logo de la plataforma', api('DELETE', '/plataforma/logo', null, $tSA), 200);
status('Quitar el logo de la tienda', api('DELETE', '/config-sistema/logo', null, $t), 200);
$tiendaMe = d(api('GET', '/auth/me', null, $t))['tienda'] ?? [];
check('Sin logo, la sesión ya no lo trae', array_key_exists('logo_url', $tiendaMe) && $tiendaMe['logo_url'] === null);

// El superadmin sube el logo por la tienda: es el mismo que la tienda ve y puede cambiar
$rLT = api('PUT', "/plataforma/tiendas/{$tienda['_id']}/logo", ['dataUrl' => $png], $tSA);
status('El superadmin sube el logo de una tienda (archivo, no URL)', $rLT, 200);
$urlSA = (string) (d($rLT)['logo_url'] ?? '');
check('La tienda lo ve en su sesión y en sus logos para imprimir', $urlSA !== ''
    && (d(api('GET', '/auth/me', null, $t))['tienda']['logo_url'] ?? null) === $urlSA
    && str_starts_with((string) (d(api('GET', '/tienda/logos', null, $t))['tienda'] ?? ''), 'data:image/png;base64,'));
check('La otra tienda sigue sin logo', (d(api('GET', '/tienda/logos', null, $tB2))['tienda'] ?? null) === null);
api('PUT', "/plataforma/tiendas/{$tienda['_id']}", ['logo_url' => 'https://otro-sitio.example/x.png'], $tSA);
check('Editar la tienda ya no acepta una URL de logo', (d(api('GET', '/auth/me', null, $t))['tienda']['logo_url'] ?? null) === $urlSA);
status('La tienda lo cambia desde Configuración', api('PUT', '/config-sistema/logo', ['dataUrl' => $png], $t), 200);
$urlTienda = (string) (d(api('GET', "/plataforma/tiendas/{$tienda['_id']}", null, $tSA))['logo_url'] ?? '');
check('El superadmin ve el logo nuevo de la tienda', $urlTienda !== '' && $urlTienda !== $urlSA);
status('Una tienda no puede subir el logo de otra por la ruta de plataforma', api('PUT', "/plataforma/tiendas/{$tienda['_id']}/logo", ['dataUrl' => $png], $tB2), 404);
status('SVG tampoco se acepta desde la plataforma', api('PUT', "/plataforma/tiendas/{$tienda['_id']}/logo", ['dataUrl' => 'data:image/svg+xml;base64,' . base64_encode('<svg/>')], $tSA), 400);
status('El superadmin quita el logo de la tienda', api('DELETE', "/plataforma/tiendas/{$tienda['_id']}/logo", null, $tSA), 200);
check('La tienda queda sin logo', (d(api('GET', '/tienda/logos', null, $t))['tienda'] ?? null) === null);

fin();
