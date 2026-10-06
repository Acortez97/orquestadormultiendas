<?php
// ============================================================
// Panel de administracion general (solo superadmin). Rutas /plataforma/*.
// Aqui no hay "tienda activa": los ids se codifican con la sal de plataforma
// y cada operacion indica explicitamente la tienda sobre la que actua.
// ============================================================
class PlataformaController
{
    const HORAS_SOPORTE = 2;   // vigencia del token "entrar como tienda"

    private static function tiendaFila(int $id): array
    {
        $e = Db::one('SELECT * FROM empresas WHERE id = ?', [$id]);
        if (!$e) throw new ApiError('Tienda no encontrada', 404, 'NOT_FOUND');
        return $e;
    }

    private static function fmtTienda(array $e, array $ctx): array
    {
        return [
            '_id'           => (int) $e['id'],
            'slug'          => $e['slug'],
            'dominio'       => $e['slug'] . '.' . $ctx['cfg']['login_domain'],
            'nombre'        => $e['nombre'],
            'rfc'           => $e['rfc'],
            'iva'           => (float) $e['iva'],
            'telefono'      => $e['telefono'],
            'direccion'     => $e['direccion'],
            'logo_url'      => $e['logo_url'],
            'max_usuarios'  => $e['max_usuarios'] !== null ? (int) $e['max_usuarios'] : null,
            'max_almacenes' => $e['max_almacenes'] !== null ? (int) $e['max_almacenes'] : null,
            'notas'         => $e['notas'],
            'is_active'     => $e['is_active'],
            'createdAt'     => $e['created_at'],
        ];
    }

    // ===================== Tablero =====================
    public static function dashboard(array $p, array $ctx): void
    {
        $rows = Db::all(
            "SELECT e.id, e.nombre, e.slug, e.is_active,
                    (SELECT COUNT(*) FROM users u WHERE u.id_empresa = e.id AND u.is_active = 'Si') usuarios,
                    (SELECT MAX(u.ultimo_acceso) FROM users u WHERE u.id_empresa = e.id) ultimo_acceso,
                    (SELECT COALESCE(SUM(v.total), 0) FROM ventas v WHERE v.id_empresa = e.id AND v.estado = 'completada' AND DATE(v.fecha) = CURDATE()) ventas_hoy,
                    (SELECT COALESCE(SUM(v.total), 0) FROM ventas v WHERE v.id_empresa = e.id AND v.estado = 'completada'
                        AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) ventas_mes,
                    (SELECT COUNT(*) FROM ventas v WHERE v.id_empresa = e.id AND v.estado = 'completada'
                        AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) notas_mes
             FROM empresas e ORDER BY ventas_mes DESC, e.nombre");
        $tot = ['tiendas' => count($rows), 'activas' => 0, 'usuarios' => 0, 'ventas_hoy' => 0.0, 'ventas_mes' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $tot['activas'] += $r['is_active'] === 'Si' ? 1 : 0;
            $tot['usuarios'] += (int) $r['usuarios'];
            $tot['ventas_hoy'] += (float) $r['ventas_hoy'];
            $tot['ventas_mes'] += (float) $r['ventas_mes'];
            $filas[] = ['_id' => (int) $r['id'], 'nombre' => $r['nombre'], 'slug' => $r['slug'], 'is_active' => $r['is_active'],
                        'usuarios' => (int) $r['usuarios'], 'ultimo_acceso' => $r['ultimo_acceso'],
                        'ventas_hoy' => round((float) $r['ventas_hoy'], 2), 'ventas_mes' => round((float) $r['ventas_mes'], 2),
                        'notas_mes' => (int) $r['notas_mes']];
        }
        $tot['ventas_hoy'] = round($tot['ventas_hoy'], 2); $tot['ventas_mes'] = round($tot['ventas_mes'], 2);
        Http::ok(['totales' => $tot, 'tiendas' => $filas]);
    }

    /** Catalogo de modulos con sus acciones */
    public static function modulos(array $p, array $ctx): void
    {
        $out = [];
        foreach (Db::all('SELECT m.clave, m.nombre, ma.accion, ma.nombre accion_nombre FROM modulos m
                          JOIN modulo_acciones ma ON ma.modulo = m.clave ORDER BY m.orden, ma.accion') as $r) {
            $out[$r['clave']]['clave'] = $r['clave'];
            $out[$r['clave']]['nombre'] = $r['nombre'];
            $out[$r['clave']]['acciones'][] = ['clave' => $r['accion'], 'nombre' => $r['accion_nombre']];
        }
        Http::ok(array_values($out));
    }

    // ===================== Tiendas =====================
    public static function tiendas(array $p, array $ctx): void
    {
        $where = '1 = 1'; $args = [];
        if (!empty($_GET['search'])) { $where .= ' AND (nombre LIKE ? OR slug LIKE ?)'; $s = '%' . $_GET['search'] . '%'; array_push($args, $s, $s); }
        if (in_array($_GET['is_active'] ?? '', ['Si', 'No'], true)) { $where .= ' AND is_active = ?'; $args[] = $_GET['is_active']; }
        $rows = Db::all("SELECT * FROM empresas WHERE $where ORDER BY nombre", $args);
        Http::ok(array_map(fn($e) => self::fmtTienda($e, $ctx), $rows));
    }

    public static function tienda(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $out = self::fmtTienda($e, $ctx);
        $out['modulos'] = Permisos::modulosActivos((int) $e['id']);
        $out['almacenes'] = array_map(fn($a) => ['_id' => (int) $a['id'], 'codigo' => $a['codigo'], 'nombre' => $a['nombre'], 'tipo' => $a['tipo']],
            Db::all("SELECT id, codigo, nombre, tipo FROM almacenes WHERE id_empresa = ? AND is_active = 'Si' ORDER BY nombre", [(int) $e['id']]));
        $out['usuarios'] = (int) Db::one("SELECT COUNT(*) n FROM users WHERE id_empresa = ? AND is_active = 'Si'", [(int) $e['id']])['n'];
        Http::ok($out);
    }

    public static function crearTienda(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $pass = (string) ($b['admin_password'] ?? '');
        $generada = $pass === '';
        if ($generada) $pass = Seeder::passwordAleatorio();
        if (strlen($pass) < Usuarios::PASS_MIN) throw new ApiError('La contrasena debe tener al menos ' . Usuarios::PASS_MIN . ' caracteres', 400, 'VALIDATION');
        $iva = isset($b['iva']) ? (float) $b['iva'] : 0.16;
        if ($iva < 0 || $iva >= 1) throw new ApiError('El IVA debe ser una fraccion, p. ej. 0.16', 400, 'VALIDATION');

        try {
            $t = Seeder::crearTienda([
                'slug' => (string) ($b['slug'] ?? ''), 'nombre' => $nombre, 'rfc' => $b['rfc'] ?? null, 'iva' => $iva,
                'modulos' => isset($b['modulos']) && is_array($b['modulos']) ? array_values($b['modulos']) : null,
                'admin_nombre' => trim((string) ($b['admin_nombre'] ?? '')) ?: 'Administrador',
                'admin_password' => $pass,
            ], $ctx['cfg']['login_domain']);
        } catch (InvalidArgumentException $e) {
            throw new ApiError($e->getMessage(), 400, 'VALIDATION');
        }
        Db::run('UPDATE empresas SET telefono = ?, direccion = ?, max_usuarios = ?, max_almacenes = ?, notas = ? WHERE id = ?',
            [$b['telefono'] ?? null, $b['direccion'] ?? null, self::limite($b['max_usuarios'] ?? null), self::limite($b['max_almacenes'] ?? null),
             $b['notas'] ?? null, $t['id_empresa']]);
        Ledger::audit($ctx, 'crear_tienda', 'tienda', $t['id_empresa'], "Tienda $nombre ({$t['login_admin']})");

        $out = self::fmtTienda(self::tiendaFila($t['id_empresa']), $ctx);
        // La contrasena del admin se muestra UNA sola vez (debe cambiarla al entrar)
        $out['admin'] = ['login' => $t['login_admin'], 'password' => $generada ? $pass : null, 'debe_cambiar_password' => true];
        Http::created($out, 'Tienda');
    }

    private static function limite($v): ?int
    {
        if ($v === null || $v === '') return null;
        if ((int) $v < 1) throw new ApiError('Los limites deben ser mayores a cero', 400, 'VALIDATION');
        return (int) $v;
    }

    public static function editarTienda(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $b = Http::body();
        if (array_key_exists('slug', $b) && $b['slug'] !== $e['slug']) {
            throw new ApiError('El subdominio no se puede cambiar (cambiaria el correo de acceso de todos sus usuarios)', 400, 'VALIDATION');
        }
        $iva = array_key_exists('iva', $b) ? (float) $b['iva'] : (float) $e['iva'];
        if ($iva < 0 || $iva >= 1) throw new ApiError('El IVA debe ser una fraccion, p. ej. 0.16', 400, 'VALIDATION');
        Db::run('UPDATE empresas SET nombre = ?, rfc = ?, iva = ?, telefono = ?, direccion = ?, logo_url = ?, max_usuarios = ?, max_almacenes = ?, notas = ? WHERE id = ?', [
            array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $e['nombre'],
            array_key_exists('rfc', $b) ? $b['rfc'] : $e['rfc'], $iva,
            array_key_exists('telefono', $b) ? $b['telefono'] : $e['telefono'],
            array_key_exists('direccion', $b) ? $b['direccion'] : $e['direccion'],
            array_key_exists('logo_url', $b) ? $b['logo_url'] : $e['logo_url'],
            array_key_exists('max_usuarios', $b) ? self::limite($b['max_usuarios']) : $e['max_usuarios'],
            array_key_exists('max_almacenes', $b) ? self::limite($b['max_almacenes']) : $e['max_almacenes'],
            array_key_exists('notas', $b) ? $b['notas'] : $e['notas'], $e['id']]);
        Ledger::audit($ctx, 'editar_tienda', 'tienda', $e['id'], 'Tienda ' . $e['slug']);
        Http::updated(self::fmtTienda(self::tiendaFila((int) $e['id']), $ctx), 'Tienda');
    }

    /** Suspende o reactiva. Suspendida: sus usuarios no pueden entrar ni operar; sus datos se conservan. */
    public static function estadoTienda(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $activo = (Http::body()['is_active'] ?? 'Si') === 'No' ? 'No' : 'Si';
        Db::run('UPDATE empresas SET is_active = ? WHERE id = ?', [$activo, $e['id']]);
        Ledger::audit($ctx, $activo === 'Si' ? 'reactivar_tienda' : 'suspender_tienda', 'tienda', $e['id'], 'Tienda ' . $e['slug']);
        Http::ok(self::fmtTienda(self::tiendaFila((int) $e['id']), $ctx), $activo === 'Si' ? 'Tienda reactivada' : 'Tienda suspendida');
    }

    public static function modulosTienda(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $activos = Permisos::modulosActivos((int) $e['id']);
        Http::ok(array_map(fn($m) => ['clave' => $m['clave'], 'nombre' => $m['nombre'], 'activo' => in_array($m['clave'], $activos, true)],
            Db::all('SELECT clave, nombre FROM modulos ORDER BY orden')));
    }

    /** Define los modulos habilitados. Deshabilitar no borra permisos: solo dejan de aplicar. */
    public static function guardarModulos(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $sel = Http::body()['modulos'] ?? null;
        if (!is_array($sel)) throw new ApiError('Envia la lista de modulos habilitados', 400, 'VALIDATION');
        $todos = array_column(Db::all('SELECT clave FROM modulos'), 'clave');
        foreach ($sel as $m) if (!in_array($m, $todos, true)) throw new ApiError("Modulo desconocido: $m", 400, 'VALIDATION');
        Db::begin();
        foreach ($todos as $m) {
            Db::run('INSERT INTO empresa_modulos (id_empresa, modulo, activo) VALUES (?,?,?) ON DUPLICATE KEY UPDATE activo = VALUES(activo)',
                [(int) $e['id'], $m, in_array($m, $sel, true) ? 1 : 0]);
        }
        Db::commit();
        Ledger::audit($ctx, 'modulos_tienda', 'tienda', $e['id'], 'Modulos: ' . implode(', ', $sel));
        self::modulosTienda($p, $ctx);
    }

    /** Token temporal para operar como la tienda (soporte). Todo queda en bitacora como "Soporte". */
    public static function entrar(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $u = Db::one('SELECT token_version FROM users WHERE id = ?', [$ctx['user']['id']]);
        $token = Jwt::encode(['u' => Tenant::encSesion((int) $ctx['user']['id']), 'v' => (int) $u['token_version'], 't' => Tenant::encSesion((int) $e['id'])],
            $ctx['cfg']['jwt_secret'], self::HORAS_SOPORTE * 3600);
        Ledger::audit($ctx, 'entrar_tienda', 'tienda', $e['id'], 'Soporte en ' . $e['slug']);
        Http::ok(['token' => $token, 'tienda' => ['nombre' => $e['nombre'], 'slug' => $e['slug']], 'expira_horas' => self::HORAS_SOPORTE], 'Modo soporte');
    }

    /** Recalcula saldos de clientes desde sus movimientos y reporta diferencias */
    public static function conciliar(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $emp = (int) $e['id'];
        $dif = [];
        foreach (Db::all(
            "SELECT c.id, c.nombre, c.saldo_credito, c.saldo_favor,
                    (SELECT COALESCE(SUM(CASE m.efecto WHEN 'cargo' THEN m.monto WHEN 'abono' THEN -m.monto ELSE 0 END), 0)
                       FROM cliente_movimientos m WHERE m.id_empresa = c.id_empresa AND m.id_cliente = c.id AND m.anulado = 0) cxc,
                    (SELECT COALESCE(SUM(mm.importe), 0) FROM monedero_movimientos mm WHERE mm.id_empresa = c.id_empresa AND mm.id_cliente = c.id) mon
             FROM clientes c WHERE c.id_empresa = ?", [$emp]) as $r) {
            if (abs((float) $r['saldo_credito'] - (float) $r['cxc']) > 0.009) $dif[] = ['cliente' => $r['nombre'], 'tipo' => 'cxc', 'saldo' => (float) $r['saldo_credito'], 'movimientos' => (float) $r['cxc']];
            if (abs((float) $r['saldo_favor'] - (float) $r['mon']) > 0.009)   $dif[] = ['cliente' => $r['nombre'], 'tipo' => 'monedero', 'saldo' => (float) $r['saldo_favor'], 'movimientos' => (float) $r['mon']];
        }
        // Cuentas bancarias: saldo = suma de su libro de movimientos
        foreach (Db::all(
            "SELECT b.nombre, b.saldo_actual,
                    (SELECT COALESCE(SUM(CASE m.tipo WHEN 'ingreso' THEN m.monto ELSE -m.monto END), 0)
                       FROM banco_movimientos m WHERE m.id_empresa = b.id_empresa AND m.id_banco = b.id) libro
             FROM bancos b WHERE b.id_empresa = ?", [$emp]) as $r) {
            if (abs((float) $r['saldo_actual'] - (float) $r['libro']) > 0.009) $dif[] = ['cliente' => $r['nombre'], 'tipo' => 'cuenta', 'saldo' => (float) $r['saldo_actual'], 'movimientos' => (float) $r['libro']];
        }
        Http::ok(['tienda' => $e['slug'], 'cuadra' => !$dif, 'diferencias' => $dif]);
    }

    /** Respaldo JSON de TODOS los datos de una tienda (ids internos; uso exclusivo del superadmin) */
    public static function exportar(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $emp = (int) $e['id'];
        $tablas = array_column(Db::all(
            "SELECT DISTINCT c.table_name t FROM information_schema.columns c
             WHERE c.table_schema = DATABASE() AND c.column_name = 'id_empresa' ORDER BY c.table_name"), 't');
        $datos = ['tienda' => array_diff_key($e, array_flip(['id_salt', 'uploads_token', 'pin_lista_alta'])), 'exportado' => date('c')];
        foreach ($tablas as $t) {
            $rows = Db::all("SELECT * FROM `$t` WHERE id_empresa = ?", [$emp]);
            if ($t === 'users') foreach ($rows as &$r) unset($r['password']);
            unset($r);
            $datos['tablas'][$t] = $rows;
        }
        Ledger::audit($ctx, 'exportar_tienda', 'tienda', $emp, 'Respaldo de ' . $e['slug']);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="respaldo-' . $e['slug'] . '-' . date('Ymd-His') . '.json"');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ===================== Usuarios de cualquier tienda =====================
    private static function fmtUsuarioPlat(array $u, array $perms): array
    {
        $o = Usuarios::formatear($u, $perms);
        $o['tienda'] = ['_id' => (int) $u['id_empresa'], 'nombre' => $u['tienda_nombre'], 'slug' => $u['tienda_slug']];
        return $o;
    }

    public static function usuarios(array $p, array $ctx): void
    {
        $where = 'u.id_empresa IS NOT NULL'; $args = [];
        if (!empty($_GET['search'])) { $where .= ' AND (u.login LIKE ? OR u.nombre LIKE ?)'; $s = '%' . $_GET['search'] . '%'; array_push($args, $s, $s); }
        $rows = Db::all("SELECT u.*, e.nombre tienda_nombre, e.slug tienda_slug FROM users u JOIN empresas e ON e.id = u.id_empresa
                         WHERE $where ORDER BY e.nombre, u.rol, u.nombre LIMIT 1000", $args);
        Http::ok(self::conPermisos($rows));
    }

    public static function usuariosTienda(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        Http::ok(self::conPermisos(Db::all('SELECT u.*, e.nombre tienda_nombre, e.slug tienda_slug FROM users u JOIN empresas e ON e.id = u.id_empresa
                                             WHERE u.id_empresa = ? ORDER BY u.rol, u.nombre', [(int) $e['id']])));
    }

    private static function conPermisos(array $rows): array
    {
        $porTienda = [];
        foreach ($rows as $r) $porTienda[(int) $r['id_empresa']][] = (int) $r['id'];
        $perms = [];
        foreach ($porTienda as $emp => $ids) $perms += Usuarios::permisosAsignados($emp, $ids);
        return array_map(fn($r) => self::fmtUsuarioPlat($r, $perms[(int) $r['id']] ?? []), $rows);
    }

    private static function usuarioPlat(int $id): array
    {
        $u = Db::one('SELECT u.*, e.nombre tienda_nombre, e.slug tienda_slug FROM users u JOIN empresas e ON e.id = u.id_empresa WHERE u.id = ?', [$id]);
        if (!$u) throw new ApiError('Usuario no encontrado', 404, 'NOT_FOUND');
        return $u;
    }

    private static function respUsuario(int $id): array
    {
        $u = self::usuarioPlat($id);
        $perms = Usuarios::permisosAsignados((int) $u['id_empresa'], [$id]);
        return self::fmtUsuarioPlat($u, $perms[$id] ?? []);
    }

    public static function crearUsuario(array $p, array $ctx): void
    {
        $e = self::tiendaFila((int) $p['id']);
        $b = Http::body();
        $generada = ($b['password'] ?? '') === '';
        if ($generada) $b['password'] = Seeder::passwordAleatorio();
        $u = Usuarios::crear((int) $e['id'], $b, ['usuario', 'admin_tienda'], $ctx['cfg']['login_domain']);
        Ledger::audit($ctx, 'crear_usuario', 'usuario', $u['id'], $u['login']);
        $out = self::respUsuario((int) $u['id']);
        $out['password_generada'] = $generada ? $b['password'] : null;
        Http::created($out, 'Usuario');
    }

    public static function editarUsuario(array $p, array $ctx): void
    {
        $u = self::usuarioPlat((int) $p['id']);
        Usuarios::actualizar((int) $u['id_empresa'], (int) $u['id'], Http::body(), false, null, $ctx['cfg']['login_domain']);
        Ledger::audit($ctx, 'editar_usuario', 'usuario', $u['id'], $u['login']);
        Http::updated(self::respUsuario((int) $u['id']), 'Usuario');
    }

    public static function desactivarUsuario(array $p, array $ctx): void
    {
        $u = self::usuarioPlat((int) $p['id']);
        Usuarios::desactivar((int) $u['id_empresa'], (int) $u['id'], false, null);
        Ledger::audit($ctx, 'desactivar_usuario', 'usuario', $u['id'], $u['login']);
        Http::deleted('Usuario');
    }

    public static function resetPassword(array $p, array $ctx): void
    {
        $u = self::usuarioPlat((int) $p['id']);
        $nueva = (string) (Http::bodyCrudo()['newPassword'] ?? '');
        $generada = $nueva === '';
        if ($generada) $nueva = Seeder::passwordAleatorio();
        Usuarios::resetPassword((int) $u['id_empresa'], (int) $u['id'], $nueva, false, null);
        Ledger::audit($ctx, 'reset_password', 'usuario', $u['id'], $u['login']);
        Http::ok(['password_generada' => $generada ? $nueva : null], 'Contrasena restablecida; debe cambiarla al entrar');
    }

    // ===================== Bitacora global =====================
    public static function auditLog(array $p, array $ctx): void
    {
        $where = '1 = 1'; $args = [];
        if (!empty($_GET['id_tienda'])) { $where .= ' AND a.id_empresa = ?'; $args[] = (int) $_GET['id_tienda']; }
        if (($_GET['solo_plataforma'] ?? '') === '1') $where .= ' AND a.id_empresa IS NULL';
        if (!empty($_GET['accion'])) { $where .= ' AND a.accion = ?'; $args[] = $_GET['accion']; }
        if (!empty($_GET['desde']))  { $where .= ' AND a.created_at >= ?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))  { $where .= ' AND a.created_at <= ?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(2000, max(1, (int) ($_GET['limit'] ?? 500)));
        $rows = Db::all("SELECT a.*, e.nombre tienda_nombre, e.slug tienda_slug, s.login soporte_login
                         FROM audit_log a LEFT JOIN empresas e ON e.id = a.id_empresa LEFT JOIN users s ON s.id = a.act_as
                         WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT $limit", $args);
        Http::ok(array_map(fn($r) => [
            '_id' => (int) $r['id'], 'fecha' => $r['created_at'],
            'tienda' => $r['id_empresa'] ? ['_id' => (int) $r['id_empresa'], 'nombre' => $r['tienda_nombre'], 'slug' => $r['tienda_slug']] : null,
            'usuario' => $r['act_as'] ? 'Soporte (' . $r['soporte_login'] . ')' : $r['usuario'],
            'accion' => $r['accion'], 'entidad' => $r['entidad'], 'descripcion' => $r['descripcion'], 'ip' => $r['ip'],
        ], $rows));
    }
}
