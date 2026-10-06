<?php
class CompraController
{
    private static function ivaEmpresa(int $emp): float
    {
        $e = Db::one('SELECT iva FROM empresas WHERE id=?', [$emp]);
        return $e ? (float) $e['iva'] : 0.16;
    }

    private static function nextFolio(int $emp): string
    {
        $n = (int) (Db::one('SELECT COUNT(*) c FROM compras WHERE id_empresa=?', [$emp])['c'] ?? 0) + 1;
        return 'C-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    /** Cabecera resumida para listados */
    private static function fmtRow(array $c): array
    {
        return [
            '_id'          => (string) $c['id'],
            'folio'        => $c['folio'],
            'fecha'        => $c['fecha'],
            'id_proveedor' => ['_id' => (string) $c['id_proveedor'], 'nombre' => $c['prov_nombre'] ?? ''],
            'id_almacen'   => ['_id' => (string) $c['id_almacen'], 'nombre' => $c['alm_nombre'] ?? ''],
            'aplica_iva'   => (bool) $c['aplica_iva'],
            'subtotal'     => (float) $c['subtotal'],
            'iva'          => (float) $c['iva'],
            'total'        => (float) $c['total'],
            'total_pagado' => (float) $c['total_pagado'],
            'estado'       => $c['estado'],
        ];
    }

    /** Compra completa (con lineas) para detalle */
    public static function obtenerCompra(int $emp, int $id): array
    {
        $c = Db::one(
            'SELECT c.*, p.nombre prov_nombre, al.nombre alm_nombre, u.nombre apr_nombre, u.apellido apr_apellido
             FROM compras c
             JOIN proveedores p ON p.id=c.id_proveedor
             JOIN almacenes al  ON al.id=c.id_almacen
             LEFT JOIN users u ON u.id=c.aprobada_por
             WHERE c.id=? AND c.id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');

        $lineas = array_map(fn($l) => [
            '_id'            => (string) $l['id'],
            'id_articulo'    => ['_id' => (string) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion']],
            'codigo'         => $l['codigo'],
            'descripcion'    => $l['descripcion'],
            'id_color'       => (int) $l['id_color'] === 0 ? ['_id' => '', 'nombre' => 'Unico'] : ['_id' => (string) $l['id_color'], 'nombre' => $l['col_nombre']],
            'id_talla'       => (int) $l['id_talla'] === 0 ? ['_id' => '', 'nombre' => 'Unica'] : ['_id' => (string) $l['id_talla'], 'nombre' => $l['tal_nombre']],
            'cantidad'       => (float) $l['cantidad'],
            'costo_unitario' => (float) $l['costo_unitario'],
            'importe'        => (float) $l['importe'],
        ], Db::all(
            'SELECT cl.*, c.nombre col_nombre, t.nombre tal_nombre
             FROM compra_lineas cl
             LEFT JOIN atributo_valores c ON c.id=cl.id_color
             LEFT JOIN atributo_valores t ON t.id=cl.id_talla
             WHERE cl.id_compra=?', [$id]));

        $out = self::fmtRow($c);
        $out['notas']  = $c['notas'];
        $out['aplica_iva'] = (bool) $c['aplica_iva'];
        $out['aprobada_por'] = $c['aprobada_por'] ? ['nombre' => $c['apr_nombre'], 'apellido' => $c['apr_apellido']] : null;
        $out['fecha_aprobacion'] = $c['fecha_aprobacion'];
        $out['lineas'] = $lineas;
        $out['createdAt'] = $c['created_at'];
        return $out;
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'c.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_proveedor'])) { $where .= ' AND c.id_proveedor=?'; $args[] = (int) $_GET['id_proveedor']; }
        if (!empty($_GET['id_almacen']))   { $where .= ' AND c.id_almacen=?';   $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['estado']))       { $where .= ' AND c.estado=?';        $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))        { $where .= ' AND c.fecha>=?';        $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))        { $where .= ' AND c.fecha<=?';        $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT c.*, p.nombre prov_nombre, al.nombre alm_nombre
             FROM compras c
             JOIN proveedores p ON p.id=c.id_proveedor
             JOIN almacenes al  ON al.id=c.id_almacen
             WHERE $where ORDER BY c.fecha DESC, c.id DESC LIMIT $limit", $args);

        Http::ok(array_map([self::class, 'fmtRow'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerCompra((int) $ctx['user']['id_empresa'], (int) $p['id']));
    }

    /** Normaliza y valida las lineas del body; devuelve [lineas, subtotal] */
    private static function prepararLineas(int $emp, array $rawLineas): array
    {
        if (!is_array($rawLineas) || count($rawLineas) === 0) {
            throw new ApiError('La compra no tiene lineas', 400, 'VALIDATION');
        }
        $lineas = []; $subtotal = 0.0;
        foreach ($rawLineas as $ln) {
            $idArt = id_or_null($ln['id_articulo'] ?? null);
            if (!$idArt) throw new ApiError('Linea sin id_articulo', 400, 'VALIDATION');
            $cant = (float) ($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $art = Db::one('SELECT id,codigo,descripcion FROM articulos WHERE id=? AND id_empresa=?', [$idArt, $emp]);
            if (!$art) throw new ApiError("Articulo $idArt no encontrado", 404, 'NOT_FOUND');
            $costo = num($ln['costo_unitario'] ?? 0);
            $importe = round($cant * $costo, 2);
            $subtotal += $importe;
            $lineas[] = [
                'id_articulo'    => $idArt,
                'codigo'         => $art['codigo'],
                'descripcion'    => $art['descripcion'],
                'id_color'       => id_or_zero($ln['id_color'] ?? 0),
                'id_talla'       => id_or_zero($ln['id_talla'] ?? 0),
                'cantidad'       => $cant,
                'costo_unitario' => $costo,
                'importe'        => $importe,
            ];
        }
        if (count($lineas) === 0) throw new ApiError('Captura al menos una cantidad mayor a cero', 400, 'VALIDATION');
        return [$lineas, round($subtotal, 2)];
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idProv = id_or_null($b['id_proveedor'] ?? null);
        $idAlm  = id_or_null($b['id_almacen'] ?? null);
        if (!$idProv) throw new ApiError('id_proveedor es obligatorio', 400, 'VALIDATION');
        if (!$idAlm)  throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');
        if (!Db::one('SELECT id FROM proveedores WHERE id=? AND id_empresa=?', [$idProv, $emp]))
            throw new ApiError('Proveedor no encontrado', 404, 'NOT_FOUND');
        if (!Db::one('SELECT id FROM almacenes WHERE id=? AND id_empresa=?', [$idAlm, $emp]))
            throw new ApiError('Almacen no encontrado', 404, 'NOT_FOUND');

        [$lineas, $subtotal] = self::prepararLineas($emp, $b['lineas'] ?? []);
        $aplicaIva = !empty($b['aplica_iva']);
        $iva   = $aplicaIva ? round($subtotal * self::ivaEmpresa($emp), 2) : 0.0;
        $total = round($subtotal + $iva, 2);

        Db::begin();
        try {
            $folio = self::nextFolio($emp);
            $compraId = Db::insert(
                'INSERT INTO compras (id_empresa,folio,fecha,id_proveedor,id_almacen,id_usuario,
                   aplica_iva,subtotal,iva,total,estado,notas)
                 VALUES (?,?,NOW(),?,?,?,?,?,?,?,\'por_aprobar\',?)',
                [$emp, $folio, $idProv, $idAlm, (int) $ctx['user']['id'],
                 $aplicaIva ? 1 : 0, $subtotal, $iva, $total, $b['notas'] ?? null]);

            foreach ($lineas as $ln) {
                Db::insert(
                    'INSERT INTO compra_lineas (id_compra,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,costo_unitario,importe)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$compraId, $ln['id_articulo'], $ln['codigo'], $ln['descripcion'], $ln['id_color'],
                     $ln['id_talla'], $ln['cantidad'], $ln['costo_unitario'], $ln['importe']]);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::created(self::obtenerCompra($emp, $compraId), 'Compra');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $c = Db::one('SELECT * FROM compras WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        if ($c['estado'] !== 'por_aprobar')
            throw new ApiError('Solo se puede editar una compra por aprobar', 400, 'VALIDATION');

        $idProv = array_key_exists('id_proveedor', $b) ? id_or_null($b['id_proveedor']) : (int) $c['id_proveedor'];
        $idAlm  = array_key_exists('id_almacen', $b)   ? id_or_null($b['id_almacen'])   : (int) $c['id_almacen'];
        if (!$idProv || !$idAlm) throw new ApiError('Proveedor y almacen son obligatorios', 400, 'VALIDATION');

        $aplicaIva = array_key_exists('aplica_iva', $b) ? !empty($b['aplica_iva']) : (bool) $c['aplica_iva'];

        Db::begin();
        try {
            if (array_key_exists('lineas', $b)) {
                [$lineas, $subtotal] = self::prepararLineas($emp, $b['lineas']);
                Db::run('DELETE FROM compra_lineas WHERE id_compra=?', [$id]);
                foreach ($lineas as $ln) {
                    Db::insert(
                        'INSERT INTO compra_lineas (id_compra,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,costo_unitario,importe)
                         VALUES (?,?,?,?,?,?,?,?,?)',
                        [$id, $ln['id_articulo'], $ln['codigo'], $ln['descripcion'], $ln['id_color'],
                         $ln['id_talla'], $ln['cantidad'], $ln['costo_unitario'], $ln['importe']]);
                }
            } else {
                $subtotal = (float) $c['subtotal'];
            }
            $iva   = $aplicaIva ? round($subtotal * self::ivaEmpresa($emp), 2) : 0.0;
            $total = round($subtotal + $iva, 2);
            Db::run('UPDATE compras SET id_proveedor=?, id_almacen=?, aplica_iva=?, subtotal=?, iva=?, total=?, notas=? WHERE id=?',
                [$idProv, $idAlm, $aplicaIva ? 1 : 0, $subtotal, $iva, $total,
                 array_key_exists('notas', $b) ? $b['notas'] : $c['notas'], $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::updated(self::obtenerCompra($emp, $id), 'Compra');
    }

    public static function aprobar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $c = Db::one('SELECT * FROM compras WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        if ($c['estado'] === 'aprobada')  throw new ApiError('La compra ya esta aprobada', 400, 'VALIDATION');
        if ($c['estado'] === 'cancelada') throw new ApiError('No se puede aprobar una compra cancelada', 400, 'VALIDATION');

        $lineas = Db::all('SELECT * FROM compra_lineas WHERE id_compra=?', [$id]);
        if (count($lineas) === 0) throw new ApiError('La compra no tiene lineas', 400, 'VALIDATION');

        Db::begin();
        try {
            foreach ($lineas as $l) {
                // entrada de stock en la celda exacta color/talla
                InventarioController::aplicar($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                    (int) $c['id_almacen'], (float) $l['cantidad'], 'compra', 'Compra ' . $c['folio'],
                    'Compra', $id, (int) $ctx['user']['id'], $c['folio']);
                // actualizar ultimo costo del articulo
                if ((float) $l['costo_unitario'] > 0) {
                    Db::run('UPDATE articulos SET costo=? WHERE id=? AND id_empresa=?',
                        [(float) $l['costo_unitario'], (int) $l['id_articulo'], $emp]);
                }
            }
            // CxP: cargo al proveedor por el total de la compra
            Ledger::proveedorMov($emp, (int) $c['id_proveedor'], 'cargo', 'Compra ' . $c['folio'], (float) $c['total'],
                'MXN', null, 'Compra', $id);
            Db::run('UPDATE compras SET estado=\'aprobada\', aprobada_por=?, fecha_aprobacion=NOW() WHERE id=?',
                [(int) $ctx['user']['id'], $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok(self::obtenerCompra($emp, $id), 'Compra aprobada');
    }

    /** "Eliminar" = cancelar. Si estaba aprobada, revierte el stock ingresado. */
    public static function eliminar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $c = Db::one('SELECT * FROM compras WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        if ($c['estado'] === 'cancelada') throw new ApiError('La compra ya esta cancelada', 400, 'VALIDATION');

        Db::begin();
        try {
            if ($c['estado'] === 'aprobada') {
                foreach (Db::all('SELECT * FROM compra_lineas WHERE id_compra=?', [$id]) as $l) {
                    InventarioController::aplicar($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                        (int) $c['id_almacen'], -1 * (float) $l['cantidad'], 'cancelacion_compra',
                        'Cancelacion compra ' . $c['folio'], 'CancelacionCompra', $id, (int) $ctx['user']['id'], $c['folio']);
                }
                // CxP: restaurar banco de pagos hechos y borrar los movimientos de esta compra
                foreach (Db::all("SELECT * FROM proveedor_movimientos WHERE ref_tipo IN ('Compra','CompraPago') AND id_referencia=?", [$id]) as $pm) {
                    if ($pm['tipo'] === 'pago' && $pm['id_banco']) Ledger::bancoDelta((int) $pm['id_banco'], (float) $pm['monto']);
                }
                Db::run("DELETE FROM proveedor_movimientos WHERE ref_tipo IN ('Compra','CompraPago') AND id_referencia=?", [$id]);
            }
            Db::run('UPDATE compras SET estado=\'cancelada\' WHERE id=?', [$id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok(self::obtenerCompra($emp, $id), 'Compra cancelada');
    }

    // ---- Pagos a proveedor ----
    public static function pagos(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        if (!Db::one('SELECT id FROM compras WHERE id=? AND id_empresa=?', [$id, $emp]))
            throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        $rows = Db::all('SELECT * FROM compra_pagos WHERE id_compra=? ORDER BY fecha DESC, id DESC', [$id]);
        Http::ok(array_map(fn($pg) => [
            '_id'        => (string) $pg['id'],
            'fecha'      => $pg['fecha'],
            'forma_pago' => $pg['forma_pago'],
            'importe'    => (float) $pg['importe'],
            'aplica_iva' => (bool) $pg['aplica_iva'],
            'referencia' => $pg['referencia'],
        ], $rows));
    }

    public static function registrarPago(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $c = Db::one('SELECT * FROM compras WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        if ($c['estado'] === 'cancelada') throw new ApiError('No se puede pagar una compra cancelada', 400, 'VALIDATION');

        $importe = num($b['importe'] ?? 0);
        if ($importe <= 0) throw new ApiError('El importe debe ser mayor a cero', 400, 'VALIDATION');

        Db::begin();
        try {
            Db::insert(
                'INSERT INTO compra_pagos (id_compra,fecha,forma_pago,importe,aplica_iva,referencia,id_usuario)
                 VALUES (?,NOW(),?,?,?,?,?)',
                [$id, $b['forma_pago'] ?? 'efectivo', $importe, !empty($b['aplica_iva']) ? 1 : 0,
                 $b['referencia'] ?? null, (int) $ctx['user']['id']]);
            Db::run('UPDATE compras SET total_pagado=total_pagado+? WHERE id=?', [$importe, $id]);
            // CxP: registrar el pago como movimiento del proveedor (solo si la compra ya genero cargo)
            if ($c['estado'] === 'aprobada') {
                $idBanco = id_or_null($b['id_banco'] ?? null);
                Ledger::proveedorMov($emp, (int) $c['id_proveedor'], 'pago', 'Pago compra ' . $c['folio'], $importe,
                    'MXN', $idBanco, 'CompraPago', $id);
                Ledger::bancoDelta($idBanco, -$importe);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::created(['id_compra' => (string) $id, 'importe' => $importe], 'Pago');
    }
}
