<?php
class CorteController
{
    /** Calcula el resumen del dia para una tienda */
    public static function computar(int $emp, int $idAlm, string $fecha): array
    {
        $ventas = Db::all(
            'SELECT * FROM ventas WHERE id_empresa=? AND id_almacen=? AND estado=\'completada\' AND DATE(fecha)=?',
            [$emp, $idAlm, $fecha]);

        $formas = ['efectivo' => 0.0, 'tdc' => 0.0, 'tdb' => 0.0, 'transferencia' => 0.0, 'monedero' => 0.0];
        $totalVendido = 0.0; $totalCredito = 0.0; $favorUsado = 0.0; $favorGenerado = 0.0; $cambioEfectivo = 0.0;
        $detalle = [];

        foreach ($ventas as $v) {
            $totalVendido  += (float) $v['total'];
            $totalCredito  += (float) $v['monto_credito'];
            $favorUsado    += (float) $v['saldo_favor_usado'];
            $favorGenerado += (float) $v['saldo_favor_generado'];
            $cambioEfectivo += (float) ($v['cambio_efectivo'] ?? 0);

            $pagos = Db::all('SELECT forma, importe FROM venta_pagos WHERE id_venta=?', [$v['id']]);
            $formasVenta = [];
            // Desglose de pagos por nota para el detalle simple (formato reporte diario).
            $pagoNota = ['efectivo' => 0.0, 'tarjeta' => 0.0, 'otros' => 0.0, 'monedero' => 0.0];
            foreach ($pagos as $pg) {
                $f = $pg['forma'];
                if (!isset($formas[$f])) $formas[$f] = 0.0;
                $imp = (float) $pg['importe'];
                $formas[$f] += $imp;
                $formasVenta[] = $f;
                if ($f === 'efectivo') $pagoNota['efectivo'] += $imp;
                elseif ($f === 'tdc') $pagoNota['tarjeta'] += $imp;
                elseif ($f === 'tdb' || $f === 'transferencia') $pagoNota['otros'] += $imp;
                elseif ($f === 'monedero') $pagoNota['monedero'] += $imp;
                // 'anticipo' no cuenta en caja.
            }
            $cliente = null;
            if (!empty($v['id_cliente'])) {
                $c = Db::one('SELECT nombre FROM clientes WHERE id=?', [$v['id_cliente']]);
                $cliente = $c['nombre'] ?? null;
            }
            $detalle[] = [
                'id_venta' => (string) $v['id'], 'folio' => $v['folio'],
                'cliente' => $cliente,
                'total' => (float) $v['total'], 'formas_pago' => array_values(array_unique($formasVenta)),
                'a_credito' => (bool) $v['a_credito'],
                'efectivo' => round($pagoNota['efectivo'], 2),
                'otros' => round($pagoNota['otros'], 2),
                'tarjeta' => round($pagoNota['tarjeta'], 2),
                'credito' => round($v['a_credito'] ? $v['monto_credito'] : 0, 2),
                'monedero' => round($pagoNota['monedero'], 2),
            ];
        }

        foreach ($formas as $k => $val) $formas[$k] = round($val, 2);
        $cambioEfectivo = round($cambioEfectivo, 2);
        // efectivo neto = efectivo recibido - cambio entregado; ese es el que debe estar en caja
        $efectivoNeto = round($formas['efectivo'] - $cambioEfectivo, 2);

        return [
            'resumen' => [
                'total_vendido'   => round($totalVendido, 2),
                'num_notas'       => count($ventas),
                'por_forma_pago'  => $formas,
                'total_credito'   => round($totalCredito, 2),
                'saldo_favor_usado'    => round($favorUsado, 2),
                'saldo_favor_generado' => round($favorGenerado, 2),
                'cambio_efectivo'      => $cambioEfectivo,
                'efectivo_neto'        => $efectivoNeto,
                'efectivo_esperado_caja' => $efectivoNeto,
            ],
            'detalle_notas' => $detalle,
            // modulos no incluidos en el nucleo (se dejan vacios para compatibilidad de UI)
            'devoluciones' => [],
            'cambios'      => [],
            'anticipos'    => [],
        ];
    }

    public static function preview(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $idAlm = id_or_null($_GET['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null);
        $fecha = $_GET['fecha'] ?? date('Y-m-d');
        if (!$idAlm) throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');
        Http::ok(self::computar($emp, $idAlm, $fecha));
    }

    public static function cerrar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idAlm = id_or_null($b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null);
        $fecha = $b['fecha'] ?? date('Y-m-d');
        if (!$idAlm) throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');

        $data = self::computar($emp, $idAlm, $fecha);
        $fondo = num($b['fondo'] ?? 0);
        $contado = num($b['efectivo_contado'] ?? 0);
        $esperado = $fondo + $data['resumen']['efectivo_esperado_caja'];
        $diferencia = round($contado - $esperado, 2);
        $folio = 'CORTE-' . $fecha . '-T' . $idAlm;

        $id = Db::insert(
            'INSERT INTO cortes (id_empresa,folio,fecha,id_almacen,id_usuario,fondo,efectivo_contado,diferencia,resumen,detalle_notas,notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $emp, $folio, $fecha, $idAlm, (int) $ctx['user']['id'], $fondo, $contado, $diferencia,
                json_encode($data['resumen'], JSON_UNESCAPED_UNICODE),
                json_encode($data['detalle_notas'], JSON_UNESCAPED_UNICODE),
                $b['notas'] ?? null,
            ]
        );
        Http::created(self::fmt(Db::one('SELECT * FROM cortes WHERE id=?', [$id])), 'Corte');
    }

    public static function fmt(array $c): array
    {
        return [
            '_id'        => (string) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'],
            'id_almacen' => (string) $c['id_almacen'], 'id_usuario' => $c['id_usuario'] !== null ? (string) $c['id_usuario'] : null,
            'fondo'      => (float) $c['fondo'], 'efectivo_contado' => (float) $c['efectivo_contado'],
            'diferencia' => (float) $c['diferencia'],
            'resumen'    => $c['resumen'] ? json_decode($c['resumen'], true) : null,
            'detalle_notas' => $c['detalle_notas'] ? json_decode($c['detalle_notas'], true) : [],
            'notas'      => $c['notas'], 'createdAt' => $c['created_at'],
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_almacen'])) { $where .= ' AND id_almacen=?'; $args[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['desde']))      { $where .= ' AND fecha>=?';     $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $where .= ' AND fecha<=?';     $args[] = $_GET['hasta']; }
        $rows = Db::all("SELECT * FROM cortes WHERE $where ORDER BY fecha DESC, id DESC LIMIT 500", $args);
        Http::ok(array_map([self::class, 'fmt'], $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        $c = Db::one('SELECT * FROM cortes WHERE id=? AND id_empresa=?', [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        if (!$c) throw new ApiError('Corte no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::fmt($c));
    }
}
