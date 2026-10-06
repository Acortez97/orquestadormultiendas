<?php
// ============================================================
// MultiTienda — Configuracion del backend
// PRODUCCION (GoDaddy): edita los 3 valores de la BD abajo (name/user/pass).
// (En GoDaddy: cPanel > "Bases de datos MySQL" para crear la BD y el usuario.)
// En LOCAL (XAMPP) NO edites esto: lib/config.local.php sobreescribe estos valores.
// ============================================================

$cfg = [
    // --- Base de datos MySQL ---
    // GODADDY: cambia SOLO name/user/pass por los de tu cPanel.
    // 'host' = 'localhost' y 'port' = '3306' normalmente NO se tocan en GoDaddy.
    // En cPanel el nombre de la BD y del usuario llevan prefijo, p.ej. 'micuenta_multitienda'.
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        'name'    => getenv('DB_NAME') ?: 'multitienda',       // <- GoDaddy: si cPanel le puso prefijo, usa el nombre COMPLETO (ej. micuenta_multitienda)
        'user'    => getenv('DB_USER') ?: 'multitienda',       // <- GoDaddy: usuario de la BD (con prefijo si aplica)
        'pass'    => getenv('DB_PASS') ?: 'multitienda123',    // <- GoDaddy: contraseña de ese usuario
        'charset' => 'utf8mb4',
    ],

    // --- Seguridad / JWT ---
    // CAMBIA esta cadena por una propia y unica en tu despliegue (o define JWT_SECRET por env).
    // Puedes generar una nueva en https://www.random.org/strings o con: openssl rand -hex 48
    'jwt_secret'  => getenv('JWT_SECRET') ?: 'mt_CAMBIA_ESTE_SECRETO_9d4e1c7a2f8b03e56a1d9c4b7e2f5081a3c6d9b0e4f7a2c5',
    'jwt_expira'  => 60 * 60 * 12, // 12 horas (segundos)

    // --- CORS ---
    // Front y back en el MISMO dominio (GoDaddy) -> deja '' (no hace falta CORS).
    // Solo para desarrollo con Vite en otro puerto se usa un origen; eso lo pone config.local.php.
    'cors_origin' => getenv('CORS_ORIGIN') ?: '',

    // Errores detallados: SIEMPRE false en produccion.
    'debug' => (getenv('APP_DEBUG') === 'true'),
];

// --- Overrides de DESARROLLO LOCAL ---
// Si existe lib/config.local.php, sus valores sobreescriben los de arriba (BD local de XAMPP).
// Ese archivo NO se incluye en el paquete de GoDaddy, asi que en produccion se usan los valores de arriba.
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) $cfg = array_replace_recursive($cfg, $local);
}

return $cfg;
