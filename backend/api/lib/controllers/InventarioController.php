<?php
class InventarioController
{
    /** Objeto color/talla poblado, con placeholder cuando es 0 (Unico/Unica) */
    private static function refColor($id, $nombre, $hex): array
    {
        if ((int) $id === 0) return ['_id' => '', 'nombre' => 'Unico', 'hex' => null];
        return ['_id' => (string) $id, 'nombre' => $nombre, 'hex' => $hex];
    }
    private static function refTalla($id, $nombre, $orden): array
    {
        if ((int) $id === 0) return ['_id' => '', 'nombre' => 'Unica', 'orden' => 0];
        return ['_id' => (string) $id, 'nombre' => $nombre, 'orden' => (int) $orden];
    }

    /**
     * Aplica un delta a la existencia y registra el movimiento (kardex).
     * Devuelve la fila de inventario actualizada. Permite negativos.
     */
    public static function aplicar(int $emp, int $idArt, int $idColor, int $idTalla, int $idAlm,
        float $delta, string $tipo, ?string $motivo, ?string $refTipo, ?int $idRef, ?int $idUser, ?string $folio = null): array
    {
        $inv = Db::one('SELECT * FROM inventario WHERE id_articulo=? AND id_color=? AND id_talla=? AND id_almacen=?',
            [$idArt, $idColor, $idTalla, $idAlm]);
        if (!$inv) {
            $id = Db::insert('INSERT INTO inventario (id_empresa,id_articulo,id_color,id_talla,id_almacen,cantidad) VALUES (?,?,?,?,?,0)',
                [$emp, $idArt, $idColor, $idTalla, $idAlm]);
            $inv = Db::one('SELECT * FROM inventario WHERE id=?', [$id]);
        }
        $nuevo = round((float) $inv['cantidad'] + $delta, 2);
        Db::run('UPDATE inventario SET cantidad=? WHERE id=?', [$nuevo, $inv['id']]);
        Db::insert(
            'INSERT INTO inventario_movimientos (id_empresa,folio,tipo,id_articulo,id_color,id_talla,id_almacen,
              cantidad,saldo_resultante,motivo,referencia_tipo,id_referencia,id_usuario)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, $folio, $tipo, $idArt, $idColor, $idTalla, $idAlm, $delta, $nuevo, $motivo, $refTipo, $idRef, $idUser]
        );
        return Db::one('SELECT * FROM inventario WHERE id=?', [$inv['id']]);
    }

    public static function existencias(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'i.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_almacen']))  { $where .= ' AND i.id_almacen=?';  $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_articulo'])) { $where .= ' AND i.id_articulo=?'; $args[] = (int) $_GET['id_articulo']; }
        if (!empty($_GET['solo_con_stock']) && $_GET['solo_con_stock'] !== 'false') $where .= ' AND i.cantidad <> 0';
        $limit = min(2000, max(1, (int) ($_GET['limit'] ?? 500)));

        $rows = Db::all(
            "SELECT i.*, a.codigo art_codigo, a.descripcion art_desc, a.id_marca art_marca, a.costo art_costo,
                    m.nombre marca_nombre,
                    c.nombre col_nombre, c.extra col_hex,
                    t.nombre tal_nombre, t.orden tal_orden,
                    al.nombre alm_nombre, al.tipo alm_tipo
             FROM inventario i
             JOIN articulos a ON a.id=i.id_articulo
             LEFT JOIN marcas m ON m.id=a.id_marca
             LEFT JOIN atributo_valores c ON c.id=i.id_color
             LEFT JOIN atributo_valores t ON t.id=i.id_talla
             JOIN almacenes al ON al.id=i.id_almacen
             WHERE $where ORDER BY a.descripcion LIMIT $limit", $args);

        $out = array_map(fn($r) => [
            '_id'         => (string) $r['id'],
            'id_articulo' => [
                '_id' => (string) $r['id_articulo'], 'codigo' => $r['art_codigo'], 'descripcion' => $r['art_desc'],
                'costo' => (float) $r['art_costo'],
                'id_marca' => $r['art_marca'] !== null ? ['_id' => (string) $r['art_marca'], 'nombre' => $r['marca_nombre']] : null,
            ],
            'id_color'    => self::refColor($r['id_color'], $r['col_nombre'], $r['col_hex']),
            'id_talla'    => self::refTalla($r['id_talla'], $r['tal_nombre'], $r['tal_orden']),
            'id_almacen'  => ['_id' => (string) $r['id_almacen'], 'nombre' => $r['alm_nombre'], 'tipo' => $r['alm_tipo']],
            'cantidad'    => (float) $r['cantidad'],
            'reservado'   => (float) $r['reservado'],
            'updatedAt'   => $r['updated_at'],
        ], $rows);
        Http::ok($out);
    }

    public static function kardex(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'm.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_almacen']))  { $where .= ' AND m.id_almacen=?';  $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_articulo'])) { $where .= ' AND m.id_articulo=?'; $args[] = (int) $_GET['id_articulo']; }
        if (!empty($_GET['tipo']))        { $where .= ' AND m.tipo=?';        $args[] = $_GET['tipo']; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT m.*, a.codigo art_codigo, a.descripcion art_desc,
                    c.nombre col_nombre, t.nombre tal_nombre,
                    al.nombre alm_nombre, u.nombre usr_nombre, u.apellido usr_apellido
             FROM inventario_movimientos m
             JOIN articulos a ON a.id=m.id_articulo
             LEFT JOIN atributo_valores c ON c.id=m.id_color
             LEFT JOIN atributo_valores t ON t.id=m.id_talla
             JOIN almacenes al ON al.id=m.id_almacen
             LEFT JOIN users u ON u.id=m.id_usuario
             WHERE $where ORDER BY m.created_at DESC, m.id DESC LIMIT $limit", $args);

        $out = array_map(fn($r) => [
            '_id'          => (string) $r['id'],
            'folio'        => $r['folio'],
            'tipo'         => $r['tipo'],
            'id_articulo'  => ['_id' => (string) $r['id_articulo'], 'codigo' => $r['art_codigo'], 'descripcion' => $r['art_desc']],
            'id_color'     => self::refColor($r['id_color'], $r['col_nombre'], null),
            'id_talla'     => self::refTalla($r['id_talla'], $r['tal_nombre'], 0),
            'id_almacen'   => ['_id' => (string) $r['id_almacen'], 'nombre' => $r['alm_nombre']],
            'cantidad'     => (float) $r['cantidad'],
            'saldo_resultante' => (float) $r['saldo_resultante'],
            'motivo'       => $r['motivo'],
            'referencia_tipo' => $r['referencia_tipo'],
            'id_usuario'   => $r['id_usuario'] ? ['_id' => (string) $r['id_usuario'], 'nombre' => $r['usr_nombre'], 'apellido' => $r['usr_apellido']] : null,
            'createdAt'    => $r['created_at'],
        ], $rows);
        Http::ok($out);
    }

    public static function ajuste(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idArt = id_or_null($b['id_articulo'] ?? null);
        $idAlm = id_or_null($b['id_almacen'] ?? null);
        $delta = (float) ($b['delta'] ?? 0);
        if (!$idArt || !$idAlm) throw new ApiError('id_articulo e id_almacen son obligatorios', 400, 'VALIDATION');
        if ($delta == 0) throw new ApiError('El delta no puede ser cero', 400, 'VALIDATION');

        Db::begin();
        try {
            $inv = self::aplicar($emp, $idArt, id_or_zero($b['id_color'] ?? 0), id_or_zero($b['id_talla'] ?? 0),
                $idAlm, $delta, 'ajuste', $b['motivo'] ?? 'Ajuste manual', 'Ajuste', null, (int) $ctx['user']['id']);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok(['_id' => (string) $inv['id'], 'cantidad' => (float) $inv['cantidad']], 'Ajuste aplicado');
    }

    public static function ajusteLote(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idAlm = id_or_null($b['id_almacen'] ?? null);
        $lineas = $b['lineas'] ?? [];
        if (!$idAlm) throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('Se requiere al menos una linea', 400, 'VALIDATION');
        $motivo = $b['motivo'] ?? 'Ajuste manual';

        $res = [];
        Db::begin();
        try {
            foreach ($lineas as $ln) {
                $delta = (float) ($ln['delta'] ?? 0);
                if ($delta == 0) continue;
                $idArt = id_or_null($ln['id_articulo'] ?? null);
                if (!$idArt) throw new ApiError('Linea sin id_articulo', 400, 'VALIDATION');
                $inv = self::aplicar($emp, $idArt, id_or_zero($ln['id_color'] ?? 0), id_or_zero($ln['id_talla'] ?? 0),
                    $idAlm, $delta, 'ajuste', $motivo, 'Ajuste', null, (int) $ctx['user']['id']);
                $res[] = ['_id' => (string) $inv['id'], 'cantidad' => (float) $inv['cantidad']];
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok($res, 'Ajustes aplicados');
    }
}
