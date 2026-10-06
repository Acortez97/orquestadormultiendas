<?php
class ComisionController
{
    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'co.id_empresa=? AND co.anulada=0'; $args = [$emp];
        if (!empty($_GET['id_empleado'])) { $where .= ' AND co.id_empleado=?'; $args[] = (int) $_GET['id_empleado']; }
        if (!empty($_GET['pagada']) && in_array($_GET['pagada'], ['Si', 'No'], true)) {
            $where .= ' AND co.pagada=?'; $args[] = $_GET['pagada'];
        }
        $rows = Db::all(
            "SELECT co.*, e.nombre emp_nombre, e.apellido emp_apellido
             FROM comisiones co
             JOIN empleados e ON e.id=co.id_empleado
             WHERE $where ORDER BY co.created_at DESC, co.id DESC LIMIT 1000", $args);

        Http::ok(array_map(fn($r) => [
            '_id'         => (string) $r['id'],
            'createdAt'   => $r['created_at'],
            'id_empleado' => ['_id' => (string) $r['id_empleado'], 'nombre' => $r['emp_nombre'], 'apellido' => $r['emp_apellido']],
            'folio_venta' => $r['folio_venta'],
            'base'        => (float) $r['base'],
            'porcentaje'  => (float) $r['porcentaje'],
            'importe'     => (float) $r['importe'],
            'pagada'      => $r['pagada'],
        ], $rows));
    }

    public static function resumen(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
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
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $co = Db::one('SELECT * FROM comisiones WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$co) throw new ApiError('Comision no encontrada', 404, 'NOT_FOUND');
        if ($co['anulada']) throw new ApiError('La comision esta anulada', 400, 'VALIDATION');
        if ($co['pagada'] === 'Si') throw new ApiError('La comision ya esta pagada', 400, 'VALIDATION');
        Db::run('UPDATE comisiones SET pagada=\'Si\', fecha_pago=NOW() WHERE id=?', [$id]);
        Ledger::audit($ctx, 'pagar', 'Comision', $id, 'Comision ' . $co['folio_venta']);
        Http::ok(['_id' => (string) $id, 'pagada' => 'Si'], 'Comision pagada');
    }
}
