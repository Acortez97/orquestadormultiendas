<?php
// ============================================================
// Plantilla de configuracion local / de despliegue.
// Copiala como lib/config.local.php (ese archivo esta en .gitignore) y llena los valores.
// ============================================================
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3307',                    // XAMPP local en 3307 (ver iniciar.bat). GoDaddy: 3306
        'name' => 'orquestadormultiendas',   // GoDaddy: nombre con prefijo de cPanel (micuenta_orquestador)
        'user' => 'root',
        'pass' => '',
    ],
    'jwt_secret'   => 'PON_AQUI_64_CARACTERES_ALEATORIOS',   // openssl rand -hex 48
    'migrar_clave' => 'PON_AQUI_OTRA_CLAVE_LARGA',           // para abrir api/migrar.php?clave=... (16+ caracteres)
    'login_domain' => 'levotek.com',
    'debug'        => true,                  // GoDaddy: false
    'cors_origin'  => '*',                   // GoDaddy: '' (mismo dominio)
];
