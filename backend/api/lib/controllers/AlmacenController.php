<?php
class AlmacenController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'           => (string) $r['id'],
            'codigo'        => $r['codigo'],
            'nombre'        => $r['nombre'],
            'tipo'          => $r['tipo'],
            'vende_publico' => (bool) $r['vende_publico'],
            'serie_folio'   => $r['serie_folio'],
            'direccion'     => $r['direccion'],
            'telefono'      => $r['telefono'],
            'is_active'     => $r['is_active'],
            'createdAt'     => $r['created_at'] ?? null,
            'updatedAt'     => $r['updated_at'] ?? null,
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        $rows = Db::all("SELECT * FROM almacenes WHERE $where ORDER BY nombre", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $codigo = strtoupper(trim($b['codigo'] ?? ''));
        $nombre = trim($b['nombre'] ?? '');
        if ($codigo === '' || $nombre === '') throw new ApiError('Codigo y nombre son obligatorios', 400, 'VALIDATION');
        $emp = (int) $ctx['user']['id_empresa'];
        if (Db::one('SELECT id FROM almacenes WHERE id_empresa=? AND codigo=?', [$emp, $codigo]))
            throw new ApiError('Ya existe un almacen con ese codigo', 409, 'CONFLICT');

        $id = Db::insert(
            'INSERT INTO almacenes (id_empresa,codigo,nombre,tipo,vende_publico,serie_folio,direccion,telefono)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $emp, $codigo, $nombre,
                ($b['tipo'] ?? 'tienda') === 'bodega' ? 'bodega' : 'tienda',
                array_key_exists('vende_publico', $b) ? (!empty($b['vende_publico']) ? 1 : 0) : 1,
                $b['serie_folio'] ?? null, $b['direccion'] ?? null, $b['telefono'] ?? null,
            ]
        );
        Http::created(self::fmt(Db::one('SELECT * FROM almacenes WHERE id=?', [$id])), 'Almacen');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $r = Db::one('SELECT * FROM almacenes WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$r) throw new ApiError('Almacen no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run(
            'UPDATE almacenes SET codigo=?, nombre=?, tipo=?, vende_publico=?, serie_folio=?, direccion=?, telefono=?, is_active=? WHERE id=?',
            [
                array_key_exists('codigo', $b) ? strtoupper(trim($b['codigo'])) : $r['codigo'],
                array_key_exists('nombre', $b) ? trim($b['nombre']) : $r['nombre'],
                array_key_exists('tipo', $b) ? (($b['tipo'] === 'bodega') ? 'bodega' : 'tienda') : $r['tipo'],
                array_key_exists('vende_publico', $b) ? (!empty($b['vende_publico']) ? 1 : 0) : $r['vende_publico'],
                array_key_exists('serie_folio', $b) ? $b['serie_folio'] : $r['serie_folio'],
                array_key_exists('direccion', $b) ? $b['direccion'] : $r['direccion'],
                array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
                $id,
            ]
        );
        Http::updated(self::fmt(Db::one('SELECT * FROM almacenes WHERE id=?', [$id])), 'Almacen');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE almacenes SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Almacen');
    }
}
