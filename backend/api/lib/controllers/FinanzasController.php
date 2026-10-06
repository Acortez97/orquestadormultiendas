<?php
// Bancos, cuentas por cobrar (clientes) y cuentas por pagar (proveedores) de la tienda.
class FinanzasController
{
    // ============================== BANCOS ==============================
    private static function fmtBanco(array $b): array
    {
        return ['_id' => (int) $b['id'], 'nombre' => $b['nombre'], 'moneda' => $b['moneda'], 'cuenta' => $b['cuenta'],
                'clabe' => $b['clabe'], 'saldo_actual' => (float) $b['saldo_actual']];
    }

    public static function bancosListar(array $p, array $ctx): void
    {
        Http::ok(array_map([self::class, 'fmtBanco'],
            Db::all("SELECT * FROM bancos WHERE id_empresa = ? AND is_active = 'Si' ORDER BY nombre", [Tenant::id()])));
    }

    public static function bancosCrear(array $p, array $ctx): void
    {
        $b = Http::body();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $id = Db::insert('INSERT INTO bancos (id_empresa, nombre, moneda, cuenta, clabe, saldo_actual) VALUES (?,?,?,?,?,?)',
            [Tenant::id(), $nombre, ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN', $b['cuenta'] ?? null, $b['clabe'] ?? null, num($b['saldo_inicial'] ?? 0)]);
        Ledger::audit($ctx, 'crear', 'Banco', $id, $nombre);
        Http::created(self::fmtBanco(Db::one('SELECT * FROM bancos WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()])), 'Banco');
    }

    // ===================== CUENTAS POR COBRAR (CxC) ====================
    public static function cxcListar(array $p, array $ctx): void
    {
        $args = [Tenant::id()];
        $like = '';
        if (($_GET['search'] ?? '') !== '') { $like = ' AND c.nombre LIKE ?'; $args[] = '%' . $_GET['search'] . '%'; }
        $rows = Db::all(
            "SELECT c.id, c.nombre, c.rfc, c.saldo_credito,
                    COALESCE(SUM(CASE WHEN m.efecto = 'cargo' AND m.anulado = 0 THEN m.monto END), 0) cargos,
                    COALESCE(SUM(CASE WHEN m.efecto = 'abono' AND m.anulado = 0 THEN m.monto END), 0) abonos
             FROM clientes c
             LEFT JOIN cliente_movimientos m ON m.id_empresa = c.id_empresa AND m.id_cliente = c.id
             WHERE c.id_empresa = ? $like
             GROUP BY c.id, c.nombre, c.rfc, c.saldo_credito
             HAVING cargos > 0 OR abonos > 0 OR c.saldo_credito <> 0
             ORDER BY c.saldo_credito DESC, c.nombre", $args);
        Http::ok(array_map(fn($r) => [
            '_id' => (int) $r['id'], 'nombre' => $r['nombre'], 'rfc' => $r['rfc'],
            'cargos' => (float) $r['cargos'], 'abonos' => (float) $r['abonos'], 'saldo' => (float) $r['saldo_credito'],
        ], $rows));
    }

    private static function movsCliente(int $idCli): array
    {
        return Db::all('SELECT * FROM cliente_movimientos WHERE id_empresa = ? AND id_cliente = ? ORDER BY fecha DESC, id DESC LIMIT 500', [Tenant::id(), $idCli]);
    }

    public static function cxcMovimientos(array $p, array $ctx): void
    {
        $idCli = Tenant::owns('clientes', $p['id'], 'Cliente', true);
        Http::ok(array_map(fn($m) => [
            '_id' => (int) $m['id'], 'fecha' => $m['fecha'], 'concepto' => $m['concepto'], 'moneda' => $m['moneda'],
            'tipo' => $m['efecto'] === 'cargo' ? 'cargo' : 'pago', 'monto' => (float) $m['monto'],
        ], self::movsCliente($idCli)));
    }

    public static function cxcEstadoCuenta(array $p, array $ctx): void
    {
        $c = ClienteController::cliente((int) $p['id']);
        Http::ok([
            'cliente' => ['saldo_credito' => (float) $c['saldo_credito'], 'saldo_favor' => (float) $c['saldo_favor']],
            'movimientos' => array_map(fn($m) => [
                '_id' => (int) $m['id'], 'tipo' => $m['tipo'], 'concepto' => $m['concepto'], 'monto' => (float) $m['monto'],
                'efecto' => $m['efecto'], 'fecha' => $m['fecha'], 'anulado' => (bool) $m['anulado'],
            ], self::movsCliente((int) $c['id'])),
        ]);
    }

    public static function cxcAbono(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idCli = Tenant::owns('clientes', $b['id_cliente'] ?? null, 'Cliente', true);
        $idBanco = Tenant::owns('bancos', $b['id_banco'] ?? null, 'Banco');
        $monto = num($b['monto'] ?? 0);
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        Db::begin();
        $c = Db::one('SELECT saldo_credito FROM clientes WHERE id = ? AND id_empresa = ? FOR UPDATE', [$idCli, $emp]);
        if ($monto > (float) $c['saldo_credito'] + 0.01) throw new ApiError('El abono excede el saldo del cliente (' . (float) $c['saldo_credito'] . ')', 400, 'VALIDATION');
        $movId = Ledger::clienteMov($emp, $idCli, 'abono', $b['concepto'] ?? 'Abono a cuenta', $monto, 'abono', 'MXN', $idBanco, 'Abono', null);
        Ledger::bancoDelta($emp, $idBanco, $monto);
        Db::commit();
        Ledger::audit($ctx, 'abono', 'CxC', $idCli, 'Abono ' . $monto);
        Http::created(['_id' => $movId, 'id_cliente' => $idCli, 'monto' => $monto], 'Abono');
    }

    // ===================== CUENTAS POR PAGAR (CxP) =====================
    public static function cxpListar(array $p, array $ctx): void
    {
        $rows = Db::all(
            "SELECT pr.id, pr.nombre, pr.rfc, m.moneda,
                    COALESCE(SUM(CASE WHEN m.tipo = 'cargo' THEN m.monto END), 0) cargos,
                    COALESCE(SUM(CASE WHEN m.tipo = 'pago'  THEN m.monto END), 0) pagos
             FROM proveedores pr
             JOIN proveedor_movimientos m ON m.id_empresa = pr.id_empresa AND m.id_proveedor = pr.id
             WHERE pr.id_empresa = ?
             GROUP BY pr.id, pr.nombre, pr.rfc, m.moneda
             ORDER BY pr.nombre", [Tenant::id()]);
        $byProv = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            $byProv[$pid] = $byProv[$pid] ?? ['_id' => $pid, 'nombre' => $r['nombre'], 'rfc' => $r['rfc'], 'saldos' => [
                'MXN' => ['cargos' => 0.0, 'pagos' => 0.0, 'saldo' => 0.0], 'USD' => ['cargos' => 0.0, 'pagos' => 0.0, 'saldo' => 0.0]]];
            $mon = $r['moneda'] === 'USD' ? 'USD' : 'MXN';
            $byProv[$pid]['saldos'][$mon] = ['cargos' => (float) $r['cargos'], 'pagos' => (float) $r['pagos'], 'saldo' => round((float) $r['cargos'] - (float) $r['pagos'], 2)];
        }
        Http::ok(array_values($byProv));
    }

    public static function cxpMovimientos(array $p, array $ctx): void
    {
        $idProv = Tenant::owns('proveedores', $p['id'], 'Proveedor', true);
        Http::ok(array_map(fn($m) => [
            '_id' => (int) $m['id'], 'fecha' => $m['fecha'], 'concepto' => $m['concepto'], 'moneda' => $m['moneda'],
            'tipo' => $m['tipo'], 'monto' => (float) $m['monto'],
        ], Db::all('SELECT * FROM proveedor_movimientos WHERE id_empresa = ? AND id_proveedor = ? ORDER BY fecha DESC, id DESC LIMIT 500', [Tenant::id(), $idProv])));
    }

    public static function cxpPago(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idProv = Tenant::owns('proveedores', $b['id_proveedor'] ?? null, 'Proveedor', true);
        $idBanco = Tenant::owns('bancos', $b['id_banco'] ?? null, 'Banco');
        $monto = num($b['monto'] ?? 0);
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        $moneda = ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        Db::begin();
        $movId = Ledger::proveedorMov($emp, $idProv, 'pago', $b['concepto'] ?? 'Pago a proveedor', $monto, $moneda, $idBanco, 'PagoProv', null);
        Ledger::bancoDelta($emp, $idBanco, -$monto);
        Db::commit();
        Ledger::audit($ctx, 'pago', 'CxP', $idProv, 'Pago ' . $monto . ' ' . $moneda);
        Http::created(['_id' => $movId, 'id_proveedor' => $idProv, 'monto' => $monto, 'moneda' => $moneda], 'Pago');
    }
}
