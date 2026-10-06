<?php
// Ventas (POS) de la tienda: cotizacion, registro, consulta y cancelacion.
class VentaController
{
    const FORMAS_PAGO = ['efectivo', 'tdc', 'tdb', 'transferencia', 'cheque', 'monedero'];

    private static function ivaEmpresa(): float
    {
        return (float) (Tenant::empresa()['iva'] ?? 0.16);
    }

    /**
     * Afecta el inventario de una linea. Si el articulo es KIT se mueven sus componentes
     * (cantidad x componente) en vez del kit. $signo = -1 vende, +1 repone.
     */
    public static function afectarInventario(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm, float $cantidad,
        int $signo, string $tipo, string $motivo, string $refTipo, int $idRef, ?int $idUser, string $folio): void
    {
        $art = Db::one('SELECT es_kit FROM articulos WHERE id = ? AND id_empresa = ?', [$idArt, $emp]);
        if ($art && (int) $art['es_kit']) {
            foreach (Db::all('SELECT id_componente, cantidad FROM articulo_componentes WHERE id_empresa = ? AND id_kit = ?', [$emp, $idArt]) as $comp) {
                InventarioController::aplicar($emp, (int) $comp['id_componente'], null, null, $idAlm,
                    $signo * $cantidad * (float) $comp['cantidad'], $tipo, $motivo . ' (kit)', $refTipo, $idRef, $idUser, $folio);
            }
        } else {
            InventarioController::aplicar($emp, $idArt, $v1, $v2, $idAlm, $signo * $cantidad, $tipo, $motivo, $refTipo, $idRef, $idUser, $folio);
        }
    }

    /** Siguiente folio de venta de la tienda (serie del almacen). Requiere transaccion abierta. */
    public static function siguienteFolio(int $emp, int $idAlm, string $tipo = 'venta', string $serieDefault = 'V'): string
    {
        $alm = Db::one('SELECT serie_folio FROM almacenes WHERE id = ? AND id_empresa = ?', [$idAlm, $emp]);
        $serie = ($alm && trim((string) $alm['serie_folio']) !== '') ? strtoupper(trim($alm['serie_folio'])) : $serieDefault;
        return Db::folio($emp, $tipo, $serie, 5);
    }

    public static function cotizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $lineas = $b['lineas'] ?? [];
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('Sin lineas para cotizar', 400, 'VALIDATION');
        Http::ok(Pricing::cotizar(Tenant::id(), Tenant::owns('clientes', $b['id_cliente'] ?? null, 'Cliente'), $lineas, self::ivaEmpresa()));
    }

    public static function crear(array $p, array $ctx): void
    {
        $ventaId = self::registrar(Http::body(), $ctx);
        Http::created(self::obtenerVenta(Tenant::id(), $ventaId), 'Venta');
    }

    /**
     * Crea una venta (POS y liquidacion de apartados). Devuelve el id.
     * Si $enTransaccion = true, el llamador maneja la transaccion (apartados).
     * $formasInternas: formas de pago que solo puede usar el sistema (p. ej. 'anticipo' al liquidar
     * un apartado); nunca se aceptan desde el POS.
     */
    public static function registrar(array $b, array $ctx, bool $enTransaccion = false, array $formasInternas = []): int
    {
        $emp = Tenant::id();
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen', true);
        $lineas = $b['lineas'] ?? [];
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('La venta no tiene lineas', 400, 'VALIDATION');
        $idCliente = Tenant::owns('clientes', $b['id_cliente'] ?? null, 'Cliente');
        $idVendedor = Tenant::owns('empleados', $b['id_vendedor'] ?? null, 'Vendedor');
        $q = Pricing::cotizar($emp, $idCliente, $lineas, self::ivaEmpresa());
        $total = $q['total'];

        // Pagos
        $pagos = [];
        $totalPagado = 0.0; $saldoFavorUsado = 0.0; $totalEfectivo = 0.0;
        foreach (is_array($b['pagos'] ?? null) ? $b['pagos'] : [] as $pg) {
            $forma = (string) ($pg['forma'] ?? 'efectivo');
            if (!in_array($forma, array_merge(self::FORMAS_PAGO, $formasInternas), true)) throw new ApiError("Forma de pago no valida: $forma", 400, 'VALIDATION');
            $imp = num($pg['importe'] ?? 0);
            if ($imp < 0) throw new ApiError('Un pago no puede ser negativo', 400, 'VALIDATION');
            if ($imp == 0) continue;
            // tarjeta -> terminal (y su cuenta); transferencia/cheque -> cuenta; efectivo -> caja del almacen
            [$idBanco, $idTerminal] = Cobros::destino($forma, $pg['id_banco'] ?? null, $pg['id_terminal'] ?? null);
            $pagos[] = ['forma' => $forma, 'importe' => $imp, 'id_banco' => $idBanco, 'id_terminal' => $idTerminal,
                        'referencia' => $pg['referencia'] ?? null];
            $totalPagado += $imp;
            if ($forma === 'monedero') $saldoFavorUsado += $imp;
            if ($forma === 'efectivo') $totalEfectivo += $imp;
        }
        $totalPagado = round($totalPagado, 2);
        $aCredito = !empty($b['a_credito']);
        if (($aCredito || $saldoFavorUsado > 0) && !$idCliente) {
            throw new ApiError($aCredito ? 'Una venta a credito requiere cliente' : 'Pagar con monedero requiere cliente', 400, 'VALIDATION');
        }

        $montoCredito = 0.0; $saldoFavorGenerado = 0.0; $cambioEfectivo = 0.0;
        if ($aCredito) {
            $montoCredito = round(max(0, $total - $totalPagado), 2);
        } else {
            if ($totalPagado + 0.01 < $total) throw new ApiError('El pago es insuficiente para una venta de contado', 400, 'VALIDATION');
            $excedente = round(max(0, $totalPagado - $total), 2);
            if ($excedente > 0 && ($b['destino_cambio'] ?? 'monedero') === 'efectivo' && $totalEfectivo + 0.001 >= $excedente) {
                $cambioEfectivo = $excedente;            // se entrega en efectivo
            } elseif ($excedente > 0) {
                if (!$idCliente) {
                    if ($totalEfectivo + 0.001 < $excedente) throw new ApiError('El excedente solo puede ir al monedero si hay cliente', 400, 'VALIDATION');
                    $cambioEfectivo = $excedente;        // sin cliente, el cambio siempre es en efectivo
                } else {
                    $saldoFavorGenerado = $excedente;    // excedente al monedero del cliente
                }
            }
        }

        if (!$enTransaccion) Db::begin();
        try {
            if ($saldoFavorUsado > 0) {
                $c = Db::one('SELECT saldo_favor FROM clientes WHERE id = ? AND id_empresa = ? FOR UPDATE', [$idCliente, $emp]);
                if ((float) $c['saldo_favor'] + 0.001 < $saldoFavorUsado) throw new ApiError('El saldo del monedero es insuficiente', 400, 'VALIDATION');
            }
            $folio = self::siguienteFolio($emp, $idAlm);
            $idUser = $ctx['user']['id'];
            $ventaId = Db::insert(
                'INSERT INTO ventas (id_empresa, folio, fecha, id_almacen, id_cliente, id_vendedor, id_usuario,
                   total_prendas_lista, nivel_cantidad, lista_cliente, subtotal, iva, total, total_pagado, a_credito,
                   monto_credito, saldo_favor_usado, saldo_favor_generado, cambio_efectivo, estado, notas)
                 VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'completada\',?)',
                [$emp, $folio, $idAlm, $idCliente, $idVendedor, $idUser,
                 $q['total_prendas_lista'], $q['nivel_cantidad'], $q['lista_cliente'], $q['subtotal'], $q['iva'], $total, $totalPagado,
                 $aCredito ? 1 : 0, $montoCredito, $saldoFavorUsado, $saldoFavorGenerado, $cambioEfectivo, $b['notas'] ?? null]);

            foreach ($q['lineas'] as $ln) {
                $v1 = Variantes::norm($ln['id_color']); $v2 = Variantes::norm($ln['id_talla']);
                Db::insert(
                    'INSERT INTO venta_lineas (id_empresa, id_venta, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad,
                       precio_unitario, costo_unitario, lista_aplicada, comisiona, importe)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$emp, $ventaId, (int) $ln['id_articulo'], $v1, $v2, $ln['codigo'], $ln['descripcion'], $ln['cantidad'],
                     $ln['precio_unitario'], $ln['costo_unitario'], $ln['lista_aplicada'], $ln['comisiona'] ? 1 : 0, $ln['importe']]);
                self::afectarInventario($emp, (int) $ln['id_articulo'], $v1, $v2, $idAlm, (float) $ln['cantidad'], -1,
                    'venta', 'Venta ' . $folio, 'Venta', $ventaId, $idUser, $folio);
            }
            foreach ($pagos as $pg) {
                Db::insert('INSERT INTO venta_pagos (id_empresa, id_venta, forma, importe, id_banco, id_terminal, referencia) VALUES (?,?,?,?,?,?,?)',
                    [$emp, $ventaId, $pg['forma'], $pg['importe'], $pg['id_banco'], $pg['id_terminal'], $pg['referencia']]);
                // dinero que no es efectivo: entra a la cuenta de la terminal / transferencia
                if ($pg['forma'] !== 'efectivo') {
                    Cobros::entrada($emp, $pg['forma'], $pg['importe'], $pg['id_banco'], $pg['id_terminal'], $idAlm, 'Venta ' . $folio, 'Venta', $ventaId, $idUser);
                }
            }
            // efectivo: entra a la caja lo recibido menos el cambio entregado
            Cobros::entrada($emp, 'efectivo', round($totalEfectivo - $cambioEfectivo, 2), null, null, $idAlm, 'Venta ' . $folio, 'Venta', $ventaId, $idUser);

            if ($idCliente) {
                if ($montoCredito > 0)       Ledger::clienteMov($emp, $idCliente, 'venta', 'Venta ' . $folio, $montoCredito, 'cargo', 'MXN', null, 'Venta', $ventaId);
                if ($saldoFavorUsado > 0)    Ledger::monederoMov($emp, $idCliente, -1 * $saldoFavorUsado, 'gasto', 'Venta ' . $folio, 'Venta', $ventaId);
                if ($saldoFavorGenerado > 0) Ledger::monederoMov($emp, $idCliente, $saldoFavorGenerado, 'deposito', 'Venta ' . $folio, 'Venta', $ventaId);
            }

            // Comision del vendedor sobre lineas que comisionan
            if ($idVendedor) {
                $pct = (float) (Db::one('SELECT pct_comision FROM empleados WHERE id = ? AND id_empresa = ?', [$idVendedor, $emp])['pct_comision'] ?? 0);
                $base = 0.0;
                foreach ($q['lineas'] as $ln) if (!empty($ln['comisiona'])) $base += (float) $ln['importe'];
                if ($pct > 0 && $base > 0) {
                    Db::insert("INSERT INTO comisiones (id_empresa, id_empleado, id_venta, base, porcentaje, importe, pagada) VALUES (?,?,?,?,?,?,'No')",
                        [$emp, $idVendedor, $ventaId, round($base, 2), $pct, round($base * $pct / 100, 2)]);
                }
            }
            if (!$enTransaccion) Db::commit();
        } catch (Throwable $e) {
            if (!$enTransaccion) Db::rollback();
            throw $e;
        }
        Ledger::audit($ctx, 'crear', 'Venta', $ventaId, 'Venta ' . $folio);
        return $ventaId;
    }

    public static function obtenerVenta(int $emp, int $id): array
    {
        $v = Db::one('SELECT * FROM ventas WHERE id = ? AND id_empresa = ?', [$id, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');
        $cli  = $v['id_cliente'] ? Db::one('SELECT nombre FROM clientes WHERE id = ? AND id_empresa = ?', [$v['id_cliente'], $emp]) : null;
        $vend = $v['id_vendedor'] ? Db::one('SELECT nombre, apellido FROM empleados WHERE id = ? AND id_empresa = ?', [$v['id_vendedor'], $emp]) : null;
        $alm  = Db::one('SELECT nombre, serie_folio, direccion, telefono FROM almacenes WHERE id = ? AND id_empresa = ?', [$v['id_almacen'], $emp]);

        $filas = Db::all('SELECT * FROM venta_lineas WHERE id_empresa = ? AND id_venta = ? ORDER BY id', [$emp, $id]);
        $nombres = Variantes::nombres(array_merge(array_column($filas, 'id_valor1'), array_column($filas, 'id_valor2')));
        $lineas = array_map(fn($l) => [
            '_id' => (int) $l['id'],
            'id_articulo' => (int) $l['id_articulo'],
            'codigo' => $l['codigo'], 'descripcion' => $l['descripcion'],
            'id_color' => (int) $l['id_valor1'], 'id_talla' => (int) $l['id_valor2'],
            'color' => $nombres[(int) $l['id_valor1']] ?? null, 'talla' => $nombres[(int) $l['id_valor2']] ?? null,
            'cantidad' => (float) $l['cantidad'], 'precio_unitario' => (float) $l['precio_unitario'],
            'costo_unitario' => (float) $l['costo_unitario'], 'lista_aplicada' => $l['lista_aplicada'],
            'comisiona' => (bool) $l['comisiona'], 'importe' => (float) $l['importe'],
        ], $filas);

        $pagos = array_map(fn($pg) => [
            'forma' => $pg['forma'], 'importe' => (float) $pg['importe'],
            'id_banco' => $pg['id_banco'] !== null ? (int) $pg['id_banco'] : null,
            'id_terminal' => $pg['id_terminal'] !== null ? (int) $pg['id_terminal'] : null, 'referencia' => $pg['referencia'],
        ], Db::all('SELECT * FROM venta_pagos WHERE id_empresa = ? AND id_venta = ? ORDER BY id', [$emp, $id]));

        return [
            '_id' => (int) $v['id'], 'folio' => $v['folio'], 'fecha' => $v['fecha'],
            'id_almacen' => ['_id' => (int) $v['id_almacen'], 'nombre' => $alm['nombre'] ?? '', 'serie_folio' => $alm['serie_folio'] ?? null,
                             'direccion' => $alm['direccion'] ?? null, 'telefono' => $alm['telefono'] ?? null],
            'id_cliente' => $v['id_cliente'] ? ['_id' => (int) $v['id_cliente'], 'nombre' => $cli['nombre'] ?? ''] : null,
            'id_vendedor' => $v['id_vendedor'] ? ['_id' => (int) $v['id_vendedor'], 'nombre' => $vend['nombre'] ?? '', 'apellido' => $vend['apellido'] ?? ''] : null,
            'total_prendas_lista' => (int) $v['total_prendas_lista'], 'nivel_cantidad' => (int) $v['nivel_cantidad'],
            'lista_cliente' => (int) $v['lista_cliente'],
            'subtotal' => (float) $v['subtotal'], 'iva' => (float) $v['iva'], 'total' => (float) $v['total'],
            'total_pagado' => (float) $v['total_pagado'], 'a_credito' => (bool) $v['a_credito'],
            'monto_credito' => (float) $v['monto_credito'], 'saldo_favor_usado' => (float) $v['saldo_favor_usado'],
            'saldo_favor_generado' => (float) $v['saldo_favor_generado'], 'cambio_efectivo' => (float) $v['cambio_efectivo'],
            'estado' => $v['estado'], 'notas' => $v['notas'], 'lineas' => $lineas, 'pagos' => $pagos, 'createdAt' => $v['created_at'],
        ];
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerVenta(Tenant::id(), (int) $p['id']));
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'v.id_empresa = ?'; $args = [$emp];
        if (!empty($_GET['id_almacen'])) { $where .= ' AND v.id_almacen = ?'; $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_cliente'])) { $where .= ' AND v.id_cliente = ?'; $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['estado']))     { $where .= ' AND v.estado = ?';     $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))      { $where .= ' AND v.fecha >= ?';     $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $where .= ' AND v.fecha <= ?';     $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT v.*, c.nombre cli_nombre, al.nombre alm_nombre, e.nombre vend_nombre, e.apellido vend_apellido
             FROM ventas v
             LEFT JOIN clientes c ON c.id_empresa = v.id_empresa AND c.id = v.id_cliente
             JOIN almacenes al ON al.id_empresa = v.id_empresa AND al.id = v.id_almacen
             LEFT JOIN empleados e ON e.id_empresa = v.id_empresa AND e.id = v.id_vendedor
             WHERE $where ORDER BY v.fecha DESC, v.id DESC LIMIT $limit", $args);

        Http::ok(array_map(fn($v) => [
            '_id' => (int) $v['id'], 'folio' => $v['folio'], 'fecha' => $v['fecha'],
            'id_almacen' => ['_id' => (int) $v['id_almacen'], 'nombre' => $v['alm_nombre']],
            'id_cliente' => $v['id_cliente'] ? ['_id' => (int) $v['id_cliente'], 'nombre' => $v['cli_nombre']] : null,
            'id_vendedor' => $v['id_vendedor'] ? ['_id' => (int) $v['id_vendedor'], 'nombre' => $v['vend_nombre'], 'apellido' => $v['vend_apellido']] : null,
            'total' => (float) $v['total'], 'total_pagado' => (float) $v['total_pagado'],
            'a_credito' => (bool) $v['a_credito'], 'estado' => $v['estado'],
        ], $rows));
    }

    public static function cancelar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        try {
            $v = Db::one('SELECT * FROM ventas WHERE id = ? AND id_empresa = ? FOR UPDATE', [$id, $emp]);
            if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');
            if ($v['estado'] === 'cancelada') throw new ApiError('La venta ya esta cancelada', 400, 'VALIDATION');
            if (Db::one('SELECT 1 FROM devoluciones WHERE id_empresa = ? AND id_venta = ? UNION SELECT 1 FROM cambios WHERE id_empresa = ? AND id_venta = ?', [$emp, $id, $emp, $id]))
                throw new ApiError('La venta tiene devoluciones o cambios; no se puede cancelar', 400, 'VALIDATION');
            $idUser = $ctx['user']['id'];

            foreach (Db::all('SELECT * FROM venta_lineas WHERE id_empresa = ? AND id_venta = ?', [$emp, $id]) as $l) {
                self::afectarInventario($emp, (int) $l['id_articulo'], Variantes::norm($l['id_valor1']), Variantes::norm($l['id_valor2']),
                    (int) $v['id_almacen'], (float) $l['cantidad'], 1, 'devolucion', 'Cancelacion ' . $v['folio'],
                    'CancelacionVenta', $id, $idUser, $v['folio']);
            }
            if ($v['id_cliente']) {
                $cid = (int) $v['id_cliente'];
                if ((float) $v['monto_credito'] > 0)        Ledger::clienteMov($emp, $cid, 'cancelacion', 'Cancelacion ' . $v['folio'], (float) $v['monto_credito'], 'abono', 'MXN', null, 'CancelacionVenta', $id);
                if ((float) $v['saldo_favor_usado'] > 0)    Ledger::monederoMov($emp, $cid, (float) $v['saldo_favor_usado'], 'deposito', 'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id);
                if ((float) $v['saldo_favor_generado'] > 0) Ledger::monederoMov($emp, $cid, -1 * (float) $v['saldo_favor_generado'], 'gasto', 'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id);
            }
            // el dinero cobrado se devuelve: sale de la caja (efectivo neto) y de las cuentas (tarjeta / transferencia)
            $efectivo = 0.0;
            foreach (Db::all('SELECT * FROM venta_pagos WHERE id_empresa = ? AND id_venta = ?', [$emp, $id]) as $pg) {
                if ($pg['forma'] === 'efectivo') { $efectivo += (float) $pg['importe']; continue; }
                Cobros::salida($emp, $pg['forma'], (float) $pg['importe'], $pg['id_banco'] !== null ? (int) $pg['id_banco'] : null,
                    $pg['id_terminal'] !== null ? (int) $pg['id_terminal'] : null, (int) $v['id_almacen'], 'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id, $idUser);
            }
            Cobros::salida($emp, 'efectivo', round($efectivo - (float) $v['cambio_efectivo'], 2), null, null, (int) $v['id_almacen'],
                'Cancelacion ' . $v['folio'], 'CancelacionVenta', $id, $idUser);
            Db::run('UPDATE comisiones SET anulada = 1 WHERE id_empresa = ? AND id_venta = ?', [$emp, $id]);
            Db::run("UPDATE ventas SET estado = 'cancelada', cancelada_por = ?, fecha_cancelacion = NOW() WHERE id = ? AND id_empresa = ?", [$idUser, $id, $emp]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Ledger::audit($ctx, 'cancelar', 'Venta', $id, 'Cancelacion ' . $v['folio']);
        Http::ok(self::obtenerVenta($emp, $id), 'Venta cancelada');
    }
}
