<?php
// ============================================================
// Orquestador MultiTiendas — Actualizador de base de datos
//
//   Navegador: https://TU-DOMINIO/api/migrar.php          (muestra pendientes)
//              https://TU-DOMINIO/api/migrar.php?go=1     (las aplica)
//   Consola:   php migrar.php [--go]
//
// Lleva una base ya instalada a la version actual (ver lib/Migraciones.php).
// Es seguro correrlo varias veces: solo agrega lo que falta y nunca borra datos.
// Despues de usarlo en produccion: BORRA este archivo del servidor.
// ============================================================

$cli = PHP_SAPI === 'cli';
$cfg = require __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Migraciones.php';

if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Actualizar base de datos</title>';
    echo '<body style="font-family:system-ui,Segoe UI,sans-serif;max-width:760px;margin:40px auto;color:#1e1b4b">';
    echo '<h1 style="color:#4f46e5">Actualizar base de datos</h1>';
}
function linea(string $msg, bool $ok = true): void {
    global $cli;
    if ($cli) { echo ($ok ? '[ok] ' : '[!!] ') . $msg . "\n"; return; }
    echo '<p style="margin:4px 0">' . ($ok ? '✅' : '⚠️') . ' ' . htmlspecialchars($msg) . '</p>';
}
function termina(string $msg): void {
    global $cli;
    if ($cli) { fwrite(STDERR, "[ERROR] $msg\n"); exit(1); }
    echo '<p style="color:#be123c"><b>❌ ' . htmlspecialchars($msg) . '</b></p></body>'; exit;
}

$go = $cli ? in_array('--go', $argv ?? [], true) : (($_GET['go'] ?? '') === '1');

try {
    Db::init($cfg['db']);
} catch (Throwable $e) {
    termina('No se pudo conectar a la BD. Revisa lib/config.local.php.');
}
$pdo = Db::pdo();
$tablas = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'empresas'")->fetchColumn();
if ($tablas === 0) termina('La base no esta instalada. Usa install.php primero.');

$pend = Migraciones::pendientes($pdo);
if (!$pend) {
    linea('La base ya esta actualizada. No hay nada que aplicar.');
    if (!$cli) echo '<p style="color:#be123c"><b>Borra <code>migrar.php</code> del servidor.</b></p></body>';
    exit(0);
}
if (!$go) {
    foreach ($pend as [$n, $d]) linea("Pendiente: $d ($n)", false);
    if ($cli) { echo "Para aplicarlas: php migrar.php --go\n"; exit(0); }
    echo '<p>Haz un respaldo de la base (cPanel → phpMyAdmin → Exportar) antes de continuar.</p>';
    echo '<p><a style="background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none" href="?go=1">Aplicar ahora</a></p></body>';
    exit(0);
}

try {
    foreach (Migraciones::aplicar($pdo) as [$n, $d, $estado]) linea("$d: $estado");
} catch (Throwable $e) {
    termina('Error al actualizar: ' . $e->getMessage());
}
linea('Base de datos actualizada.');
if (!$cli) echo '<p style="color:#be123c"><b>IMPORTANTE:</b> borra <code>migrar.php</code> del servidor.</p></body>';
