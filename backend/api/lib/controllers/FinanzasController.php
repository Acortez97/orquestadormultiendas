<?php
// ============================================================
// Finanzas de la tienda: cuentas bancarias, terminales, efectivo por almacen (cajas),
// cuentas por cobrar (clientes) y cuentas por pagar (proveedores).
// Donde esta el dinero: cada peso esta en una CAJA (efectivo de un almacen) o en una CUENTA.
// Los saldos salen de sus libros (caja_movimientos / banco_movimientos) y solo se mueven via Ledger/Cobros.
// ============================================================
class FinanzasController
{
    // ============================== CUENTAS BANCARIAS ==============================
    private static function fmtBanco(array $b): array
    {
        return ['_id' => (int) $b['id'], 'nombre' => $b['nombre'], 'moneda' => $b['moneda'], 'cuenta' => $b['cuenta'],
                'clabe' => $b['clabe'], 'saldo_actual' => (float) $b['saldo_actual'], 'is_active' => $b['is_active'],
                'terminales' => (int) ($b['terminales'] ?? 0)];
    }

    private static function banco(int $id): array
    {
        $b = Db::one('SELECT * FROM bancos WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$b) throw new ApiError('Cuenta no encontrada', 404, 'NOT_FOUND');
        return $b;
    }

    public static function bancosListar(array $p, array $ctx): void
    {
        $todas = ($_GET['is_active'] ?? '') === 'todos';
        $rows = array_map([self::class, 'fmtBanco'], Db::all(
            "SELECT b.*, (SELECT COUNT(*) FROM terminales t WHERE t.id_empresa = b.id_empresa AND t.id_banco = b.id AND t.is_active = 'Si') terminales
             FROM bancos b WHERE b.id_empresa = ?" . ($todas ? '' : " AND b.is_active = 'Si'") . ' ORDER BY b.nombre', [Tenant::id()]));
        // quien solo cobra (cajero) elige la cuenta destino: no ve saldos, numero de cuenta ni CLABE
        if (!Permisos::tiene($ctx, 'finanzas.ver') && !Permisos::tiene($ctx, 'compras.pagar')) {
            $rows = array_map(fn($b) => ['_id' => $b['_id'], 'nombre' => $b['nombre'], 'moneda' => $b['moneda'], 'is_active' => $b['is_active']], $rows);
        }
        Http::ok($rows);
    }

    public static function bancosCrear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $nombre = trim((string) ($b['nombre'] ?? ''));
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $inicial = num($b['saldo_inicial'] ?? 0);
        if ($inicial < 0) throw new ApiError('El saldo inicial no puede ser negativo', 400, 'VALIDATION');
        Db::begin();
        $id = Db::insert('INSERT INTO bancos (id_empresa, nombre, moneda, cuenta, clabe, saldo_actual) VALUES (?,?,?,?,?,0)',
            [$emp, $nombre, ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN', $b['cuenta'] ?? null, $b['clabe'] ?? null]);
        if ($inicial > 0) Ledger::bancoMov($emp, $id, 'ingreso', $inicial, 'Saldo inicial', null, null, null, 'SaldoInicial', null, $ctx['user']['id']);
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Banco', $id, $nombre);
        Http::created(self::fmtBanco(self::banco($id)), 'Cuenta');
    }

    public static function bancosEditar(array $p, array $ctx): void
    {
        $bn = self::banco((int) $p['id']);
        $b = Http::body();
        Db::run('UPDATE bancos SET nombre = ?, cuenta = ?, clabe = ?, is_active = ? WHERE id = ? AND id_empresa = ?', [
            array_key_exists('nombre', $b) && trim((string) $b['nombre']) !== '' ? trim((string) $b['nombre']) : $bn['nombre'],
            array_key_exists('cuenta', $b) ? $b['cuenta'] : $bn['cuenta'],
            array_key_exists('clabe', $b) ? $b['clabe'] : $bn['clabe'],
            array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $bn['is_active'],
            $bn['id'], Tenant::id()]);
        Http::updated(self::fmtBanco(self::banco((int) $bn['id'])), 'Cuenta');
    }

    /** Libro de una cuenta: cada ingreso / egreso con su origen */
    public static function bancoMovimientos(array $p, array $ctx): void
    {
        $bn = self::banco((int) $p['id']);
        $where = 'm.id_empresa = ? AND m.id_banco = ?'; $args = [Tenant::id(), (int) $bn['id']];
        if (!empty($_GET['desde'])) { $where .= ' AND m.fecha >= ?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND m.fecha <= ?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all(
            "SELECT m.*, t.nombre terminal, a.nombre almacen FROM banco_movimientos m
             LEFT JOIN terminales t ON t.id_empresa = m.id_empresa AND t.id = m.id_terminal
             LEFT JOIN almacenes a ON a.id_empresa = m.id_empresa AND a.id = m.id_almacen
             WHERE $where ORDER BY m.fecha DESC, m.id DESC LIMIT 1000", $args);
        Http::ok(['cuenta' => self::fmtBanco($bn), 'movimientos' => array_map(fn($m) => [
            '_id' => (int) $m['id'], 'fecha' => $m['fecha'], 'tipo' => $m['tipo'], 'monto' => (float) $m['monto'],
            'forma' => $m['forma'], 'terminal' => $m['terminal'], 'almacen' => $m['almacen'], 'concepto' => $m['concepto'],
        ], $rows)]);
    }

    /**
     * Movimiento manual de una cuenta:
     *  deposito  = efectivo de la caja de un almacen -> cuenta   (requiere id_almacen)
     *  retiro    = cuenta -> caja de un almacen                   (requiere id_almacen)
     *  ingreso / egreso = ajuste solo de la cuenta (comisiones bancarias, intereses, etc.)
     */
    public static function bancoMovimiento(array $p, array $ctx): void
    {
        $bn = self::banco((int) $p['id']);
        $b = Http::body();
        $emp = Tenant::id();
        $tipo = (string) ($b['tipo'] ?? '');
        $monto = num($b['monto'] ?? 0);
        if (!in_array($tipo, ['deposito', 'retiro', 'ingreso', 'egreso'], true)) throw new ApiError('Tipo de movimiento no valido', 400, 'VALIDATION');
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        if ($bn['is_active'] !== 'Si') throw new ApiError('La cuenta esta desactivada', 400, 'VALIDATION');
        $concepto = trim((string) ($b['concepto'] ?? '')) ?: ['deposito' => 'Deposito de efectivo', 'retiro' => 'Retiro a caja',
            'ingreso' => 'Ingreso', 'egreso' => 'Egreso'][$tipo];
        $idAlm = in_array($tipo, ['deposito', 'retiro'], true) ? Tenant::owns('almacenes', $b['id_almacen'] ?? null, 'Almacen', true) : null;
        $idUser = $ctx['user']['id'];
        Db::begin();
        if ($tipo === 'deposito') {
            if ($bn['moneda'] !== 'MXN') throw new ApiError('El efectivo de caja (pesos) solo se deposita en cuentas en pesos', 400, 'VALIDATION');
            $saldoCaja = round(self::saldoCaja($emp, $idAlm, true), 2);
            if ($monto > $saldoCaja + 0.001) throw new ApiError("La caja solo tiene $saldoCaja en efectivo", 400, 'VALIDATION');
            Ledger::cajaMov($emp, $idAlm, 'egreso', $monto, $concepto . ' a ' . $bn['nombre'], 'Deposito', (int) $bn['id'], $idUser);
            Ledger::bancoMov($emp, (int) $bn['id'], 'ingreso', $monto, $concepto, 'efectivo', null, $idAlm, 'Deposito', null, $idUser);
        } elseif ($tipo === 'retiro') {
            $saldoCuenta = (float) Db::one('SELECT saldo_actual FROM bancos WHERE id = ? AND id_empresa = ? FOR UPDATE', [(int) $bn['id'], $emp])['saldo_actual'];
            if ($bn['moneda'] !== 'MXN') throw new ApiError('Solo se retira efectivo a caja de cuentas en pesos', 400, 'VALIDATION');
            if ($monto > $saldoCuenta + 0.001) throw new ApiError('La cuenta no tiene saldo suficiente', 400, 'VALIDATION');
            Ledger::bancoMov($emp, (int) $bn['id'], 'egreso', $monto, $concepto, 'efectivo', null, $idAlm, 'Retiro', null, $idUser);
            Ledger::cajaMov($emp, $idAlm, 'ingreso', $monto, $concepto . ' de ' . $bn['nombre'], 'Retiro', (int) $bn['id'], $idUser);
        } else {
            Ledger::bancoMov($emp, (int) $bn['id'], $tipo, $monto, $concepto, null, null, null, 'Ajuste', null, $idUser);
        }
        Db::commit();
        Ledger::audit($ctx, $tipo, 'Banco', $bn['id'], "$concepto: $monto");
        Http::created(self::fmtBanco(self::banco((int) $bn['id'])), 'Movimiento');
    }

    // ============================== TERMINALES ==============================
    private static function fmtTerminal(array $t): array
    {
        return ['_id' => (int) $t['id'], 'nombre' => $t['nombre'], 'proveedor' => $t['proveedor'],
                'id_banco' => ['_id' => (int) $t['id_banco'], 'nombre' => $t['banco_nombre'] ?? null],
                'comision_pct' => (float) $t['comision_pct'], 'is_active' => $t['is_active']];
    }

    private static function terminales(?int $id = null, bool $todas = false): array
    {
        $where = 't.id_empresa = ?'; $args = [Tenant::id()];
        if ($id) { $where .= ' AND t.id = ?'; $args[] = $id; }
        elseif (!$todas) $where .= " AND t.is_active = 'Si'";
        return Db::all("SELECT t.*, b.nombre banco_nombre FROM terminales t JOIN bancos b ON b.id_empresa = t.id_empresa AND b.id = t.id_banco
                        WHERE $where ORDER BY t.nombre", $args);
    }

    public static function terminalesListar(array $p, array $ctx): void
    {
        Http::ok(array_map([self::class, 'fmtTerminal'], self::terminales(null, ($_GET['is_active'] ?? '') === 'todos')));
    }

    private static function datosTerminal(array $b, ?array $actual = null): array
    {
        $nombre = array_key_exists('nombre', $b) ? trim((string) $b['nombre']) : ($actual['nombre'] ?? '');
        if ($nombre === '') throw new ApiError('El nombre es obligatorio', 400, 'VALIDATION');
        $idBanco = array_key_exists('id_banco', $b) ? Tenant::owns('bancos', $b['id_banco'], 'Cuenta', true) : (int) $actual['id_banco'];
        $com = array_key_exists('comision_pct', $b) ? num($b['comision_pct']) : (float) ($actual['comision_pct'] ?? 0);
        if ($com < 0 || $com >= 100) throw new ApiError('La comision debe estar entre 0 y 99.99 %', 400, 'VALIDATION');
        return [$nombre, array_key_exists('proveedor', $b) ? $b['proveedor'] : ($actual['proveedor'] ?? null), $idBanco, $com,
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : ($actual['is_active'] ?? 'Si')];
    }

    public static function terminalesCrear(array $p, array $ctx): void
    {
        [$nombre, $prov, $idBanco, $com, $act] = self::datosTerminal(Http::body());
        $id = Db::insert('INSERT INTO terminales (id_empresa, nombre, proveedor, id_banco, comision_pct, is_active) VALUES (?,?,?,?,?,?)',
            [Tenant::id(), $nombre, $prov, $idBanco, $com, $act]);
        Ledger::audit($ctx, 'crear', 'Terminal', $id, $nombre);
        Http::created(self::fmtTerminal(self::terminales($id)[0]), 'Terminal');
    }

    public static function terminalesEditar(array $p, array $ctx): void
    {
        $t = self::terminales(Tenant::owns('terminales', $p['id'], 'Terminal', true))[0];
        [$nombre, $prov, $idBanco, $com, $act] = self::datosTerminal(Http::body(), $t);
        Db::run('UPDATE terminales SET nombre = ?, proveedor = ?, id_banco = ?, comision_pct = ?, is_active = ? WHERE id = ? AND id_empresa = ?',
            [$nombre, $prov, $idBanco, $com, $act, $t['id'], Tenant::id()]);
        Http::updated(self::fmtTerminal(self::terminales((int) $t['id'])[0]), 'Terminal');
    }

    // ============================== CAJAS (efectivo por almacen) ==============================
    /**
     * Efectivo de la caja de un almacen. $bloquear = true (dentro de una transaccion) bloquea el almacen
     * para que dos salidas de efectivo simultaneas no saquen mas de lo que hay.
     */
    public static function saldoCaja(int $emp, int $idAlm, bool $bloquear = false): float
    {
        if ($bloquear) Db::one('SELECT id FROM almacenes WHERE id = ? AND id_empresa = ? FOR UPDATE', [$idAlm, $emp]);
        return (float) Db::one("SELECT COALESCE(SUM(CASE tipo WHEN 'ingreso' THEN monto ELSE -monto END), 0) s
                                FROM caja_movimientos WHERE id_empresa = ? AND id_almacen = ?", [$emp, $idAlm])['s'];
    }

    /** Efectivo que debe haber en cada almacen (acumulado de su libro de caja) */
    public static function cajas(array $p, array $ctx): void
    {
        Http::ok(array_map(fn($r) => [
            '_id' => (int) $r['id'], 'codigo' => $r['codigo'], 'nombre' => $r['nombre'], 'saldo' => round((float) $r['saldo'], 2),
            'ultimo_corte' => $r['ultimo_corte'],
        ], Db::all(
            "SELECT a.id, a.codigo, a.nombre,
                    (SELECT COALESCE(SUM(CASE m.tipo WHEN 'ingreso' THEN m.monto ELSE -m.monto END), 0)
                       FROM caja_movimientos m WHERE m.id_empresa = a.id_empresa AND m.id_almacen = a.id) saldo,
                    (SELECT MAX(c.fecha) FROM cortes c WHERE c.id_empresa = a.id_empresa AND c.id_almacen = a.id) ultimo_corte
             FROM almacenes a WHERE a.id_empresa = ? AND a.is_active = 'Si' ORDER BY a.nombre", [Tenant::id()])));
    }

    public static function cajaMovimientos(array $p, array $ctx): void
    {
        $idAlm = Tenant::owns('almacenes', $p['id'], 'Almacen', true);
        $where = 'm.id_empresa = ? AND m.id_almacen = ?'; $args = [Tenant::id(), $idAlm];
        if (!empty($_GET['desde'])) { $where .= ' AND m.fecha >= ?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND m.fecha <= ?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        Http::ok(['saldo' => round(self::saldoCaja(Tenant::id(), $idAlm), 2), 'movimientos' => array_map(fn($m) => [
            '_id' => (int) $m['id'], 'fecha' => $m['fecha'], 'tipo' => $m['tipo'], 'monto' => (float) $m['monto'],
            'concepto' => $m['concepto'], 'usuario' => trim(($m['nombre'] ?? '') . ' ' . ($m['apellido'] ?? '')) ?: null,
        ], Db::all("SELECT m.*, u.nombre, u.apellido FROM caja_movimientos m
                    LEFT JOIN users u ON u.id_empresa = m.id_empresa AND u.id = m.id_usuario
                    WHERE $where ORDER BY m.fecha DESC, m.id DESC LIMIT 1000", $args))]);
    }

    /** Entrada o salida manual de efectivo de una caja (gastos menores, fondo de cambio, etc.) */
    public static function cajaMovimiento(array $p, array $ctx): void
    {
        $idAlm = Tenant::owns('almacenes', $p['id'], 'Almacen', true);
        $b = Http::body();
        $tipo = (string) ($b['tipo'] ?? '');
        $monto = num($b['monto'] ?? 0);
        $concepto = trim((string) ($b['concepto'] ?? ''));
        if (!in_array($tipo, ['ingreso', 'egreso'], true)) throw new ApiError('Tipo de movimiento no valido', 400, 'VALIDATION');
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        if ($concepto === '') throw new ApiError('Indica el concepto', 400, 'VALIDATION');
        $emp = Tenant::id();
        Db::begin();
        if ($tipo === 'egreso' && $monto > self::saldoCaja($emp, $idAlm, true) + 0.001) throw new ApiError('La caja no tiene ese efectivo', 400, 'VALIDATION');
        Ledger::cajaMov($emp, $idAlm, $tipo, $monto, $concepto, 'Manual', null, $ctx['user']['id']);
        Db::commit();
        Ledger::audit($ctx, $tipo, 'Caja', $idAlm, "$concepto: $monto");
        Http::created(['saldo' => round(self::saldoCaja($emp, $idAlm), 2)], 'Movimiento');
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
            'tipo' => $m['efecto'] === 'cargo' ? 'cargo' : 'pago', 'monto' => (float) $m['monto'], 'forma' => $m['forma'],
        ], self::movsCliente($idCli)));
    }

    public static function cxcEstadoCuenta(array $p, array $ctx): void
    {
        $c = ClienteController::cliente((int) $p['id']);
        Http::ok([
            'cliente' => ['saldo_credito' => (float) $c['saldo_credito'], 'saldo_favor' => (float) $c['saldo_favor']],
            'movimientos' => array_map(fn($m) => [
                '_id' => (int) $m['id'], 'tipo' => $m['tipo'], 'concepto' => $m['concepto'], 'monto' => (float) $m['monto'],
                'efecto' => $m['efecto'], 'fecha' => $m['fecha'], 'anulado' => (bool) $m['anulado'], 'forma' => $m['forma'],
            ], self::movsCliente((int) $c['id'])),
        ]);
    }

    /** Abono a la cuenta de un cliente. El dinero entra a la caja del almacen (efectivo) o a la cuenta/terminal. */
    public static function cxcAbono(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idCli = Tenant::owns('clientes', $b['id_cliente'] ?? null, 'Cliente', true);
        $monto = num($b['monto'] ?? 0);
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        // compatibilidad: si solo llega id_banco, es una transferencia
        $forma = (string) ($b['forma'] ?? (!empty($b['id_banco']) ? 'transferencia' : 'efectivo'));
        if (!in_array($forma, ['efectivo', 'tdc', 'tdb', 'transferencia', 'cheque'], true)) throw new ApiError('Forma de pago no valida', 400, 'VALIDATION');
        [$idBanco, $idTerminal] = Cobros::destino($forma, $b['id_banco'] ?? null, $b['id_terminal'] ?? null);
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen');
        if (!$idAlm) throw new ApiError('Indica la tienda donde se recibio el abono', 400, 'VALIDATION');

        Db::begin();
        $c = Db::one('SELECT saldo_credito FROM clientes WHERE id = ? AND id_empresa = ? FOR UPDATE', [$idCli, $emp]);
        if ($monto > (float) $c['saldo_credito'] + 0.001) throw new ApiError('El abono excede el saldo del cliente (' . (float) $c['saldo_credito'] . ')', 400, 'VALIDATION');
        $movId = Ledger::clienteMov($emp, $idCli, 'abono', $b['concepto'] ?? 'Abono a cuenta', $monto, 'abono', 'MXN', $idBanco, 'Abono', null);
        Db::run('UPDATE cliente_movimientos SET forma = ?, id_terminal = ?, id_almacen = ? WHERE id = ? AND id_empresa = ?', [$forma, $idTerminal, $idAlm, $movId, $emp]);
        Cobros::entrada($emp, $forma, $monto, $idBanco, $idTerminal, $idAlm, 'Abono CxC', 'Abono', $movId, $ctx['user']['id']);
        Db::commit();
        Ledger::audit($ctx, 'abono', 'CxC', $idCli, 'Abono ' . $monto . ' ' . $forma);
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
            'tipo' => $m['tipo'], 'monto' => (float) $m['monto'], 'forma' => $m['forma'],
            'tipo_cambio' => $m['tipo_cambio'] !== null ? (float) $m['tipo_cambio'] : null,
        ], Db::all('SELECT * FROM proveedor_movimientos WHERE id_empresa = ? AND id_proveedor = ? ORDER BY fecha DESC, id DESC LIMIT 500', [Tenant::id(), $idProv])));
    }

    /**
     * Pago directo a proveedor: sale de la caja de un almacen (efectivo) o de una cuenta (transferencia / cheque).
     * 'moneda' es la de la deuda con el proveedor. Si el dinero sale de una caja / cuenta en otra moneda se pide el
     * tipo de cambio (pesos por dolar): al proveedor se le abona 'monto' y de la caja / cuenta sale lo convertido.
     */
    public static function cxpPago(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idProv = Tenant::owns('proveedores', $b['id_proveedor'] ?? null, 'Proveedor', true);
        $monto = num($b['monto'] ?? 0);
        if ($monto <= 0) throw new ApiError('El monto debe ser mayor a cero', 400, 'VALIDATION');
        $moneda = ($b['moneda'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        $forma = (string) ($b['forma'] ?? (!empty($b['id_banco']) ? 'transferencia' : 'efectivo'));
        if (!in_array($forma, ['efectivo', 'transferencia', 'cheque'], true)) throw new ApiError('Forma de pago no valida (efectivo, transferencia o cheque)', 400, 'VALIDATION');
        [$idBanco] = Cobros::destino($forma, $b['id_banco'] ?? null, null, null);
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? $ctx['user']['id_tienda'] ?? null, 'Almacen');
        if ($forma === 'efectivo' && !$idAlm) throw new ApiError('Indica de que caja (almacen) sale el efectivo', 400, 'VALIDATION');
        $monedaOrigen = $idBanco ? (Db::one('SELECT moneda FROM bancos WHERE id = ? AND id_empresa = ?', [$idBanco, $emp])['moneda'] ?? 'MXN') : 'MXN';
        $tc = null; $salida = $monto;
        if ($monedaOrigen !== $moneda) {
            $tc = num($b['tipo_cambio'] ?? 0);
            if ($tc <= 0) throw new ApiError("Indica el tipo de cambio: el pago es en $moneda y sale de " . ($idBanco ? "una cuenta en $monedaOrigen" : 'la caja (pesos)'), 400, 'VALIDATION');
            $salida = round($moneda === 'USD' ? $monto * $tc : $monto / $tc, 2);
            if ($salida <= 0) throw new ApiError('El monto convertido debe ser mayor a cero', 400, 'VALIDATION');
        }
        $concepto = trim((string) ($b['concepto'] ?? '')) ?: 'Pago a proveedor';
        $conceptoSalida = $tc ? "$concepto ($moneda " . number_format($monto, 2) . " a TC " . rtrim(rtrim(number_format($tc, 4, '.', ''), '0'), '.') . ')' : $concepto;

        Db::begin();
        if ($forma === 'efectivo' && $salida > self::saldoCaja($emp, $idAlm, true) + 0.001) throw new ApiError('La caja no tiene ese efectivo', 400, 'VALIDATION');
        $movId = Ledger::proveedorMov($emp, $idProv, 'pago', $concepto, $monto, $moneda, $idBanco, 'PagoProv', null);
        Db::run('UPDATE proveedor_movimientos SET forma = ?, id_almacen = ?, tipo_cambio = ? WHERE id = ? AND id_empresa = ?', [$forma, $idAlm, $tc, $movId, $emp]);
        Cobros::salida($emp, $forma, $salida, $idBanco, null, $idAlm, $conceptoSalida, 'PagoProv', $movId, $ctx['user']['id']);
        Db::commit();
        Ledger::audit($ctx, 'pago', 'CxP', $idProv, 'Pago ' . $monto . ' ' . $moneda . ' ' . $forma . ($tc ? " TC $tc" : ''));
        Http::created(['_id' => $movId, 'id_proveedor' => $idProv, 'monto' => $monto, 'moneda' => $moneda,
                       'tipo_cambio' => $tc, 'monto_salida' => $salida, 'moneda_salida' => $monedaOrigen], 'Pago');
    }
}
