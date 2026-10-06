<?php
// Cortes de caja por almacen y dia (uno por almacen/dia).
class CorteController
{
    /**
     * Resumen del dia de un almacen.
     * - Ventas, notas con sus lineas, ventas por lista y por forma de pago.
     * - Anticipos de apartados cobrados ese dia, devoluciones, cambios y abonos de clientes recibidos en la tienda.
     * - Movimientos de la caja del dia: el EFECTIVO ESPERADO sale de este libro (entradas - salidas),
     *   asi incluye ventas (menos cambio), anticipos, diferencias de cambios, abonos, pagos en efectivo y depositos.
     * - Cobros que NO son efectivo, por cuenta / terminal (donde quedo ese dinero).
     */
    public static function computar(int $emp, int $idAlm, string $fecha): array
    {
        $ventas = Db::all(
            "SELECT v.*, c.nombre cli_nombre FROM ventas v
             LEFT JOIN clientes c ON c.id_empresa = v.id_empresa AND c.id = v.id_cliente
             WHERE v.id_empresa = ? AND v.id_almacen = ? AND v.estado = 'completada' AND DATE(v.fecha) = ? ORDER BY v.fecha, v.id",
            [$emp, $idAlm, $fecha]);
        $pagosPorVenta = []; $lineasPorVenta = [];
        if ($ventas) {
            $ids = array_map(fn($v) => (int) $v['id'], $ventas);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT id_venta, forma, importe FROM venta_pagos WHERE id_empresa = ? AND id_venta IN ($ph)", array_merge([$emp], $ids)) as $pg) {
                $pagosPorVenta[(int) $pg['id_venta']][] = $pg;
            }
            foreach (Db::all("SELECT * FROM venta_lineas WHERE id_empresa = ? AND id_venta IN ($ph) ORDER BY id", array_merge([$emp], $ids)) as $l) {
                $lineasPorVenta[(int) $l['id_venta']][] = $l;
            }
        }

        $formas = ['efectivo' => 0.0, 'tdc' => 0.0, 'tdb' => 0.0, 'transferencia' => 0.0, 'cheque' => 0.0, 'monedero' => 0.0];
        $porLista = [];
        $totalVendido = 0.0; $totalCredito = 0.0; $favorUsado = 0.0; $favorGenerado = 0.0; $cambioEfectivo = 0.0;
        $detalle = [];
        foreach ($ventas as $v) {
            $totalVendido   += (float) $v['total'];
            $totalCredito   += (float) $v['monto_credito'];
            $favorUsado     += (float) $v['saldo_favor_usado'];
            $favorGenerado  += (float) $v['saldo_favor_generado'];
            $cambioEfectivo += (float) $v['cambio_efectivo'];
            $formasVenta = [];
            $nota = ['efectivo' => 0.0, 'tarjeta' => 0.0, 'otros' => 0.0, 'monedero' => 0.0];
            foreach ($pagosPorVenta[(int) $v['id']] ?? [] as $pg) {
                $f = $pg['forma']; $imp = (float) $pg['importe'];
                $formasVenta[] = $f;
                if ($f === 'anticipo') continue;   // ya se conto el dia que se cobro el anticipo
                $formas[$f] = ($formas[$f] ?? 0.0) + $imp;
                if ($f === 'efectivo') $nota['efectivo'] += $imp;
                elseif ($f === 'tdc' || $f === 'tdb') $nota['tarjeta'] += $imp;
                elseif ($f === 'monedero') $nota['monedero'] += $imp;
                else $nota['otros'] += $imp;
            }
            $lineas = [];
            foreach ($lineasPorVenta[(int) $v['id']] ?? [] as $l) {
                $porLista[$l['lista_aplicada']] = ($porLista[$l['lista_aplicada']] ?? 0.0) + (float) $l['importe'];
                $lineas[] = ['codigo' => $l['codigo'], 'descripcion' => $l['descripcion'], 'cantidad' => (float) $l['cantidad'],
                             'precio_unitario' => (float) $l['precio_unitario'], 'lista_aplicada' => $l['lista_aplicada'], 'importe' => (float) $l['importe']];
            }
            $detalle[] = [
                'id_venta' => (int) $v['id'], 'folio' => $v['folio'], 'cliente' => $v['cli_nombre'],
                'total' => (float) $v['total'], 'formas_pago' => array_values(array_unique($formasVenta)),
                'a_credito' => (bool) $v['a_credito'],
                'efectivo' => round($nota['efectivo'], 2), 'otros' => round($nota['otros'], 2), 'tarjeta' => round($nota['tarjeta'], 2),
                'credito' => round($v['a_credito'] ? (float) $v['monto_credito'] : 0, 2), 'monedero' => round($nota['monedero'], 2),
                'cambio_efectivo' => (float) $v['cambio_efectivo'], 'lineas' => $lineas,
            ];
        }

        // Anticipos de apartados de este almacen cobrados este dia
        $anticipos = Db::all(
            "SELECT aa.forma, aa.importe, a.folio, c.nombre cliente, TRIM(CONCAT(COALESCE(e.nombre, ''), ' ', COALESCE(e.apellido, ''))) vendedor
             FROM apartado_anticipos aa
             JOIN apartados a ON a.id_empresa = aa.id_empresa AND a.id = aa.id_apartado
             JOIN clientes c ON c.id_empresa = a.id_empresa AND c.id = a.id_cliente
             LEFT JOIN empleados e ON e.id_empresa = a.id_empresa AND e.id = a.id_vendedor
             WHERE aa.id_empresa = ? AND a.id_almacen = ? AND DATE(aa.fecha) = ? ORDER BY aa.fecha, aa.id", [$emp, $idAlm, $fecha]);
        $antPorForma = [];
        foreach ($anticipos as $a) $antPorForma[$a['forma']] = round(($antPorForma[$a['forma']] ?? 0) + (float) $a['importe'], 2);

        $devoluciones = Db::all(
            "SELECT d.folio, v.folio folio_venta, d.total, d.destino_saldo FROM devoluciones d
             LEFT JOIN ventas v ON v.id_empresa = d.id_empresa AND v.id = d.id_venta
             WHERE d.id_empresa = ? AND d.id_almacen = ? AND DATE(d.fecha) = ? ORDER BY d.fecha, d.id", [$emp, $idAlm, $fecha]);
        $cambios = Db::all(
            "SELECT ca.folio, v.folio folio_venta, ca.total_devuelto, ca.total_nuevo, ca.diferencia, ca.pago_diferencia, ca.forma_diferencia
             FROM cambios ca LEFT JOIN ventas v ON v.id_empresa = ca.id_empresa AND v.id = ca.id_venta
             WHERE ca.id_empresa = ? AND ca.id_almacen = ? AND DATE(ca.fecha) = ? ORDER BY ca.fecha, ca.id", [$emp, $idAlm, $fecha]);
        $abonos = Db::all(
            "SELECT m.monto, m.forma, c.nombre cliente FROM cliente_movimientos m
             JOIN clientes c ON c.id_empresa = m.id_empresa AND c.id = m.id_cliente
             WHERE m.id_empresa = ? AND m.id_almacen = ? AND m.tipo = 'abono' AND DATE(m.fecha) = ? ORDER BY m.fecha, m.id", [$emp, $idAlm, $fecha]);

        // Caja del dia (efectivo) y cobros por cuenta / terminal
        $caja = Db::all("SELECT tipo, monto, concepto, fecha FROM caja_movimientos WHERE id_empresa = ? AND id_almacen = ? AND DATE(fecha) = ? ORDER BY fecha, id",
            [$emp, $idAlm, $fecha]);
        $entradas = 0.0; $salidas = 0.0;
        foreach ($caja as $m) { if ($m['tipo'] === 'ingreso') $entradas += (float) $m['monto']; else $salidas += (float) $m['monto']; }
        $porDestino = Db::all(
            "SELECT b.nombre cuenta, t.nombre terminal, m.forma,
                    SUM(CASE m.tipo WHEN 'ingreso' THEN m.monto ELSE -m.monto END) monto
             FROM banco_movimientos m
             JOIN bancos b ON b.id_empresa = m.id_empresa AND b.id = m.id_banco
             LEFT JOIN terminales t ON t.id_empresa = m.id_empresa AND t.id = m.id_terminal
             WHERE m.id_empresa = ? AND m.id_almacen = ? AND DATE(m.fecha) = ? AND m.ref_tipo NOT IN ('Deposito', 'Retiro')
             GROUP BY b.nombre, t.nombre, m.forma ORDER BY b.nombre, t.nombre", [$emp, $idAlm, $fecha]);
        $depositos = (float) Db::one("SELECT COALESCE(SUM(monto), 0) s FROM caja_movimientos WHERE id_empresa = ? AND id_almacen = ? AND DATE(fecha) = ? AND ref_tipo = 'Deposito'",
            [$emp, $idAlm, $fecha])['s'];

        foreach ($formas as $k => $val) $formas[$k] = round($val, 2);
        foreach ($porLista as $k => $val) $porLista[$k] = round($val, 2);
        $cambioEfectivo = round($cambioEfectivo, 2);
        $efectivoCambios = 0.0;
        foreach ($cambios as $cm) if (($cm['forma_diferencia'] ?? 'efectivo') === 'efectivo') $efectivoCambios += (float) $cm['pago_diferencia'];
        return [
            'resumen' => [
                'total_vendido' => round($totalVendido, 2), 'num_notas' => count($ventas), 'por_forma_pago' => $formas,
                'vendido_por_lista' => $porLista,
                'total_credito' => round($totalCredito, 2), 'saldo_favor_usado' => round($favorUsado, 2),
                'saldo_favor_generado' => round($favorGenerado, 2), 'cambio_efectivo' => $cambioEfectivo,
                'efectivo_neto' => round($formas['efectivo'] - $cambioEfectivo, 2),   // solo de ventas
                'total_anticipos' => round(array_sum(array_column($anticipos, 'importe')), 2),
                'anticipos_por_forma_pago' => $antPorForma,
                'total_devoluciones' => round(array_sum(array_column($devoluciones, 'total')), 2),
                'efectivo_cambios' => round($efectivoCambios, 2),
                'total_abonos' => round(array_sum(array_column($abonos, 'monto')), 2),
                'caja_entradas' => round($entradas, 2), 'caja_salidas' => round($salidas, 2), 'depositos' => round($depositos, 2),
                // lo que debe haber en caja al cierre (sin contar el fondo): todo lo que entro en efectivo menos lo que salio
                'efectivo_esperado_caja' => round($entradas - $salidas, 2),
                'cobrado_en_cuentas' => round(array_sum(array_column($porDestino, 'monto')), 2),
            ],
            'detalle_notas' => $detalle,
            'anticipos' => array_map(fn($a) => ['folio' => $a['folio'], 'cliente' => $a['cliente'], 'vendedor' => $a['vendedor'] ?: null,
                                                'forma' => $a['forma'], 'importe' => (float) $a['importe']], $anticipos),
            'devoluciones' => array_map(fn($d) => ['folio' => $d['folio'], 'folio_venta' => $d['folio_venta'], 'total' => (float) $d['total'],
                                                   'destino_saldo' => $d['destino_saldo']], $devoluciones),
            'cambios' => array_map(fn($c) => ['folio' => $c['folio'], 'folio_venta' => $c['folio_venta'], 'total_devuelto' => (float) $c['total_devuelto'],
                                              'total_nuevo' => (float) $c['total_nuevo'], 'diferencia' => (float) $c['diferencia'],
                                              'pago_diferencia' => (float) $c['pago_diferencia'], 'forma' => $c['forma_diferencia']], $cambios),
            'abonos' => array_map(fn($a) => ['cliente' => $a['cliente'], 'forma' => $a['forma'], 'monto' => (float) $a['monto']], $abonos),
            'por_destino' => array_map(fn($d) => ['cuenta' => $d['cuenta'], 'terminal' => $d['terminal'], 'forma' => $d['forma'],
                                                  'monto' => round((float) $d['monto'], 2)], $porDestino),
            'caja_movimientos' => array_map(fn($m) => ['hora' => substr($m['fecha'], 11, 5), 'tipo' => $m['tipo'], 'concepto' => $m['concepto'],
                                                       'monto' => (float) $m['monto']], $caja),
        ];
    }

    private static function fecha($f): string
    {
        $f = (string) ($f ?: date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) throw new ApiError('Fecha invalida (AAAA-MM-DD)', 400, 'VALIDATION');
        return $f;
    }

    public static function preview(array $p, array $ctx): void
    {
        $idAlm = Tenant::owns('almacenes', $_GET['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen', true);
        Http::ok(self::computar(Tenant::id(), $idAlm, self::fecha($_GET['fecha'] ?? null)));
    }

    public static function cerrar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen', true);
        $fecha = self::fecha($b['fecha'] ?? null);
        $alm = Db::one('SELECT codigo FROM almacenes WHERE id = ? AND id_empresa = ?', [$idAlm, $emp]);
        Db::begin();
        if (Db::one('SELECT 1 FROM cortes WHERE id_empresa = ? AND id_almacen = ? AND fecha = ? FOR UPDATE', [$emp, $idAlm, $fecha]))
            throw new ApiError('Ya existe el corte de ese almacen para esa fecha', 409, 'CONFLICT');
        $data = self::computar($emp, $idAlm, $fecha);
        $fondo = num($b['fondo'] ?? 0);
        $contado = num($b['efectivo_contado'] ?? 0);
        $diferencia = round($contado - ($fondo + $data['resumen']['efectivo_esperado_caja']), 2);
        $id = Db::insert(
            'INSERT INTO cortes (id_empresa, folio, fecha, id_almacen, id_usuario, fondo, efectivo_contado, diferencia, resumen, detalle_notas, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, 'CORTE-' . $fecha . '-' . $alm['codigo'], $fecha, $idAlm, $ctx['user']['id'], $fondo, $contado, $diferencia,
             // snapshot completo del dia (las secciones viajan dentro de resumen para no perderlas)
             json_encode(array_merge($data['resumen'], ['secciones' => array_diff_key($data, array_flip(['resumen', 'detalle_notas']))]), JSON_UNESCAPED_UNICODE),
             json_encode($data['detalle_notas'], JSON_UNESCAPED_UNICODE),   // ids internos; se codifican al responder
             $b['notas'] ?? null]);
        Db::commit();
        Ledger::audit($ctx, 'cerrar', 'Corte', $id, 'Corte ' . $fecha . ' ' . $alm['codigo']);
        Http::created(self::fmt(Db::one('SELECT * FROM cortes WHERE id = ? AND id_empresa = ?', [$id, $emp])), 'Corte');
    }

    public static function fmt(array $c): array
    {
        $resumen = $c['resumen'] ? json_decode($c['resumen'], true) : [];
        $secciones = $resumen['secciones'] ?? [];
        unset($resumen['secciones']);
        return $secciones + [
            '_id' => (int) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'],
            'id_almacen' => (int) $c['id_almacen'], 'id_usuario' => $c['id_usuario'] !== null ? (int) $c['id_usuario'] : null,
            'fondo' => (float) $c['fondo'], 'efectivo_contado' => (float) $c['efectivo_contado'], 'diferencia' => (float) $c['diferencia'],
            'resumen' => $resumen ?: null,
            'efectivo_esperado' => round((float) $c['fondo'] + (float) ($resumen['efectivo_esperado_caja'] ?? 0), 2),
            'detalle_notas' => $c['detalle_notas'] ? json_decode($c['detalle_notas'], true) : [],
            'notas' => $c['notas'], 'createdAt' => $c['created_at'],
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        if (!empty($_GET['id_almacen'])) { $where .= ' AND id_almacen = ?'; $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['desde']))      { $where .= ' AND fecha >= ?';     $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $where .= ' AND fecha <= ?';     $args[] = $_GET['hasta']; }
        Http::ok(array_map([self::class, 'fmt'], Db::all("SELECT * FROM cortes WHERE $where ORDER BY fecha DESC, id DESC LIMIT 500", $args)));
    }

    public static function obtener(array $p, array $ctx): void
    {
        $c = Db::one('SELECT * FROM cortes WHERE id = ? AND id_empresa = ?', [(int) $p['id'], Tenant::id()]);
        if (!$c) throw new ApiError('Corte no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::fmt($c));
    }
}
