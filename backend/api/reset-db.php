<?php
// ============================================================
// MultiTienda — RESET de base de datos (SOLO DESARROLLO / PRUEBAS)
// Elimina TODAS las tablas de la BD y reinstala el esquema limpio
// (ya con FOREIGN KEYS) + la semilla inicial.
//
//   Abrir:  http://localhost/api/reset-db.php?go=1
//
// ⚠️ BORRA TODOS LOS DATOS. No subir a produccion. Borrar tras usar.
// ============================================================

header('Content-Type: text/html; charset=utf-8');
$cfg = require __DIR__ . '/lib/config.php';   // solo devuelve un array (seguro requerir 2 veces)

echo '<!doctype html><meta charset="utf-8"><title>Reset MultiTienda</title>';
echo '<body style="font-family:system-ui,Segoe UI,sans-serif;max-width:720px;margin:40px auto;color:#1e1b4b">';
echo '<h1 style="color:#be123c">MultiTienda — Reset de base de datos</h1>';

if (($_GET['go'] ?? '') !== '1') {
    echo '<p>Esto <b>elimina TODAS las tablas</b> de <code>' . htmlspecialchars($cfg['db']['name']) . '</code> y las vuelve a crear con sus relaciones (FKs) + datos iniciales.</p>';
    echo '<p><a style="background:#be123c;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none" href="?go=1">Borrar y reinstalar</a></p>';
    echo '</body>'; exit;
}

// 1) Eliminar todas las tablas con una conexion propia (NO se carga Db.php aqui,
//    para que install.php pueda requerirlo sin chocar por doble declaracion de clase).
$db = $cfg['db'];
$charset = $db['charset'] ?? 'utf8mb4';
try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$charset}",
        $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    echo '<p style="color:#be123c"><b>❌ No se pudo conectar a la BD: ' . htmlspecialchars($e->getMessage()) . '</b></p></body>'; exit;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tablas = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablas as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo = null; // liberar la conexion antes de reinstalar
echo '<p>🗑️ Tablas eliminadas: ' . count($tablas) . '</p>';

// 2) Reinstalar: reusa install.php (aplica schema.sql con FKs, migraciones y semilla).
$_GET['go'] = '1';
require __DIR__ . '/install.php';
