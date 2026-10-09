<?php
// ============================================================
// Sesion (login por correo de acceso) y usuarios de la tienda (admin_tienda).
// ============================================================
class AuthController
{
    /** Hash de relleno: se verifica aunque el usuario no exista, para no revelar por tiempo de respuesta si existe */
    const HASH_RELLENO = '$2y$12$Ro0kh9jgO3utgPMQ5tjynOtyWu7ifolgOOgJBon1NLOxiQ0jTeWFG';
    const MAX_FALLOS_LOGIN = 5;    // por correo DESDE la misma IP, en la ventana (otro equipo no puede bloquear al usuario)
    const MAX_FALLOS_IP = 20;      // por IP, en la ventana
    const MAX_FALLOS_CORREO = 50;  // por correo desde cualquier IP (ataque repartido), en la ventana
    const VENTANA_MIN = 15;

    /** Datos de sesion para el front: usuario + permisos efectivos + tienda */
    private static function sesion(array $u, array $permisos, ?array $emp, bool $soporte = false): array
    {
        $data = Usuarios::formatear($u, $permisos);
        $data['permisos']['admin'] = false;   // compat: el front ya no usa un "admin" global
        $data['es_superadmin'] = $u['rol'] === 'superadmin' && !$soporte;
        $data['soporte'] = $soporte;           // superadmin operando como tienda
        if ($soporte) $data['rol'] = 'admin_tienda';
        $data['tienda'] = $emp ? ['nombre' => $emp['nombre'], 'slug' => $emp['slug'], 'logo_url' => $emp['logo_url'],
                                   'aviso_pago' => $emp['aviso_pago'] ?? null, 'iva' => (float) ($emp['iva'] ?? 0.16),
                                   'color' => $emp['color'] ?? null] : null;
        return $data;
    }

    public static function login(array $p, array $ctx): void
    {
        $cfg = $ctx['cfg'];
        $b = Http::bodyCrudo();
        $login = strtolower(trim((string) ($b['email'] ?? $b['login'] ?? '')));
        $pass  = (string) ($b['password'] ?? '');
        $ip    = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        // Bloqueo temporal por intentos fallidos
        $f = Db::one(
            "SELECT COALESCE(SUM(login = ? AND ip = ?), 0) por_login, COALESCE(SUM(ip = ?), 0) por_ip, COALESCE(SUM(login = ?), 0) por_correo
             FROM login_intentos
             WHERE exito = 0 AND created_at > (NOW() - INTERVAL " . self::VENTANA_MIN . " MINUTE) AND (login = ? OR ip = ?) AND login NOT LIKE 'pin:%'",
            [$login, $ip, $ip, $login, $login, $ip]);
        if ((int) $f['por_login'] >= self::MAX_FALLOS_LOGIN || (int) $f['por_ip'] >= self::MAX_FALLOS_IP
            || (int) $f['por_correo'] >= self::MAX_FALLOS_CORREO) {
            throw new ApiError('Demasiados intentos fallidos. Espera ' . self::VENTANA_MIN . ' minutos e intenta de nuevo.', 429, 'TOO_MANY_ATTEMPTS');
        }

        $u = null; $emp = null;
        $sep = LoginId::separar($login, $cfg['login_domain']);
        if ($sep) {
            if ($sep['slug'] === null) {
                $u = Db::one("SELECT * FROM users WHERE id_empresa IS NULL AND rol = 'superadmin' AND usuario = ?", [$sep['usuario']]);
            } else {
                $emp = Db::one('SELECT * FROM empresas WHERE slug = ?', [$sep['slug']]);
                if ($emp) $u = Db::one('SELECT * FROM users WHERE id_empresa = ? AND usuario = ?', [(int) $emp['id'], $sep['usuario']]);
            }
        }
        $valido = password_verify($pass, $u['password'] ?? self::HASH_RELLENO) && $u && $u['is_active'] === 'Si';
        Db::run('INSERT INTO login_intentos (login, ip, exito) VALUES (?,?,?)', [$login, $ip, $valido ? 1 : 0]);
        if (!$valido) throw new ApiError('Correo o contrasena incorrectos', 401, 'UNAUTHORIZED');
        if ($emp && $emp['is_active'] !== 'Si') throw new ApiError('Cuenta suspendida, contacta al administrador', 403, 'SUSPENDED');

        Db::run('UPDATE users SET ultimo_acceso = NOW() WHERE id = ?', [(int) $u['id']]);
        $token = Jwt::encode(['u' => Tenant::encSesion((int) $u['id']), 'v' => (int) $u['token_version']],
            $cfg['jwt_secret'], $cfg['jwt_expira']);

        if ($emp) {
            Tenant::activarTienda($emp);
            $permisos = Permisos::efectivos(['id' => (int) $u['id'], 'id_empresa' => (int) $emp['id'], 'rol' => $u['rol']]);
        } else {
            Tenant::activarPlataforma();
            $permisos = [];
        }
        Http::ok(['token' => $token, 'user' => self::sesion($u, $permisos, $emp)], 'Inicio de sesion exitoso');
    }

    public static function me(array $p, array $ctx): void
    {
        $id = $ctx['user']['id'] ?? $ctx['user']['act_as'];
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        Http::ok(self::sesion($u, $ctx['permisos'], Tenant::empresa(), !empty($ctx['user']['act_as'])));
    }

    /** Cambia la contrasena propia. Cierra las demas sesiones y devuelve un token nuevo. */
    public static function changePassword(array $p, array $ctx): void
    {
        if (!empty($ctx['user']['act_as'])) throw new ApiError('No disponible en modo soporte', 400, 'VALIDATION');
        $b = Http::bodyCrudo();
        $cur = (string) ($b['currentPassword'] ?? '');
        $new = (string) ($b['newPassword'] ?? '');
        if (strlen($new) < Usuarios::PASS_MIN) throw new ApiError('La nueva contrasena debe tener al menos ' . Usuarios::PASS_MIN . ' caracteres', 400, 'VALIDATION');
        if ($new === $cur) throw new ApiError('La nueva contrasena debe ser distinta a la actual', 400, 'VALIDATION');

        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int) $ctx['user']['id']]);
        if (!password_verify($cur, $u['password'])) throw new ApiError('Contrasena actual incorrecta', 400, 'VALIDATION');

        Db::run('UPDATE users SET password = ?, debe_cambiar_password = 0, token_version = token_version + 1 WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), (int) $u['id']]);
        $token = Jwt::encode(['u' => Tenant::encSesion((int) $u['id']), 'v' => (int) $u['token_version'] + 1],
            $ctx['cfg']['jwt_secret'], $ctx['cfg']['jwt_expira']);
        Http::ok(['token' => $token], 'Contrasena actualizada exitosamente');
    }

    // ===== Usuarios de la tienda (solo admin_tienda; ver Permisos 'admin:usuarios') =====

    /** Modulos activos de la tienda con sus acciones (para el editor de permisos) */
    public static function modulosTienda(array $p, array $ctx): void
    {
        $activos = Permisos::modulosActivos(Tenant::id());
        $out = [];
        foreach (Db::all('SELECT m.clave, m.nombre, ma.accion, ma.nombre accion_nombre FROM modulos m
                          JOIN modulo_acciones ma ON ma.modulo = m.clave ORDER BY m.orden, ma.accion') as $r) {
            if (!in_array($r['clave'], $activos, true)) continue;
            $out[$r['clave']]['clave'] = $r['clave'];
            $out[$r['clave']]['nombre'] = $r['nombre'];
            $out[$r['clave']]['acciones'][] = ['clave' => $r['accion'], 'nombre' => $r['accion_nombre']];
        }
        $emp = Tenant::empresa();
        Http::ok(['modulos' => array_values($out), 'dominio' => $emp['slug'] . '.' . $ctx['cfg']['login_domain']]);
    }

    public static function listar(array $p, array $ctx): void
    {
        Http::ok(Usuarios::listar(Tenant::id()));
    }

    public static function crear(array $p, array $ctx): void
    {
        $u = Usuarios::crear(Tenant::id(), Http::body(), ['usuario'], $ctx['cfg']['login_domain']);
        Ledger::audit($ctx, 'crear', 'usuario', $u['id'], 'Usuario ' . $u['login']);
        Http::created(self::fila($u), 'Usuario');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $u = Usuarios::actualizar(Tenant::id(), (int) $p['id'], Http::body(), true, $ctx['user']['id'], $ctx['cfg']['login_domain']);
        Ledger::audit($ctx, 'editar', 'usuario', $u['id'], 'Usuario ' . $u['login']);
        Http::updated(self::fila($u), 'Usuario');
    }

    public static function desactivar(array $p, array $ctx): void
    {
        Usuarios::desactivar(Tenant::id(), (int) $p['id'], true, $ctx['user']['id']);
        Ledger::audit($ctx, 'desactivar', 'usuario', (int) $p['id'], 'Usuario desactivado');
        Http::deleted('Usuario');
    }

    public static function resetPassword(array $p, array $ctx): void
    {
        Usuarios::resetPassword(Tenant::id(), (int) $p['id'], (string) (Http::bodyCrudo()['newPassword'] ?? ''), true, $ctx['user']['id']);
        Ledger::audit($ctx, 'reset_password', 'usuario', (int) $p['id'], 'Contrasena restablecida');
        Http::ok(null, 'Contrasena restablecida');
    }

    private static function fila(array $u): array
    {
        $perms = Usuarios::permisosAsignados(Tenant::id(), [(int) $u['id']]);
        return Usuarios::formatear($u, $perms[(int) $u['id']] ?? []);
    }
}
