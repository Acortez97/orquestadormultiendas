<?php
// ============================================================
// F4 — API de plataforma (superadmin).
//   php backend/api/reset-db.php --go --demo
//   php -S 127.0.0.1:8082 -t backend/api      (otra terminal)
//   php backend/tests/plataforma_test.php
// ============================================================
require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);

$c = credenciales();
[$tSA] = entrar('admin@levotek.com', $c['admin@levotek.com']);
[$tA1] = entrar('admin@demo1.levotek.com', $c['admin@demo1.levotek.com']);

function d(array $r) { return $r['body']['data'] ?? []; }

seccion('Tablero y catalogo');
$r = api('GET', '/plataforma/dashboard', null, $tSA);
status('Dashboard', $r, 200);
check('Dashboard cuenta las 2 tiendas demo', d($r)['totales']['tiendas'] === 2);
check('Dashboard sin ids internos', !idsCrudos(d($r)), implode(',', idsCrudos(d($r))));
check('Catalogo de 18 modulos', count(d(api('GET', '/plataforma/modulos', null, $tSA))) === 18);

seccion('Alta de tienda');
$r = api('POST', '/plataforma/tiendas', ['slug' => 'ZapateriaCentro', 'nombre' => 'Zapateria Centro', 'admin_nombre' => 'Dueno',
    'modulos' => ['ventas', 'clientes', 'catalogos', 'cortes', 'usuarios', 'almacen'], 'max_usuarios' => 3], $tSA);
status('Crea tienda con modulos limitados', $r, 201);
$z = d($r);
check('Subdominio normalizado a minusculas', $z['slug'] === 'zapateriacentro');
check('Correo del admin armado con su subdominio', $z['admin']['login'] === 'admin@zapateriacentro.levotek.com');
check('Contrasena generada y mostrada una vez', strlen((string) $z['admin']['password']) >= 8);
check('Sin ids internos', !idsCrudos($z));
status('Subdominio invalido -> 400', api('POST', '/plataforma/tiendas', ['slug' => 'mi tienda', 'nombre' => 'X'], $tSA), 400);
status('Subdominio reservado -> 400', api('POST', '/plataforma/tiendas', ['slug' => 'admin', 'nombre' => 'X'], $tSA), 400);
status('Subdominio repetido -> 400', api('POST', '/plataforma/tiendas', ['slug' => 'demo1', 'nombre' => 'X'], $tSA), 400);
status('Modulo inexistente -> 400', api('POST', '/plataforma/tiendas', ['slug' => 'otra', 'nombre' => 'X', 'modulos' => ['volar']], $tSA), 400);
status('No se puede cambiar el subdominio', api('PUT', "/plataforma/tiendas/{$z['_id']}", ['slug' => 'otro'], $tSA), 400);
status('Edita nombre e IVA', api('PUT', "/plataforma/tiendas/{$z['_id']}", ['nombre' => 'Zapateria del Centro', 'iva' => 0.08], $tSA), 200);

[$tZ, $uZ] = entrar($z['admin']['login'], $z['admin']['password']);
check('El admin nuevo debe cambiar su contrasena', $uZ['debe_cambiar_password'] === true);
$mods = array_keys(array_diff_key($uZ['permisos'], ['admin' => 1])); sort($mods);
check('Solo tiene los modulos habilitados', $mods == ['almacen', 'catalogos', 'clientes', 'cortes', 'usuarios', 'ventas'], implode(',', $mods));
$almZ = d(api('GET', '/almacen/almacenes', null, $tZ));
check('La tienda nueva solo ve su almacen sembrado', count($almZ) === 1 && $almZ[0]['codigo'] === 'PRINCIPAL');
check('Tiene sus propios catalogos base (3 categorias)', count(d(api('GET', '/categorias', null, $tZ))) === 3);
status('Modulo no habilitado (compras) -> 403', api('GET', '/compras', null, $tZ), 403);

seccion('Modulos de la tienda');
status('Deshabilita "clientes"', api('PUT', "/plataforma/tiendas/{$z['_id']}/modulos", ['modulos' => ['ventas', 'catalogos', 'cortes', 'usuarios', 'almacen']], $tSA), 200);
status('El admin de la tienda ya no puede crear clientes', api('POST', '/clientes', ['nombre' => 'X'], $tZ), 403);
check('Y desaparece de sus permisos', empty(d(api('GET', '/auth/me', null, $tZ))['permisos']['clientes']));
status('Rehabilita "clientes" y habilita "bitacora"', api('PUT', "/plataforma/tiendas/{$z['_id']}/modulos", ['modulos' => ['ventas', 'clientes', 'catalogos', 'cortes', 'usuarios', 'almacen', 'bitacora']], $tSA), 200);
status('Vuelve a poder crear clientes', api('POST', '/clientes', ['nombre' => 'Cliente Z'], $tZ), 201);

seccion('Usuarios desde la plataforma');
$r = api('POST', "/plataforma/tiendas/{$z['_id']}/usuarios", ['usuario' => 'gerente', 'nombre' => 'Gerente', 'rol' => 'admin_tienda'], $tSA);
status('Superadmin crea un segundo admin de tienda', $r, 201);
$ger = d($r);
check('Login correcto y contrasena generada', $ger['login'] === 'gerente@zapateriacentro.levotek.com' && strlen((string) $ger['password_generada']) >= 8);
status('Permiso de modulo no habilitado -> 400', api('POST', "/plataforma/tiendas/{$z['_id']}/usuarios",
    ['usuario' => 'v1', 'nombre' => 'V', 'permisos' => ['compras' => ['ver' => true]]], $tSA), 400);
status('Limite de usuarios (max 3)', api('POST', "/plataforma/tiendas/{$z['_id']}/usuarios", ['usuario' => 'v2', 'nombre' => 'V'], $tSA), 201);
status('El cuarto usuario rebasa el limite -> 400', api('POST', "/plataforma/tiendas/{$z['_id']}/usuarios", ['usuario' => 'v3', 'nombre' => 'V'], $tSA), 400);
[$tG] = entrar($ger['login'], $ger['password_generada']);
status('Cambiar rol del gerente a usuario', api('PUT', "/plataforma/usuarios/{$ger['_id']}", ['rol' => 'usuario', 'permisos' => ['ventas' => ['ver' => true]]], $tSA), 200);
status('La sesion del gerente se cerro', api('GET', '/auth/me', null, $tG), 401);
$r = api('POST', "/plataforma/usuarios/{$ger['_id']}/reset-password", null, $tSA);
status('Reset de contrasena genera una nueva', $r, 200);
[, $uG] = entrar($ger['login'], d($r)['password_generada']);
check('Tras el reset debe cambiarla', $uG['debe_cambiar_password'] === true && $uG['rol'] === 'usuario');
$lista = d(api('GET', "/plataforma/tiendas/{$z['_id']}/usuarios", null, $tSA));
check('Lista de usuarios de la tienda (3)', count($lista) === 3 && !array_filter($lista, fn($u) => !str_ends_with($u['login'], '@zapateriacentro.levotek.com')));
check('Lista global incluye todas las tiendas', count(d(api('GET', '/plataforma/usuarios', null, $tSA))) === 7);

seccion('Suspension');
status('Suspende la tienda', api('PATCH', "/plataforma/tiendas/{$z['_id']}/estado", ['is_active' => 'No'], $tSA), 200);
$r = api('GET', '/auth/me', null, $tZ);
check('Sus usuarios quedan fuera (403 SUSPENDED)', $r['status'] === 403 && $r['body']['error']['code'] === 'SUSPENDED');
status('Las demas tiendas siguen operando', api('GET', '/auth/me', null, $tA1), 200);
check('Sus datos se conservan', (int) Db::one("SELECT COUNT(*) n FROM clientes c JOIN empresas e ON e.id = c.id_empresa WHERE e.slug = 'zapateriacentro'")['n'] === 2);
status('Reactiva la tienda', api('PATCH', "/plataforma/tiendas/{$z['_id']}/estado", ['is_active' => 'Si'], $tSA), 200);
status('Vuelve a operar', api('GET', '/auth/me', null, $tZ), 200);

seccion('Aviso de pago pendiente');
check('Sin aviso de inicio', d(api('GET', '/auth/me', null, $tZ))['tienda']['aviso_pago'] === null);
$r = api('PUT', "/plataforma/tiendas/{$z['_id']}/aviso-pago", ['mensaje' => '  Tu mensualidad esta vencida  '], $tSA);
status('El superadmin pone el aviso', $r, 200);
check('La tienda lo ve en su sesion (sin espacios sobrantes)', d(api('GET', '/auth/me', null, $tZ))['tienda']['aviso_pago'] === 'Tu mensualidad esta vencida');
check('La tienda sigue operando con el aviso', api('GET', '/clientes', null, $tZ)['status'] === 200);
check('Las demas tiendas no lo ven', d(api('GET', '/auth/me', null, $tA1))['tienda']['aviso_pago'] === null);
check('El listado de tiendas lo muestra', array_values(array_filter(d(api('GET', '/plataforma/tiendas', null, $tSA)), fn($x) => $x['_id'] === $z['_id']))[0]['aviso_pago'] === 'Tu mensualidad esta vencida');
status('La tienda no puede quitarse el aviso', api('PUT', "/plataforma/tiendas/{$z['_id']}/aviso-pago", ['mensaje' => ''], $tZ), 404);
status('Aviso de mas de 500 caracteres -> 400', api('PUT', "/plataforma/tiendas/{$z['_id']}/aviso-pago", ['mensaje' => str_repeat('x', 501)], $tSA), 400);
status('El superadmin quita el aviso', api('PUT', "/plataforma/tiendas/{$z['_id']}/aviso-pago", ['mensaje' => ''], $tSA), 200);
check('Ya no aparece en la sesion de la tienda', d(api('GET', '/auth/me', null, $tZ))['tienda']['aviso_pago'] === null);
check('Queda en la bitacora global', count(d(api('GET', '/plataforma/audit-log?accion=poner_aviso_pago', null, $tSA))) === 1);

seccion('Modo soporte (entrar como tienda)');
$r = api('POST', "/plataforma/tiendas/{$z['_id']}/entrar", null, $tSA);
status('Obtiene token de soporte', $r, 200);
$tS = d($r)['token'];
$me = d(api('GET', '/auth/me', null, $tS));
check('En soporte: ve la tienda y se marca como soporte', $me['soporte'] === true && $me['tienda']['slug'] === 'zapateriacentro');
status('En soporte opera la tienda (crea cliente)', api('POST', '/clientes', ['nombre' => 'Creado por soporte'], $tS), 201);
status('Con token de soporte no entra a la plataforma', api('GET', '/plataforma/tiendas', null, $tS), 404);
$bit = d(api('GET', '/audit-log', null, $tZ));
check('La bitacora de la tienda lo muestra como "Soporte"', (bool) array_filter($bit, fn($a) => $a['usuario'] === 'Soporte'));
$glob = d(api('GET', '/plataforma/audit-log?accion=entrar_tienda', null, $tSA));
check('La bitacora global registra la entrada de soporte', count($glob) === 1);
status('Superadmin sin soporte no usa rutas de tienda', api('GET', '/clientes', null, $tSA), 404);

seccion('Conciliacion y respaldo');
$r = d(api('GET', "/plataforma/tiendas/{$z['_id']}/conciliar", null, $tSA));
check('Saldos de la tienda cuadran con sus movimientos', $r['cuadra'] === true);
$r = api('GET', "/plataforma/tiendas/{$z['_id']}/export", null, $tSA);
$exp = json_decode($r['raw'], true);
check('Respaldo JSON con todas sus tablas', $r['status'] === 200 && isset($exp['tablas']['clientes']) && count($exp['tablas']['clientes']) === 3);
check('El respaldo no incluye contrasenas ni secretos', !str_contains($r['raw'], '"password"') && !isset($exp['tienda']['id_salt']));
check('El respaldo solo contiene datos de esa tienda',
    !array_filter($exp['tablas'], fn($rows) => (bool) array_filter($rows, fn($row) => (int) $row['id_empresa'] !== (int) $exp['tienda']['id'])));

fin();
