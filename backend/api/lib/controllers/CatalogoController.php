<?php
class CatalogoController
{
    private static $tablas = [
        'colores'         => 'colores',
        'tallas'          => 'tallas',
        'familias'        => 'familias',
        'lineas'          => 'lineas',
        'colecciones'     => 'colecciones',
        'cortes'          => 'cortes_catalogo',
        'marcas'          => 'marcas',
        'conceptos-gasto' => 'conceptos_gasto',
    ];

    private static function tabla(string $tipo): string
    {
        if (!isset(self::$tablas[$tipo])) throw new ApiError("Catalogo desconocido: $tipo", 404, 'NOT_FOUND');
        return self::$tablas[$tipo];
    }

    private static function fmt(string $tipo, array $r): array
    {
        $o = [
            '_id'       => (string) $r['id'],
            'nombre'    => $r['nombre'],
            'is_active' => $r['is_active'],
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
        if ($tipo === 'colores') $o['hex'] = $r['hex'];
        if ($tipo === 'tallas')  $o['orden'] = (int) $r['orden'];
        return $o;
    }

    public static function listar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];

        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado === 'todos' || $estado === '') {
            // sin filtro de estado
        } else {
            $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si');
        }
        if (!empty($_GET['search'])) { $where .= ' AND nombre LIKE ?'; $args[] = '%' . $_GET['search'] . '%'; }

        $orden = ($p['tipo'] === 'tallas') ? 'orden ASC, nombre ASC' : 'nombre ASC';
        $rows = Db::all("SELECT * FROM $tabla WHERE $where ORDER BY $orden", $args);
        Http::ok(array_map(fn($r) => self::fmt($p['tipo'], $r), $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $r = Db::one("SELECT * FROM $tabla WHERE id=? AND id_empresa=?", [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        if (!$r) throw new ApiError('Registro no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::fmt($p['tipo'], $r));
    }

    public static function crear(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $b = Http::body();
        $nombre = trim($b['nombre'] ?? '');
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $emp = (int) $ctx['user']['id_empresa'];

        if ($p['tipo'] === 'colores') {
            $id = Db::insert("INSERT INTO colores (id_empresa,nombre,hex) VALUES (?,?,?)",
                [$emp, $nombre, $b['hex'] ?? null]);
        } elseif ($p['tipo'] === 'tallas') {
            $id = Db::insert("INSERT INTO tallas (id_empresa,nombre,orden) VALUES (?,?,?)",
                [$emp, $nombre, (int) ($b['orden'] ?? 0)]);
        } else {
            $id = Db::insert("INSERT INTO $tabla (id_empresa,nombre) VALUES (?,?)", [$emp, $nombre]);
        }
        $r = Db::one("SELECT * FROM $tabla WHERE id=?", [$id]);
        Http::created(self::fmt($p['tipo'], $r), 'Registro');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        $emp = (int) $ctx['user']['id_empresa'];
        $r = Db::one("SELECT * FROM $tabla WHERE id=? AND id_empresa=?", [(int) $p['id'], $emp]);
        if (!$r) throw new ApiError('Registro no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();

        $nombre = array_key_exists('nombre', $b) ? trim($b['nombre']) : $r['nombre'];
        $active = array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $r['is_active'];

        if ($p['tipo'] === 'colores') {
            Db::run("UPDATE colores SET nombre=?, hex=?, is_active=? WHERE id=?",
                [$nombre, array_key_exists('hex', $b) ? $b['hex'] : $r['hex'], $active, $r['id']]);
        } elseif ($p['tipo'] === 'tallas') {
            Db::run("UPDATE tallas SET nombre=?, orden=?, is_active=? WHERE id=?",
                [$nombre, array_key_exists('orden', $b) ? (int) $b['orden'] : $r['orden'], $active, $r['id']]);
        } else {
            Db::run("UPDATE $tabla SET nombre=?, is_active=? WHERE id=?", [$nombre, $active, $r['id']]);
        }
        $r = Db::one("SELECT * FROM $tabla WHERE id=?", [$r['id']]);
        Http::updated(self::fmt($p['tipo'], $r), 'Registro');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $tabla = self::tabla($p['tipo']);
        Db::run("UPDATE $tabla SET is_active='No' WHERE id=? AND id_empresa=?",
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Registro');
    }
}
