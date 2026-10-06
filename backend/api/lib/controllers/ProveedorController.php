<?php
class ProveedorController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'       => (string) $r['id'],
            'nombre'    => $r['nombre'],
            'telefono'  => $r['telefono'],
            'rfc'       => $r['rfc'],
            'contacto'  => $r['contacto'],
            'tipo'      => $r['tipo'] ?? 'Mercancia',
            'domicilio' => !empty($r['domicilio']) ? json_decode($r['domicilio'], true) : null,
            'notas'     => $r['notas'],
            'is_active' => $r['is_active'],
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }

    /** Serializa el domicilio a JSON (o null) */
    private static function domicilioJson($dom): ?string
    {
        if (!is_array($dom)) return null;
        $campos = ['calle', 'numero_ext', 'numero_int', 'colonia', 'municipio', 'estado', 'codigo_postal', 'pais'];
        $out = [];
        foreach ($campos as $c) $out[$c] = isset($dom[$c]) ? trim((string) $dom[$c]) : '';
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND nombre LIKE ?'; $args[] = '%' . $_GET['search'] . '%'; }
        $rows = Db::all("SELECT * FROM proveedores WHERE $where ORDER BY nombre", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert('INSERT INTO proveedores (id_empresa,nombre,telefono,rfc,contacto,tipo,domicilio,notas) VALUES (?,?,?,?,?,?,?,?)',
            [(int) $ctx['user']['id_empresa'], trim($b['nombre']), $b['telefono'] ?? null, $b['rfc'] ?? null, $b['contacto'] ?? null,
             $b['tipo'] ?? 'Mercancia', self::domicilioJson($b['domicilio'] ?? null), $b['notas'] ?? null]);
        Http::created(self::fmt(Db::one('SELECT * FROM proveedores WHERE id=?', [$id])), 'Proveedor');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $r = Db::one('SELECT * FROM proveedores WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$r) throw new ApiError('Proveedor no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run('UPDATE proveedores SET nombre=?, telefono=?, rfc=?, contacto=?, tipo=?, domicilio=?, notas=?, is_active=? WHERE id=?',
            [
                array_key_exists('nombre', $b) ? trim($b['nombre']) : $r['nombre'],
                array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
                array_key_exists('rfc', $b) ? $b['rfc'] : $r['rfc'],
                array_key_exists('contacto', $b) ? $b['contacto'] : $r['contacto'],
                array_key_exists('tipo', $b) ? $b['tipo'] : ($r['tipo'] ?? 'Mercancia'),
                array_key_exists('domicilio', $b) ? self::domicilioJson($b['domicilio']) : ($r['domicilio'] ?? null),
                array_key_exists('notas', $b) ? $b['notas'] : $r['notas'],
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
                $id,
            ]);
        Http::updated(self::fmt(Db::one('SELECT * FROM proveedores WHERE id=?', [$id])), 'Proveedor');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE proveedores SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Proveedor');
    }
}
