<?php
// ============================================================
// Orquestador MultiTiendas — Instalador (esquema v2)
//
//   Navegador: https://TU-DOMINIO/api/install.php?go=1          (produccion: sin tiendas demo)
//   Consola:   php install.php --go [--demo]                     (desarrollo)
//
// Solo instala sobre una base VACIA. Crea el esquema, el superadmin y,
// con --demo / ?demo=1, dos tiendas de prueba (demo1, demo2).
// Las contrasenas se generan al azar y se muestran UNA vez.
// Despues de instalar en produccion: BORRA este archivo del servidor.
// ============================================================

$cli = PHP_SAPI === 'cli';
$cfg = require __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Seeder.php';

if (!$cli && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Instalador</title>';
    echo '<body style="font-family:system-ui,Segoe UI,sans-serif;max-width:760px;margin:40px auto;color:#1e1b4b">';
    echo '<h1 style="color:#4f46e5">Orquestador MultiTiendas — Instalador</h1>';
}

function out(string $msg, bool $ok = true): void {
    global $cli;
    if ($cli) { echo ($ok ? '[ok] ' : '[!!] ') . $msg . "\n"; return; }
    echo '<p style="margin:4px 0">' . ($ok ? '✅' : '⚠️') . ' ' . htmlspecialchars($msg) . '</p>';
}
function fatal(string $msg): void {
    global $cli;
    if ($cli) { fwrite(STDERR, "[ERROR] $msg\n"); exit(1); }
    echo '<p style="color:#be123c"><b>❌ ' . htmlspecialchars($msg) . '</b></p></body>'; exit;
}

$args = $cli ? ($argv ?? []) : [];
$go   = $cli ? in_array('--go', $args, true)   : (($_GET['go'] ?? '') === '1');
$demo = $cli ? in_array('--demo', $args, true) : (($_GET['demo'] ?? '') === '1');

if (strlen((string) $cfg['jwt_secret']) < 32) fatal('Falta jwt_secret (min. 32 caracteres) en lib/config.local.php');
if (empty($cfg['login_domain'])) fatal('Falta login_domain en la configuracion');

try {
    Db::init($cfg['db']);
} catch (Throwable $e) {
    fatal('No se pudo conectar a la BD. Revisa lib/config.local.php. Detalle: ' . $e->getMessage());
}
$pdo = Db::pdo();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
out('Conexion a la base de datos OK (' . $cfg['db']['name'] . ', ' . $version . ')');
// Las reglas CHECK del esquema solo se aplican en MySQL 8.0.16+ o MariaDB 10.2+.
// En versiones anteriores se ignoran (el aislamiento por llaves foraneas compuestas SI funciona
// y la API valida esas mismas reglas), pero conviene saberlo.
$esMaria = stripos($version, 'mariadb') !== false;
$num = preg_replace('/[^0-9.].*$/', '', $version);
if ((!$esMaria && version_compare($num, '8.0.16', '<')) || ($esMaria && version_compare($num, '10.2', '<'))) {
    out("Version de BD $version: las reglas CHECK no se aplicaran en la base (la API las valida igual). Recomendado: MySQL 8.0.16+ o MariaDB 10.4+.", false);
}

$tablas = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($tablas > 0) {
    fatal("La base ya tiene $tablas tablas: el instalador solo trabaja sobre una base vacia. "
        . 'En desarrollo usa: php reset-db.php --go --demo');
}

if (!$go) {
    if ($cli) { echo "Uso: php install.php --go [--demo]\n"; exit(1); }
    echo '<p>Esto creara las tablas y el superadministrador.</p>';
    echo '<p><a style="background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none" href="?go=1">Instalar ahora</a></p></body>';
    exit;
}

// --- 1) Esquema ---
$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) fatal('No se encontro schema.sql junto a install.php');
$lineas = array_filter(preg_split('/\r?\n/', $sql), fn($l) => strpos(ltrim($l), '--') !== 0);
$sentencias = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', implode("\n", $lineas))));
$n = 0;
foreach ($sentencias as $st) {
    try {
        $pdo->exec($st);
        $n++;
    } catch (Throwable $e) {
        fatal('Error aplicando el esquema: ' . $e->getMessage() . ' — en: ' . substr($st, 0, 120));
    }
}
out("Esquema aplicado ($n sentencias)");

// --- 2) Superadmin y tiendas demo ---
$dominio = $cfg['login_domain'];
$cred = [];
try {
    Db::begin();
    $passSa = Seeder::passwordAleatorio();
    Seeder::superadmin('admin', 'Superadministrador', $passSa, $dominio);
    $cred[] = ['Superadmin', LoginId::armar('admin', null, $dominio), $passSa];

    if ($demo) {
        foreach ([['demo1', 'Tienda Demo Uno'], ['demo2', 'Tienda Demo Dos']] as [$slug, $nombre]) {
            $passAdmin = Seeder::passwordAleatorio();
            $t = Seeder::crearTienda(['slug' => $slug, 'nombre' => $nombre, 'admin_password' => $passAdmin], $dominio);
            $passCajero = Seeder::passwordAleatorio();
            $c = Seeder::datosDemo($t['id_empresa'], $slug, $dominio, $passCajero);
            $cred[] = ["Admin $nombre", $t['login_admin'], $passAdmin];
            $cred[] = ["Cajero $nombre", $c['login_cajero'], $passCajero];
        }
    }
    Db::commit();
} catch (Throwable $e) {
    Db::rollback();
    fatal('Error creando datos iniciales: ' . $e->getMessage());
}
out('Superadmin creado' . ($demo ? ' + 2 tiendas demo (demo1, demo2)' : ''));

// --- 3) Credenciales (se muestran una sola vez) ---
$archivoCred = null;
if ($cfg['debug']) {
    // En desarrollo se guardan en un archivo ignorado por git para no perderlas.
    $archivoCred = __DIR__ . '/credenciales.local.txt';
    $txt = "Credenciales generadas por install.php el " . date('Y-m-d H:i') . " (solo desarrollo; ignorado por git)\n";
    foreach ($cred as [$quien, $login, $pass]) $txt .= str_pad($quien, 28) . str_pad($login, 40) . $pass . "\n";
    file_put_contents($archivoCred, $txt);
}

if ($cli) {
    echo "\nInstalacion completa. Todos deben cambiar su contrasena al primer acceso.\n";
    echo $archivoCred ? "Credenciales guardadas en: $archivoCred\n" : '';
    if (!$archivoCred) foreach ($cred as [$quien, $login, $pass]) echo str_pad($quien, 28) . str_pad($login, 40) . $pass . "\n";
} else {
    echo '<hr><h2>✅ Instalacion completa</h2><p>Copia estas credenciales ahora: <b>no se volveran a mostrar</b>. '
       . 'Todos deben cambiar su contrasena al primer acceso.</p><table cellpadding="6" style="border-collapse:collapse">';
    foreach ($cred as [$quien, $login, $pass]) {
        echo '<tr><td>' . htmlspecialchars($quien) . '</td><td><code>' . htmlspecialchars($login) . '</code></td><td><code>'
           . htmlspecialchars($pass) . '</code></td></tr>';
    }
    echo '</table><p style="color:#be123c"><b>IMPORTANTE:</b> borra <code>install.php</code> y <code>reset-db.php</code> del servidor.</p></body>';
}
