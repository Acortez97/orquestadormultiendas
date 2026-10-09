<?php
// ============================================================
// Cobros, destino del dinero y corte de caja (un dia completo en una tienda).
//   php backend/api/reset-db.php --go --demo
//   php -S 127.0.0.1:8082 -t backend/api      (otra terminal)
//   php backend/tests/cobros_test.php
//
// Verifica peso por peso: caja del almacen (efectivo), cada cuenta bancaria, el corte del dia
// (efectivo esperado, desglose por cuenta/terminal, anticipos, cambios, abonos) y la conciliacion.
// ============================================================
require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);

$c = credenciales();
[$t] = entrar('admin@demo1.levotek.com', $c['admin@demo1.levotek.com']);
[$tSA] = entrar('admin@levotek.com', $c['admin@levotek.com']);
function d(array $r) { return $r['body']['data'] ?? []; }
function igual(string $desc, float $esperado, float $real): void { check($desc, abs($esperado - $real) < 0.01, "esperado $esperado, real $real"); }

$alm = array_values(array_filter(d(api('GET', '/almacen/almacenes', null, $t)), fn($a) => $a['codigo'] === 'PRINCIPAL'))[0]['_id'];
$cuentas = []; foreach (d(api('GET', '/finanzas/bancos', null, $t)) as $b) $cuentas[$b['nombre']] = $b['_id'];
$term = []; foreach (d(api('GET', '/finanzas/terminales', null, $t)) as $x) $term[$x['nombre']] = $x;
$C1 = $cuentas['Cuenta principal']; $C2 = $cuentas['Banco secundario'];
$T1 = $term['Terminal 1']['_id']; $T2 = $term['Terminal 2']['_id'];
$arts = d(api('GET', '/articulos', null, $t));
$simple = array_values(array_filter($arts, fn($a) => $a['codigo'] === 'GEN-00001'))[0]['_id'];
$linea = fn($n) => [['id_articulo' => $simple, 'cantidad' => $n]];

seccion('Configuracion de cuentas y terminales');
check('Terminal 1 deposita en Cuenta principal', $term['Terminal 1']['id_banco']['_id'] === $C1);
check('Terminal 2 deposita en Banco secundario', $term['Terminal 2']['id_banco']['_id'] === $C2);
$r = api('POST', '/finanzas/terminales', ['nombre' => 'Terminal 3', 'proveedor' => 'Getnet', 'id_banco' => $C1, 'comision_pct' => 2.5], $t);
status('Alta de terminal ligada a una cuenta', $r, 201);
$saldo = fn($id) => (float) array_values(array_filter(d(api('GET', '/finanzas/bancos', null, $t)), fn($b) => $b['_id'] === $id))[0]['saldo_actual'];
$caja = fn() => (float) array_values(array_filter(d(api('GET', '/finanzas/cajas', null, $t)), fn($a) => $a['_id'] === $alm))[0]['saldo'];

seccion('Validaciones de destino');
status('Tarjeta sin terminal -> 400', api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(1), 'pagos' => [['forma' => 'tdc', 'importe' => 49]]], $t), 400);
status('Transferencia sin cuenta -> 400', api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(1), 'pagos' => [['forma' => 'transferencia', 'importe' => 49]]], $t), 400);
status('Forma de pago inexistente -> 400', api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(1), 'pagos' => [['forma' => 'vales', 'importe' => 49]]], $t), 400);

$espCaja = 0.0; $espC1 = 0.0; $espC2 = 0.0;
seccion('Ventas con cada forma de pago');
$v1 = d(api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 100]], 'destino_cambio' => 'efectivo'], $t));
$espCaja += $v1['total'];
check('Venta en efectivo: cambio entregado', abs($v1['cambio_efectivo'] - (100 - $v1['total'])) < 0.01);
$total2 = d(api('POST', '/ventas/cotizar', ['lineas' => $linea(2)], $t))['total'];
$r = api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(2), 'pagos' => [['forma' => 'tdc', 'importe' => $total2, 'id_terminal' => $T1]]], $t);
status('Venta con TDC en Terminal 1', $r, 201); $v2 = d($r); $espC1 += $total2;
check('La venta guarda la terminal del pago', $v2['pagos'][0]['id_terminal'] === $T1 && $v2['pagos'][0]['id_banco'] === $C1);
$r = api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(3), 'pagos' => [['forma' => 'efectivo', 'importe' => 50], ['forma' => 'tdb', 'importe' => 85, 'id_terminal' => $T2]]], $t);
status('Venta mixta: efectivo + TDB en Terminal 2', $r, 201); $espCaja += 50; $espC2 += 85;
$r = api('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $linea(1), 'pagos' => [['forma' => 'transferencia', 'importe' => 49, 'id_banco' => $C2, 'referencia' => 'SPEI-123']]], $t);
status('Venta por transferencia a Banco secundario', $r, 201); $espC2 += 49;

seccion('Apartado: anticipos con destino y liquidacion');
$cli = d(api('POST', '/clientes', ['nombre' => 'Cliente Cobros', 'forma_pago' => 'Credito', 'limite_credito' => 5000], $t))['_id'];
$apt = d(api('POST', '/apartados', ['id_cliente' => $cli, 'id_almacen' => $alm, 'lineas' => $linea(4)], $t));
status('Anticipo en efectivo', api('PATCH', "/apartados/{$apt['_id']}/anticipo", ['importe' => 60, 'forma' => 'efectivo'], $t), 200); $espCaja += 60;
status('Anticipo con TDC sin terminal -> 400', api('PATCH', "/apartados/{$apt['_id']}/anticipo", ['importe' => 10, 'forma' => 'tdc'], $t), 400);
status('Anticipo con TDC en Terminal 1', api('PATCH', "/apartados/{$apt['_id']}/anticipo", ['importe' => 40, 'forma' => 'tdc', 'id_terminal' => $T1], $t), 200); $espC1 += 40;
$resto = $apt['total'] - 100;
status('Liquidacion por transferencia', api('PATCH', "/apartados/{$apt['_id']}/liquidar", ['pagos' => [['forma' => 'transferencia', 'importe' => $resto, 'id_banco' => $C1]]], $t), 200);
$espC1 += $resto;

seccion('Cambio con diferencia pagada con tarjeta');
$v5 = d(api('POST', '/ventas', ['id_almacen' => $alm, 'id_cliente' => $cli, 'lineas' => $linea(1), 'pagos' => [['forma' => 'efectivo', 'importe' => 49]]], $t));
$espCaja += 49;
$camb = api('POST', '/devoluciones/cambios', ['folio_venta' => $v5['folio'], 'lineas_devueltas' => [['id_articulo' => $simple, 'cantidad' => 1]],
    'lineas_nuevas' => $linea(2), 'pago_diferencia' => 49, 'forma_diferencia' => 'tdb', 'id_terminal' => $T2], $t);
status('Cambio: el cliente paga la diferencia con TDB', $camb, 201);
$difCambio = d($camb)['pago_diferencia']; $espC2 += $difCambio;

seccion('Credito, abono en efectivo y cancelacion');
$vc = d(api('POST', '/ventas', ['id_almacen' => $alm, 'id_cliente' => $cli, 'a_credito' => true, 'lineas' => $linea(2), 'pagos' => []], $t));
status('Abono de cliente en efectivo en la tienda', api('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $cli, 'monto' => 30, 'forma' => 'efectivo', 'id_almacen' => $alm], $t), 201);
$espCaja += 30;
status('Cancelar la venta con TDC: el dinero sale de la Cuenta principal', api('PATCH', "/ventas/{$v2['_id']}/cancelar", null, $t), 200);
$espC1 -= $total2;

seccion('Pagos a proveedor, deposito y gasto de caja');
$prov = d(api('POST', '/proveedores', ['nombre' => 'Proveedor Cobros'], $t))['_id'];
$compra = d(api('POST', '/compras', ['id_proveedor' => $prov, 'id_almacen' => $alm, 'lineas' => [['id_articulo' => $simple, 'cantidad' => 2, 'costo_unitario' => 20]]], $t));
status('Pago de compra en efectivo (sale de la caja)', api('POST', "/compras/{$compra['_id']}/pagos", ['importe' => 20, 'forma_pago' => 'efectivo'], $t), 201); $espCaja -= 20;
status('Pago de compra con TDC -> 400 (no se paga a proveedores con terminal)', api('POST', "/compras/{$compra['_id']}/pagos", ['importe' => 5, 'forma_pago' => 'tdc'], $t), 400);
status('Pago CxP por transferencia desde Cuenta principal', api('POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $prov, 'monto' => 15, 'forma' => 'transferencia', 'id_banco' => $C1], $t), 201); $espC1 -= 15;
status('No puede depositar mas efectivo del que hay en caja', api('POST', "/finanzas/bancos/$C1/movimientos", ['tipo' => 'deposito', 'monto' => 999999, 'id_almacen' => $alm], $t), 400);
status('Deposito de efectivo de la caja a la Cuenta principal', api('POST', "/finanzas/bancos/$C1/movimientos", ['tipo' => 'deposito', 'monto' => 100, 'id_almacen' => $alm], $t), 201);
$espCaja -= 100; $espC1 += 100;
status('Gasto menor pagado de la caja', api('POST', "/finanzas/cajas/$alm/movimientos", ['tipo' => 'egreso', 'monto' => 15, 'concepto' => 'Papeleria'], $t), 201); $espCaja -= 15;

seccion('Donde esta el dinero');
igual('Efectivo en la caja de la tienda', $espCaja, $caja());
igual('Saldo de Cuenta principal', $espC1, $saldo($C1));
igual('Saldo de Banco secundario', $espC2, $saldo($C2));

seccion('Corte de caja del dia');
$pv = d(api('GET', "/cortes/preview?id_almacen=$alm", null, $t));
igual('Efectivo esperado en caja = libro de caja del dia', $espCaja, $pv['resumen']['efectivo_esperado_caja']);
igual('Anticipos del dia', 100, $pv['resumen']['total_anticipos']);
check('Anticipos por forma (efectivo / tdc)', ($pv['resumen']['anticipos_por_forma_pago']['efectivo'] ?? 0) == 60 && ($pv['resumen']['anticipos_por_forma_pago']['tdc'] ?? 0) == 40);
check('El corte lista el cambio con su forma de pago', count($pv['cambios']) === 1 && $pv['cambios'][0]['forma'] === 'tdb');
check('El corte lista el abono del cliente', count($pv['abonos']) === 1 && $pv['abonos'][0]['monto'] == 30);
check('El corte trae ventas por lista y lineas por nota', !empty($pv['resumen']['vendido_por_lista']) && !empty($pv['detalle_notas'][0]['lineas']));
$dest = [];
foreach ($pv['por_destino'] as $x) $dest[$x['cuenta'] . '|' . ($x['terminal'] ?? '') . '|' . $x['forma']] = $x['monto'];
igual('Cobrado con TDC en Terminal 1 (anticipo; la venta se cancelo)', 40, $dest['Cuenta principal|Terminal 1|tdc'] ?? 0);
igual('Cobrado con TDB en Terminal 2 (venta mixta + cambio)', 85 + $difCambio, $dest['Banco secundario|Terminal 2|tdb'] ?? 0);
igual('Transferencias a Banco secundario', 49, $dest['Banco secundario||transferencia'] ?? 0);
igual('Transferencias a Cuenta principal (liquidacion; el pago a proveedor no es cobro)', $resto, $dest['Cuenta principal||transferencia'] ?? 0);
check('Los depositos no aparecen como cobros', !array_filter(array_keys($dest), fn($k) => str_ends_with($k, '|efectivo')));
$r = api('POST', '/cortes/cerrar', ['id_almacen' => $alm, 'fondo' => 500, 'efectivo_contado' => 500 + $espCaja], $t);
status('Cierre del corte con el efectivo exacto', $r, 201);
igual('Diferencia del corte = 0', 0, d($r)['diferencia']);
$cerrado = d(api('GET', '/cortes/' . d($r)['_id'], null, $t));
check('El corte cerrado conserva anticipos, cambios y desglose por cuenta', count($cerrado['anticipos'] ?? []) === 2 && !empty($cerrado['por_destino']) && !empty($cerrado['caja_movimientos']));

seccion('Conciliacion');
$tienda = array_values(array_filter(d(api('GET', '/plataforma/tiendas', null, $tSA)), fn($x) => $x['slug'] === 'demo1'))[0]['_id'];
$con = d(api('GET', "/plataforma/tiendas/$tienda/conciliar", null, $tSA));
check('Clientes y cuentas bancarias cuadran con sus libros', $con['cuadra'] === true, json_encode($con['diferencias'] ?? []));
$mov = d(api('GET', "/finanzas/bancos/$C1/movimientos", null, $t));
check('El libro de la cuenta muestra terminal y tienda de cada cobro', (bool) array_filter($mov['movimientos'], fn($m) => $m['terminal'] === 'Terminal 1' && $m['almacen'] === 'Tienda principal'));
check('Sin ids internos en el corte', !idsCrudos($pv), implode(',', array_slice(idsCrudos($pv), 0, 3)));

seccion('Cajero sin permiso de finanzas: elige cuenta y terminal, sin ver saldos');
[$tCaj] = entrar('cajero1@demo1.levotek.com', $c['cajero1@demo1.levotek.com']);
$bc = d(api('GET', '/finanzas/bancos', null, $tCaj));
check('El cajero lista las cuentas para cobrar', count($bc) === count($cuentas) && isset($bc[0]['_id'], $bc[0]['nombre']));
check('Sin saldo, numero de cuenta ni CLABE', !array_filter($bc, fn($b) => array_intersect_key($b, array_flip(['saldo_actual', 'cuenta', 'clabe']))));
$tc = d(api('GET', '/finanzas/terminales', null, $tCaj));
check('El cajero lista las terminales con su cuenta', count($tc) >= 2 && isset($tc[0]['id_banco']['nombre']));
check('Sin comision ni proveedor de la terminal', !array_filter($tc, fn($x) => array_intersect_key($x, array_flip(['comision_pct', 'proveedor']))));
check('El admin (con finanzas) si recibe saldos', isset(d(api('GET', '/finanzas/bancos', null, $t))[0]['saldo_actual']));

fin();
