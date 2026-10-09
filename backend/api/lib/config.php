<?php
// ============================================================
// Orquestador MultiTiendas — Configuracion del backend
//
// Este archivo NO lleva secretos. Los valores reales vienen de:
//   1) variables de entorno (DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET, ...), o
//   2) lib/config.local.php  (ignorado por git; ver lib/config.local.example.php).
// En GoDaddy: copia config.local.example.php como config.local.php y llena tus datos.
// ============================================================

$cfg = [
    // --- Base de datos MySQL / MariaDB ---
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: '',
        'user'    => getenv('DB_USER') ?: '',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        // Hora de la operacion (cortes, plazos, vencimientos). PHP y MySQL usan la misma.
        'zona_horaria' => getenv('APP_TZ') ?: 'America/Mexico_City',
    ],

    // --- Seguridad / JWT ---
    // Obligatorio: minimo 32 caracteres aleatorios (openssl rand -hex 48). Sin el, el API no arranca.
    'jwt_secret'  => getenv('JWT_SECRET') ?: '',
    'jwt_expira'  => 60 * 60 * 12, // 12 horas (segundos)

    // --- Correos de acceso ---
    // Los usuarios entran con usuario@<slug-tienda>.<login_domain>; el superadmin con usuario@<login_domain>.
    // Son identificadores de acceso, no buzones reales.
    'login_domain' => getenv('LOGIN_DOMAIN') ?: 'levotek.com',

    // --- CORS ---
    // Front y back en el MISMO dominio -> '' (no hace falta CORS). En desarrollo lo pone config.local.php.
    'cors_origin' => getenv('CORS_ORIGIN') ?: '',

    // Errores detallados: SIEMPRE false en produccion.
    'debug' => (getenv('APP_DEBUG') === 'true'),
];

// --- Overrides locales / de despliegue (archivo ignorado por git) ---
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) $cfg = array_replace_recursive($cfg, $local);
}

return $cfg;
