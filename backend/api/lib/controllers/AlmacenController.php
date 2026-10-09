<?php
// Almacenes (sucursales / bodegas) de la tienda.
class AlmacenController
{
    public static function fmt(array $r): array
    {
        return [
            '_id'           => (int) $r['id'],
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

    private static function almacen(int $id): array
    {
        $r = Db::one('SELECT * FROM almacenes WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$r) throw new ApiError('Almacen no encontrado', 404, 'NOT_FOUND');
        return $r;
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM almacenes WHERE $where ORDER BY nombre", $args)));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $codigo = strtoupper(trim((string) ($b['codigo'] ?? '')));
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($codigo === '' || $nombre === '') throw new ApiError('Codigo y nombre son obligatorios', 400, 'VALIDATION');
        if (Db::one('SELECT id FROM almacenes WHERE id_empresa = ? AND codigo = ?', [$emp, $codigo]))
            throw new ApiError('Ya existe un almacen con ese codigo', 409, 'CONFLICT');
        $max = Tenant::empresa()['max_almacenes'];
        if ($max !== null && (int) Db::one("SELECT COUNT(*) n FROM almacenes WHERE id_empresa = ? AND is_active = 'Si'", [$emp])['n'] >= (int) $max)
            throw new ApiError('La tienda alcanzo su limite de almacenes', 400, 'LIMITE');

        $id = Db::insert(
            'INSERT INTO almacenes (id_empresa, codigo, nombre, tipo, vende_publico, serie_folio, direccion, telefono) VALUES (?,?,?,?,?,?,?,?)',
            [$emp, $codigo, $nombre, ($b['tipo'] ?? 'tienda') === 'bodega' ? 'bodega' : 'tienda',
             array_key_exists('vende_publico', $b) ? (!empty($b['vende_publico']) ? 1 : 0) : 1,
             $b['serie_folio'] ?? null, $b['direccion'] ?? null, $b['telefono'] ?? null]);
        Ledger::audit($ctx, 'crear', 'almacen', $id, "Almacen $codigo");
        Http::created(self::fmt(self::almacen($id)), 'Almacen');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $r = self::almacen((int) $p['id']);
        $b = Http::body();
        $codigo = array_key_exists('codigo', $b) ? strtoupper(trim((string) $b['codigo'])) : $r['codigo'];
        if ($codigo === '') throw new ApiError('El codigo es obligatorio', 400, 'VALIDATION');
        if ($codigo !== $r['codigo'] && Db::one('SELECT id FROM almacenes WHERE id_empresa = ? AND codigo = ? AND id <> ?', [Tenant::id(), $codigo, $r['id']]))
            throw new ApiError('Ya existe un almacen con ese codigo', 409, 'CONFLICT');
        // reactivar cuenta contra el limite de almacenes de la plataforma
        if (($b['is_active'] ?? null) === 'Si' && $r['is_active'] !== 'Si') {
            $max = Tenant::empresa()['max_almacenes'];
            if ($max !== null && (int) Db::one("SELECT COUNT(*) n FROM almacenes WHERE id_empresa = ? AND is_active = 'Si'", [Tenant::id()])['n'] >= (int) $max)
                throw new ApiError('La tienda alcanzo su limite de almacenes', 400, 'LIMITE');
        }
        Db::run(
            'UPDATE almacenes SET codigo = ?, nombre = ?, tipo = ?, vende_publico = ?, serie_folio = ?, direccion = ?, telefono = ?, is_active = ?
             WHERE id = ? AND id_empresa = ?',
            [$codigo,
             array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $r['nombre'],
             array_key_exists('tipo', $b) ? (($b['tipo'] === 'bodega') ? 'bodega' : 'tienda') : $r['tipo'],
             array_key_exists('vende_publico', $b) ? (!empty($b['vende_publico']) ? 1 : 0) : $r['vende_publico'],
             array_key_exists('serie_folio', $b) ? $b['serie_folio'] : $r['serie_folio'],
             array_key_exists('direccion', $b) ? $b['direccion'] : $r['direccion'],
             array_key_exists('telefono', $b) ? $b['telefono'] : $r['telefono'],
             array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'],
             $r['id'], Tenant::id()]);
        Http::updated(self::fmt(self::almacen((int) $r['id'])), 'Almacen');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $r = self::almacen((int) $p['id']);
        Db::run("UPDATE almacenes SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$r['id'], Tenant::id()]);
        Ledger::audit($ctx, 'desactivar', 'almacen', $r['id'], "Almacen {$r['codigo']}");
        Http::deleted('Almacen');
    }
}
