<?php
// ============================================================
// Resumen del dia y pendientes de la tienda (pantalla de Inicio y contadores del menu).
// Cada dato solo se calcula si el usuario tiene permiso de verlo.
// ============================================================
class TiendaController
{
    const STOCK_BAJO = 5;

    /** Contadores del menu: [ruta => {n, tono, texto}] ('rojo' urgente, 'ambar' por revisar) */
    public static function pendientes(array $p, array $ctx): void
    {
        Http::ok(self::calcularPendientes($ctx));
    }

    private static function calcularPendientes(array $ctx): array
    {
        $emp = Tenant::id();
        $out = [];
        if (Permisos::tiene($ctx, 'apartados.ver')) {
            $r = Db::one("SELECT SUM(fecha_limite < CURDATE()) vencidos, SUM(fecha_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)) por_vencer
                          FROM apartados WHERE id_empresa = ? AND estado IN ('vigente','con_anticipo')", [$emp]);
            if ((int) $r['vencidos'] > 0) $out['/apartados'] = ['n' => (int) $r['vencidos'], 'tono' => 'rojo', 'texto' => self::plural((int) $r['vencidos'], 'apartado vencido', 'apartados vencidos')];
            elseif ((int) $r['por_vencer'] > 0) $out['/apartados'] = ['n' => (int) $r['por_vencer'], 'tono' => 'ambar', 'texto' => self::plural((int) $r['por_vencer'], 'apartado por vencer', 'apartados por vencer')];
        }
        if (Permisos::tiene($ctx, 'compras.ver')) {
            $n = (int) Db::one("SELECT COUNT(*) n FROM compras WHERE id_empresa = ? AND estado = 'por_aprobar'", [$emp])['n'];
            if ($n > 0) $out['/compras'] = ['n' => $n, 'tono' => 'ambar', 'texto' => self::plural($n, 'compra por aprobar', 'compras por aprobar')];
        }
        if (Permisos::tiene($ctx, 'traspasos.ver')) {
            $n = (int) Db::one("SELECT COUNT(*) n FROM traspasos WHERE id_empresa = ? AND estado = 'pendiente'", [$emp])['n'];
            if ($n > 0) $out['/traspasos'] = ['n' => $n, 'tono' => 'ambar', 'texto' => self::plural($n, 'traspaso por aceptar', 'traspasos por aceptar')];
        }
        if (Permisos::tiene($ctx, 'almacen.ver')) {
            $n = (int) Db::one(
                "SELECT COUNT(*) n FROM (
                   SELECT a.id, COALESCE(SUM(i.cantidad - i.reservado), 0) libre FROM articulos a
                   LEFT JOIN inventario i ON i.id_empresa = a.id_empresa AND i.id_articulo = a.id
                   WHERE a.id_empresa = ? AND a.is_active = 'Si' AND a.es_kit = 0 GROUP BY a.id HAVING libre <= ?) x",
                [$emp, self::STOCK_BAJO])['n'];
            if ($n > 0) $out['/inventario'] = ['n' => $n, 'tono' => 'ambar', 'texto' => self::plural($n, 'artículo con poco stock', 'artículos con poco stock')];
        }
        if (Permisos::tiene($ctx, 'cortes.ver')) {
            // almacenes que vendieron hoy y aun no cierran su corte
            $n = (int) Db::one(
                "SELECT COUNT(DISTINCT v.id_almacen) n FROM ventas v
                 WHERE v.id_empresa = ? AND DATE(v.fecha) = CURDATE()
                   AND NOT EXISTS (SELECT 1 FROM cortes c WHERE c.id_empresa = v.id_empresa AND c.id_almacen = v.id_almacen AND c.fecha = CURDATE())",
                [$emp])['n'];
            if ($n > 0) $out['/cortes'] = ['n' => $n, 'tono' => 'ambar', 'texto' => $n === 1 ? 'Corte de hoy abierto' : "$n cortes de hoy abiertos"];
        }
        return $out;
    }

    private static function plural(int $n, string $uno, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $uno : $varios);
    }

    /** Resumen de hoy para la pantalla de Inicio */
    public static function hoy(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $out = ['fecha' => date('Y-m-d'), 'pendientes' => self::calcularPendientes($ctx)];
        $idAlm = Tenant::owns('almacenes', $_GET['id_almacen'] ?? null, 'Almacen');
        $wa = $idAlm ? ' AND v.id_almacen = ?' : '';
        $aa = $idAlm ? [$idAlm] : [];

        if (Permisos::tiene($ctx, 'ventas.ver') || Permisos::tiene($ctx, 'reportes.ver')) {
            $dia = fn(string $expr) => Db::one(
                "SELECT COUNT(*) notas, COALESCE(SUM(v.total), 0) total,
                        COALESCE(SUM((SELECT SUM(l.cantidad) FROM venta_lineas l WHERE l.id_empresa = v.id_empresa AND l.id_venta = v.id)), 0) piezas
                 FROM ventas v WHERE v.id_empresa = ? AND v.estado = 'completada' AND DATE(v.fecha) = $expr $wa",
                array_merge([$emp], $aa));
            $hoy = $dia('CURDATE()');
            $semana = $dia('DATE_SUB(CURDATE(), INTERVAL 7 DAY)');   // mismo dia de la semana pasada
            $out['ventas'] = [
                'total' => round((float) $hoy['total'], 2), 'notas' => (int) $hoy['notas'],
                'ticket_promedio' => (int) $hoy['notas'] > 0 ? round((float) $hoy['total'] / (int) $hoy['notas'], 2) : 0.0,
                'piezas_por_nota' => (int) $hoy['notas'] > 0 ? round((float) $hoy['piezas'] / (int) $hoy['notas'], 1) : 0.0,
                'total_semana_pasada' => round((float) $semana['total'], 2),
            ];
            $horas = array_fill(0, 24, 0.0);
            foreach (Db::all("SELECT HOUR(v.fecha) h, SUM(v.total) t FROM ventas v
                              WHERE v.id_empresa = ? AND v.estado = 'completada' AND DATE(v.fecha) = CURDATE() $wa GROUP BY HOUR(v.fecha)",
                              array_merge([$emp], $aa)) as $r) $horas[(int) $r['h']] = round((float) $r['t'], 2);
            $out['por_hora'] = $horas;
            $out['ultimas'] = array_map(fn($v) => [
                '_id' => (int) $v['id'], 'folio' => $v['folio'], 'fecha' => $v['fecha'], 'total' => (float) $v['total'],
                'estado' => $v['estado'], 'cliente' => $v['cliente'] ?: 'Publico General',
                'vendedor' => trim(($v['vnom'] ?? '') . ' ' . ($v['vape'] ?? '')) ?: null,
            ], Db::all("SELECT v.id, v.folio, v.fecha, v.total, v.estado, c.nombre cliente, e.nombre vnom, e.apellido vape FROM ventas v
                        LEFT JOIN clientes c ON c.id_empresa = v.id_empresa AND c.id = v.id_cliente
                        LEFT JOIN empleados e ON e.id_empresa = v.id_empresa AND e.id = v.id_vendedor
                        WHERE v.id_empresa = ? AND DATE(v.fecha) = CURDATE() $wa ORDER BY v.fecha DESC, v.id DESC LIMIT 6", array_merge([$emp], $aa)));
        }
        if (Permisos::tiene($ctx, 'finanzas.ver') || Permisos::tiene($ctx, 'cortes.ver')) {
            $out['efectivo_caja'] = round((float) Db::one(
                "SELECT COALESCE(SUM(CASE tipo WHEN 'ingreso' THEN monto ELSE -monto END), 0) s FROM caja_movimientos WHERE id_empresa = ?" . ($idAlm ? ' AND id_almacen = ?' : ''),
                array_merge([$emp], $aa))['s'], 2);
        }
        if (Permisos::tiene($ctx, 'finanzas.ver') || Permisos::tiene($ctx, 'clientes.ver')) {
            $r = Db::one("SELECT COALESCE(SUM(saldo_credito), 0) s, SUM(saldo_credito > 0.004) n FROM clientes WHERE id_empresa = ?", [$emp]);
            $out['por_cobrar'] = ['total' => round((float) $r['s'], 2), 'clientes' => (int) $r['n']];
        }
        Http::ok($out);
    }
}
