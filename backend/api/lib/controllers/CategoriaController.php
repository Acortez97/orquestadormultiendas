<?php
// Categorias: definen los ejes de variante (atributos) y la ficha de cada tipo de producto. Por tienda.
class CategoriaController
{
    private static function refAtributo($id): ?array
    {
        if ($id === null) return null;
        $a = Db::one('SELECT nombre FROM atributos WHERE id = ? AND id_empresa = ?', [(int) $id, Tenant::id()]);
        return ['_id' => (int) $id, 'nombre' => $a['nombre'] ?? null];
    }

    public static function fmt(array $c): array
    {
        return [
            '_id'              => (int) $c['id'],
            'nombre'           => $c['nombre'],
            'prefijo_sku'      => $c['prefijo_sku'],
            'id_atributo_eje1' => self::refAtributo($c['id_atributo_eje1']),
            'id_atributo_eje2' => self::refAtributo($c['id_atributo_eje2']),
            'maneja_variantes' => ($c['id_atributo_eje1'] !== null || $c['id_atributo_eje2'] !== null),
            'ficha_schema'     => !empty($c['ficha_schema']) ? json_decode($c['ficha_schema'], true) : [],
            'is_active'        => $c['is_active'],
            'createdAt'        => $c['created_at'] ?? null,
            'updatedAt'        => $c['updated_at'] ?? null,
        ];
    }

    private static function categoria(int $id): array
    {
        $c = Db::one('SELECT * FROM categorias WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$c) throw new ApiError('Categoria no encontrada', 404, 'NOT_FOUND');
        return $c;
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM categorias WHERE $where ORDER BY nombre", $args)));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::fmt(self::categoria((int) $p['id'])));
    }

    private static function fichaJson($ficha): ?string
    {
        if (!is_array($ficha)) return null;
        $out = [];
        foreach ($ficha as $campo) {
            if (!is_array($campo) || trim($campo['key'] ?? '') === '') continue;
            $out[] = [
                'key'   => trim($campo['key']),
                'label' => trim($campo['label'] ?? $campo['key']),
                'tipo'  => in_array($campo['tipo'] ?? 'texto', ['texto', 'numero', 'fecha', 'booleano'], true) ? $campo['tipo'] : 'texto',
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** Valida los ejes: deben ser atributos de la tienda, el 2 requiere el 1 y no pueden repetirse */
    private static function ejes($e1, $e2): array
    {
        $e1 = Tenant::owns('atributos', $e1, 'Atributo del eje 1');
        $e2 = Tenant::owns('atributos', $e2, 'Atributo del eje 2');
        if ($e2 && !$e1) throw new ApiError('Define el eje 1 antes que el eje 2', 400, 'VALIDATION');
        if ($e1 && $e1 === $e2) throw new ApiError('Los dos ejes deben ser atributos distintos', 400, 'VALIDATION');
        return [$e1, $e2];
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        [$e1, $e2] = self::ejes($b['id_atributo_eje1'] ?? null, $b['id_atributo_eje2'] ?? null);
        $id = Db::insert(
            'INSERT INTO categorias (id_empresa, nombre, prefijo_sku, id_atributo_eje1, id_atributo_eje2, ficha_schema) VALUES (?,?,?,?,?,?)',
            [Tenant::id(), $nombre, !empty($b['prefijo_sku']) ? strtoupper(trim($b['prefijo_sku'])) : null, $e1, $e2,
             self::fichaJson($b['ficha_schema'] ?? null)]);
        Http::created(self::fmt(self::categoria($id)), 'Categoria');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $c = self::categoria((int) $p['id']);
        $b = Http::body();
        [$e1, $e2] = self::ejes(
            array_key_exists('id_atributo_eje1', $b) ? $b['id_atributo_eje1'] : $c['id_atributo_eje1'],
            array_key_exists('id_atributo_eje2', $b) ? $b['id_atributo_eje2'] : $c['id_atributo_eje2']);
        Db::run('UPDATE categorias SET nombre = ?, prefijo_sku = ?, id_atributo_eje1 = ?, id_atributo_eje2 = ?, ficha_schema = ?, is_active = ?
                 WHERE id = ? AND id_empresa = ?', [
            array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $c['nombre'],
            array_key_exists('prefijo_sku', $b) ? (!empty($b['prefijo_sku']) ? strtoupper(trim($b['prefijo_sku'])) : null) : $c['prefijo_sku'],
            $e1, $e2,
            array_key_exists('ficha_schema', $b) ? self::fichaJson($b['ficha_schema']) : $c['ficha_schema'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $c['is_active'],
            $c['id'], Tenant::id(),
        ]);
        Http::updated(self::fmt(self::categoria((int) $c['id'])), 'Categoria');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $c = self::categoria((int) $p['id']);
        Db::run("UPDATE categorias SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$c['id'], Tenant::id()]);
        Http::deleted('Categoria');
    }
}
