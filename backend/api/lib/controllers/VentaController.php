<?php
class VentaController
{
    private static function ivaEmpresa(int $emp): float
    {
        $e = Db::one('SELECT iva FROM empresas WHERE id=?', [$emp]);
        return $e ? (float) $e['iva'] : 0.16;
    }

    /**
     * Afecta el inventario de una línea de venta. Si el artículo es un KIT (F5), en vez de
     * mover el stock del kit se mueven sus componentes (cantidad × componente). `signo` = -1 vende, +1 repone.
     */
    private static function afectarInventario(int $emp, int $idArt, int $e1, int $e2, int $idAlm, float $cantidad,
        int $signo, string $tipo, string $motivo, string $refTipo, int $idRef, int $idUser, string $folio): void
    {
        $art = Db::one('SELECT es_kit FROM articulos WHERE id=?', [$idArt]);
        if ($art && (int) $art['es_kit']) {
            foreach (Db::all('SELECT id_componente, cantidad FROM articulo_componentes WHERE id_kit=?', [$idArt]) as $comp) {
                InventarioController::aplicar($emp, (int) $comp['id_componente'], 0, 0, $idAlm,
                    $signo * $cantidad * (float) $comp['cantidad'], $tipo, $motivo . ' (kit)', $refTipo, $idRef, $idUser, $folio);
            }
        } else {
            InventarioController::aplicar($emp, $idArt, $e1, $e2, $idAlm, $signo * $cantidad, $tipo, $motivo, $refTipo, $idRef, $idUser, $folio);
        }
    }

    private static function nextFolio(int $emp, int $idAlm): string
    {
        $alm = Db::one('SELECT serie_folio FROM almacenes WHERE id=?', [$idAlm]);
        $serie = ($alm && $alm['serie_folio']) ? $alm['serie_folio'] : 'V';
        $n = (int) (Db::one('SELECT COUNT(*) c FROM ventas WHERE id_almacen=?', [$idAlm])['c'] ?? 0) + 1;
        return $serie . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public static function cotizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $lineas = $b['lineas'] ?? [];
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('Sin lineas para cotizar', 400, 'VALIDATION');
        $r = Pricing::cotizar($emp, id_or_null($b['id_cliente'] ?? null), $lineas, self::ivaEmpresa($emp));
        Http::ok($r);
    }

    public static function crear(array $p, array $ctx): void
    {
        $ventaId = self::registrar(Http::body(), $ctx);
        Http::created(self::obtenerVenta((int) $ctx['user']['id_empresa'], $ventaId), 'Venta');
    }

    /** Crea una venta a partir de un arreglo de datos (reutilizable: POS y liquidacion de apartados). Devuelve el id. */
    public static function registrar(array $b, array $ctx): int
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $idAlm = id_or_null($b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null);
        if (!$idAlm) throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');
        $lineas = $b['lineas'] ?? [];
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('La venta no tiene lineas', 400, 'VALIDATION');

        $idCliente = id_or_null($b['id_cliente'] ?? null);
        $iva = self::ivaEmpresa($emp);
        $q = Pricing::cotizar($emp, $idCliente, $lineas, $iva);

        // Pagos
        $pagos = is_array($b['pagos'] ?? null) ? $b['pagos'] : [];
        $totalPagado = 0.0; $saldoFavorUsado = 0.0;
        foreach ($pagos as $pg) {
            $imp = num($pg['importe'] ?? 0);
            $totalPagado += $imp;
            if (($pg['forma'] ?? '') === 'monedero') $saldoFavorUsado += $imp;
        }
        $totalPagado = round($totalPagado, 2);
        $aCredito = !empty($b['a_credito']);
        $total = $q['total'];

        $montoCredito = 0.0; $saldoFavorGenerado = 0.0; $cambioEfectivo = 0.0;
        if ($aCredito) {
            $montoCredito = round(max(0, $total - $totalPagado), 2);
        } else {
            if ($totalPagado + 0.01 < $total) throw new ApiError('El pago es insuficiente para una venta de contado', 400, 'VALIDATION');
            $excedente = round(max(0, $totalPagado - $total), 2);
            // efectivo recibido: solo se puede devolver cambio en efectivo si lo cubre
            $totalEfectivo = 0.0;
            foreach ($pagos as $pg) if (($pg['forma'] ?? '') === 'efectivo') $totalEfectivo += num($pg['importe'] ?? 0);
            $destinoCambio = $b['destino_cambio'] ?? 'monedero';
            if ($excedente > 0 && $destinoCambio === 'efectivo' && $totalEfectivo + 0.001 >= $excedente) {
                $cambioEfectivo = $excedente;       // se entrega en efectivo (no va al monedero)
            } else {
                $saldoFavorGenerado = $excedente;   // por defecto: excedente al monedero
            }
        }

        Db::begin();
        try {
            $folio = self::nextFolio($emp, $idAlm);
            $ventaId = Db::insert(
                'INSERT INTO ventas (id_empresa,folio,fecha,id_almacen,id_cliente,id_vendedor,id_usuario,
                  total_prendas_lista,nivel_cantidad,lista_cliente,subtotal,iva,total,total_pagado,a_credito,
                  monto_credito,saldo_favor_usado,saldo_favor_generado,cambio_efectivo,estado,notas)
                 VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'completada\',?)',
                [
                    $emp, $folio, $idAlm, $idCliente, id_or_null($b['id_vendedor'] ?? null), (int) $ctx['user']['id'],
                    $q['total_prendas_lista'], $q['nivel_cantidad'], $q['lista_cliente'],
                    $q['subtotal'], $q['iva'], $total, $totalPagado, $aCredito ? 1 : 0,
                    $montoCredito, $saldoFavorUsado, $saldoFavorGenerado, $cambioEfectivo, $b['notas'] ?? null,
                ]
            );

            foreach ($q['lineas'] as $ln) {
                Db::insert(
                    'INSERT INTO venta_lineas (id_venta,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,
                      precio_unitario,costo_unitario,lista_aplicada,comisiona,importe)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$ventaId, (int) $ln['id_articulo'], $ln['codigo'], $ln['descripcion'],
                     id_or_zero($ln['id_color']), id_or_zero($ln['id_talla']), $ln['cantidad'],
                     $ln['precio_unitario'], $ln['costo_unitario'], $ln['lista_aplicada'], $ln['comisiona'] ? 1 : 0, $ln['importe']]
                );
                // descontar inventario (kits descuentan sus componentes)
                self::afectarInventario($emp, (int) $ln['id_articulo'], id_or_zero($ln['id_color']),
                    id_or_zero($ln['id_talla']), $idAlm, (float) $ln['cantidad'], -1, 'venta', 'Venta ' . $folio,
                    'Venta', $ventaId, (int) $ctx['user']['id'], $folio);
            }

            foreach ($pagos as $pg) {
                Db::insert('INSERT INTO venta_pagos (id_venta,forma,importe,id_banco,referencia) VALUES (?,?,?,?,?)',
                    [$ventaId, $pg['forma'] ?? 'efectivo', num($pg['importe'] ?? 0), id_or_null($pg['id_banco'] ?? null), $pg['referencia'] ?? null]);
            }

            // actualizar saldos del cliente (via Ledger: historico + saldo)
            if ($idCliente) {
                if ($montoCredito > 0)       Ledger::clienteMov($emp, $idCliente, 'venta', 'Venta ' . $folio, $montoCredito, 'cargo', 'MXN', null, 'Venta', $ventaId);
                if ($saldoFavorUsado > 0)    Ledger::monederoMov($emp, $idCliente, -1 * $saldoFavorUsado, 'gasto', 'Venta ' . $folio, 'Venta', $ventaId);
                if ($saldoFavorGenerado > 0) Ledger::monederoMov($emp, $idCliente, $saldoFavorGenerado, 'deposito', 'Venta ' . $folio, 'Venta', $ventaId);
            }

            // generar comision del vendedor sobre lineas que comisionan
            $idVendedor = id_or_null($b['id_vendedor'] ?? null);
            if ($idVendedor) {
                $empl = Db::one('SELECT pct_comision FROM empleados WHERE id=? AND id_empresa=?', [$idVendedor, $emp]);
                $pct = $empl ? (float) $empl['pct_comision'] : 0.0;
                if ($pct > 0) {
                    $base = 0.0;
                    foreach ($q['lineas'] as $ln) if (!empty($ln['comisiona'])) $base += (float) $ln['importe'];
                    if ($base > 0) {
                        Db::insert(
                            'INSERT INTO comisiones (id_empresa,id_empleado,id_venta,folio_venta,base,porcentaje,importe,pagada)
                             VALUES (?,?,?,?,?,?,?,\'No\')',
                            [$emp, $idVendedor, $ventaId, $folio, round($base, 2), $pct, round($base * $pct / 100, 2)]);
                    }
                }
            }

            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'crear', 'Venta', $ventaId, 'Venta ' . $folio);
        return $ventaId;
    }

    public static function obtenerVenta(int $emp, int $id): array
    {
        $v = Db::one('SELECT * FROM ventas WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');
        $cli = $v['id_cliente'] ? Db::one('SELECT nombre FROM clientes WHERE id=?', [$v['id_cliente']]) : null;
        $vend = $v['id_vendedor'] ? Db::one('SELECT nombre,apellido FROM empleados WHERE id=?', [$v['id_vendedor']]) : null;
        $alm = Db::one('SELECT nombre,serie_folio,direccion,telefono FROM almacenes WHERE id=?', [$v['id_almacen']]);

        $lineas = array_map(fn($l) => [
            '_id' => (string) $l['id'],
            'id_articulo' => (string) $l['id_articulo'],
            'codigo' => $l['codigo'], 'descripcion' => $l['descripcion'],
            'id_color' => (string) $l['id_color'], 'id_talla' => (string) $l['id_talla'],
            'cantidad' => (float) $l['cantidad'], 'precio_unitario' => (float) $l['precio_unitario'],
            'costo_unitario' => (float) $l['costo_unitario'], 'lista_aplicada' => $l['lista_aplicada'],
            'comisiona' => (bool) $l['comisiona'], 'importe' => (float) $l['importe'],
        ], Db::all('SELECT * FROM venta_lineas WHERE id_venta=?', [$id]));

        $pagos = array_map(fn($pg) => [
            'forma' => $pg['forma'], 'importe' => (float) $pg['importe'],
            'id_banco' => $pg['id_banco'] !== null ? (string) $pg['id_banco'] : null, 'referencia' => $pg['referencia'],
        ], Db::all('SELECT * FROM venta_pagos WHERE id_venta=?', [$id]));

        return [
            '_id' => (string) $v['id'], 'folio' => $v['folio'], 'fecha' => $v['fecha'],
            'id_almacen' => ['_id' => (string) $v['id_almacen'], 'nombre' => $alm['nombre'] ?? '', 'serie_folio' => $alm['serie_folio'] ?? null, 'direccion' => $alm['direccion'] ?? null, 'telefono' => $alm['telefono'] ?? null],
            'id_cliente' => $v['id_cliente'] ? ['_id' => (string) $v['id_cliente'], 'nombre' => $cli['nombre'] ?? ''] : null,
            'id_vendedor' => $v['id_vendedor'] ? ['_id' => (string) $v['id_vendedor'], 'nombre' => $vend['nombre'] ?? '', 'apellido' => $vend['apellido'] ?? ''] : null,
            'total_prendas_lista' => (int) $v['total_prendas_lista'], 'nivel_cantidad' => (int) $v['nivel_cantidad'],
            'lista_cliente' => (int) $v['lista_cliente'],
            'subtotal' => (float) $v['subtotal'], 'iva' => (float) $v['iva'], 'total' => (float) $v['total'],
            'total_pagado' => (float) $v['total_pagado'], 'a_credito' => (bool) $v['a_credito'],
            'monto_credito' => (float) $v['monto_credito'], 'saldo_favor_usado' => (float) $v['saldo_favor_usado'],
            'saldo_favor_generado' => (float) $v['saldo_favor_generado'], 'cambio_efectivo' => (float) ($v['cambio_efectivo'] ?? 0),
            'estado' => $v['estado'],
            'notas' => $v['notas'], 'lineas' => $lineas, 'pagos' => $pagos, 'createdAt' => $v['created_at'],
        ];
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerVenta((int) $ctx['user']['id_empresa'], (int) $p['id']));
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'v.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_almacen'])) { $where .= ' AND v.id_almacen=?'; $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_cliente'])) { $where .= ' AND v.id_cliente=?'; $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['estado']))     { $where .= ' AND v.estado=?';     $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))      { $where .= ' AND v.fecha>=?';     $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $where .= ' AND v.fecha<=?';     $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT v.*, c.nombre cli_nombre, al.nombre alm_nombre, e.nombre vend_nombre, e.apellido vend_apellido
             FROM ventas v
             LEFT JOIN clientes c ON c.id=v.id_cliente
             JOIN almacenes al ON al.id=v.id_almacen
             LEFT JOIN empleados e ON e.id=v.id_vendedor
             WHERE $where ORDER BY v.fecha DESC, v.id DESC LIMIT $limit", $args);

        $out = array_map(fn($v) => [
            '_id' => (string) $v['id'], 'folio' => $v['folio'], 'fecha' => $v['fecha'],
            'id_almacen' => ['_id' => (string) $v['id_almacen'], 'nombre' => $v['alm_nombre']],
            'id_cliente' => $v['id_cliente'] ? ['_id' => (string) $v['id_cliente'], 'nombre' => $v['cli_nombre']] : null,
            'id_vendedor' => $v['id_vendedor'] ? ['_id' => (string) $v['id_vendedor'], 'nombre' => $v['vend_nombre'], 'apellido' => $v['vend_apellido']] : null,
            'total' => (float) $v['total'], 'total_pagado' => (float) $v['total_pagado'],
            'a_credito' => (bool) $v['a_credito'], 'estado' => $v['estado'],
        ], $rows);
        Http::ok($out);
    }

    public static function cancelar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $v = Db::one('SELECT * FROM ventas WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');
        if ($v['estado'] === 'cancelada') throw new ApiError('La venta ya esta cancelada', 400, 'VALIDATION');

        Db::begin();
        try {
            // reponer inventario
            foreach (Db::all('SELECT * FROM venta_lineas WHERE id_venta=?', [$id]) as $l) {
                self::afectarInventario($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                    (int) $v['id_almacen'], (float) $l['cantidad'], 1, 'devolucion', 'Cancelacion ' . $v['folio'],
                    'CancelacionVenta', $id, (int) $ctx['user']['id'], $v['folio']);
            }
            // revertir saldos del cliente (via Ledger)
            if ($v['id_cliente']) {
                $cid = (int) $v['id_cliente'];
                if ((float) $v['monto_credito'] > 0)        Ledger::clienteMov($emp, $cid, 'cancelacion', 'Cancelacion ' . $v['folio'], (float) $v['monto_credito'], 'abono', 'MXN', null, 'CancelacionVenta', $id);
                if ((float) $v['saldo_favor_usado'] > 0)    Ledger::monederoMov($emp, $cid, (float) $v['saldo_favor_usado'], 'deposito', 'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id);
                if ((float) $v['saldo_favor_generado'] > 0) Ledger::monederoMov($emp, $cid, -1 * (float) $v['saldo_favor_generado'], 'gasto', 'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id);
            }
            // anular comisiones generadas por esta venta
            Db::run('UPDATE comisiones SET anulada=1 WHERE id_venta=?', [$id]);
            Db::run('UPDATE ventas SET estado=\'cancelada\', cancelada_por=?, fecha_cancelacion=NOW() WHERE id=?',
                [(int) $ctx['user']['id'], $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok(self::obtenerVenta($emp, $id), 'Venta cancelada');
    }
}
