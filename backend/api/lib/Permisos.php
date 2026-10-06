<?php
// ============================================================
// Permisos efectivos = permisos del usuario ∩ modulos activos de su tienda.
// admin_tienda (y el superadmin "entrando como tienda") tiene todas las acciones
// de los modulos activos. Formato: ['ventas' => ['ver' => true, 'crear' => true], ...]
// ============================================================
class Permisos
{
    /** Claves de los modulos activos de la tienda */
    public static function modulosActivos(int $idEmpresa): array
    {
        return array_column(Db::all(
            'SELECT em.modulo FROM empresa_modulos em JOIN modulos m ON m.clave = em.modulo
             WHERE em.id_empresa = ? AND em.activo = 1 ORDER BY m.orden', [$idEmpresa]), 'modulo');
    }

    public static function efectivos(array $user): array
    {
        if ($user['id_empresa'] === null) return [];
        if ($user['rol'] === 'admin_tienda') {
            $rows = Db::all(
                'SELECT ma.modulo, ma.accion FROM modulo_acciones ma
                 JOIN empresa_modulos em ON em.modulo = ma.modulo AND em.id_empresa = ? AND em.activo = 1',
                [$user['id_empresa']]);
        } else {
            $rows = Db::all(
                'SELECT up.modulo, up.accion FROM user_permisos up
                 JOIN empresa_modulos em ON em.id_empresa = up.id_empresa AND em.modulo = up.modulo AND em.activo = 1
                 WHERE up.id_empresa = ? AND up.id_user = ?',
                [$user['id_empresa'], $user['id']]);
        }
        $out = [];
        foreach ($rows as $r) $out[$r['modulo']][$r['accion']] = true;
        return $out;
    }

    public static function tiene(array $ctx, string $permiso): bool
    {
        [$m, $a] = array_pad(explode('.', $permiso, 2), 2, 'ver');
        return !empty($ctx['permisos'][$m][$a]);
    }

    /** Evalua el permiso declarado en la tabla de rutas */
    public static function autoriza($permiso, array $ctx): bool
    {
        if ($permiso === 'sesion') return true;
        if ($permiso === 'plataforma') return !empty($ctx['user']['es_superadmin']) && empty($ctx['user']['act_as']);
        if (!Tenant::hayTienda()) return false;
        if ($permiso === 'tienda') return true;
        if (is_string($permiso) && strncmp($permiso, 'admin:', 6) === 0) {
            return $ctx['user']['rol'] === 'admin_tienda' && !empty($ctx['permisos'][substr($permiso, 6)]);
        }
        foreach ((array) $permiso as $p) if (self::tiene($ctx, $p)) return true;
        return false;
    }

    /**
     * Valida una lista de permisos que se quiere asignar a un usuario de la tienda $idEmpresa.
     * Entrada: ['ventas' => ['ver' => true, 'crear' => true], ...] o [['modulo'=>..,'accion'=>..], ...]
     * Devuelve pares [modulo, accion] validos. Lanza 400 si hay modulos no habilitados o acciones invalidas.
     */
    public static function normalizar($entrada, int $idEmpresa): array
    {
        if (!is_array($entrada)) return [];
        $pares = [];
        foreach ($entrada as $k => $v) {
            if (is_array($v) && isset($v['modulo'])) { $pares[] = [(string) $v['modulo'], (string) ($v['accion'] ?? 'ver')]; continue; }
            if (!is_string($k) || $k === 'admin') continue;
            if ($v === true) { $pares[] = [$k, 'ver']; continue; }
            if (is_array($v)) foreach ($v as $acc => $on) if ($on) $pares[] = [$k, (string) $acc];
        }
        if (!$pares) return [];
        $activos = self::modulosActivos($idEmpresa);
        $validas = [];
        foreach (Db::all('SELECT modulo, accion FROM modulo_acciones') as $r) $validas[$r['modulo'] . '.' . $r['accion']] = true;
        $out = [];
        foreach ($pares as [$m, $a]) {
            if (!in_array($m, $activos, true)) throw new ApiError("El modulo '$m' no esta habilitado en la tienda", 400, 'VALIDATION');
            if (!isset($validas["$m.$a"])) throw new ApiError("Accion invalida: $m.$a", 400, 'VALIDATION');
            $out["$m.$a"] = [$m, $a];
        }
        return array_values($out);
    }

    /** Reemplaza los permisos de un usuario (dentro de la transaccion del llamador) */
    public static function guardar(int $idEmpresa, int $idUser, array $pares): void
    {
        Db::run('DELETE FROM user_permisos WHERE id_empresa = ? AND id_user = ?', [$idEmpresa, $idUser]);
        foreach ($pares as [$m, $a]) {
            Db::run('INSERT INTO user_permisos (id_empresa, id_user, modulo, accion) VALUES (?,?,?,?)', [$idEmpresa, $idUser, $m, $a]);
        }
    }
}
