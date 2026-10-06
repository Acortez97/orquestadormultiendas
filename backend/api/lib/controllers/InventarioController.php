<?php
// Existencias por celda (almacen + articulo + variante) y kardex de la tienda.
class InventarioController
{
    /**
     * Aplica un delta a la existencia de una celda y registra el movimiento en el kardex.
     * Debe llamarse dentro de una transaccion. Bloquea la fila (FOR UPDATE) para evitar carreras.
     * $v1 / $v2 = valores de variante (null = sin ese eje). Permite existencias negativas.
     */
    public static function aplicar(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm,
        float $delta, string $tipo, ?string $motivo, ?string $refTipo, ?int $idRef, ?int $idUser, ?string $folio = null): array
    {
        $sql = 'SELECT * FROM inventario WHERE id_empresa = ? AND id_almacen = ? AND id_articulo = ? AND v1_key = ? AND v2_key = ? FOR UPDATE';
        $args = [$emp, $idAlm, $idArt, $v1 ?? 0, $v2 ?? 0];
        $inv = Db::one($sql, $args);
        if (!$inv) {
            Db::insert('INSERT INTO inventario (id_empresa, id_almacen, id_articulo, id_valor1, id_valor2, cantidad) VALUES (?,?,?,?,?,0)',
                [$emp, $idAlm, $idArt, $v1, $v2]);
            $inv = Db::one($sql, $args);
        }
        $nuevo = round((float) $inv['cantidad'] + $delta, 2);
        Db::run('UPDATE inventario SET cantidad = ? WHERE id = ? AND id_empresa = ?', [$nuevo, $inv['id'], $emp]);
        Db::insert(
            'INSERT INTO inventario_movimientos (id_empresa, folio, tipo, id_almacen, id_articulo, id_valor1, id_valor2,
               cantidad, saldo_resultante, motivo, ref_tipo, id_referencia, id_usuario)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, $folio, $tipo, $idAlm, $idArt, $v1, $v2, $delta, $nuevo, $motivo, $refTipo, $idRef, $idUser]);
        $inv['cantidad'] = $nuevo;
        return $inv;
    }

    /** Ajusta la cantidad reservada (apartados). Debe llamarse dentro de una transaccion. */
    public static function reservar(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm, float $delta): void
    {
        $sql = 'SELECT id, reservado FROM inventario WHERE id_empresa = ? AND id_almacen = ? AND id_articulo = ? AND v1_key = ? AND v2_key = ? FOR UPDATE';
        $args = [$emp, $idAlm, $idArt, $v1 ?? 0, $v2 ?? 0];
        $inv = Db::one($sql, $args);
        if (!$inv) {
            Db::insert('INSERT INTO inventario (id_empresa, id_almacen, id_articulo, id_valor1, id_valor2, cantidad) VALUES (?,?,?,?,?,0)',
                [$emp, $idAlm, $idArt, $v1, $v2]);
            $inv = Db::one($sql, $args);
        }
        Db::run('UPDATE inventario SET reservado = GREATEST(0, reservado + ?) WHERE id = ? AND id_empresa = ?', [round($delta, 2), $inv['id'], $emp]);
    }

    /** Existencia disponible (cantidad - reservado) de una celda */
    public static function disponible(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm): float
    {
        $r = Db::one('SELECT cantidad - reservado d FROM inventario WHERE id_empresa = ? AND id_almacen = ? AND id_articulo = ? AND v1_key = ? AND v2_key = ?',
            [$emp, $idAlm, $idArt, $v1 ?? 0, $v2 ?? 0]);
        return $r ? (float) $r['d'] : 0.0;
    }

    public static function existencias(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'i.id_empresa = ?'; $args = [$emp];
        if (!empty($_GET['id_almacen']))  { $where .= ' AND i.id_almacen = ?';  $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_articulo'])) { $where .= ' AND i.id_articulo = ?'; $args[] = (int) $_GET['id_articulo']; }
        if (!empty($_GET['solo_con_stock']) && $_GET['solo_con_stock'] !== 'false') $where .= ' AND i.cantidad <> 0';
        $limit = min(2000, max(1, (int) ($_GET['limit'] ?? 500)));

        $rows = Db::all(
            "SELECT i.*, a.codigo art_codigo, a.descripcion art_desc, a.id_marca art_marca, a.costo art_costo, m.nombre marca_nombre,
                    v1.nombre v1_nombre, v1.extra v1_hex, v1.orden v1_orden, v2.nombre v2_nombre, v2.orden v2_orden,
                    al.nombre alm_nombre, al.tipo alm_tipo
             FROM inventario i
             JOIN articulos a ON a.id_empresa = i.id_empresa AND a.id = i.id_articulo
             LEFT JOIN marcas m ON m.id_empresa = a.id_empresa AND m.id = a.id_marca
             LEFT JOIN atributo_valores v1 ON v1.id_empresa = i.id_empresa AND v1.id = i.id_valor1
             LEFT JOIN atributo_valores v2 ON v2.id_empresa = i.id_empresa AND v2.id = i.id_valor2
             JOIN almacenes al ON al.id_empresa = i.id_empresa AND al.id = i.id_almacen
             WHERE $where ORDER BY a.descripcion LIMIT $limit", $args);

        Http::ok(array_map(fn($r) => [
            '_id'         => (int) $r['id'],
            'id_articulo' => [
                '_id' => (int) $r['id_articulo'], 'codigo' => $r['art_codigo'], 'descripcion' => $r['art_desc'], 'costo' => (float) $r['art_costo'],
                'id_marca' => $r['art_marca'] !== null ? ['_id' => (int) $r['art_marca'], 'nombre' => $r['marca_nombre']] : null,
            ],
            'id_color'    => Variantes::ref($r['id_valor1'], $r['v1_nombre'], $r['v1_hex'], $r['v1_orden'], 'Unico'),
            'id_talla'    => Variantes::ref($r['id_valor2'], $r['v2_nombre'], null, $r['v2_orden'], 'Unica'),
            'id_almacen'  => ['_id' => (int) $r['id_almacen'], 'nombre' => $r['alm_nombre'], 'tipo' => $r['alm_tipo']],
            'cantidad'    => (float) $r['cantidad'],
            'reservado'   => (float) $r['reservado'],
            'updatedAt'   => $r['updated_at'],
        ], $rows));
    }

    public static function kardex(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'm.id_empresa = ?'; $args = [$emp];
        if (!empty($_GET['id_almacen']))  { $where .= ' AND m.id_almacen = ?';  $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_articulo'])) { $where .= ' AND m.id_articulo = ?'; $args[] = (int) $_GET['id_articulo']; }
        if (!empty($_GET['tipo']))        { $where .= ' AND m.tipo = ?';        $args[] = $_GET['tipo']; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT m.*, a.codigo art_codigo, a.descripcion art_desc, v1.nombre v1_nombre, v2.nombre v2_nombre,
                    al.nombre alm_nombre, u.nombre usr_nombre, u.apellido usr_apellido
             FROM inventario_movimientos m
             JOIN articulos a ON a.id_empresa = m.id_empresa AND a.id = m.id_articulo
             LEFT JOIN atributo_valores v1 ON v1.id_empresa = m.id_empresa AND v1.id = m.id_valor1
             LEFT JOIN atributo_valores v2 ON v2.id_empresa = m.id_empresa AND v2.id = m.id_valor2
             JOIN almacenes al ON al.id_empresa = m.id_empresa AND al.id = m.id_almacen
             LEFT JOIN users u ON u.id_empresa = m.id_empresa AND u.id = m.id_usuario
             WHERE $where ORDER BY m.created_at DESC, m.id DESC LIMIT $limit", $args);

        Http::ok(array_map(fn($r) => [
            '_id'          => (int) $r['id'],
            'folio'        => $r['folio'],
            'tipo'         => $r['tipo'],
            'id_articulo'  => ['_id' => (int) $r['id_articulo'], 'codigo' => $r['art_codigo'], 'descripcion' => $r['art_desc']],
            'id_color'     => Variantes::ref($r['id_valor1'], $r['v1_nombre'], null, 0, 'Unico'),
            'id_talla'     => Variantes::ref($r['id_valor2'], $r['v2_nombre'], null, 0, 'Unica'),
            'id_almacen'   => ['_id' => (int) $r['id_almacen'], 'nombre' => $r['alm_nombre']],
            'cantidad'     => (float) $r['cantidad'],
            'saldo_resultante' => (float) $r['saldo_resultante'],
            'motivo'       => $r['motivo'],
            'referencia_tipo' => $r['ref_tipo'],
            'id_usuario'   => $r['id_usuario'] ? ['_id' => (int) $r['id_usuario'], 'nombre' => $r['usr_nombre'], 'apellido' => $r['usr_apellido']] : null,
            'createdAt'    => $r['created_at'],
        ], $rows));
    }

    /** Una linea de ajuste validada: [idArt, v1, v2] */
    private static function lineaAjuste(array $ln): array
    {
        $idArt = Tenant::owns('articulos', $ln['id_articulo'] ?? null, 'Articulo', true);
        $art = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [$idArt, Tenant::id()]);
        if ((int) $art['es_kit']) throw new ApiError("\"{$art['descripcion']}\" es un kit: ajusta sus componentes", 400, 'VALIDATION');
        [$v1, $v2] = Variantes::validar($art, $ln['id_color'] ?? null, $ln['id_talla'] ?? null);
        return [$idArt, $v1, $v2];
    }

    public static function ajuste(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? null, 'Almacen', true);
        $delta = num($b['delta'] ?? 0);
        if ($delta == 0) throw new ApiError('El ajuste no puede ser cero', 400, 'VALIDATION');
        [$idArt, $v1, $v2] = self::lineaAjuste($b);

        Db::begin();
        $inv = self::aplicar($emp, $idArt, $v1, $v2, $idAlm, $delta, 'ajuste', $b['motivo'] ?? 'Ajuste manual', 'Ajuste', null, $ctx['user']['id']);
        Db::commit();
        Ledger::audit($ctx, 'ajuste', 'inventario', $inv['id'], 'Ajuste ' . $delta);
        Http::ok(['_id' => (int) $inv['id'], 'cantidad' => (float) $inv['cantidad']], 'Ajuste aplicado');
    }

    public static function ajusteLote(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? null, 'Almacen', true);
        $lineas = $b['lineas'] ?? [];
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('Se requiere al menos una linea', 400, 'VALIDATION');
        $motivo = $b['motivo'] ?? 'Ajuste manual';

        $validas = [];
        foreach ($lineas as $ln) {
            $delta = num($ln['delta'] ?? 0);
            if ($delta == 0) continue;
            $validas[] = array_merge(self::lineaAjuste($ln), [$delta]);
        }
        $res = [];
        Db::begin();
        foreach ($validas as [$idArt, $v1, $v2, $delta]) {
            $inv = self::aplicar($emp, $idArt, $v1, $v2, $idAlm, $delta, 'ajuste', $motivo, 'Ajuste', null, $ctx['user']['id']);
            $res[] = ['_id' => (int) $inv['id'], 'cantidad' => (float) $inv['cantidad']];
        }
        Db::commit();
        Ledger::audit($ctx, 'ajuste_lote', 'inventario', null, count($res) . ' ajustes');
        Http::ok($res, 'Ajustes aplicados');
    }
}
