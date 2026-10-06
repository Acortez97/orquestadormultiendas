<?php
class CategoriaController
{
    private static function refAtributo($id): ?array
    {
        if ($id === null) return null;
        $a = Db::one('SELECT id,nombre FROM atributos WHERE id=?', [(int) $id]);
        return ['_id' => (string) $id, 'nombre' => $a['nombre'] ?? null];
    }

    private static function fmt(array $c): array
    {
        return [
            '_id'          => (string) $c['id'],
            'nombre'       => $c['nombre'],
            'prefijo_sku'  => $c['prefijo_sku'],
            'id_atributo_eje1' => self::refAtributo($c['id_atributo_eje1']),
            'id_atributo_eje2' => self::refAtributo($c['id_atributo_eje2']),
            'maneja_variantes' => ($c['id_atributo_eje1'] !== null || $c['id_atributo_eje2'] !== null),
            'ficha_schema' => !empty($c['ficha_schema']) ? json_decode($c['ficha_schema'], true) : [],
            'is_active'    => $c['is_active'],
            'createdAt'    => $c['created_at'] ?? null,
            'updatedAt'    => $c['updated_at'] ?? null,
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $estado = $_GET['is_active'] ?? 'Si';
        $where = 'id_empresa=?'; $args = [$emp];
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        $rows = Db::all("SELECT * FROM categorias WHERE $where ORDER BY nombre", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        $c = Db::one('SELECT * FROM categorias WHERE id=? AND id_empresa=?', [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        if (!$c) throw new ApiError('Categoria no encontrada', 404, 'NOT_FOUND');
        Http::ok(self::fmt($c));
    }

    /** Valida que un atributo (eje) pertenezca a la empresa; devuelve id o null */
    private static function ejeValido(int $emp, $id): ?int
    {
        $id = id_or_null($id);
        if (!$id) return null;
        if (!Db::one('SELECT id FROM atributos WHERE id=? AND id_empresa=?', [$id, $emp]))
            throw new ApiError("Atributo de eje $id no encontrado", 404, 'NOT_FOUND');
        return $id;
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

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert(
            'INSERT INTO categorias (id_empresa,nombre,prefijo_sku,id_atributo_eje1,id_atributo_eje2,ficha_schema) VALUES (?,?,?,?,?,?)',
            [$emp, trim($b['nombre']), $b['prefijo_sku'] ? strtoupper(trim($b['prefijo_sku'])) : null,
             self::ejeValido($emp, $b['id_atributo_eje1'] ?? null), self::ejeValido($emp, $b['id_atributo_eje2'] ?? null),
             self::fichaJson($b['ficha_schema'] ?? null)]);
        Http::created(self::fmt(Db::one('SELECT * FROM categorias WHERE id=?', [$id])), 'Categoria');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $c = Db::one('SELECT * FROM categorias WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Categoria no encontrada', 404, 'NOT_FOUND');
        $b = Http::body();
        Db::run('UPDATE categorias SET nombre=?, prefijo_sku=?, id_atributo_eje1=?, id_atributo_eje2=?, ficha_schema=?, is_active=? WHERE id=?', [
            array_key_exists('nombre', $b) ? trim($b['nombre']) : $c['nombre'],
            array_key_exists('prefijo_sku', $b) ? ($b['prefijo_sku'] ? strtoupper(trim($b['prefijo_sku'])) : null) : $c['prefijo_sku'],
            array_key_exists('id_atributo_eje1', $b) ? self::ejeValido($emp, $b['id_atributo_eje1']) : $c['id_atributo_eje1'],
            array_key_exists('id_atributo_eje2', $b) ? self::ejeValido($emp, $b['id_atributo_eje2']) : $c['id_atributo_eje2'],
            array_key_exists('ficha_schema', $b) ? self::fichaJson($b['ficha_schema']) : $c['ficha_schema'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $c['is_active'],
            $id,
        ]);
        Http::updated(self::fmt(Db::one('SELECT * FROM categorias WHERE id=?', [$id])), 'Categoria');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE categorias SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Categoria');
    }
}
