<?php
class FinanzasController
{
    // ============================== BANCOS ==============================
    public static function bancosListar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $rows = Db::all('SELECT * FROM bancos WHERE id_empresa=? AND is_active=\'Si\' ORDER BY nombre', [$emp]);
        Http::ok(array_map(fn($b) => [
            '_id'          => (string) $b['id'],
            'nombre'       => $b['nombre'],
            'moneda'       => $b['moneda'],
            'cuenta'       => $b['cuenta'],
            'clabe'        => $b['clabe'],
            'saldo_actual' => (float) $b['saldo_actual'],
        ], $rows));
    }

    public static function bancosCrear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        if (trim($b['nombre'] ?? '') === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $moneda = ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        $id = Db::insert('INSERT INTO bancos (id_empresa,nombre,moneda,cuenta,clabe,saldo_actual) VALUES (?,?,?,?,?,?)',
            [$emp, trim($b['nombre']), $moneda, $b['cuenta'] ?? null, $b['clabe'] ?? null, num($b['saldo_inicial'] ?? 0)]);
        $row = Db::one('SELECT * FROM bancos WHERE id=?', [$id]);
        Ledger::audit($ctx, 'crear', 'Banco', $id, $row['nombre']);
        Http::created([
            '_id' => (string) $row['id'], 'nombre' => $row['nombre'], 'moneda' => $row['moneda'],
            'cuenta' => $row['cuenta'], 'clabe' => $row['clabe'], 'saldo_actual' => (float) $row['saldo_actual'],
        ], 'Banco');
    }

    // ===================== CUENTAS POR COBRAR (CxC) ====================
    public static function cxcListar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $search = $_GET['search'] ?? '';
        $args = [$emp];
        $having = '';
        $like = '';
        if ($search !== '') { $like = ' AND c.nombre LIKE ?'; $args[] = '%' . $search . '%'; }

        $rows = Db::all(
            "SELECT c.id, c.nombre, c.rfc, c.saldo_credito,
                    COALESCE(SUM(CASE WHEN m.efecto='cargo' AND m.anulado=0 THEN m.monto END),0) cargos,
                    COALESCE(SUM(CASE WHEN m.efecto='abono' AND m.anulado=0 THEN m.monto END),0) abonos
             FROM clientes c
             LEFT JOIN cliente_movimientos m ON m.id_cliente=c.id
             WHERE c.id_empresa=? $like
             GROUP BY c.id, c.nombre, c.rfc, c.saldo_credito
             HAVING cargos > 0 OR abonos > 0 OR c.saldo_credito <> 0
             ORDER BY c.saldo_credito DESC, c.nombre", $args);

        Http::ok(array_map(fn($r) => [
            '_id'    => (string) $r['id'],
            'nombre' => $r['nombre'],
            'rfc'    => $r['rfc'],
            'cargos' => (float) $r['cargos'],
            'abonos' => (float) $r['abonos'],
            'saldo'  => (float) $r['saldo_credito'],
        ], $rows));
    }

    public static function cxcMovimientos(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idCli = (int) $p['id'];
        if (!Db::one('SELECT id FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp]))
            throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $rows = Db::all('SELECT * FROM cliente_movimientos WHERE id_cliente=? ORDER BY fecha DESC, id DESC LIMIT 500', [$idCli]);
        Http::ok(array_map(fn($m) => [
            '_id'     => (string) $m['id'],
            'fecha'   => $m['fecha'],
            'concepto'=> $m['concepto'],
            'moneda'  => $m['moneda'],
            'tipo'    => $m['efecto'] === 'cargo' ? 'cargo' : 'pago',
            'monto'   => (float) $m['monto'],
        ], $rows));
    }

    public static function cxcEstadoCuenta(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idCli = (int) $p['id'];
        $c = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp]);
        if (!$c) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $movs = Db::all('SELECT * FROM cliente_movimientos WHERE id_cliente=? ORDER BY fecha DESC, id DESC LIMIT 500', [$idCli]);
        Http::ok([
            'cliente' => [
                'saldo_credito' => (float) $c['saldo_credito'],
                'saldo_favor'   => (float) $c['saldo_favor'],
            ],
            'movimientos' => array_map(fn($m) => [
                '_id'      => (string) $m['id'],
                'tipo'     => $m['tipo'],
                'concepto' => $m['concepto'],
                'monto'    => (float) $m['monto'],
                'efecto'   => $m['efecto'],
                'fecha'    => $m['fecha'],
                'anulado'  => (bool) $m['anulado'],
            ], $movs),
        ]);
    }

    public static function cxcAbono(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idCli = id_or_null($b['id_cliente'] ?? null);
        $monto = num($b['monto'] ?? 0);
        if (!$idCli) throw new ApiError('id_cliente es obligatorio', 400, 'VALIDATION');
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        $c = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp]);
        if (!$c) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $idBanco = id_or_null($b['id_banco'] ?? null);

        Db::begin();
        try {
            $movId = Ledger::clienteMov($emp, $idCli, 'abono', $b['concepto'] ?? 'Abono a cuenta', $monto, 'abono',
                'MXN', $idBanco, 'Abono', null);
            Ledger::bancoDelta($idBanco, $monto);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'abono', 'CxC', $idCli, 'Abono ' . $monto);
        Http::created(['_id' => (string) $movId, 'id_cliente' => (string) $idCli, 'monto' => $monto], 'Abono');
    }

    // ===================== CUENTAS POR PAGAR (CxP) =====================
    public static function cxpListar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $rows = Db::all(
            "SELECT pr.id, pr.nombre, pr.rfc, m.moneda,
                    COALESCE(SUM(CASE WHEN m.tipo='cargo' THEN m.monto END),0) cargos,
                    COALESCE(SUM(CASE WHEN m.tipo='pago'  THEN m.monto END),0) pagos
             FROM proveedores pr
             JOIN proveedor_movimientos m ON m.id_proveedor=pr.id
             WHERE pr.id_empresa=?
             GROUP BY pr.id, pr.nombre, pr.rfc, m.moneda
             ORDER BY pr.nombre", [$emp]);

        // agrupar por proveedor con sub-objeto por moneda
        $byProv = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            if (!isset($byProv[$pid])) {
                $byProv[$pid] = [
                    '_id' => (string) $pid, 'nombre' => $r['nombre'], 'rfc' => $r['rfc'],
                    'saldos' => [
                        'MXN' => ['cargos' => 0.0, 'pagos' => 0.0, 'saldo' => 0.0],
                        'USD' => ['cargos' => 0.0, 'pagos' => 0.0, 'saldo' => 0.0],
                    ],
                ];
            }
            $mon = ($r['moneda'] === 'USD') ? 'USD' : 'MXN';
            $cargos = (float) $r['cargos']; $pagos = (float) $r['pagos'];
            $byProv[$pid]['saldos'][$mon] = ['cargos' => $cargos, 'pagos' => $pagos, 'saldo' => round($cargos - $pagos, 2)];
        }
        Http::ok(array_values($byProv));
    }

    public static function cxpMovimientos(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idProv = (int) $p['id'];
        if (!Db::one('SELECT id FROM proveedores WHERE id=? AND id_empresa=?', [$idProv, $emp]))
            throw new ApiError('Proveedor no encontrado', 404, 'NOT_FOUND');
        $rows = Db::all('SELECT * FROM proveedor_movimientos WHERE id_proveedor=? ORDER BY fecha DESC, id DESC LIMIT 500', [$idProv]);
        Http::ok(array_map(fn($m) => [
            '_id'     => (string) $m['id'],
            'fecha'   => $m['fecha'],
            'concepto'=> $m['concepto'],
            'moneda'  => $m['moneda'],
            'tipo'    => $m['tipo'],
            'monto'   => (float) $m['monto'],
        ], $rows));
    }

    public static function cxpPago(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idProv = id_or_null($b['id_proveedor'] ?? null);
        $monto = num($b['monto'] ?? 0);
        if (!$idProv) throw new ApiError('id_proveedor es obligatorio', 400, 'VALIDATION');
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        if (!Db::one('SELECT id FROM proveedores WHERE id=? AND id_empresa=?', [$idProv, $emp]))
            throw new ApiError('Proveedor no encontrado', 404, 'NOT_FOUND');
        $moneda = ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        $idBanco = id_or_null($b['id_banco'] ?? null);

        Db::begin();
        try {
            $movId = Ledger::proveedorMov($emp, $idProv, 'pago', $b['concepto'] ?? 'Pago a proveedor', $monto,
                $moneda, $idBanco, 'PagoProv', null);
            Ledger::bancoDelta($idBanco, -$monto);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'pago', 'CxP', $idProv, 'Pago ' . $monto . ' ' . $moneda);
        Http::created(['_id' => (string) $movId, 'id_proveedor' => (string) $idProv, 'monto' => $monto, 'moneda' => $moneda], 'Pago');
    }
}
