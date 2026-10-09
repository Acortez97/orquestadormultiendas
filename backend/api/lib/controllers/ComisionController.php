<?php
class ComisionController
{
    /**
     * Cancelacion de venta: se anulan las comisiones no pagadas; las ya pagadas quedan y se registra
     * un descuento (importe negativo, pendiente) que se aplica en el siguiente pago al vendedor.
     */
    public static function anularVenta(int $emp, int $idVenta): void
    {
        $neto = Db::all("SELECT id_empleado, porcentaje, SUM(base) base, SUM(importe) importe FROM comisiones
                         WHERE id_empresa = ? AND id_venta = ? AND anulada = 0 AND pagada = 'Si' GROUP BY id_empleado, porcentaje", [$emp, $idVenta]);
        Db::run("UPDATE comisiones SET anulada = 1 WHERE id_empresa = ? AND id_venta = ? AND pagada = 'No'", [$emp, $idVenta]);
        foreach ($neto as $c) {
            if ((float) $c['importe'] <= 0) continue;
            Db::insert("INSERT INTO comisiones (id_empresa, id_empleado, id_venta, base, porcentaje, importe, pagada) VALUES (?,?,?,?,?,?,'No')",
                [$emp, (int) $c['id_empleado'], $idVenta, -1 * (float) $c['base'], (float) $c['porcentaje'], -1 * (float) $c['importe']]);
        }
    }

    /**
     * Devolucion / cambio: ajusta la comision de la venta segun la base comisionable que cambio
     * ($deltaBase negativo = se devolvio mercancia). Se registra como movimiento pendiente (+/-).
     */
    public static function ajustarVenta(int $emp, int $idVenta, float $deltaBase): void
    {
        $deltaBase = round($deltaBase, 2);
        if (abs($deltaBase) < 0.005) return;
        $c = Db::one('SELECT id_empleado, porcentaje FROM comisiones WHERE id_empresa = ? AND id_venta = ? AND anulada = 0 AND importe > 0 ORDER BY id LIMIT 1', [$emp, $idVenta]);
        if (!$c) return;   // la venta no genero comision
        $importe = round($deltaBase * (float) $c['porcentaje'] / 100, 2);
        if (abs($importe) < 0.005) return;
        Db::insert("INSERT INTO comisiones (id_empresa, id_empleado, id_venta, base, porcentaje, importe, pagada) VALUES (?,?,?,?,?,?,'No')",
            [$emp, (int) $c['id_empleado'], $idVenta, $deltaBase, (float) $c['porcentaje'], $importe]);
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'co.id_empresa=? AND co.anulada=0'; $args = [$emp];
        if (!empty($_GET['id_empleado'])) { $where .= ' AND co.id_empleado=?'; $args[] = (int) $_GET['id_empleado']; }
        if (!empty($_GET['pagada']) && in_array($_GET['pagada'], ['Si', 'No'], true)) {
            $where .= ' AND co.pagada=?'; $args[] = $_GET['pagada'];
        }
        $rows = Db::all(
            "SELECT co.*, e.nombre emp_nombre, e.apellido emp_apellido, v.folio folio_venta
             FROM comisiones co
             JOIN empleados e ON e.id_empresa=co.id_empresa AND e.id=co.id_empleado
             LEFT JOIN ventas v ON v.id_empresa=co.id_empresa AND v.id=co.id_venta
             WHERE $where ORDER BY co.created_at DESC, co.id DESC LIMIT 1000", $args);

        Http::ok(array_map(fn($r) => [
            '_id'         => (int) $r['id'],
            'createdAt'   => $r['created_at'],
            'id_empleado' => ['_id' => (int) $r['id_empleado'], 'nombre' => $r['emp_nombre'], 'apellido' => $r['emp_apellido']],
            'folio_venta' => $r['folio_venta'],
            'base'        => (float) $r['base'],
            'porcentaje'  => (float) $r['porcentaje'],
            'importe'     => (float) $r['importe'],
            'pagada'      => $r['pagada'],
        ], $rows));
    }

    public static function resumen(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $r = Db::one(
            "SELECT COALESCE(SUM(importe),0) total,
                    COALESCE(SUM(CASE WHEN pagada='No' THEN importe END),0) pendiente,
                    COALESCE(SUM(CASE WHEN pagada='Si' THEN importe END),0) pagadas,
                    COUNT(*) cantidad
             FROM comisiones WHERE id_empresa=? AND anulada=0", [$emp]);
        Http::ok([
            'total'     => (float) $r['total'],
            'pendiente' => (float) $r['pendiente'],
            'pagadas'   => (float) $r['pagadas'],
            'cantidad'  => (int) $r['cantidad'],
        ]);
    }

    public static function pagar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        $co = Db::one('SELECT co.*, v.folio folio_venta FROM comisiones co LEFT JOIN ventas v ON v.id_empresa=co.id_empresa AND v.id=co.id_venta WHERE co.id=? AND co.id_empresa=?', [$id, $emp]);
        if (!$co) throw new ApiError('Comision no encontrada', 404, 'NOT_FOUND');
        if ($co['anulada']) throw new ApiError('La comision esta anulada', 400, 'VALIDATION');
        if ($co['pagada'] === 'Si') throw new ApiError('La comision ya esta pagada', 400, 'VALIDATION');
        Db::run('UPDATE comisiones SET pagada=\'Si\', fecha_pago=NOW() WHERE id=? AND id_empresa=?', [$id, $emp]);
        Ledger::audit($ctx, 'pagar', 'Comision', $id, 'Comision ' . $co['folio_venta']);
        Http::ok(['_id' => $id, 'pagada' => 'Si'], 'Comision pagada');
    }
}
