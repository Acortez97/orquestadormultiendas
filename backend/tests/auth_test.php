<?php
// ============================================================
// F2 — Sesion, permisos, ids opacos y usuarios de tienda (por API).
//   php backend/api/reset-db.php --go --demo   (BD limpia)
//   php -S 127.0.0.1:8082 -t backend/api        (en otra terminal)
//   php backend/tests/auth_test.php
// ============================================================
require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/../api/lib/config.php';
require_once __DIR__ . '/../api/lib/Db.php';
Db::init($cfg['db']);

$c = credenciales();
$SA = 'admin@levotek.com'; $A1 = 'admin@demo1.levotek.com'; $C1 = 'cajero1@demo1.levotek.com';
$A2 = 'admin@demo2.levotek.com';

seccion('Login por correo de acceso');
$r = api('POST', '/auth/login', ['email' => $SA, 'password' => $c[$SA]]);
status('Superadmin entra', $r, 200);
$tSA = $r['body']['data']['token'];
check('Superadmin marcado como tal y sin tienda', $r['body']['data']['user']['es_superadmin'] === true && $r['body']['data']['user']['tienda'] === null);

[$tA1, $uA1] = entrar($A1, $c[$A1]);
check('Admin de tienda trae su tienda', ($uA1['tienda']['slug'] ?? '') === 'demo1' && $uA1['rol'] === 'admin_tienda');
check('Admin de tienda tiene todos los modulos activos', count($uA1['permisos']) - 1 === 18, 'modulos: ' . (count($uA1['permisos']) - 1));
[$tC1, $uC1] = entrar($C1, $c[$C1]);
check('Cajero solo tiene sus permisos asignados',
    !empty($uC1['permisos']['ventas']['crear']) && empty($uC1['permisos']['compras']) && empty($uC1['permisos']['usuarios']));
check('Usuarios nuevos deben cambiar contrasena', $uC1['debe_cambiar_password'] === true);
[$tA2] = entrar($A2, $c[$A2]);

$genericos = [
    'Contrasena incorrecta'         => [$A1, 'mala-contrasena'],
    'Usuario inexistente'           => ['nadie@demo1.levotek.com', 'x'],
    'Tienda inexistente'            => ['admin@noexiste.levotek.com', 'x'],
    'Dominio ajeno'                 => ['admin@gmail.com', 'x'],
    'Usuario de tienda sin subdominio (como superadmin)' => ['cajero1@levotek.com', $c[$C1]],
    'Usuario correcto en la tienda equivocada'           => ['cajero1@demo2.levotek.com', $c[$C1]],
];
$mensajes = [];
foreach ($genericos as $desc => [$l, $p]) {
    $r = api('POST', '/auth/login', ['email' => $l, 'password' => $p]);
    status("$desc -> 401", $r, 401);
    $mensajes[] = $r['body']['error']['message'] ?? '';
}
check('Todos los fallos de login dan el MISMO mensaje', count(array_unique($mensajes)) === 1, implode(' | ', array_unique($mensajes)));

seccion('JWT sin ids legibles');
$payload = json_decode(base64_decode(strtr(explode('.', $tA1)[1], '-_', '+/')), true);
check('El token solo trae u, v, iat, exp', array_keys($payload) == ['u', 'v', 'iat', 'exp'], implode(',', array_keys($payload)));
check('El usuario va cifrado (no es un numero)', is_string($payload['u']) && strlen($payload['u']) === 9 && !ctype_digit($payload['u']));

seccion('Ids opacos');
$r = api('GET', '/auth/usuarios', null, $tA1);
status('Admin lista usuarios de su tienda', $r, 200);
$lista1 = $r['body']['data'];
check('Ningun id interno expuesto en la respuesta', !idsCrudos($lista1), implode(', ', array_slice(idsCrudos($lista1), 0, 5)));
check('Solo ve usuarios de SU tienda', count($lista1) === 2 && !array_filter($lista1, fn($u) => !str_ends_with($u['login'], '@demo1.levotek.com')));
$idCajero1 = array_values(array_filter($lista1, fn($u) => $u['usuario'] === 'cajero1'))[0]['_id'];
$lista2 = api('GET', '/auth/usuarios', null, $tA2)['body']['data'];
$idCajero2 = array_values(array_filter($lista2, fn($u) => $u['usuario'] === 'cajero1'))[0]['_id'];
check('El mismo usuario en dos tiendas tiene ids distintos', $idCajero1 !== $idCajero2);
$idInterno = (int) Db::one("SELECT u.id FROM users u JOIN empresas e ON e.id = u.id_empresa WHERE e.slug = 'demo2' AND u.usuario = 'cajero1'")['id'];

seccion('Cruces entre tiendas por API (deben dar 404)');
status('Admin A edita un usuario de B (id opaco de B)', api('PUT', "/auth/usuarios/$idCajero2", ['nombre' => 'Hackeado'], $tA1), 404);
status('Admin A usa el id interno numerico de B', api('PUT', "/auth/usuarios/$idInterno", ['nombre' => 'Hackeado'], $tA1), 404);
status('Admin A resetea contrasena de un usuario de B', api('POST', "/auth/usuarios/$idCajero2/reset-password", ['newPassword' => 'otra12345'], $tA1), 404);
status('Admin A desactiva un usuario de B', api('DELETE', "/auth/usuarios/$idCajero2", null, $tA1), 404);
check('El usuario de B sigue intacto', Db::one('SELECT nombre, is_active FROM users WHERE id = ?', [$idInterno]) == ['nombre' => 'Cajero demo', 'is_active' => 'Si']);
status('Admin A manda id_empresa de B en el body: se ignora', api('POST', '/auth/usuarios',
    ['usuario' => 'intruso', 'nombre' => 'X', 'password' => 'segura1234', 'id_empresa' => 2], $tA1), 201);
check('... y el usuario quedo en SU tienda', (int) Db::one("SELECT e.slug = 'demo1' ok FROM users u JOIN empresas e ON e.id = u.id_empresa WHERE u.usuario = 'intruso'")['ok'] === 1);

seccion('Acceso por rol');
status('Cajero no puede ver usuarios', api('GET', '/auth/usuarios', null, $tC1), 403);
status('Cajero no puede ver compras', api('GET', '/compras', null, $tC1), 403);
status('Usuario de tienda no ve rutas de plataforma (404)', api('GET', '/plataforma/tiendas', null, $tA1), 404);
status('Superadmin sin "entrar como tienda" no usa rutas de tienda (404)', api('GET', '/auth/usuarios', null, $tSA), 404);
status('Sin token -> 401', api('GET', '/auth/me'), 401);
status('Token alterado -> 401', api('GET', '/auth/me', null, $tA1 . 'x'), 401);
status('Ruta inexistente -> 404', api('GET', '/no/existe', null, $tA1), 404);

seccion('Admin de tienda gestiona sus usuarios');
$r = api('GET', '/auth/modulos-tienda', null, $tA1);
check('Editor de permisos: dominio fijo de la tienda', ($r['body']['data']['dominio'] ?? '') === 'demo1.levotek.com');
$r = api('POST', '/auth/usuarios', ['usuario' => 'vendedor1', 'nombre' => 'Vendedor', 'password' => 'segura1234',
    'permisos' => ['ventas' => ['ver' => true, 'crear' => true]]], $tA1);
status('Crea usuario con permisos', $r, 201);
$nuevo = $r['body']['data'];
check('Correo de acceso armado por el servidor', $nuevo['login'] === 'vendedor1@demo1.levotek.com');
$r = api('POST', '/auth/usuarios', ['email' => 'otro@demo2.levotek.com', 'nombre' => 'X', 'password' => 'segura1234'], $tA1);
check('Si manda el dominio de otra tienda, se ignora y queda en la suya', ($r['body']['data']['login'] ?? '') === 'otro@demo1.levotek.com');
status('No puede crear admin_tienda', api('POST', '/auth/usuarios', ['usuario' => 'jefe', 'nombre' => 'J', 'password' => 'segura1234', 'rol' => 'admin_tienda'], $tA1), 400);
status('No puede crear superadmin', api('POST', '/auth/usuarios', ['usuario' => 'jefe', 'nombre' => 'J', 'password' => 'segura1234', 'rol' => 'superadmin'], $tA1), 400);
status('Contrasena corta -> 400', api('POST', '/auth/usuarios', ['usuario' => 'corto', 'nombre' => 'C', 'password' => '123'], $tA1), 400);
status('Usuario repetido en su tienda -> 409', api('POST', '/auth/usuarios', ['usuario' => 'vendedor1', 'nombre' => 'V', 'password' => 'segura1234'], $tA1), 409);
status('Permiso con accion invalida -> 400', api('POST', '/auth/usuarios', ['usuario' => 'v2', 'nombre' => 'V', 'password' => 'segura1234',
    'permisos' => ['ventas' => ['volar' => true]]], $tA1), 400);
Db::run("UPDATE empresa_modulos SET activo = 0 WHERE modulo = 'facturacion' AND id_empresa = (SELECT id FROM empresas WHERE slug = 'demo1')");
status('Permiso de modulo deshabilitado en la tienda -> 400', api('POST', '/auth/usuarios', ['usuario' => 'v3', 'nombre' => 'V', 'password' => 'segura1234',
    'permisos' => ['facturacion' => ['ver' => true]]], $tA1), 400);
status('No puede dar el modulo "usuarios" a un usuario normal -> 400', api('POST', '/auth/usuarios', ['usuario' => 'v4', 'nombre' => 'V', 'password' => 'segura1234',
    'permisos' => ['usuarios' => ['ver' => true]]], $tA1), 400);
$adminId = $uA1['_id'];
status('No puede editarse a si mismo', api('PUT', "/auth/usuarios/$adminId", ['nombre' => 'Yo'], $tA1), 400);

seccion('Cambiar permisos cierra la sesion del usuario (token_version)');
[$tV] = entrar('vendedor1@demo1.levotek.com', 'segura1234');
status('Vendedor ve ventas', api('GET', '/auth/me', null, $tV), 200);
status('Admin le quita permisos', api('PUT', "/auth/usuarios/{$nuevo['_id']}", ['permisos' => ['ventas' => ['ver' => true]]], $tA1), 200);
status('El token viejo del vendedor ya no sirve', api('GET', '/auth/me', null, $tV), 401);
[$tV] = entrar('vendedor1@demo1.levotek.com', 'segura1234');
$me = api('GET', '/auth/me', null, $tV)['body']['data'];
check('Al volver a entrar tiene los permisos nuevos', !empty($me['permisos']['ventas']['ver']) && empty($me['permisos']['ventas']['crear']));

seccion('Cambio de contrasena propia');
$r = api('POST', '/auth/change-password', ['currentPassword' => 'segura1234', 'newPassword' => 'nueva12345'], $tV);
status('Cambia su contrasena', $r, 200);
status('El token anterior queda invalidado', api('GET', '/auth/me', null, $tV), 401);
status('El token nuevo funciona', api('GET', '/auth/me', null, $r['body']['data']['token']), 200);
status('Contrasena actual incorrecta -> 400', api('POST', '/auth/change-password', ['currentPassword' => 'mala', 'newPassword' => 'otra123456'], $r['body']['data']['token']), 400);

seccion('Tienda suspendida');
Db::run("UPDATE empresas SET is_active = 'No' WHERE slug = 'demo2'");
$r = api('GET', '/auth/me', null, $tA2);
check('Sesion abierta de la tienda suspendida -> 403 SUSPENDED', $r['status'] === 403 && $r['body']['error']['code'] === 'SUSPENDED');
$r = api('POST', '/auth/login', ['email' => $A2, 'password' => $c[$A2]]);
check('Login con contrasena correcta -> "Cuenta suspendida"', $r['status'] === 403 && $r['body']['error']['code'] === 'SUSPENDED');
status('Login con contrasena incorrecta -> 401 generico (no revela la suspension)', api('POST', '/auth/login', ['email' => $A2, 'password' => 'mala']), 401);
status('La otra tienda sigue operando', api('GET', '/auth/me', null, $tA1), 200);
Db::run("UPDATE empresas SET is_active = 'Si' WHERE slug = 'demo2'");

seccion('Bloqueo por intentos fallidos');
$victima = 'cajero1@demo1.levotek.com';
Db::run('DELETE FROM login_intentos');
for ($i = 0; $i < 5; $i++) api('POST', '/auth/login', ['email' => $victima, 'password' => 'mala']);
$r = api('POST', '/auth/login', ['email' => $victima, 'password' => $c[$C1]]);
status('Tras 5 fallos, incluso la contrasena correcta espera (429)', $r, 429);
Db::run('DELETE FROM login_intentos');
status('Tras la ventana, vuelve a entrar', api('POST', '/auth/login', ['email' => $victima, 'password' => $c[$C1]]), 200);

fin();
