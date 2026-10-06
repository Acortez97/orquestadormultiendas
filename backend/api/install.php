<?php
// ============================================================
// LEVOTEK — Instalador (ejecutar UNA vez, luego BORRAR este archivo)
// Abre en el navegador:  https://TU-DOMINIO/api/install.php?go=1
// ============================================================

header('Content-Type: text/html; charset=utf-8');
$cfg = require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/Db.php';

echo '<!doctype html><meta charset="utf-8"><title>Instalador MultiTienda</title>';
echo '<body style="font-family:system-ui,Segoe UI,sans-serif;max-width:720px;margin:40px auto;color:#1e1b4b">';
echo '<h1 style="color:#4f46e5">MultiTienda — Instalador</h1>';

function step($msg, $ok = true) { echo '<p style="margin:4px 0">' . ($ok ? '✅' : '⚠️') . ' ' . htmlspecialchars($msg) . '</p>'; }
function fatal($msg) { echo '<p style="color:#be123c"><b>❌ ' . htmlspecialchars($msg) . '</b></p></body>'; exit; }

try {
    Db::init($cfg['db']);
} catch (Throwable $e) {
    fatal('No se pudo conectar a la BD. Revisa lib/config.php. Detalle: ' . $e->getMessage());
}
step('Conexion a la base de datos OK (' . $cfg['db']['name'] . ')');

if (($_GET['go'] ?? '') !== '1') {
    echo '<p>Esto creara las tablas y un usuario administrador inicial.</p>';
    echo '<p><a style="background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none" href="?go=1">Instalar ahora</a></p>';
    echo '</body>'; exit;
}

// --- 1) Crear tablas desde schema.sql ---
$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) fatal('No se encontro schema.sql junto a install.php');

$pdo = Db::pdo();
$stmts = preg_split('/;\s*[\r\n]/', $sql);
$creadas = 0;
foreach ($stmts as $st) {
    // quitar lineas de comentario para no anular la sentencia
    $lineas = preg_split('/\r?\n/', $st);
    $lineas = array_filter($lineas, fn($l) => strpos(trim($l), '--') !== 0);
    $clean = trim(implode("\n", $lineas));
    if ($clean === '') continue;
    try { $pdo->exec($clean); $creadas++; } catch (Throwable $e) { /* ya existe / ignorable */ }
}
step("Esquema aplicado ($creadas sentencias)");

// --- 1b) Migraciones idempotentes (columnas nuevas sobre tablas ya existentes) ---
$migraciones = [
    ['ventas',    'cambio_efectivo', "ALTER TABLE ventas ADD COLUMN cambio_efectivo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER saldo_favor_generado"],
    ['empleados', 'email',           "ALTER TABLE empleados ADD COLUMN email VARCHAR(160) DEFAULT NULL AFTER telefono"],
    ['empleados', 'area',            "ALTER TABLE empleados ADD COLUMN area VARCHAR(40) NOT NULL DEFAULT 'ventas' AFTER puesto"],
    ['empleados', 'es_vendedor',     "ALTER TABLE empleados ADD COLUMN es_vendedor TINYINT(1) NOT NULL DEFAULT 0 AFTER id_tienda"],
    ['articulos',   'id_corte',          "ALTER TABLE articulos ADD COLUMN id_corte INT DEFAULT NULL AFTER id_coleccion"],
    ['proveedores', 'tipo',              "ALTER TABLE proveedores ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'Mercancia' AFTER contacto"],
    ['proveedores', 'domicilio',         "ALTER TABLE proveedores ADD COLUMN domicilio JSON DEFAULT NULL AFTER tipo"],
    ['empresas',    'pin_lista_alta',    "ALTER TABLE empresas ADD COLUMN pin_lista_alta VARCHAR(255) DEFAULT NULL AFTER iva"],
    ['empresas',    'pin_lista_alta_at', "ALTER TABLE empresas ADD COLUMN pin_lista_alta_at DATETIME DEFAULT NULL AFTER pin_lista_alta"],
];
$mig = 0;
foreach ($migraciones as [$tabla, $col, $alter]) {
    $existe = Db::one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$tabla, $col]);
    if (!$existe) { try { $pdo->exec($alter); $mig++; } catch (Throwable $e) { /* ignorable */ } }
}
if ($mig > 0) step("Migraciones aplicadas ($mig columna/s nueva/s)");

// Migracion de datos: "Colecciones" -> "Cortes" (conserva ids para no romper articulos.id_corte)
try { $pdo->exec("INSERT IGNORE INTO cortes_catalogo (id,id_empresa,nombre,is_active,created_at) SELECT id,id_empresa,nombre,is_active,created_at FROM colecciones"); } catch (Throwable $e) {}
try { $pdo->exec("UPDATE articulos SET id_corte=id_coleccion WHERE id_corte IS NULL AND id_coleccion IS NOT NULL"); } catch (Throwable $e) {}

// --- 2) Semilla (solo si no hay empresa) ---
$existe = Db::one('SELECT id FROM empresas LIMIT 1');
if ($existe) {
    step('La base ya tenia datos; se omitio la semilla.', false);
    echo '<hr><p><b>Listo.</b> Por seguridad <b>borra install.php</b> del servidor.</p></body>'; exit;
}

$emp = Db::insert('INSERT INTO empresas (nombre,rfc) VALUES (?,?)', ['MultiTienda', 'XAXX010101000']);

$permAdmin = json_encode(['admin' => true]);
Db::insert('INSERT INTO users (id_empresa,nombre,apellido,email,password,rol,is_active,permisos) VALUES (?,?,?,?,?,?,?,?)',
    [$emp, 'Administrador', '', 'admin@levotek.mx', password_hash('admin123', PASSWORD_BCRYPT), 'admin', 'Si', $permAdmin]);

$tienda = Db::insert('INSERT INTO almacenes (id_empresa,codigo,nombre,tipo,vende_publico,serie_folio) VALUES (?,?,?,?,?,?)',
    [$emp, 'TIENDA01', 'Tienda Principal', 'tienda', 1, 'T01']);
Db::insert('INSERT INTO almacenes (id_empresa,codigo,nombre,tipo,vende_publico,serie_folio) VALUES (?,?,?,?,?,?)',
    [$emp, 'BODEGA', 'Bodega Central', 'bodega', 0, 'B01']);

// catalogos base
$colores = [['Negro','#111827'],['Blanco','#f9fafb'],['Azul','#1d4ed8'],['Rojo','#dc2626']];
$colorIds = [];
foreach ($colores as $c) $colorIds[] = Db::insert('INSERT INTO colores (id_empresa,nombre,hex) VALUES (?,?,?)', [$emp, $c[0], $c[1]]);
$tallas = [['CH',1],['M',2],['G',3],['XG',4]];
$tallaIds = [];
foreach ($tallas as $t) $tallaIds[] = Db::insert('INSERT INTO tallas (id_empresa,nombre,orden) VALUES (?,?,?)', [$emp, $t[0], $t[1]]);
$fam = Db::insert('INSERT INTO familias (id_empresa,nombre) VALUES (?,?)', [$emp, 'Ropa']);
$mar = Db::insert('INSERT INTO marcas (id_empresa,nombre) VALUES (?,?)', [$emp, 'MultiTienda']);

// MultiTienda: motor de atributos + categorias base
$atrColor  = Db::insert('INSERT INTO atributos (id_empresa,nombre,tipo_valor,orden) VALUES (?,?,?,?)', [$emp, 'Color', 'color', 1]);
$atrTalla  = Db::insert('INSERT INTO atributos (id_empresa,nombre,tipo_valor,orden) VALUES (?,?,?,?)', [$emp, 'Talla', 'texto', 2]);
$atrNumero = Db::insert('INSERT INTO atributos (id_empresa,nombre,tipo_valor,orden) VALUES (?,?,?,?)', [$emp, 'Numero', 'numero', 3]);
$avColor = []; $avTalla = [];
foreach ($colores as $i => $c) $avColor[] = Db::insert('INSERT INTO atributo_valores (id_empresa,id_atributo,nombre,extra,orden) VALUES (?,?,?,?,?)', [$emp, $atrColor, $c[0], $c[1], $i]);
foreach ($tallas as $t)        $avTalla[] = Db::insert('INSERT INTO atributo_valores (id_empresa,id_atributo,nombre,orden) VALUES (?,?,?,?)', [$emp, $atrTalla, $t[0], $t[1]]);
foreach (['22','23','24','25','26','27','28'] as $i => $n) Db::insert('INSERT INTO atributo_valores (id_empresa,id_atributo,nombre,orden) VALUES (?,?,?,?)', [$emp, $atrNumero, $n, $i]);
$catRopa    = Db::insert('INSERT INTO categorias (id_empresa,nombre,prefijo_sku,id_atributo_eje1,id_atributo_eje2) VALUES (?,?,?,?,?)', [$emp, 'Ropa', 'ROP', $atrColor, $atrTalla]);
Db::insert('INSERT INTO categorias (id_empresa,nombre,prefijo_sku,id_atributo_eje1,id_atributo_eje2) VALUES (?,?,?,?,?)', [$emp, 'Calzado', 'CAL', $atrColor, $atrNumero]);
$catGeneral = Db::insert('INSERT INTO categorias (id_empresa,nombre,prefijo_sku,id_atributo_eje1,id_atributo_eje2) VALUES (?,?,?,?,?)', [$emp, 'General', 'GEN', null, null]);

// articulo demo CON variantes (Ropa): eje1=Color, eje2=Talla -> valores de atributo_valores
$art = Db::insert(
    'INSERT INTO articulos (id_empresa,codigo,sku,descripcion,id_familia,id_marca,id_categoria,costo,lista1,lista2,lista3,lista4,lista5,piezas_por_caja)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$emp, 'ROP-00001', 'ROP-00001', 'Playera basica (demo)', $fam, $mar, $catRopa, 80, 199, 189, 179, 169, 159, 1]);
foreach ($avColor as $cid) Db::run('INSERT INTO articulo_colores (id_articulo,id_color) VALUES (?,?)', [$art, $cid]);
foreach ($avTalla as $tid) Db::run('INSERT INTO articulo_tallas (id_articulo,id_talla) VALUES (?,?)', [$art, $tid]);
foreach ($avColor as $cid) foreach ($avTalla as $tid)
    Db::insert('INSERT INTO inventario (id_empresa,id_articulo,id_color,id_talla,id_almacen,cantidad) VALUES (?,?,?,?,?,?)',
        [$emp, $art, $cid, $tid, $tienda, 10]);

// articulo demo SIN variantes (General): se usa la celda unica (0,0)
$art2 = Db::insert(
    'INSERT INTO articulos (id_empresa,codigo,sku,descripcion,id_categoria,unidad,costo,lista1,lista2,lista3,lista4,lista5)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    [$emp, 'GEN-00002', 'GEN-00002', 'Producto simple (demo)', $catGeneral, 'pieza', 20, 49, 45, 42, 39, 35]);
Db::insert('INSERT INTO inventario (id_empresa,id_articulo,id_color,id_talla,id_almacen,cantidad) VALUES (?,?,0,0,?,?)',
    [$emp, $art2, $tienda, 25]);

// cliente publico general
Db::insert('INSERT INTO clientes (id_empresa,nombre,lista_precios,es_publico_general,id_tienda) VALUES (?,?,?,?,?)',
    [$emp, 'Publico General', 1, 1, $tienda]);

step('Datos iniciales creados');
echo '<hr><h2>✅ Instalacion completa</h2>';
echo '<p><b>Usuario:</b> admin@levotek.mx<br><b>Contrasena:</b> admin123</p>';
echo '<p style="color:#be123c"><b>IMPORTANTE:</b> 1) Cambia la contrasena al entrar. 2) <b>Borra este archivo install.php</b> del servidor. 3) Cambia <code>jwt_secret</code> en lib/config.php.</p>';
echo '</body>';
