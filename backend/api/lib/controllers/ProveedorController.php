<?php
// Proveedores de la tienda.
class ProveedorController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'       => (int) $r['id'],
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

    private static function proveedor(int $id): array
    {
        $r = Db::one('SELECT * FROM proveedores WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$r) throw new ApiError('Proveedor no encontrado', 404, 'NOT_FOUND');
        return $r;
    }

    /** Domicilio estructurado a JSON (solo campos conocidos) */
    private static function domicilioJson($dom): ?string
    {
        if (!is_array($dom)) return null;
        $out = [];
        foreach (['calle', 'numero_ext', 'numero_int', 'colonia', 'municipio', 'estado', 'codigo_postal', 'pais'] as $c) {
            $out[$c] = isset($dom[$c]) ? trim((string) $dom[$c]) : '';
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND nombre LIKE ?'; $args[] = '%' . $_GET['search'] . '%'; }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM proveedores WHERE $where ORDER BY nombre", $args)));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert('INSERT INTO proveedores (id_empresa, nombre, telefono, rfc, contacto, tipo, domicilio, notas) VALUES (?,?,?,?,?,?,?,?)',
            [Tenant::id(), $nombre, $b['telefono'] ?? null, $b['rfc'] ?? null, $b['contacto'] ?? null,
             $b['tipo'] ?? 'Mercancia', self::domicilioJson($b['domicilio'] ?? null), $b['notas'] ?? null]);
        Http::created(self::fmt(self::proveedor($id)), 'Proveedor');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $r = self::proveedor((int) $p['id']);
        $b = Http::body();
        Db::run('UPDATE proveedores SET nombre = ?, telefono = ?, rfc = ?, contacto = ?, tipo = ?, domicilio = ?, notas = ?, is_active = ?
                 WHERE id = ? AND id_empresa = ?',
            [array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $r['nombre'],
             array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
             array_key_exists('rfc', $b) ? $b['rfc'] : $r['rfc'],
             array_key_exists('contacto', $b) ? $b['contacto'] : $r['contacto'],
             array_key_exists('tipo', $b) ? $b['tipo'] : $r['tipo'],
             array_key_exists('domicilio', $b) ? self::domicilioJson($b['domicilio']) : $r['domicilio'],
             array_key_exists('notas', $b) ? $b['notas'] : $r['notas'],
             array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
             $r['id'], Tenant::id()]);
        Http::updated(self::fmt(self::proveedor((int) $r['id'])), 'Proveedor');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $r = self::proveedor((int) $p['id']);
        Db::run("UPDATE proveedores SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$r['id'], Tenant::id()]);
        Http::deleted('Proveedor');
    }
}
