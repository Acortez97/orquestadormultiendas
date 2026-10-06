<?php
// ============================================================
// Arma la carpeta lista para subir a GoDaddy:  deploy-godaddy/public_html/
//
//   1) cd frontend && npm run build        (genera frontend/dist)
//   2) php herramientas/empaquetar.php
//
// Copia frontend/dist -> public_html/  y  backend/api -> public_html/api/
// EXCLUYENDO todo lo que nunca debe llegar al servidor (reset-db.php, configuracion
// y credenciales locales, fotos de prueba, pruebas). Al final verifica el paquete.
// ============================================================
$raiz = dirname(__DIR__);
$dist = "$raiz/frontend/dist";
$api  = "$raiz/backend/api";
$dest = "$raiz/deploy-godaddy/public_html";

function falla(string $m): void { fwrite(STDERR, "[ERROR] $m\n"); exit(1); }

if (!is_file("$dist/index.html")) falla('No existe frontend/dist. Corre primero: cd frontend && npm run build');
$envProd = @file_get_contents("$raiz/frontend/.env.production") ?: '';
if (!str_contains($envProd, 'VITE_API_URL=/api/')) falla('frontend/.env.production debe apuntar a /api/... (mismo dominio)');

// Nunca se suben (rutas relativas a backend/api)
$excluir = [
    '#^reset-db\.php$#',
    '#^credenciales\.local\.txt$#',
    '#^lib/config\.local\.php$#',
    '#^uploads/(?!\.htaccess$)#',      // fotos locales de prueba; en el servidor se crean solas
    '#\.log$#',
];

function borrar(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

function copiar(string $origen, string $destino, array $excluir = []): int
{
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($origen) + 1));
        foreach ($excluir as $re) if (preg_match($re, $rel)) continue 2;
        $to = "$destino/$rel";
        if ($f->isDir()) { if (!is_dir($to)) mkdir($to, 0775, true); continue; }
        if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
        copy($f->getPathname(), $to);
        $n++;
    }
    return $n;
}

borrar("$raiz/deploy-godaddy");
mkdir($dest, 0775, true);
$nFront = copiar($dist, $dest);
$nApi = copiar($api, "$dest/api", $excluir);
if (!is_dir("$dest/api/uploads")) mkdir("$dest/api/uploads", 0775, true);

// ---- Verificacion del paquete ----
$prohibidos = ['api/reset-db.php', 'api/credenciales.local.txt', 'api/lib/config.local.php'];
foreach ($prohibidos as $p) if (file_exists("$dest/$p")) falla("El paquete contiene $p");
foreach (['index.html', '.htaccess', 'api/index.php', 'api/install.php', 'api/schema.sql', 'api/.htaccess', 'api/lib/.htaccess',
          'api/uploads/.htaccess', 'api/lib/config.php', 'api/lib/config.local.example.php'] as $p) {
    if (!file_exists("$dest/$p")) falla("Falta $p en el paquete");
}
$cfgPhp = file_get_contents("$dest/api/lib/config.php");
if (preg_match("/'jwt_secret'\s*=>\s*getenv\('JWT_SECRET'\)\s*\?:\s*'[^']+'/", $cfgPhp)) falla('config.php trae un jwt_secret por defecto');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (preg_match('/\.(js|html)$/', $f->getFilename()) && str_contains(file_get_contents($f->getPathname()), '127.0.0.1:8082')) {
        falla('El frontend compilado apunta al backend local (127.0.0.1:8082): revisa frontend/.env.production');
    }
}

echo "Paquete listo en deploy-godaddy/public_html ($nFront archivos del frontend, $nApi del API).\n";
echo "Siguiente: crear lib/config.local.php en el servidor (ver DESPLIEGUE-GODADDY.md).\n";
