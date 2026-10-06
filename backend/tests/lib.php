<?php
// ============================================================
// Arnes minimo para pruebas HTTP contra la API local.
//   Requiere el backend corriendo:  php -S 127.0.0.1:8082 -t backend/api
//   y la BD con tiendas demo:        php backend/api/reset-db.php --go --demo
// API_URL se puede cambiar con la variable de entorno API_URL.
// ============================================================

define('API', getenv('API_URL') ?: 'http://127.0.0.1:8082/index.php/v1');

$GLOBALS['T_OK'] = 0;
$GLOBALS['T_FALLOS'] = [];

/** Peticion HTTP. Devuelve ['status' => int, 'body' => array|null, 'raw' => string] */
function api(string $metodo, string $ruta, $body = null, ?string $token = null): array
{
    $h = ['Content-Type: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $ctx = stream_context_create(['http' => [
        'method' => $metodo, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 30,
        'content' => $body === null ? '' : json_encode($body),
    ]]);
    $raw = @file_get_contents(API . $ruta, false, $ctx);
    if ($raw === false) { fwrite(STDERR, "No hay conexion con " . API . " (¿esta corriendo el backend?)\n"); exit(2); }
    preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => json_decode($raw, true), 'raw' => $raw];
}

function check(string $desc, bool $cond, string $detalle = ''): bool
{
    if ($cond) { $GLOBALS['T_OK']++; echo "  ✓ $desc\n"; return true; }
    $GLOBALS['T_FALLOS'][] = $desc . ($detalle ? " — $detalle" : '');
    echo "  ✗ $desc" . ($detalle ? "  ($detalle)" : '') . "\n";
    return false;
}

/** Verifica el codigo HTTP de una respuesta */
function status(string $desc, array $r, int $esperado): bool
{
    return check($desc, $r['status'] === $esperado,
        "esperado $esperado, llego {$r['status']}: " . substr($r['body']['error']['message'] ?? $r['raw'], 0, 160));
}

function seccion(string $t): void { echo "\n== $t ==\n"; }

function fin(): void
{
    $ok = $GLOBALS['T_OK']; $f = $GLOBALS['T_FALLOS'];
    echo "\n== Resultado: $ok / " . ($ok + count($f)) . " ==\n";
    if ($f) { echo "FALLOS:\n - " . implode("\n - ", $f) . "\n"; exit(1); }
    exit(0);
}

/** Credenciales generadas por install.php (solo desarrollo) */
function credenciales(): array
{
    $f = __DIR__ . '/../api/credenciales.local.txt';
    if (!is_file($f)) { fwrite(STDERR, "Falta $f. Corre: php backend/api/reset-db.php --go --demo\n"); exit(2); }
    $out = [];
    foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1) as $l) {
        $p = preg_split('/\s{2,}/', trim($l));
        if (count($p) >= 3) $out[$p[count($p) - 2]] = $p[count($p) - 1];
    }
    return $out;
}

/** Login y devuelve [token, user] o termina si falla */
function entrar(string $login, string $pass): array
{
    $r = api('POST', '/auth/login', ['email' => $login, 'password' => $pass]);
    if ($r['status'] !== 200) { fwrite(STDERR, "Login fallo para $login: {$r['raw']}\n"); exit(2); }
    return [$r['body']['data']['token'], $r['body']['data']['user']];
}

/** Busca en una respuesta cualquier id interno expuesto (clave de id con valor numerico) */
function idsCrudos($data, string $ruta = ''): array
{
    $malos = [];
    if (!is_array($data)) return $malos;
    foreach ($data as $k => $v) {
        $r = $ruta === '' ? (string) $k : "$ruta.$k";
        $esId = is_string($k) && ($k === 'id' || $k === '_id' || strncmp($k, 'id_', 3) === 0 || substr($k, -4) === '_por');
        if ($k === 'id_empresa') $malos[] = "$r (id_empresa expuesto)";
        elseif ($esId && (is_int($v) || (is_string($v) && $v !== '' && ctype_digit($v)))) $malos[] = "$r=$v";
        elseif (is_array($v)) $malos = array_merge($malos, idsCrudos($v, $r));
    }
    return $malos;
}
