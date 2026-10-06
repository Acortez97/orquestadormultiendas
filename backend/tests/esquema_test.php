<?php
// ============================================================
// Prueba de AISLAMIENTO a nivel de base de datos (esquema v2).
// Intenta cruzar datos entre dos tiendas directamente con SQL: la BD debe rechazarlo.
// Tambien valida reglas de integridad (CHECKs, llaves unicas por tienda).
//
//   Requisitos: BD instalada con tiendas demo  ->  php backend/api/reset-db.php --go --demo
//   Ejecutar:   php backend/tests/esquema_test.php
//
// Todo corre dentro de una transaccion que se revierte al final: no deja datos.
// Codigo de salida 0 = todo bien; 1 = hubo fallos.
// ============================================================

$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);
$pdo = Db::pdo();

$ok = 0; $fallos = [];

function debeFallar(string $desc, string $sql, array $p = []): void {
    global $pdo, $ok, $fallos;
    try {
        $pdo->prepare($sql)->execute($p);
        $fallos[] = "DEBIO FALLAR: $desc";
        echo "  ✗ $desc  (la BD lo acepto)\n";
    } catch (PDOException $e) {
        // Solo cuenta si lo rechazo una regla de integridad (FK, UNIQUE, CHECK = SQLSTATE 23000),
        // no un error de sintaxis u otro que pudiera esconder un fallo real.
        if ($e->getCode() !== '23000') {
            $fallos[] = "ERROR INESPERADO: $desc — " . $e->getMessage();
            echo "  ✗ $desc  (error no esperado: " . $e->getMessage() . ")\n";
            return;
        }
        $ok++;
        echo "  ✓ $desc\n";
    }
}
function debePasar(string $desc, string $sql, array $p = []): int {
    global $pdo, $ok, $fallos;
    try {
        $pdo->prepare($sql)->execute($p);
        $ok++;
        echo "  ✓ $desc\n";
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        $fallos[] = "DEBIO PASAR: $desc — " . $e->getMessage();
        echo "  ✗ $desc  (" . $e->getMessage() . ")\n";
        return 0;
    }
}
function val(string $sql, array $p = []) { return Db::one($sql, $p) ? array_values(Db::one($sql, $p))[0] : null; }

/** Ids de referencia de una tienda */
function datos(string $slug): array {
    $e = (int) val('SELECT id FROM empresas WHERE slug = ?', [$slug]);
    if (!$e) { fwrite(STDERR, "No existe la tienda '$slug'. Corre: php backend/api/reset-db.php --go --demo\n"); exit(1); }
    $d = ['emp' => $e];
    $d['alm']   = (int) val("SELECT id FROM almacenes WHERE id_empresa = ? AND codigo = 'PRINCIPAL'", [$e]);
    $d['bod']   = (int) val("SELECT id FROM almacenes WHERE id_empresa = ? AND codigo = 'BODEGA'", [$e]);
    $d['cli']   = (int) val('SELECT id FROM clientes WHERE id_empresa = ? LIMIT 1', [$e]);
    $d['art']   = (int) val('SELECT id FROM articulos WHERE id_empresa = ? ORDER BY id LIMIT 1', [$e]);
    $d['val']   = (int) val('SELECT id FROM atributo_valores WHERE id_empresa = ? LIMIT 1', [$e]);
    $d['atr']   = (int) val('SELECT id FROM atributos WHERE id_empresa = ? LIMIT 1', [$e]);
    $d['cat']   = (int) val('SELECT id FROM categorias WHERE id_empresa = ? LIMIT 1', [$e]);
    $d['user']  = (int) val("SELECT id FROM users WHERE id_empresa = ? AND usuario = 'cajero1'", [$e]);
    $d['banco'] = (int) val('SELECT id FROM bancos WHERE id_empresa = ? LIMIT 1', [$e]);
    $d['prov']  = (int) Db::insert('INSERT INTO proveedores (id_empresa, nombre) VALUES (?, ?)', [$e, "Proveedor $slug"]);
    $d['empl']  = (int) Db::insert('INSERT INTO empleados (id_empresa, nombre, es_vendedor) VALUES (?, ?, 1)', [$e, "Vendedor $slug"]);
    $d['venta'] = (int) Db::insert(
        'INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_cliente, total) VALUES (?, ?, NOW(), ?, ?, 100)',
        [$e, 'TEST-0001', $d['alm'], $d['cli']]);
    return $d;
}

Db::begin();
$A = datos('demo1');
$B = datos('demo2');

echo "\n== 1. Cruces entre tiendas (la BD debe RECHAZARLOS) ==\n";
debeFallar('Venta de A en almacen de B',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen) VALUES (?, 'X1', NOW(), ?)", [$A['emp'], $B['alm']]);
debeFallar('Venta de A con cliente de B',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_cliente) VALUES (?, 'X2', NOW(), ?, ?)", [$A['emp'], $A['alm'], $B['cli']]);
debeFallar('Venta de A con vendedor de B',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_vendedor) VALUES (?, 'X3', NOW(), ?, ?)", [$A['emp'], $A['alm'], $B['empl']]);
debeFallar('Venta de A registrada por usuario de B',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_usuario) VALUES (?, 'X4', NOW(), ?, ?)", [$A['emp'], $A['alm'], $B['user']]);
debeFallar('Linea de venta de A con articulo de B',
    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $A['venta'], $B['art']]);
debeFallar('Linea de venta de A con variante de B',
    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, id_valor1, cantidad) VALUES (?, ?, ?, ?, 1)', [$A['emp'], $A['venta'], $A['art'], $B['val']]);
debeFallar('Linea marcada como de A colgando de una venta de B',
    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $B['venta'], $A['art']]);
debeFallar('Pago de venta de A a banco de B',
    "INSERT INTO venta_pagos (id_empresa, id_venta, forma, importe, id_banco) VALUES (?, ?, 'tarjeta', 10, ?)", [$A['emp'], $A['venta'], $B['banco']]);
debeFallar('Existencia de A con articulo de B',
    'INSERT INTO inventario (id_empresa, id_almacen, id_articulo, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $A['alm'], $B['art']]);
debeFallar('Existencia de A en almacen de B',
    'INSERT INTO inventario (id_empresa, id_almacen, id_articulo, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $B['alm'], $A['art']]);
debeFallar('Kardex de A con articulo de B',
    "INSERT INTO inventario_movimientos (id_empresa, tipo, id_almacen, id_articulo, cantidad) VALUES (?, 'ajuste', ?, ?, 1)", [$A['emp'], $A['alm'], $B['art']]);
debeFallar('Traspaso de A hacia almacen de B',
    "INSERT INTO traspasos (id_empresa, folio, fecha, id_almacen_origen, id_almacen_destino) VALUES (?, 'T1', NOW(), ?, ?)", [$A['emp'], $A['alm'], $B['alm']]);
debeFallar('Compra de A a proveedor de B',
    "INSERT INTO compras (id_empresa, folio, fecha, id_proveedor, id_almacen) VALUES (?, 'C1', NOW(), ?, ?)", [$A['emp'], $B['prov'], $A['alm']]);
debeFallar('Movimiento CxC de A con cliente de B',
    "INSERT INTO cliente_movimientos (id_empresa, id_cliente, fecha, tipo, monto) VALUES (?, ?, NOW(), 'abono', 10)", [$A['emp'], $B['cli']]);
debeFallar('Movimiento CxC de A abonado a banco de B',
    "INSERT INTO cliente_movimientos (id_empresa, id_cliente, fecha, tipo, monto, id_banco) VALUES (?, ?, NOW(), 'abono', 10, ?)", [$A['emp'], $A['cli'], $B['banco']]);
debeFallar('Monedero de A con cliente de B',
    "INSERT INTO monedero_movimientos (id_empresa, id_cliente, tipo, importe) VALUES (?, ?, 'ajuste', 10)", [$A['emp'], $B['cli']]);
debeFallar('Movimiento CxP de A con proveedor de B',
    "INSERT INTO proveedor_movimientos (id_empresa, id_proveedor, fecha, tipo, monto) VALUES (?, ?, NOW(), 'pago', 10)", [$A['emp'], $B['prov']]);
debeFallar('Apartado de A con cliente de B',
    "INSERT INTO apartados (id_empresa, folio, fecha, id_cliente, id_almacen) VALUES (?, 'AP1', NOW(), ?, ?)", [$A['emp'], $B['cli'], $A['alm']]);
debeFallar('Devolucion de A sobre venta de B',
    "INSERT INTO devoluciones (id_empresa, folio, fecha, id_venta) VALUES (?, 'D1', NOW(), ?)", [$A['emp'], $B['venta']]);
debeFallar('Comision de A para empleado de B',
    'INSERT INTO comisiones (id_empresa, id_empleado, id_venta) VALUES (?, ?, ?)', [$A['emp'], $B['empl'], $A['venta']]);
debeFallar('Corte de caja de A en almacen de B',
    "INSERT INTO cortes (id_empresa, folio, fecha, id_almacen) VALUES (?, 'K1', CURDATE(), ?)", [$A['emp'], $B['alm']]);
debeFallar('Articulo de A en categoria de B',
    "INSERT INTO articulos (id_empresa, codigo, descripcion, id_categoria) VALUES (?, 'Z1', 'x', ?)", [$A['emp'], $B['cat']]);
debeFallar('Categoria de A con eje (atributo) de B',
    "INSERT INTO categorias (id_empresa, nombre, id_atributo_eje1) VALUES (?, 'x', ?)", [$A['emp'], $B['atr']]);
debeFallar('Variante de articulo de A con valor de B',
    'INSERT INTO articulo_eje_valores (id_empresa, id_articulo, eje, id_valor) VALUES (?, ?, 1, ?)', [$A['emp'], $A['art'], $B['val']]);
debeFallar('Kit de A con componente de B',
    'INSERT INTO articulo_componentes (id_empresa, id_kit, id_componente, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $A['art'], $B['art']]);
debeFallar('Usuario de A con almacen por defecto de B',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol, id_almacen_default) VALUES (?, 'x1', 'x1@t', 'x', 'h', 'usuario', ?)", [$A['emp'], $B['alm']]);
debeFallar('Permiso para usuario de B registrado como de A',
    "INSERT INTO user_permisos (id_empresa, id_user, modulo, accion) VALUES (?, ?, 'ventas', 'ver')", [$A['emp'], $B['user']]);
debeFallar('Cliente de A con vendedor de B',
    'INSERT INTO clientes (id_empresa, nombre, id_vendedor) VALUES (?, ?, ?)', [$A['emp'], 'x', $B['empl']]);
debeFallar('Bitacora de A atribuida a usuario de B',
    "INSERT INTO audit_log (id_empresa, id_usuario, accion) VALUES (?, ?, 'x')", [$A['emp'], $B['user']]);

echo "\n== 2. Reglas de integridad (la BD debe RECHAZARLAS) ==\n";
debeFallar('Superadmin con tienda asignada',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'sa2', 'sa2@t', 'x', 'h', 'superadmin')", [$A['emp']]);
debeFallar('Usuario de tienda sin tienda',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (NULL, 'u2', 'u2@t', 'x', 'h', 'usuario')");
debeFallar('Nombre de usuario con mayusculas/espacios',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'Juan Perez', 'jp@t', 'x', 'h', 'usuario')", [$A['emp']]);
debeFallar('Correo de acceso duplicado',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'cajero9', 'cajero1@demo1.levotek.com', 'x', 'h', 'usuario')", [$A['emp']]);
debeFallar('Mismo usuario dos veces en la misma tienda',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'cajero1', 'otro@t', 'x', 'h', 'usuario')", [$A['emp']]);
debeFallar('Subdominio de tienda invalido',
    "INSERT INTO empresas (slug, nombre, uploads_token, id_salt) VALUES ('Mi Tienda', 'x', REPEAT('a',32), REPEAT('b',32))");
debeFallar('Subdominio de tienda repetido',
    "INSERT INTO empresas (slug, nombre, uploads_token, id_salt) VALUES ('demo1', 'x', REPEAT('c',32), REPEAT('d',32))");
debeFallar('Dos existencias para la misma celda (producto sin variante)',
    'INSERT INTO inventario (id_empresa, id_almacen, id_articulo, cantidad) VALUES (?, ?, (SELECT id FROM articulos WHERE id_empresa = ? AND codigo = ?), 1)',
    [$A['emp'], $A['alm'], $A['emp'], 'GEN-00001']);
debeFallar('Folio de venta repetido en la misma tienda',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen) VALUES (?, 'TEST-0001', NOW(), ?)", [$A['emp'], $A['alm']]);
debeFallar('Traspaso con mismo origen y destino',
    "INSERT INTO traspasos (id_empresa, folio, fecha, id_almacen_origen, id_almacen_destino) VALUES (?, 'T9', NOW(), ?, ?)", [$A['emp'], $A['alm'], $A['alm']]);
debeFallar('Permiso con accion que no existe',
    "INSERT INTO user_permisos (id_empresa, id_user, modulo, accion) VALUES (?, ?, 'ventas', 'volar')", [$A['emp'], $A['user']]);
Db::run("DELETE FROM user_permisos WHERE id_empresa = ? AND modulo = 'facturacion'", [$A['emp']]);
Db::run("DELETE FROM empresa_modulos WHERE id_empresa = ? AND modulo = 'facturacion'", [$A['emp']]);
debeFallar('Permiso de un modulo que la tienda NO tiene',
    "INSERT INTO user_permisos (id_empresa, id_user, modulo, accion) VALUES (?, ?, 'facturacion', 'ver')", [$A['emp'], $A['user']]);
debeFallar('Kit que se contiene a si mismo',
    'INSERT INTO articulo_componentes (id_empresa, id_kit, id_componente, cantidad) VALUES (?, ?, ?, 1)', [$A['emp'], $A['art'], $A['art']]);
debeFallar('Lista de precios fuera de rango (1-5)',
    'INSERT INTO clientes (id_empresa, nombre, lista_precios) VALUES (?, ?, 9)', [$A['emp'], 'x']);
debeFallar('Linea de venta con cantidad cero',
    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, cantidad) VALUES (?, ?, ?, 0)', [$A['emp'], $A['venta'], $A['art']]);

echo "\n== 3. Operaciones validas (deben PASAR) ==\n";
debePasar('Venta de A con su almacen, cliente, vendedor y usuario',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_cliente, id_vendedor, id_usuario) VALUES (?, 'OK-1', NOW(), ?, ?, ?, ?)",
    [$A['emp'], $A['alm'], $A['cli'], $A['empl'], $A['user']]);
debePasar('Linea de venta de A con su articulo y variante',
    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, id_valor1, cantidad) VALUES (?, ?, ?, ?, 1)', [$A['emp'], $A['venta'], $A['art'], $A['val']]);
debePasar('El mismo folio en la tienda B (folios por tienda)',
    "INSERT INTO ventas (id_empresa, folio, fecha, id_almacen) VALUES (?, 'OK-1', NOW(), ?)", [$B['emp'], $B['alm']]);
debePasar('El mismo nombre de usuario en otra tienda (cajero2 en A y en B)',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'cajero2', 'cajero2@demo1.levotek.com', 'x', 'h', 'usuario')", [$A['emp']]);
debePasar('... y en B',
    "INSERT INTO users (id_empresa, usuario, login, nombre, password, rol) VALUES (?, 'cajero2', 'cajero2@demo2.levotek.com', 'x', 'h', 'usuario')", [$B['emp']]);
debePasar('SKU nuevo en la tienda A',
    "INSERT INTO articulos (id_empresa, codigo, sku, descripcion) VALUES (?, 'SKU-PRUEBA', 'SKU-PRUEBA', 'x')", [$A['emp']]);
debePasar('El mismo codigo y SKU en la tienda B (unicos por tienda)',
    "INSERT INTO articulos (id_empresa, codigo, sku, descripcion) VALUES (?, 'SKU-PRUEBA', 'SKU-PRUEBA', 'x')", [$B['emp']]);
debePasar('Traspaso de A entre sus propios almacenes',
    "INSERT INTO traspasos (id_empresa, folio, fecha, id_almacen_origen, id_almacen_destino) VALUES (?, 'T-OK', NOW(), ?, ?)", [$A['emp'], $A['alm'], $A['bod']]);
debePasar('Bitacora de plataforma (superadmin, sin tienda)',
    "INSERT INTO audit_log (id_empresa, id_usuario, act_as, accion) VALUES (NULL, NULL, (SELECT id FROM users WHERE rol = 'superadmin' LIMIT 1), 'crear_tienda')");

Db::rollback();

// schema.sql y lib/Migraciones.php deben describir la misma estructura
require_once __DIR__ . '/../api/lib/Migraciones.php';
$pend = Migraciones::pendientes($pdo);
if ($pend) { $fallos[] = 'schema.sql no incluye: ' . implode(', ', array_column($pend, 0)); echo "  ✗ Migraciones pendientes sobre un esquema recien instalado
"; }
else { $ok++; echo "  ✓ Un esquema recien instalado no tiene migraciones pendientes
"; }

$total = $ok + count($fallos);
echo "\n== Resultado: $ok / $total correctos ==\n";
if ($fallos) { echo "FALLOS:\n - " . implode("\n - ", $fallos) . "\n"; exit(1); }
echo "Aislamiento a nivel de BD: OK (nada se guardo; la transaccion se revirtio)\n";
exit(0);
