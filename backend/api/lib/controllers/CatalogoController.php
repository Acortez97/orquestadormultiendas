<?php
// Catalogos simples por tienda. Colores y tallas ahora son valores de atributos (AtributoController).
class CatalogoController
{
    private static $tablas = [
        'familias'        => 'familias',
        'lineas'          => 'lineas',
        'cortes'          => 'cortes_catalogo',
        'marcas'          => 'marcas',
        'conceptos-gasto' => 'conceptos_gasto',
    ];

    private static function tabla(string $tipo): string
    {
        if (!isset(self::$tablas[$tipo])) throw new ApiError('Catalogo no encontrado', 404, 'NOT_FOUND');
        return self::$tablas[$tipo];
    }

    private static function fmt(array $r): array
    {
        return [
            '_id'       => (int) $r['id'],
            'nombre'    => $r['nombre'],
            'is_active' => $r['is_active'],
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }

    private static function fila(string $tabla, int $id): array
    {
        $r = Db::one("SELECT * FROM $tabla WHERE id = ? AND id_empresa = ?", [$id, Tenant::id()]);
        if (!$r) throw new ApiError('Registro no encontrado', 404, 'NOT_FOUND');
        return $r;
    }

    public static function listar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        if (!empty($_GET['search'])) { $where .= ' AND nombre LIKE ?'; $args[] = '%' . $_GET['search'] . '%'; }
        $rows = Db::all("SELECT * FROM $tabla WHERE $where ORDER BY nombre ASC", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::fmt(self::fila(self::tabla($p['tipo']), (int) $p['id'])));
    }

    public static function crear(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $nombre = trim((string) (Http::body()['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert("INSERT INTO $tabla (id_empresa, nombre) VALUES (?, ?)", [Tenant::id(), $nombre]);
        Http::created(self::fmt(self::fila($tabla, $id)), 'Registro');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $r = self::fila($tabla, (int) $p['id']);
        $b = Http::body();
        $nombre = array_key_exists('nombre', $b) ? trim((string) $b['nombre']) : $r['nombre'];
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $active = array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'];
        Db::run("UPDATE $tabla SET nombre = ?, is_active = ? WHERE id = ? AND id_empresa = ?", [$nombre, $active, $r['id'], Tenant::id()]);
        Http::updated(self::fmt(self::fila($tabla, (int) $r['id'])), 'Registro');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $r = self::fila($tabla, (int) $p['id']);
        Db::run("UPDATE $tabla SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$r['id'], Tenant::id()]);
        Http::deleted('Registro');
    }
}
