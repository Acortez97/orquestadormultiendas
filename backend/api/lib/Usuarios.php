<?php
// ============================================================
// Servicio de usuarios de tienda. Lo usan:
//   - AuthController (/auth/usuarios)        -> admin_tienda, solo su tienda, solo rol 'usuario'
//   - PlataformaController (/plataforma/...) -> superadmin, cualquier tienda, rol usuario/admin_tienda
// El correo de acceso siempre es usuario@<slug-de-la-tienda>.<login_domain>: el dominio lo pone el servidor.
// ============================================================
class Usuarios
{
    const PASS_MIN = 8;

    /** Formato para el frontend (sin password). $permisos = mapa modulo => [accion => true] */
    public static function formatear(array $u, ?array $permisos = null): array
    {
        return [
            '_id'            => (int) $u['id'],
            'usuario'        => $u['usuario'],
            'login'          => $u['login'],
            'email'          => $u['login'],          // compatibilidad: el front muestra "email"
            'email_contacto' => $u['email_contacto'],
            'nombre'         => $u['nombre'],
            'apellido'       => $u['apellido'],
            'nombreCompleto' => trim($u['nombre'] . ' ' . $u['apellido']),
            'rol'            => $u['rol'],
            'is_active'      => $u['is_active'],
            'id_tienda'      => $u['id_almacen_default'] !== null ? (int) $u['id_almacen_default'] : null,
            'debe_cambiar_password' => (bool) $u['debe_cambiar_password'],
            'permisos'       => $permisos ?? [],
            'ultimo_acceso'  => $u['ultimo_acceso'] ?? null,
            'createdAt'      => $u['created_at'] ?? null,
            'updatedAt'      => $u['updated_at'] ?? null,
        ];
    }

    /** Permisos asignados (no efectivos) de varios usuarios: [id_user => [modulo => [accion => true]]] */
    public static function permisosAsignados(int $idEmpresa, array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all("SELECT id_user, modulo, accion FROM user_permisos WHERE id_empresa = ? AND id_user IN ($in)",
                         array_merge([$idEmpresa], $ids)) as $r) {
            $out[(int) $r['id_user']][$r['modulo']][$r['accion']] = true;
        }
        return $out;
    }

    public static function listar(int $idEmpresa): array
    {
        $rows = Db::all('SELECT * FROM users WHERE id_empresa = ? ORDER BY is_active DESC, rol ASC, nombre ASC', [$idEmpresa]);
        $perms = self::permisosAsignados($idEmpresa, array_map(fn($r) => (int) $r['id'], $rows));
        return array_map(fn($r) => self::formatear($r, $perms[(int) $r['id']] ?? []), $rows);
    }

    public static function obtener(int $idEmpresa, int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = ? AND id_empresa = ?', [$id, $idEmpresa]);
        if (!$u) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        return $u;
    }

    /**
     * Crea un usuario en la tienda $idEmpresa. $rolesPermitidos limita el rol (admin_tienda solo puede 'usuario').
     * Devuelve la fila creada.
     */
    public static function crear(int $idEmpresa, array $b, array $rolesPermitidos, string $dominio): array
    {
        $emp = Db::one('SELECT * FROM empresas WHERE id = ?', [$idEmpresa]);
        if (!$emp) throw new ApiError('Tienda no encontrada', 404, 'NOT_FOUND');

        $usuario = self::validarUsuario($b['usuario'] ?? self::parteLocal($b['email'] ?? $b['login'] ?? ''));
        $nombre = trim((string) ($b['nombre'] ?? ''));
        $pass = (string) ($b['password'] ?? '');
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        if (strlen($pass) < self::PASS_MIN) throw new ApiError('La contrasena debe tener al menos ' . self::PASS_MIN . ' caracteres', 400, 'VALIDATION');

        $rol = (string) ($b['rol'] ?? 'usuario');
        if (!in_array($rol, $rolesPermitidos, true)) throw new ApiError('Rol no permitido', 400, 'VALIDATION');

        if ($emp['max_usuarios'] !== null) {
            $n = (int) Db::one("SELECT COUNT(*) n FROM users WHERE id_empresa = ? AND is_active = 'Si'", [$idEmpresa])['n'];
            if ($n >= (int) $emp['max_usuarios']) throw new ApiError('La tienda alcanzo su limite de usuarios activos', 400, 'LIMITE');
        }
        if (Db::one('SELECT id FROM users WHERE id_empresa = ? AND usuario = ?', [$idEmpresa, $usuario])) {
            throw new ApiError("Ya existe el usuario '$usuario' en esta tienda", 409, 'CONFLICT');
        }
        $alm = self::almacen($idEmpresa, $b['id_tienda'] ?? $b['id_almacen_default'] ?? null);
        $pares = $rol === 'usuario' ? Permisos::normalizar($b['permisos'] ?? [], $idEmpresa) : [];

        Db::begin();
        try {
            $id = Db::insert(
                'INSERT INTO users (id_empresa, usuario, login, nombre, apellido, email_contacto, password, rol, id_almacen_default, debe_cambiar_password)
                 VALUES (?,?,?,?,?,?,?,?,?,1)',
                [$idEmpresa, $usuario, LoginId::armar($usuario, $emp['slug'], $dominio), $nombre, trim((string) ($b['apellido'] ?? '')),
                 self::emailContacto($b['email_contacto'] ?? null), password_hash($pass, PASSWORD_BCRYPT), $rol, $alm]
            );
            Permisos::guardar($idEmpresa, $id, $pares);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        return self::obtener($idEmpresa, $id);
    }

    /**
     * Actualiza un usuario. $porAdminTienda = true aplica las restricciones del admin de tienda:
     * solo usuarios con rol 'usuario', sin cambiar roles, y nunca a si mismo.
     */
    public static function actualizar(int $idEmpresa, int $id, array $b, bool $porAdminTienda, ?int $idActor, string $dominio): array
    {
        $u = self::obtener($idEmpresa, $id);
        if ($porAdminTienda) {
            if ($idActor !== null && $id === $idActor) throw new ApiError('No puedes modificar tu propio usuario desde aqui', 400, 'VALIDATION');
            if ($u['rol'] !== 'usuario') throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        }
        $emp = Db::one('SELECT slug FROM empresas WHERE id = ?', [$idEmpresa]);

        $usuario = array_key_exists('usuario', $b) ? self::validarUsuario($b['usuario']) : $u['usuario'];
        if ($usuario !== $u['usuario'] && Db::one('SELECT id FROM users WHERE id_empresa = ? AND usuario = ? AND id <> ?', [$idEmpresa, $usuario, $id])) {
            throw new ApiError("Ya existe el usuario '$usuario' en esta tienda", 409, 'CONFLICT');
        }
        $rol = $u['rol'];
        if (!$porAdminTienda && isset($b['rol'])) {
            if (!in_array($b['rol'], ['usuario', 'admin_tienda'], true)) throw new ApiError('Rol no permitido', 400, 'VALIDATION');
            $rol = $b['rol'];
        }
        $activo = array_key_exists('is_active', $b) ? (($b['is_active'] === 'No' || $b['is_active'] === false) ? 'No' : 'Si') : $u['is_active'];
        // reactivar cuenta contra el limite de usuarios que puso la plataforma (el superadmin si puede excederlo)
        if ($porAdminTienda && $activo === 'Si' && $u['is_active'] !== 'Si') {
            $max = Db::one('SELECT max_usuarios FROM empresas WHERE id = ?', [$idEmpresa])['max_usuarios'];
            if ($max !== null && (int) Db::one("SELECT COUNT(*) n FROM users WHERE id_empresa = ? AND is_active = 'Si'", [$idEmpresa])['n'] >= (int) $max)
                throw new ApiError('La tienda alcanzo su limite de usuarios activos', 400, 'LIMITE');
        }
        $alm = (array_key_exists('id_tienda', $b) || array_key_exists('id_almacen_default', $b))
            ? self::almacen($idEmpresa, $b['id_tienda'] ?? $b['id_almacen_default'] ?? null) : $u['id_almacen_default'];
        $cambianPermisos = array_key_exists('permisos', $b) || $rol !== $u['rol'];
        $pares = $cambianPermisos && $rol === 'usuario' ? Permisos::normalizar($b['permisos'] ?? [], $idEmpresa) : [];
        // reenviar los mismos permisos (p. ej. al editar solo el nombre) no cierra la sesion del usuario
        if ($cambianPermisos && $rol === $u['rol']) {
            $antes = array_map(fn($r) => $r['modulo'] . '.' . $r['accion'],
                Db::all('SELECT modulo, accion FROM user_permisos WHERE id_empresa = ? AND id_user = ?', [$idEmpresa, $id]));
            $despues = array_map(fn($p) => $p[0] . '.' . $p[1], $pares);
            sort($antes); sort($despues);
            if ($antes === $despues) $cambianPermisos = false;
        }
        $invalida = $cambianPermisos || $activo !== $u['is_active'] || $usuario !== $u['usuario'];

        Db::begin();
        try {
            Db::run(
                'UPDATE users SET usuario = ?, login = ?, nombre = ?, apellido = ?, email_contacto = ?, rol = ?, is_active = ?,
                        id_almacen_default = ?, token_version = token_version + ? WHERE id = ? AND id_empresa = ?',
                [$usuario, LoginId::armar($usuario, $emp['slug'], $dominio),
                 trim((string) ($b['nombre'] ?? $u['nombre'])) ?: $u['nombre'], trim((string) ($b['apellido'] ?? $u['apellido'])),
                 array_key_exists('email_contacto', $b) ? self::emailContacto($b['email_contacto']) : $u['email_contacto'],
                 $rol, $activo, $alm, $invalida ? 1 : 0, $id, $idEmpresa]
            );
            if ($cambianPermisos) Permisos::guardar($idEmpresa, $id, $pares);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        return self::obtener($idEmpresa, $id);
    }

    public static function desactivar(int $idEmpresa, int $id, bool $porAdminTienda, ?int $idActor): void
    {
        $u = self::obtener($idEmpresa, $id);
        if ($porAdminTienda && ($u['rol'] !== 'usuario' || $id === $idActor)) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        Db::run("UPDATE users SET is_active = 'No', token_version = token_version + 1 WHERE id = ? AND id_empresa = ?", [$id, $idEmpresa]);
    }

    public static function resetPassword(int $idEmpresa, int $id, string $nueva, bool $porAdminTienda, ?int $idActor): void
    {
        $u = self::obtener($idEmpresa, $id);
        if ($porAdminTienda && ($u['rol'] !== 'usuario' || $id === $idActor)) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        if (strlen($nueva) < self::PASS_MIN) throw new ApiError('La contrasena debe tener al menos ' . self::PASS_MIN . ' caracteres', 400, 'VALIDATION');
        Db::run('UPDATE users SET password = ?, debe_cambiar_password = 1, token_version = token_version + 1 WHERE id = ? AND id_empresa = ?',
            [password_hash($nueva, PASSWORD_BCRYPT), $id, $idEmpresa]);
    }

    // ---- validaciones ----
    private static function validarUsuario($u): string
    {
        try { return LoginId::usuario((string) $u); }
        catch (InvalidArgumentException $e) { throw new ApiError($e->getMessage(), 400, 'VALIDATION'); }
    }
    /** Si el front manda un correo completo, se toma solo la parte antes de la @ (el dominio lo pone el servidor) */
    private static function parteLocal(string $s): string
    {
        $p = strpos($s, '@');
        return $p === false ? $s : substr($s, 0, $p);
    }
    private static function almacen(int $idEmpresa, $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) return null;
        if (!Db::one('SELECT 1 FROM almacenes WHERE id = ? AND id_empresa = ?', [(int) $id, $idEmpresa])) {
            throw new ApiError('Almacen no encontrado', 404, 'NOT_FOUND');
        }
        return (int) $id;
    }
    private static function emailContacto($e): ?string
    {
        $e = trim((string) $e);
        if ($e === '') return null;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new ApiError('Correo de contacto invalido', 400, 'VALIDATION');
        return strtolower($e);
    }
}
