<?php
// Empleados / vendedores de la tienda.
class EmpleadoController
{
    public static function fmt(array $r): array
    {
        $pct = (float) ($r['pct_comision'] ?? 0);
        return [
            '_id'          => (int) $r['id'],
            'nombre'       => $r['nombre'],
            'apellido'     => $r['apellido'],
            'telefono'     => $r['telefono'],
            'email'        => $r['email'] ?? null,
            'puesto'       => $r['puesto'],
            'area'         => $r['area'] ?? 'ventas',
            'id_tienda'    => $r['id_almacen'] !== null ? (int) $r['id_almacen'] : null,
            'es_vendedor'  => (bool) ($r['es_vendedor'] ?? 0),
            'pct_comision' => $pct,
            'comision_porcentaje' => $pct,
            'nombreCompleto' => trim($r['nombre'] . ' ' . $r['apellido']),
            'is_active'    => $r['is_active'],
            'createdAt'    => $r['created_at'] ?? null,
            'updatedAt'    => $r['updated_at'] ?? null,
        ];
    }

    private static function empleado(int $id): array
    {
        $r = Db::one('SELECT * FROM empleados WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$r) throw new ApiError('Empleado no encontrado', 404, 'NOT_FOUND');
        return $r;
    }

    /** Porcentaje de comision (acepta ambos nombres de campo); valida 0-100 */
    private static function pctFrom(array $b, $default = 0): float
    {
        $v = array_key_exists('comision_porcentaje', $b) ? num($b['comision_porcentaje'])
           : (array_key_exists('pct_comision', $b) ? num($b['pct_comision']) : $default);
        if ($v < 0 || $v > 100) throw new ApiError('El porcentaje de comision debe estar entre 0 y 100', 400, 'VALIDATION');
        return (float) $v;
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND (nombre LIKE ? OR apellido LIKE ?)'; $s = '%' . $_GET['search'] . '%'; array_push($args, $s, $s); }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM empleados WHERE $where ORDER BY nombre, apellido", $args)));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert(
            'INSERT INTO empleados (id_empresa, nombre, apellido, telefono, email, puesto, area, id_almacen, es_vendedor, pct_comision)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [Tenant::id(), $nombre, trim((string) ($b['apellido'] ?? '')), $b['telefono'] ?? null, $b['email'] ?? null,
             $b['puesto'] ?? null, $b['area'] ?? 'ventas', Tenant::owns('almacenes', $b['id_tienda'] ?? null, 'Almacen'),
             !empty($b['es_vendedor']) ? 1 : 0, self::pctFrom($b, 0)]);
        Http::created(self::fmt(self::empleado($id)), 'Empleado');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $r = self::empleado((int) $p['id']);
        $b = Http::body();
        Db::run(
            'UPDATE empleados SET nombre = ?, apellido = ?, telefono = ?, email = ?, puesto = ?, area = ?, id_almacen = ?, es_vendedor = ?,
                    pct_comision = ?, is_active = ? WHERE id = ? AND id_empresa = ?',
            [array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $r['nombre'],
             array_key_exists('apellido', $b) ? trim((string) $b['apellido']) : $r['apellido'],
             array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
             array_key_exists('email', $b) ? $b['email'] : $r['email'],
             array_key_exists('puesto', $b) ? $b['puesto'] : $r['puesto'],
             array_key_exists('area', $b) ? $b['area'] : $r['area'],
             array_key_exists('id_tienda', $b) ? Tenant::owns('almacenes', $b['id_tienda'], 'Almacen') : $r['id_almacen'],
             array_key_exists('es_vendedor', $b) ? (!empty($b['es_vendedor']) ? 1 : 0) : $r['es_vendedor'],
             self::pctFrom($b, (float) $r['pct_comision']),
             array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
             $r['id'], Tenant::id()]);
        Http::updated(self::fmt(self::empleado((int) $r['id'])), 'Empleado');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $r = self::empleado((int) $p['id']);
        Db::run("UPDATE empleados SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$r['id'], Tenant::id()]);
        Http::deleted('Empleado');
    }
}
