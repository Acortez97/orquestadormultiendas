<?php
// ============================================================
// Orquestador MultiTiendas — RESET de base de datos (SOLO DESARROLLO)
// Elimina TODAS las tablas y reinstala el esquema v2 + semilla.
//
//   Navegador: http://127.0.0.1:8082/reset-db.php?go=1      (solo localhost y debug=true)
//   Consola:   php reset-db.php --go [--demo]
//
// Bloqueado si debug=false o si la peticion no viene de la propia maquina.
// NUNCA subir a produccion (DESPLIEGUE-GODADDY.md lo excluye).
// ============================================================

$cfg = require __DIR__ . '/lib/config.php';
$cli = PHP_SAPI === 'cli';

$remoto = $_SERVER['REMOTE_ADDR'] ?? '';
if (!$cfg['debug'] || (!$cli && !in_array($remoto, ['127.0.0.1', '::1'], true))) {
    http_response_code(404);
    exit('Not found');
}

if ($cli) {
    $go = in_array('--go', $argv, true);
} else {
    header('Content-Type: text/html; charset=utf-8');
    $go = ($_GET['go'] ?? '') === '1';
    echo '<!doctype html><meta charset="utf-8"><title>Reset BD</title>';
    echo '<body style="font-family:system-ui,Segoe UI,sans-serif;max-width:760px;margin:40px auto;color:#1e1b4b">';
    echo '<h1 style="color:#be123c">Reset de base de datos (desarrollo)</h1>';
}

if (!$go) {
    $msg = 'Esto ELIMINA TODAS las tablas de ' . $cfg['db']['name'] . ' y reinstala el esquema v2.';
    if ($cli) { echo $msg . "\nUsa: php reset-db.php --go [--demo]\n"; exit(1); }
    echo '<p>' . htmlspecialchars($msg) . '</p><p><a href="?go=1&demo=1">Borrar y reinstalar (con tiendas demo)</a></p></body>';
    exit;
}

$db = $cfg['db'];
$pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tablas = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablas as $t) $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo = null;
echo ($cli ? '' : '<p>') . 'Tablas eliminadas: ' . count($tablas) . ($cli ? "\n" : '</p>');

// Reinstalar reutilizando install.php
if ($cli) { $argv[] = '--go'; } else { $_GET['go'] = '1'; }
require __DIR__ . '/install.php';
