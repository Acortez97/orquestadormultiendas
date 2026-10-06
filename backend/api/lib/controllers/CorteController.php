<?php
// Cortes de caja por almacen y dia (uno por almacen/dia).
class CorteController
{
    /** Calcula el resumen del dia para un almacen de la tienda */
    public static function computar(int $emp, int $idAlm, string $fecha): array
    {
        $ventas = Db::all(
            "SELECT v.*, c.nombre cli_nombre FROM ventas v
             LEFT JOIN clientes c ON c.id_empresa = v.id_empresa AND c.id = v.id_cliente
             WHERE v.id_empresa = ? AND v.id_almacen = ? AND v.estado = 'completada' AND DATE(v.fecha) = ? ORDER BY v.fecha, v.id",
            [$emp, $idAlm, $fecha]);
        $pagosPorVenta = [];
        if ($ventas) {
            $ids = array_map(fn($v) => (int) $v['id'], $ventas);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT id_venta, forma, importe FROM venta_pagos WHERE id_empresa = ? AND id_venta IN ($ph)", array_merge([$emp], $ids)) as $pg) {
                $pagosPorVenta[(int) $pg['id_venta']][] = $pg;
            }
        }

        $formas = ['efectivo' => 0.0, 'tdc' => 0.0, 'tdb' => 0.0, 'transferencia' => 0.0, 'monedero' => 0.0];
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
                $formas[$f] = ($formas[$f] ?? 0.0) + $imp;
                $formasVenta[] = $f;
                if ($f === 'efectivo') $nota['efectivo'] += $imp;
                elseif ($f === 'tdc' || $f === 'tarjeta') $nota['tarjeta'] += $imp;
                elseif ($f === 'tdb' || $f === 'transferencia') $nota['otros'] += $imp;
                elseif ($f === 'monedero') $nota['monedero'] += $imp;
                // 'anticipo' no cuenta en caja
            }
            $detalle[] = [
                'id_venta' => (int) $v['id'], 'folio' => $v['folio'], 'cliente' => $v['cli_nombre'],
                'total' => (float) $v['total'], 'formas_pago' => array_values(array_unique($formasVenta)),
                'a_credito' => (bool) $v['a_credito'],
                'efectivo' => round($nota['efectivo'], 2), 'otros' => round($nota['otros'], 2), 'tarjeta' => round($nota['tarjeta'], 2),
                'credito' => round($v['a_credito'] ? (float) $v['monto_credito'] : 0, 2), 'monedero' => round($nota['monedero'], 2),
            ];
        }
        foreach ($formas as $k => $val) $formas[$k] = round($val, 2);
        $cambioEfectivo = round($cambioEfectivo, 2);
        $efectivoNeto = round($formas['efectivo'] - $cambioEfectivo, 2);   // lo que debe haber en caja
        return [
            'resumen' => [
                'total_vendido' => round($totalVendido, 2), 'num_notas' => count($ventas), 'por_forma_pago' => $formas,
                'total_credito' => round($totalCredito, 2), 'saldo_favor_usado' => round($favorUsado, 2),
                'saldo_favor_generado' => round($favorGenerado, 2), 'cambio_efectivo' => $cambioEfectivo,
                'efectivo_neto' => $efectivoNeto, 'efectivo_esperado_caja' => $efectivoNeto,
            ],
            'detalle_notas' => $detalle,
            'devoluciones' => [], 'cambios' => [], 'anticipos' => [],
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
             json_encode($data['resumen'], JSON_UNESCAPED_UNICODE),
             json_encode($data['detalle_notas'], JSON_UNESCAPED_UNICODE),   // ids internos; se codifican al responder
             $b['notas'] ?? null]);
        Db::commit();
        Ledger::audit($ctx, 'cerrar', 'Corte', $id, 'Corte ' . $fecha . ' ' . $alm['codigo']);
        Http::created(self::fmt(Db::one('SELECT * FROM cortes WHERE id = ? AND id_empresa = ?', [$id, $emp])), 'Corte');
    }

    public static function fmt(array $c): array
    {
        return [
            '_id' => (int) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'],
            'id_almacen' => (int) $c['id_almacen'], 'id_usuario' => $c['id_usuario'] !== null ? (int) $c['id_usuario'] : null,
            'fondo' => (float) $c['fondo'], 'efectivo_contado' => (float) $c['efectivo_contado'], 'diferencia' => (float) $c['diferencia'],
            'resumen' => $c['resumen'] ? json_decode($c['resumen'], true) : null,
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
