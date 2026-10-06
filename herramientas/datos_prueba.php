<?php
// ============================================================
// Carga DATOS DE PRUEBA realistas en todas las tiendas, usando la API (como lo haria la app),
// y al final verifica que todo cuadre. Los datos se quedan para revisarlos en pantalla.
//
//   php backend/api/reset-db.php --go --demo      (BD limpia: superadmin + demo1 + demo2)
//   php -S 127.0.0.1:8082 -t backend/api           (otra terminal)
//   php herramientas/datos_prueba.php
//
// Crea ademas la tienda "Papeleria Roma" desde el panel de administracion.
// Las credenciales nuevas se agregan a backend/api/credenciales.local.txt (ignorado por git).
// Solo para desarrollo / demostracion.
// ============================================================
require __DIR__ . '/../backend/tests/lib.php';
$cfg = require __DIR__ . '/../backend/api/lib/config.php';
require_once __DIR__ . '/../backend/api/lib/Db.php';
Db::init($cfg['db']);

if ((int) Db::one('SELECT COUNT(*) n FROM ventas')['n'] > 0) {
    fwrite(STDERR, "La BD ya tiene ventas. Reiniciala primero: php backend/api/reset-db.php --go --demo\n");
    exit(1);
}

/** Llamada que DEBE funcionar; si no, se detiene mostrando el error */
function req(string $m, string $ruta, $body, string $t, int $esperado = 0): array
{
    $r = api($m, $ruta, $body, $t);
    if (($esperado && $r['status'] !== $esperado) || (!$esperado && $r['status'] >= 300)) {
        fwrite(STDERR, "\n[ERROR] $m $ruta -> {$r['status']}: " . ($r['body']['error']['message'] ?? $r['raw']) . "\n");
        exit(1);
    }
    return $r['body']['data'] ?? [];
}
function paso(string $t): void { echo "  · $t\n"; }

$credFile = __DIR__ . '/../backend/api/credenciales.local.txt';
$c = credenciales();
[$tSA] = entrar('admin@levotek.com', $c['admin@levotek.com']);

// ---------- Tienda nueva desde el panel de administracion ----------
echo "Creando Papeleria Roma desde el panel de administracion...\n";
$papel = req('POST', '/plataforma/tiendas', ['slug' => 'papeleriaroma', 'nombre' => 'Papelería Roma', 'rfc' => 'PRO010101AB1',
    'admin_nombre' => 'Rosa', 'modulos' => ['ventas', 'apartados', 'devoluciones', 'cortes', 'clientes', 'catalogos', 'proveedores',
    'empleados', 'almacen', 'traspasos', 'compras', 'finanzas', 'comisiones', 'reportes', 'configuracion', 'usuarios', 'bitacora']], $tSA, 201);
file_put_contents($credFile, str_pad('Admin Papeleria Roma', 28) . str_pad($papel['admin']['login'], 40) . $papel['admin']['password'] . "\n", FILE_APPEND);
$c[$papel['admin']['login']] = $papel['admin']['password'];

$tiendas = [
    ['slug' => 'demo1', 'admin' => 'admin@demo1.levotek.com', 'cajero' => 'cajero1@demo1.levotek.com', 'giro' => 'ropa', 'cerrarCorte' => true],
    ['slug' => 'demo2', 'admin' => 'admin@demo2.levotek.com', 'cajero' => 'cajero1@demo2.levotek.com', 'giro' => 'calzado', 'cerrarCorte' => false],
    ['slug' => 'papeleriaroma', 'admin' => $papel['admin']['login'], 'cajero' => null, 'giro' => 'papeleria', 'cerrarCorte' => false],
];

$resumen = [];
foreach ($tiendas as $cfgT) {
    echo "\n=== Tienda {$cfgT['slug']} ===\n";
    [$t] = entrar($cfgT['admin'], $c[$cfgT['admin']]);

    // ---------- Catalogos ----------
    $alms = req('GET', '/almacen/almacenes', null, $t);
    $alm = array_values(array_filter($alms, fn($a) => $a['codigo'] === 'PRINCIPAL'))[0]['_id'];
    $bod = array_values(array_filter($alms, fn($a) => $a['codigo'] === 'BODEGA'))[0]['_id'] ?? null;
    if (!$bod) $bod = req('POST', '/almacen/almacenes', ['codigo' => 'BODEGA', 'nombre' => 'Bodega', 'tipo' => 'bodega', 'vende_publico' => false], $t, 201)['_id'];
    $atr = []; foreach (req('GET', '/atributos', null, $t) as $a) $atr[$a['nombre']] = array_column($a['valores'], '_id', 'nombre');
    $cat = array_column(req('GET', '/categorias', null, $t), '_id', 'nombre');
    $marca = req('POST', '/catalogos/marcas', ['nombre' => ['ropa' => 'Urbana', 'calzado' => 'PasoFirme', 'papeleria' => 'Escolar Plus'][$cfgT['giro']]], $t, 201)['_id'];
    $fam = req('POST', '/catalogos/familias', ['nombre' => 'Temporada'], $t, 201)['_id'];
    paso('Catalogos: marca y familia');

    // ---------- Articulos ----------
    $arts = [];
    $nuevoArt = function (string $desc, ?string $categoria, array $precios, float $costo, array $extra = []) use ($t, $cat, $marca, $fam) {
        return req('POST', '/articulos', array_merge(['descripcion' => $desc, 'id_categoria' => $categoria ? $cat[$categoria] : null,
            'id_marca' => $marca, 'id_familia' => $fam, 'costo' => $costo,
            'precios' => ['lista1' => $precios[0], 'lista2' => $precios[1], 'lista3' => $precios[2], 'lista4' => $precios[3], 'lista5' => $precios[4]]], $extra), $t, 201);
    };
    if ($cfgT['giro'] === 'ropa') {
        $arts['A'] = $nuevoArt('Jeans slim', 'Ropa', [499, 469, 449, 429, 399], 230, ['colores' => [$atr['Color']['Azul'], $atr['Color']['Negro']], 'tallas' => [$atr['Talla']['CH'], $atr['Talla']['M'], $atr['Talla']['G']]]);
        $arts['B'] = $nuevoArt('Sudadera capucha', 'Ropa', [399, 379, 359, 339, 319], 180, ['colores' => [$atr['Color']['Rojo'], $atr['Color']['Blanco']], 'tallas' => [$atr['Talla']['M'], $atr['Talla']['G']]]);
        $arts['C'] = $nuevoArt('Calcetines (par)', 'General', [59, 55, 52, 49, 45], 20);
        $var = ['A' => [$atr['Color']['Azul'], $atr['Talla']['M']], 'B' => [$atr['Color']['Rojo'], $atr['Talla']['G']]];
    } elseif ($cfgT['giro'] === 'calzado') {
        $arts['A'] = $nuevoArt('Tenis deportivo', 'Calzado', [899, 869, 839, 799, 759], 420, ['colores' => [$atr['Color']['Negro'], $atr['Color']['Blanco']], 'tallas' => [$atr['Numero']['25'], $atr['Numero']['26'], $atr['Numero']['27']]]);
        $arts['B'] = $nuevoArt('Bota casual', 'Calzado', [1199, 1149, 1099, 1049, 999], 560, ['colores' => [$atr['Color']['Negro']], 'tallas' => [$atr['Numero']['26'], $atr['Numero']['27']]]);
        $arts['C'] = $nuevoArt('Agujetas', 'General', [39, 36, 34, 32, 29], 10);
        $var = ['A' => [$atr['Color']['Negro'], $atr['Numero']['26']], 'B' => [$atr['Color']['Negro'], $atr['Numero']['27']]];
    } else {
        $arts['A'] = $nuevoArt('Cuaderno profesional 100 hojas', 'General', [45, 42, 40, 38, 35], 18);
        $arts['B'] = $nuevoArt('Lapicero azul', 'General', [12, 11, 10, 9, 8], 4);
        $arts['C'] = $nuevoArt('Juego de geometria', 'General', [65, 62, 59, 55, 52], 28);
        $var = ['A' => [null, null], 'B' => [null, null]];
    }
    $arts['KIT'] = $nuevoArt($cfgT['giro'] === 'papeleria' ? 'Kit escolar (cuaderno + 3 lapiceros)' : 'Combo ' . $arts['A']['descripcion'] . ' + ' . $arts['C']['descripcion'],
        'General', [$cfgT['giro'] === 'papeleria' ? 75 : 529, 0, 0, 0, 0], 0,
        ['es_kit' => true, 'componentes' => $cfgT['giro'] === 'papeleria'
            ? [['id_componente' => $arts['A']['_id'], 'cantidad' => 1], ['id_componente' => $arts['B']['_id'], 'cantidad' => 3]]
            : [['id_componente' => $arts['C']['_id'], 'cantidad' => 2]]]);
    $L = fn(string $k, float $n) => ['id_articulo' => $arts[$k]['_id'], 'id_color' => $var[$k][0] ?? null, 'id_talla' => $var[$k][1] ?? null, 'cantidad' => $n];
    paso('Articulos: con variantes, simples y un kit');

    // ---------- Personas ----------
    $prov1 = req('POST', '/proveedores', ['nombre' => 'Distribuidora del Centro', 'telefono' => '5551234567', 'rfc' => 'DCE010101AA1'], $t, 201)['_id'];
    $prov2 = req('POST', '/proveedores', ['nombre' => 'Importadora Norte'], $t, 201)['_id'];
    $vend1 = req('POST', '/empleados', ['nombre' => 'Laura', 'apellido' => 'Mendez', 'es_vendedor' => true, 'comision_porcentaje' => 5, 'id_tienda' => $alm], $t, 201)['_id'];
    $vend2 = req('POST', '/empleados', ['nombre' => 'Jorge', 'apellido' => 'Ruiz', 'es_vendedor' => true, 'comision_porcentaje' => 3, 'id_tienda' => $alm], $t, 201)['_id'];
    $cliCred = req('POST', '/clientes', ['nombre' => 'Comercial Lopez', 'telefono' => '5550001111', 'forma_pago' => 'Credito', 'limite_credito' => 10000, 'plazo_dias' => 30], $t, 201)['_id'];
    $cliMay = req('POST', '/clientes', ['nombre' => 'Maria Fernandez', 'telefono' => '5552223333', 'lista_precios' => 3], $t, 201)['_id'];
    $cliMon = req('POST', '/clientes', ['nombre' => 'Pedro Garcia', 'telefono' => '5554445555'], $t, 201)['_id'];
    paso('Proveedores, vendedores (con comision) y clientes');

    // ---------- Dinero inicial ----------
    $cuentas = array_column(req('GET', '/finanzas/bancos', null, $t), '_id', 'nombre');
    $C1 = $cuentas['Cuenta principal'];
    $T1 = array_values(array_filter(req('GET', '/finanzas/terminales', null, $t), fn($x) => $x['nombre'] === 'Terminal 1'))[0]['_id'];
    $C2 = $cuentas['Banco secundario'] ?? req('POST', '/finanzas/bancos', ['nombre' => 'Banco secundario', 'saldo_inicial' => 0], $t, 201)['_id'];
    $T2 = array_values(array_filter(req('GET', '/finanzas/terminales', null, $t), fn($x) => $x['nombre'] === 'Terminal 2'))[0]['_id']
        ?? req('POST', '/finanzas/terminales', ['nombre' => 'Terminal 2', 'proveedor' => 'Clip', 'id_banco' => $C2, 'comision_pct' => 3.6], $t, 201)['_id'];
    req('POST', "/finanzas/bancos/$C1/movimientos", ['tipo' => 'ingreso', 'monto' => 20000, 'concepto' => 'Capital inicial'], $t, 201);
    req('POST', "/finanzas/cajas/$alm/movimientos", ['tipo' => 'ingreso', 'monto' => 1000, 'concepto' => 'Fondo de cambio'], $t, 201);
    paso('Capital inicial en cuenta y fondo de cambio en caja');

    // ---------- Compras, traspasos, ajustes ----------
    $compra = req('POST', '/compras', ['id_proveedor' => $prov1, 'id_almacen' => $alm, 'aplica_iva' => true, 'lineas' => [
        array_merge($L('A', 20), ['costo_unitario' => $arts['A']['costo']]), array_merge($L('B', 12), ['costo_unitario' => $arts['B']['costo']]),
        array_merge($L('C', 50), ['costo_unitario' => $arts['C']['costo']])]], $t, 201);
    req('PATCH', "/compras/{$compra['_id']}/aprobar", null, $t);
    req('POST', "/compras/{$compra['_id']}/pagos", ['importe' => round($compra['total'] * 0.6, 2), 'forma_pago' => 'transferencia', 'id_banco' => $C1, 'referencia' => 'SPEI-PROV-1'], $t, 201);
    req('POST', "/compras/{$compra['_id']}/pagos", ['importe' => 300, 'forma_pago' => 'efectivo'], $t, 201);
    $compra2 = req('POST', '/compras', ['id_proveedor' => $prov2, 'id_almacen' => $bod, 'lineas' => [array_merge($L('A', 10), ['costo_unitario' => $arts['A']['costo']])]], $t, 201);
    req('PATCH', "/compras/{$compra2['_id']}/aprobar", null, $t);
    req('POST', '/compras', ['id_proveedor' => $prov2, 'id_almacen' => $alm, 'notas' => 'Pendiente de aprobar', 'lineas' => [array_merge($L('B', 6), ['costo_unitario' => $arts['B']['costo']])]], $t, 201);
    $tras = req('POST', '/almacen/traspasos', ['id_almacen_origen' => $bod, 'id_almacen_destino' => $alm, 'lineas' => [$L('A', 4)]], $t, 201);
    req('PATCH', "/almacen/traspasos/{$tras['_id']}/aceptar", null, $t);
    req('POST', '/almacen/traspasos', ['id_almacen_origen' => $alm, 'id_almacen_destino' => $bod, 'notas' => 'Pendiente', 'lineas' => [$L('C', 5)]], $t, 201);
    req('POST', '/almacen/inventario/ajuste', array_merge($L('C', 1), ['id_almacen' => $alm, 'delta' => -2, 'motivo' => 'Merma: producto danado']), $t);
    paso('2 compras aprobadas (una pagada en parte por transferencia y efectivo), 1 por aprobar, traspasos y merma');

    // ---------- Ventas ----------
    $q = fn(array $lineas, $cli = null) => req('POST', '/ventas/cotizar', ['lineas' => $lineas, 'id_cliente' => $cli], $t)['total'];
    $venta = fn(array $b) => req('POST', '/ventas', array_merge(['id_almacen' => $alm], $b), $t, 201);
    $l1 = [$L('A', 1)];                     $v1 = $venta(['lineas' => $l1, 'id_vendedor' => $vend1, 'pagos' => [['forma' => 'efectivo', 'importe' => ceil($q($l1) / 100) * 100]], 'destino_cambio' => 'efectivo']);
    $l2 = [$L('B', 1), $L('C', 2)];         $v2 = $venta(['lineas' => $l2, 'id_vendedor' => $vend1, 'pagos' => [['forma' => 'tdc', 'importe' => $q($l2), 'id_terminal' => $T1]]]);
    $l3 = [$L('A', 2)];                     $tot3 = $q($l3);
    $v3 = $venta(['lineas' => $l3, 'id_vendedor' => $vend2, 'pagos' => [['forma' => 'efectivo', 'importe' => $ef3 = min(200, floor($tot3 / 2))], ['forma' => 'tdb', 'importe' => $tot3 - $ef3, 'id_terminal' => $T2]]]);
    $l4 = [$L('C', 6)];                     $v4 = $venta(['lineas' => $l4, 'id_cliente' => $cliMay, 'pagos' => [['forma' => 'transferencia', 'importe' => $q($l4, $cliMay), 'id_banco' => $C2, 'referencia' => 'SPEI-778899']]]);
    $l5 = [$L('A', 3), $L('B', 2)];         $v5 = $venta(['lineas' => $l5, 'id_cliente' => $cliCred, 'id_vendedor' => $vend2, 'a_credito' => true, 'pagos' => []]);
    $l6 = [['id_articulo' => $arts['KIT']['_id'], 'cantidad' => 1]];
    $v6 = $venta(['lineas' => $l6, 'pagos' => [['forma' => 'efectivo', 'importe' => $q($l6)]]]);
    $l7 = [$L('C', 1)];                     $v7 = $venta(['lineas' => $l7, 'pagos' => [['forma' => 'cheque', 'importe' => $q($l7), 'id_banco' => $C1, 'referencia' => 'CHQ-0045']]]);
    $l8 = [$L('B', 1)];                     $v8 = $venta(['lineas' => $l8, 'pagos' => [['forma' => 'tdc', 'importe' => $q($l8), 'id_terminal' => $T1]]]);
    req('PATCH', "/ventas/{$v8['_id']}/cancelar", null, $t);
    paso('8 ventas: efectivo con cambio, TDC, mixta efectivo+TDB, transferencia, credito, kit, cheque, y una cancelada');

    // ---------- Devolucion -> monedero, y venta pagada con monedero ----------
    $dev = req('POST', '/devoluciones', ['folio_venta' => $v2['folio'], 'lineas' => [$L('C', 1)]], $t, 201);
    $l9 = [$L('C', 1)];
    req('POST', '/devoluciones', ['folio_venta' => $v4['folio'], 'lineas' => [$L('C', 2)]], $t, 201);   // a monedero de Maria
    $tot9 = $q($l9, $cliMay);
    $v9 = $venta(['lineas' => $l9, 'id_cliente' => $cliMay, 'pagos' => [['forma' => 'monedero', 'importe' => $tot9]]]);
    paso('Devoluciones a monedero y una venta pagada con saldo a favor');

    // ---------- Cambio con diferencia pagada con tarjeta ----------
    // cambio de la venta mixta: la diferencia se paga con TDB en la Terminal 2
    $nuevas = [$L('A', 1), $L('C', 2)];
    $difC = round($q($nuevas) - $tot3 / 2, 2);
    $camb = req('POST', '/devoluciones/cambios', ['folio_venta' => $v3['folio'], 'lineas_devueltas' => [$L('A', 1)], 'lineas_nuevas' => $nuevas,
        'pago_diferencia' => max(0, $difC), 'forma_diferencia' => 'tdb', 'id_terminal' => $T2], $t, 201);
    // cambio de la venta en efectivo: diferencia pagada en efectivo
    $nuevas2 = [$L('B', 1), $L('C', 3)];
    $dif2 = round($q($nuevas2) - $q($l1), 2);
    $camb2 = req('POST', '/devoluciones/cambios', ['folio_venta' => $v1['folio'], 'lineas_devueltas' => [$L('A', 1)], 'lineas_nuevas' => $nuevas2,
        'pago_diferencia' => max(0, $dif2), 'forma_diferencia' => 'efectivo'], $t, 201);
    // cambio de un cliente con credito: la diferencia queda en su cuenta por cobrar
    req('POST', '/devoluciones/cambios', ['folio_venta' => $v5['folio'], 'lineas_devueltas' => [$L('B', 1)], 'lineas_nuevas' => [$L('A', 1)], 'pago_diferencia' => 0], $t, 201);
    paso("Cambios: diferencia con TDB ($difC), en efectivo ($dif2) y uno a credito");

    // ---------- Apartados ----------
    $la = [$L('B', 2)];
    $apt1 = req('POST', '/apartados', ['id_cliente' => $cliMon, 'id_almacen' => $alm, 'id_vendedor' => $vend1, 'lineas' => $la], $t, 201);
    req('PATCH', "/apartados/{$apt1['_id']}/anticipo", ['importe' => $an1 = round($apt1['total'] * 0.4), 'forma' => 'efectivo'], $t);
    req('PATCH', "/apartados/{$apt1['_id']}/anticipo", ['importe' => $an2 = round($apt1['total'] * 0.3), 'forma' => 'tdb', 'id_terminal' => $T2], $t);
    req('PATCH', "/apartados/{$apt1['_id']}/liquidar", ['pagos' => [['forma' => 'transferencia', 'importe' => $apt1['total'] - $an1 - $an2, 'id_banco' => $C1]]], $t);
    $apt2 = req('POST', '/apartados', ['id_cliente' => $cliMay, 'id_almacen' => $alm, 'lineas' => [$L('A', 1)]], $t, 201);
    req('PATCH', "/apartados/{$apt2['_id']}/anticipo", ['importe' => round($apt2['total'] * 0.25), 'forma' => 'efectivo'], $t);
    $apt3 = req('POST', '/apartados', ['id_cliente' => $cliMon, 'id_almacen' => $alm, 'lineas' => [$L('C', 3)]], $t, 201);
    req('PATCH', "/apartados/{$apt3['_id']}/anticipo", ['importe' => round($apt3['total'] * 0.3), 'forma' => 'tdc', 'id_terminal' => $T1], $t);
    req('PATCH', "/apartados/{$apt3['_id']}/cancelar", null, $t);
    paso('Apartados: uno liquidado (efectivo + TDB + transferencia), uno vigente con anticipo, uno cancelado');

    // ---------- CxC, CxP, comisiones, caja ----------
    $saldoCli = (float) array_values(array_filter(req('GET', '/finanzas/cuentas-cliente', null, $t), fn($x) => $x['_id'] === $cliCred))[0]['saldo'];
    $saldoProv = (float) array_values(array_filter(req('GET', '/finanzas/cuentas-proveedor', null, $t), fn($x) => $x['_id'] === $prov1))[0]['saldos']['MXN']['saldo'];
    req('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $cliCred, 'monto' => min(500, round($saldoCli * 0.3)), 'forma' => 'efectivo', 'id_almacen' => $alm, 'concepto' => 'Abono semanal'], $t, 201);
    req('POST', '/finanzas/cuentas-cliente/abono', ['id_cliente' => $cliCred, 'monto' => min(300, round($saldoCli * 0.2)), 'forma' => 'transferencia', 'id_banco' => $C2, 'id_almacen' => $alm], $t, 201);
    req('POST', '/finanzas/cuentas-proveedor/pago', ['id_proveedor' => $prov1, 'monto' => min(1000, round($saldoProv * 0.5)), 'forma' => 'transferencia', 'id_banco' => $C1, 'concepto' => 'Pago parcial'], $t, 201);
    $com = req('GET', '/comisiones', null, $t);
    if ($com) req('PATCH', "/comisiones/{$com[count($com) - 1]['_id']}/pagar", null, $t);
    $saldoCaja = (float) array_values(array_filter(req('GET', '/finanzas/cajas', null, $t), fn($x) => $x['_id'] === $alm))[0]['saldo'];
    req('POST', "/finanzas/cajas/$alm/movimientos", ['tipo' => 'egreso', 'monto' => 85, 'concepto' => 'Gasto: articulos de limpieza'], $t, 201);
    $dep = floor(($saldoCaja - 85) * 0.6 / 100) * 100;   // se deposita la mayor parte; el resto queda en caja
    if ($dep > 0) req('POST', "/finanzas/bancos/$C1/movimientos", ['tipo' => 'deposito', 'monto' => $dep, 'id_almacen' => $alm, 'concepto' => 'Deposito del dia'], $t, 201);
    req('POST', "/finanzas/bancos/$C2/movimientos", ['tipo' => 'egreso', 'monto' => 45, 'concepto' => 'Comision bancaria'], $t, 201);
    paso('Abonos de cliente, pago a proveedor, comision pagada, gasto de caja, deposito y cargo bancario');

    // ---------- Cajero (permisos limitados) ----------
    if ($cfgT['cajero']) {
        [$tc] = entrar($cfgT['cajero'], $c[$cfgT['cajero']]);
        $lc = [$L('C', 2)];
        req('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => $lc, 'pagos' => [['forma' => 'efectivo', 'importe' => 200]], 'destino_cambio' => 'efectivo'], $tc, 201);
        paso('El cajero vendio con su usuario');
    } else {
        $cj = req('POST', '/auth/usuarios', ['usuario' => 'caja1', 'nombre' => 'Carlos', 'apellido' => 'Caja', 'password' => 'CajaRoma2026', 'id_tienda' => $alm,
            'permisos' => ['ventas' => ['ver' => true, 'crear' => true], 'clientes' => ['ver' => true], 'cortes' => ['ver' => true, 'crear' => true], 'apartados' => ['ver' => true, 'crear' => true, 'editar' => true]]], $t, 201);
        file_put_contents($credFile, str_pad('Cajero Papeleria Roma', 28) . str_pad($cj['login'], 40) . "CajaRoma2026\n", FILE_APPEND);
        [$tc] = entrar($cj['login'], 'CajaRoma2026');
        req('POST', '/ventas', ['id_almacen' => $alm, 'lineas' => [$L('B', 4)], 'pagos' => [['forma' => 'efectivo', 'importe' => 100]], 'destino_cambio' => 'efectivo'], $tc, 201);
        paso('El admin de la tienda creo su cajero y el cajero vendio');
    }

    // ---------- Corte y verificacion ----------
    $pv = req('GET', "/cortes/preview?id_almacen=$alm", null, $t);
    $cajaHoy = (float) array_values(array_filter(req('GET', '/finanzas/cajas', null, $t), fn($x) => $x['_id'] === $alm))[0]['saldo'];
    $okCorte = abs($pv['resumen']['efectivo_esperado_caja'] - $cajaHoy) < 0.01;   // todo ocurrio hoy: corte = saldo de caja
    $dif = null;
    if ($cfgT['cerrarCorte']) {
        $cerr = req('POST', '/cortes/cerrar', ['id_almacen' => $alm, 'fondo' => 0, 'efectivo_contado' => $pv['resumen']['efectivo_esperado_caja'], 'notas' => 'Corte de prueba: cuadro exacto'], $t, 201);
        $dif = $cerr['diferencia'];
        paso('Corte del dia cerrado');
    }
    $con = req('GET', "/plataforma/tiendas/" . array_values(array_filter(req('GET', '/plataforma/tiendas', null, $tSA), fn($x) => $x['slug'] === $cfgT['slug']))[0]['_id'] . '/conciliar', null, $tSA);
    $bancos = req('GET', '/finanzas/bancos', null, $t);
    $resumen[$cfgT['slug']] = [
        'corte_ok' => $okCorte, 'efectivo_caja' => $cajaHoy, 'diferencia_corte' => $dif, 'conciliacion' => $con['cuadra'],
        'cuentas' => array_map(fn($b) => $b['nombre'] . ': ' . number_format($b['saldo_actual'], 2), $bancos),
        'ventas' => count(req('GET', '/ventas', null, $t)),
    ];
}

echo "\n=== Verificacion final ===\n";
$todoOk = true;
foreach ($resumen as $slug => $r) {
    $ok = $r['corte_ok'] && $r['conciliacion'] && ($r['diferencia_corte'] === null || abs($r['diferencia_corte']) < 0.01);
    $todoOk = $todoOk && $ok;
    printf("%-15s %s  ventas: %d  efectivo en caja: %s  corte: %s  libros: %s\n                cuentas: %s\n",
        $slug, $ok ? 'OK ' : 'MAL', $r['ventas'], number_format($r['efectivo_caja'], 2),
        $r['corte_ok'] ? 'cuadra' . ($r['diferencia_corte'] !== null ? ' (cerrado, diferencia ' . $r['diferencia_corte'] . ')' : ' (abierto)') : 'NO cuadra',
        $r['conciliacion'] ? 'cuadran' : 'NO cuadran', implode(' | ', $r['cuentas']));
}
echo $todoOk ? "\nDatos de prueba cargados y verificados.\n" : "\nHAY DIFERENCIAS: revisar.\n";
exit($todoOk ? 0 : 1);
