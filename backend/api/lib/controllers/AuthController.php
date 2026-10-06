<?php
class AuthController
{
    /** Da formato al usuario para el frontend (sin password) */
    public static function formatUser(array $u): array
    {
        $permisos = $u['permisos'] ? json_decode($u['permisos'], true) : null;
        if (!is_array($permisos)) $permisos = ['admin' => ($u['rol'] === 'admin')];
        return [
            '_id'          => (string) $u['id'],
            'nombre'       => $u['nombre'],
            'apellido'     => $u['apellido'],
            'email'        => $u['email'],
            'rol'          => $u['rol'],
            'is_active'    => $u['is_active'],
            'id_tienda'    => $u['id_tienda'] !== null ? (string) $u['id_tienda'] : null,
            'id_empresa'   => (string) $u['id_empresa'],
            'permisos'     => $permisos,
            'nombreCompleto' => trim($u['nombre'] . ' ' . $u['apellido']),
            'ultimo_acceso' => $u['ultimo_acceso'] ?? null,
            'createdAt'    => $u['created_at'] ?? null,
            'updatedAt'    => $u['updated_at'] ?? null,
        ];
    }

    public static function login(array $p, array $ctx): void
    {
        $b = Http::body();
        $email = strtolower(trim($b['email'] ?? ''));
        $pass  = (string) ($b['password'] ?? '');
        if ($email === '' || $pass === '') throw new ApiError('Credenciales invalidas', 401, 'UNAUTHORIZED');

        $u = Db::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$u || !password_verify($pass, $u['password'])) throw new ApiError('Credenciales invalidas', 401, 'UNAUTHORIZED');
        if ($u['is_active'] !== 'Si') throw new ApiError('Usuario desactivado', 401, 'UNAUTHORIZED');

        Db::run('UPDATE users SET ultimo_acceso=NOW() WHERE id=?', [$u['id']]);

        $userData = self::formatUser($u);
        $token = Jwt::encode([
            'id'         => (string) $u['id'],
            'email'      => $u['email'],
            'nombre'     => $u['nombre'],
            'apellido'   => $u['apellido'],
            'rol'        => $u['rol'],
            'id_empresa' => (string) $u['id_empresa'],
            'id_tienda'  => $u['id_tienda'] !== null ? (string) $u['id_tienda'] : null,
            'permisos'   => $userData['permisos'],
        ], $ctx['cfg']['jwt_secret'], $ctx['cfg']['jwt_expira']);

        Http::ok(['token' => $token, 'user' => $userData], 'Inicio de sesion exitoso');
    }

    public static function me(array $p, array $ctx): void
    {
        $u = Db::one('SELECT * FROM users WHERE id=?', [(int) $ctx['user']['id']]);
        if (!$u) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::formatUser($u));
    }

    public static function changePassword(array $p, array $ctx): void
    {
        $b = Http::body();
        $cur = (string) ($b['currentPassword'] ?? '');
        $new = (string) ($b['newPassword'] ?? '');
        if (strlen($new) < 6) throw new ApiError('La nueva contrasena debe tener al menos 6 caracteres', 400, 'VALIDATION');

        $u = Db::one('SELECT * FROM users WHERE id=?', [(int) $ctx['user']['id']]);
        if (!$u || !password_verify($cur, $u['password'])) throw new ApiError('Contrasena actual incorrecta', 400, 'VALIDATION');

        Db::run('UPDATE users SET password=? WHERE id=?', [password_hash($new, PASSWORD_BCRYPT), $u['id']]);
        Http::ok(null, 'Contrasena actualizada exitosamente');
    }

    public static function listar(array $p, array $ctx): void
    {
        $rows = Db::all('SELECT * FROM users WHERE id_empresa=? ORDER BY is_active DESC, nombre ASC',
            [(int) $ctx['user']['id_empresa']]);
        Http::ok(array_map([self::class, 'formatUser'], $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $email = strtolower(trim($b['email'] ?? ''));
        if ($email === '' || strlen($b['password'] ?? '') < 6 || trim($b['nombre'] ?? '') === '')
            throw new ApiError('Nombre, email y contrasena (min 6) son obligatorios', 400, 'VALIDATION');
        if (Db::one('SELECT id FROM users WHERE email=?', [$email]))
            throw new ApiError('Ya existe un usuario con ese email', 409, 'CONFLICT');

        $id = Db::insert(
            'INSERT INTO users (id_empresa,nombre,apellido,email,password,rol,is_active,permisos,id_tienda)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                (int) $ctx['user']['id_empresa'],
                trim($b['nombre']),
                trim($b['apellido'] ?? ''),
                $email,
                password_hash($b['password'], PASSWORD_BCRYPT),
                ($b['rol'] ?? 'usuario') === 'admin' ? 'admin' : 'usuario',
                ($b['is_active'] ?? 'Si') === 'No' ? 'No' : 'Si',
                isset($b['permisos']) ? json_encode($b['permisos'], JSON_UNESCAPED_UNICODE) : null,
                id_or_null($b['id_tienda'] ?? null),
            ]
        );
        $u = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        Http::created(self::formatUser($u), 'Usuario');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $id = (int) $p['id'];
        $u = Db::one('SELECT * FROM users WHERE id=? AND id_empresa=?', [$id, (int) $ctx['user']['id_empresa']]);
        if (!$u) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');

        Db::run(
            'UPDATE users SET nombre=?, apellido=?, email=?, rol=?, is_active=?, permisos=?, id_tienda=? WHERE id=?',
            [
                trim($b['nombre'] ?? $u['nombre']),
                trim($b['apellido'] ?? $u['apellido']),
                strtolower(trim($b['email'] ?? $u['email'])),
                ($b['rol'] ?? $u['rol']) === 'admin' ? 'admin' : 'usuario',
                ($b['is_active'] ?? $u['is_active']) === 'No' ? 'No' : 'Si',
                isset($b['permisos']) ? json_encode($b['permisos'], JSON_UNESCAPED_UNICODE) : $u['permisos'],
                array_key_exists('id_tienda', $b) ? id_or_null($b['id_tienda']) : $u['id_tienda'],
                $id,
            ]
        );
        $u = Db::one('SELECT * FROM users WHERE id=?', [$id]);
        Http::updated(self::formatUser($u), 'Usuario');
    }

    public static function desactivar(array $p, array $ctx): void
    {
        Db::run('UPDATE users SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Usuario');
    }

    public static function resetPassword(array $p, array $ctx): void
    {
        $b = Http::body();
        $new = (string) ($b['newPassword'] ?? '');
        if (strlen($new) < 6) throw new ApiError('La contrasena debe tener al menos 6 caracteres', 400, 'VALIDATION');
        Db::run('UPDATE users SET password=? WHERE id=? AND id_empresa=?',
            [password_hash($new, PASSWORD_BCRYPT), (int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::ok(null, 'Contrasena restablecida');
    }
}
