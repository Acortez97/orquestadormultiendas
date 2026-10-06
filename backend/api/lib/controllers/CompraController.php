<?php
// Compras a proveedores de la tienda: captura, aprobacion (entra stock + CxP), cancelacion y pagos.
class CompraController
{
    /** Cabecera resumida para listados */
    private static function fmtRow(array $c): array
    {
        return [
            '_id'          => (int) $c['id'],
            'folio'        => $c['folio'],
            'fecha'        => $c['fecha'],
            'id_proveedor' => ['_id' => (int) $c['id_proveedor'], 'nombre' => $c['prov_nombre'] ?? ''],
            'id_almacen'   => ['_id' => (int) $c['id_almacen'], 'nombre' => $c['alm_nombre'] ?? ''],
            'aplica_iva'   => (bool) $c['aplica_iva'],
            'subtotal'     => (float) $c['subtotal'],
            'iva'          => (float) $c['iva'],
            'total'        => (float) $c['total'],
            'total_pagado' => (float) $c['total_pagado'],
            'estado'       => $c['estado'],
        ];
    }

    /** Compra completa (con lineas) */
    public static function obtenerCompra(int $emp, int $id): array
    {
        $c = Db::one(
            'SELECT c.*, p.nombre prov_nombre, al.nombre alm_nombre, u.nombre apr_nombre, u.apellido apr_apellido
             FROM compras c
             JOIN proveedores p ON p.id_empresa = c.id_empresa AND p.id = c.id_proveedor
             JOIN almacenes al  ON al.id_empresa = c.id_empresa AND al.id = c.id_almacen
             LEFT JOIN users u  ON u.id_empresa = c.id_empresa AND u.id = c.aprobada_por
             WHERE c.id = ? AND c.id_empresa = ?', [$id, $emp]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');

        $filas = Db::all('SELECT * FROM compra_lineas WHERE id_empresa = ? AND id_compra = ? ORDER BY id', [$emp, $id]);
        $nom = Variantes::nombres(array_merge(array_column($filas, 'id_valor1'), array_column($filas, 'id_valor2')));
        $out = self::fmtRow($c);
        $out['notas'] = $c['notas'];
        $out['aprobada_por'] = $c['aprobada_por'] ? ['nombre' => $c['apr_nombre'], 'apellido' => $c['apr_apellido']] : null;
        $out['fecha_aprobacion'] = $c['fecha_aprobacion'];
        $out['lineas'] = array_map(fn($l) => [
            '_id'            => (int) $l['id'],
            'id_articulo'    => ['_id' => (int) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion']],
            'codigo'         => $l['codigo'],
            'descripcion'    => $l['descripcion'],
            'id_color'       => Variantes::ref($l['id_valor1'], $nom[(int) $l['id_valor1']] ?? null, null, 0, 'Unico'),
            'id_talla'       => Variantes::ref($l['id_valor2'], $nom[(int) $l['id_valor2']] ?? null, null, 0, 'Unica'),
            'cantidad'       => (float) $l['cantidad'],
            'costo_unitario' => (float) $l['costo_unitario'],
            'importe'        => (float) $l['importe'],
        ], $filas);
        $out['createdAt'] = $c['created_at'];
        return $out;
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'c.id_empresa = ?'; $args = [Tenant::id()];
        if (!empty($_GET['id_proveedor'])) { $where .= ' AND c.id_proveedor = ?'; $args[] = (int) $_GET['id_proveedor']; }
        if (!empty($_GET['id_almacen']))   { $where .= ' AND c.id_almacen = ?';   $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['estado']))       { $where .= ' AND c.estado = ?';       $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))        { $where .= ' AND c.fecha >= ?';       $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))        { $where .= ' AND c.fecha <= ?';       $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));
        $rows = Db::all(
            "SELECT c.*, p.nombre prov_nombre, al.nombre alm_nombre FROM compras c
             JOIN proveedores p ON p.id_empresa = c.id_empresa AND p.id = c.id_proveedor
             JOIN almacenes al  ON al.id_empresa = c.id_empresa AND al.id = c.id_almacen
             WHERE $where ORDER BY c.fecha DESC, c.id DESC LIMIT $limit", $args);
        Http::ok(array_map([self::class, 'fmtRow'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerCompra(Tenant::id(), (int) $p['id']));
    }

    /** Valida las lineas del body; devuelve [lineas, subtotal] */
    private static function prepararLineas(array $raw): array
    {
        if (!is_array($raw) || count($raw) === 0) throw new ApiError('La compra no tiene lineas', 400, 'VALIDATION');
        $lineas = []; $subtotal = 0.0;
        foreach ($raw as $ln) {
            $cant = num($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $idArt = Tenant::owns('articulos', $ln['id_articulo'] ?? null, 'Articulo', true);
            $art = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [$idArt, Tenant::id()]);
            [$v1, $v2] = Variantes::validar($art, $ln['id_color'] ?? null, $ln['id_talla'] ?? null);
            $costo = num($ln['costo_unitario'] ?? 0);
            if ($costo < 0) throw new ApiError('El costo no puede ser negativo', 400, 'VALIDATION');
            $importe = round($cant * $costo, 2);
            $subtotal += $importe;
            $lineas[] = ['id_articulo' => $idArt, 'codigo' => $art['codigo'], 'descripcion' => $art['descripcion'],
                         'v1' => $v1, 'v2' => $v2, 'cantidad' => $cant, 'costo_unitario' => $costo, 'importe' => $importe];
        }
        if (count($lineas) === 0) throw new ApiError('Captura al menos una cantidad mayor a cero', 400, 'VALIDATION');
        return [$lineas, round($subtotal, 2)];
    }

    private static function insertarLineas(int $emp, int $idCompra, array $lineas): void
    {
        foreach ($lineas as $ln) {
            Db::insert('INSERT INTO compra_lineas (id_empresa, id_compra, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad, costo_unitario, importe)
                        VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$emp, $idCompra, $ln['id_articulo'], $ln['v1'], $ln['v2'], $ln['codigo'], $ln['descripcion'], $ln['cantidad'], $ln['costo_unitario'], $ln['importe']]);
        }
    }

    private static function compraBloqueada(int $id): array
    {
        $c = Db::one('SELECT * FROM compras WHERE id = ? AND id_empresa = ? FOR UPDATE', [$id, Tenant::id()]);
        if (!$c) throw new ApiError('Compra no encontrada', 404, 'NOT_FOUND');
        return $c;
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idProv = Tenant::owns('proveedores', $b['id_proveedor'] ?? null, 'Proveedor', true);
        $idAlm  = Tenant::owns('almacenes', $b['id_almacen'] ?? null, 'Almacen', true);
        [$lineas, $subtotal] = self::prepararLineas($b['lineas'] ?? []);
        $aplicaIva = !empty($b['aplica_iva']);
        $iva = $aplicaIva ? round($subtotal * (float) Tenant::empresa()['iva'], 2) : 0.0;

        Db::begin();
        $folio = Db::folio($emp, 'compra', 'C', 5);
        $id = Db::insert(
            "INSERT INTO compras (id_empresa, folio, fecha, id_proveedor, id_almacen, id_usuario, aplica_iva, subtotal, iva, total, estado, notas)
             VALUES (?,?,NOW(),?,?,?,?,?,?,?,'por_aprobar',?)",
            [$emp, $folio, $idProv, $idAlm, $ctx['user']['id'], $aplicaIva ? 1 : 0, $subtotal, $iva, round($subtotal + $iva, 2), $b['notas'] ?? null]);
        self::insertarLineas($emp, $id, $lineas);
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Compra', $id, 'Compra ' . $folio);
        Http::created(self::obtenerCompra($emp, $id), 'Compra');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $c = self::compraBloqueada($id);
        if ($c['estado'] !== 'por_aprobar') throw new ApiError('Solo se puede editar una compra por aprobar', 400, 'VALIDATION');
        $idProv = array_key_exists('id_proveedor', $b) ? Tenant::owns('proveedores', $b['id_proveedor'], 'Proveedor', true) : (int) $c['id_proveedor'];
        $idAlm  = array_key_exists('id_almacen', $b)   ? Tenant::owns('almacenes', $b['id_almacen'], 'Almacen', true)     : (int) $c['id_almacen'];
        $aplicaIva = array_key_exists('aplica_iva', $b) ? !empty($b['aplica_iva']) : (bool) $c['aplica_iva'];
        if (array_key_exists('lineas', $b)) {
            [$lineas, $subtotal] = self::prepararLineas($b['lineas']);
            Db::run('DELETE FROM compra_lineas WHERE id_empresa = ? AND id_compra = ?', [$emp, $id]);
            self::insertarLineas($emp, $id, $lineas);
        } else {
            $subtotal = (float) $c['subtotal'];
        }
        $iva = $aplicaIva ? round($subtotal * (float) Tenant::empresa()['iva'], 2) : 0.0;
        Db::run('UPDATE compras SET id_proveedor = ?, id_almacen = ?, aplica_iva = ?, subtotal = ?, iva = ?, total = ?, notas = ? WHERE id = ? AND id_empresa = ?',
            [$idProv, $idAlm, $aplicaIva ? 1 : 0, $subtotal, $iva, round($subtotal + $iva, 2),
             array_key_exists('notas', $b) ? $b['notas'] : $c['notas'], $id, $emp]);
        Db::commit();
        Http::updated(self::obtenerCompra($emp, $id), 'Compra');
    }

    public static function aprobar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $c = self::compraBloqueada($id);
        if ($c['estado'] === 'aprobada')  throw new ApiError('La compra ya esta aprobada', 400, 'VALIDATION');
        if ($c['estado'] === 'cancelada') throw new ApiError('No se puede aprobar una compra cancelada', 400, 'VALIDATION');
        $lineas = Db::all('SELECT * FROM compra_lineas WHERE id_empresa = ? AND id_compra = ?', [$emp, $id]);
        if (count($lineas) === 0) throw new ApiError('La compra no tiene lineas', 400, 'VALIDATION');
        $idUser = $ctx['user']['id'];
        foreach ($lineas as $l) {
            InventarioController::aplicar($emp, (int) $l['id_articulo'], Variantes::norm($l['id_valor1']), Variantes::norm($l['id_valor2']),
                (int) $c['id_almacen'], (float) $l['cantidad'], 'compra', 'Compra ' . $c['folio'], 'Compra', $id, $idUser, $c['folio']);
            if ((float) $l['costo_unitario'] > 0) {
                Db::run('UPDATE articulos SET costo = ? WHERE id = ? AND id_empresa = ?', [(float) $l['costo_unitario'], (int) $l['id_articulo'], $emp]);
            }
        }
        // CxP: cargo por el total de la compra y, si ya habia pagos anticipados, sus abonos
        Ledger::proveedorMov($emp, (int) $c['id_proveedor'], 'cargo', 'Compra ' . $c['folio'], (float) $c['total'], 'MXN', null, 'Compra', $id);
        foreach (Db::all('SELECT * FROM compra_pagos WHERE id_empresa = ? AND id_compra = ?', [$emp, $id]) as $pg) {
            Ledger::proveedorMov($emp, (int) $c['id_proveedor'], 'pago', 'Pago compra ' . $c['folio'], (float) $pg['importe'],
                'MXN', $pg['id_banco'] !== null ? (int) $pg['id_banco'] : null, 'CompraPago', $id);
        }
        Db::run("UPDATE compras SET estado = 'aprobada', aprobada_por = ?, fecha_aprobacion = NOW() WHERE id = ? AND id_empresa = ?", [$idUser, $id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'aprobar', 'Compra', $id, 'Compra ' . $c['folio']);
        Http::ok(self::obtenerCompra($emp, $id), 'Compra aprobada');
    }

    /**
     * "Eliminar" = cancelar. Si estaba aprobada, revierte el stock y registra movimientos INVERSOS en CxP
     * (no se borra historia contable). Los pagos hechos regresan al banco.
     */
    public static function eliminar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $c = self::compraBloqueada($id);
        if ($c['estado'] === 'cancelada') throw new ApiError('La compra ya esta cancelada', 400, 'VALIDATION');
        $idUser = $ctx['user']['id'];
        if ($c['estado'] === 'aprobada') {
            foreach (Db::all('SELECT * FROM compra_lineas WHERE id_empresa = ? AND id_compra = ?', [$emp, $id]) as $l) {
                InventarioController::aplicar($emp, (int) $l['id_articulo'], Variantes::norm($l['id_valor1']), Variantes::norm($l['id_valor2']),
                    (int) $c['id_almacen'], -1 * (float) $l['cantidad'], 'cancelacion_compra', 'Cancelacion compra ' . $c['folio'],
                    'CancelacionCompra', $id, $idUser, $c['folio']);
            }
            $prov = (int) $c['id_proveedor'];
            foreach (Db::all("SELECT * FROM proveedor_movimientos WHERE id_empresa = ? AND ref_tipo IN ('Compra', 'CompraPago') AND id_referencia = ?", [$emp, $id]) as $pm) {
                $inverso = $pm['tipo'] === 'cargo' ? 'pago' : 'cargo';
                Ledger::proveedorMov($emp, $prov, $inverso, 'Cancelacion compra ' . $c['folio'], (float) $pm['monto'],
                    $pm['moneda'], $pm['id_banco'] !== null ? (int) $pm['id_banco'] : null, 'CancelacionCompra', $id);
                if ($pm['tipo'] === 'pago' && $pm['id_banco']) Ledger::bancoDelta($emp, (int) $pm['id_banco'], (float) $pm['monto']);
            }
        } else {
            // por aprobar: los pagos anticipados regresan al banco
            foreach (Db::all('SELECT * FROM compra_pagos WHERE id_empresa = ? AND id_compra = ? AND id_banco IS NOT NULL', [$emp, $id]) as $pg) {
                Ledger::bancoDelta($emp, (int) $pg['id_banco'], (float) $pg['importe']);
            }
        }
        Db::run("UPDATE compras SET estado = 'cancelada' WHERE id = ? AND id_empresa = ?", [$id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'cancelar', 'Compra', $id, 'Compra ' . $c['folio']);
        Http::ok(self::obtenerCompra($emp, $id), 'Compra cancelada');
    }

    // ---- Pagos a proveedor ----
    public static function pagos(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $id = Tenant::owns('compras', $p['id'], 'Compra', true);
        Http::ok(array_map(fn($pg) => [
            '_id'        => (int) $pg['id'],
            'fecha'      => $pg['fecha'],
            'forma_pago' => $pg['forma_pago'],
            'importe'    => (float) $pg['importe'],
            'aplica_iva' => (bool) $pg['aplica_iva'],
            'id_banco'   => $pg['id_banco'] !== null ? (int) $pg['id_banco'] : null,
            'referencia' => $pg['referencia'],
        ], Db::all('SELECT * FROM compra_pagos WHERE id_empresa = ? AND id_compra = ? ORDER BY fecha DESC, id DESC', [$emp, $id])));
    }

    public static function registrarPago(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        $importe = num($b['importe'] ?? 0);
        if ($importe <= 0) throw new ApiError('El importe debe ser mayor a cero', 400, 'VALIDATION');
        $idBanco = Tenant::owns('bancos', $b['id_banco'] ?? null, 'Banco');

        Db::begin();
        $c = self::compraBloqueada($id);
        if ($c['estado'] === 'cancelada') throw new ApiError('No se puede pagar una compra cancelada', 400, 'VALIDATION');
        if ((float) $c['total_pagado'] + $importe > (float) $c['total'] + 0.01) throw new ApiError('El pago excede el saldo de la compra', 400, 'VALIDATION');
        Db::insert('INSERT INTO compra_pagos (id_empresa, id_compra, fecha, forma_pago, importe, aplica_iva, id_banco, referencia, id_usuario)
                    VALUES (?,?,NOW(),?,?,?,?,?,?)',
            [$emp, $id, $b['forma_pago'] ?? 'efectivo', $importe, !empty($b['aplica_iva']) ? 1 : 0, $idBanco, $b['referencia'] ?? null, $ctx['user']['id']]);
        Db::run('UPDATE compras SET total_pagado = total_pagado + ? WHERE id = ? AND id_empresa = ?', [$importe, $id, $emp]);
        // CxP: si la compra ya genero cargo, el pago se registra ya; si no, se registra al aprobar
        if ($c['estado'] === 'aprobada') {
            Ledger::proveedorMov($emp, (int) $c['id_proveedor'], 'pago', 'Pago compra ' . $c['folio'], $importe, 'MXN', $idBanco, 'CompraPago', $id);
        }
        Ledger::bancoDelta($emp, $idBanco, -$importe);
        Db::commit();
        Ledger::audit($ctx, 'pagar', 'Compra', $id, 'Pago ' . $importe);
        Http::created(['id_compra' => $id, 'importe' => $importe], 'Pago');
    }
}
