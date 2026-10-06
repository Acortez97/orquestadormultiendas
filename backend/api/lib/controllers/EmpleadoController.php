<?php
class EmpleadoController
{
    public static function fmt(array $r): array
    {
        $pct = (float) ($r['pct_comision'] ?? 0);
        return [
            '_id'          => (string) $r['id'],
            'nombre'       => $r['nombre'],
            'apellido'     => $r['apellido'],
            'telefono'     => $r['telefono'],
            'email'        => $r['email'] ?? null,
            'puesto'       => $r['puesto'],
            'area'         => $r['area'] ?? 'ventas',
            'id_tienda'    => $r['id_tienda'] !== null ? (string) $r['id_tienda'] : null,
            'es_vendedor'  => (bool) ($r['es_vendedor'] ?? 0),
            'pct_comision' => $pct,
            'comision_porcentaje' => $pct,
            'nombreCompleto' => trim($r['nombre'] . ' ' . $r['apellido']),
            'is_active'    => $r['is_active'],
            'createdAt'    => $r['created_at'] ?? null,
            'updatedAt'    => $r['updated_at'] ?? null,
        ];
    }

    /** Lee el porcentaje de comision aceptando ambos nombres de campo */
    private static function pctFrom(array $b, $default = 0)
    {
        if (array_key_exists('comision_porcentaje', $b)) return num($b['comision_porcentaje']);
        if (array_key_exists('pct_comision', $b))        return num($b['pct_comision']);
        return $default;
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND (nombre LIKE ? OR apellido LIKE ?)'; array_push($args, '%' . $_GET['search'] . '%', '%' . $_GET['search'] . '%'); }
        $rows = Db::all("SELECT * FROM empleados WHERE $where ORDER BY nombre,apellido", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert(
            'INSERT INTO empleados (id_empresa,nombre,apellido,telefono,email,puesto,area,id_tienda,es_vendedor,pct_comision)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [(int) $ctx['user']['id_empresa'], trim($b['nombre']), trim($b['apellido'] ?? ''),
             $b['telefono'] ?? null, $b['email'] ?? null, $b['puesto'] ?? null, $b['area'] ?? 'ventas',
             id_or_null($b['id_tienda'] ?? null), !empty($b['es_vendedor']) ? 1 : 0, self::pctFrom($b, 0)]
        );
        Http::created(self::fmt(Db::one('SELECT * FROM empleados WHERE id=?', [$id])), 'Empleado');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $r = Db::one('SELECT * FROM empleados WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$r) throw new ApiError('Empleado no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run(
            'UPDATE empleados SET nombre=?, apellido=?, telefono=?, email=?, puesto=?, area=?, id_tienda=?, es_vendedor=?, pct_comision=?, is_active=? WHERE id=?',
            [
                array_key_exists('nombre', $b) ? trim($b['nombre']) : $r['nombre'],
                array_key_exists('apellido', $b) ? trim($b['apellido']) : $r['apellido'],
                array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
                array_key_exists('email', $b) ? $b['email'] : ($r['email'] ?? null),
                array_key_exists('puesto', $b) ? $b['puesto'] : $r['puesto'],
                array_key_exists('area', $b) ? $b['area'] : ($r['area'] ?? 'ventas'),
                array_key_exists('id_tienda', $b) ? id_or_null($b['id_tienda']) : $r['id_tienda'],
                array_key_exists('es_vendedor', $b) ? (!empty($b['es_vendedor']) ? 1 : 0) : ($r['es_vendedor'] ?? 0),
                (array_key_exists('comision_porcentaje', $b) || array_key_exists('pct_comision', $b)) ? self::pctFrom($b, (float) $r['pct_comision']) : $r['pct_comision'],
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
                $id,
            ]
        );
        Http::updated(self::fmt(Db::one('SELECT * FROM empleados WHERE id=?', [$id])), 'Empleado');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE empleados SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Empleado');
    }
}
