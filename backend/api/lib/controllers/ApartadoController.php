<?php
// Apartados: reservan stock, reciben anticipos y al liquidarse generan la venta (todo en una transaccion).
class ApartadoController
{
    const DIAS_LIMITE = 7;
    const FORMAS_ANTICIPO = ['efectivo', 'tdc', 'tdb', 'transferencia', 'tarjeta', 'cheque', 'otro'];

    /** Reserva (delta > 0) o libera (delta < 0) stock en la celda; al reservar valida el disponible */
    private static function reservar(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm, float $delta, string $codigo = ''): void
    {
        if ($delta > 0) {
            $disp = InventarioController::disponible($emp, $idArt, $v1, $v2, $idAlm);
            if ($delta > $disp + 0.0001) throw new ApiError("Stock insuficiente para apartar $codigo (disponible $disp)", 400, 'VALIDATION');
        }
        InventarioController::reservar($emp, $idArt, $v1, $v2, $idAlm, $delta);
    }

    private static function liberarReserva(int $emp, array $a): void
    {
        foreach (Db::all('SELECT * FROM apartado_lineas WHERE id_empresa = ? AND id_apartado = ?', [$emp, (int) $a['id']]) as $l) {
            self::reservar($emp, (int) $l['id_articulo'], Variantes::norm($l['id_valor1']), Variantes::norm($l['id_valor2']),
                (int) $a['id_almacen'], -1 * (float) $l['cantidad']);
        }
    }

    private static function guardarLineas(int $emp, int $aptId, int $idAlm, array $lineasCotizadas): void
    {
        foreach ($lineasCotizadas as $ln) {
            $v1 = Variantes::norm($ln['id_color']); $v2 = Variantes::norm($ln['id_talla']);
            Db::insert('INSERT INTO apartado_lineas (id_empresa, id_apartado, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad, precio_unitario, importe)
                        VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$emp, $aptId, (int) $ln['id_articulo'], $v1, $v2, $ln['codigo'], $ln['descripcion'], $ln['cantidad'], $ln['precio_unitario'], $ln['importe']]);
            self::reservar($emp, (int) $ln['id_articulo'], $v1, $v2, $idAlm, (float) $ln['cantidad'], $ln['codigo']);
        }
    }

    private static function fmtLineas(int $emp, int $idApartado): array
    {
        $rows = Db::all('SELECT * FROM apartado_lineas WHERE id_empresa = ? AND id_apartado = ? ORDER BY id', [$emp, $idApartado]);
        $nom = Variantes::nombres(array_merge(array_column($rows, 'id_valor1'), array_column($rows, 'id_valor2')));
        return array_map(fn($l) => [
            'id_articulo'     => ['_id' => (int) $l['id_articulo']],
            'codigo'          => $l['codigo'],
            'descripcion'     => $l['descripcion'],
            'id_color'        => Variantes::ref($l['id_valor1'], $nom[(int) $l['id_valor1']] ?? null, null, 0, 'Unico'),
            'id_talla'        => Variantes::ref($l['id_valor2'], $nom[(int) $l['id_valor2']] ?? null, null, 0, 'Unica'),
            'cantidad'        => (float) $l['cantidad'],
            'precio_unitario' => (float) $l['precio_unitario'],
            'importe'         => (float) $l['importe'],
        ], $rows);
    }

    private static function estadoMostrado(array $a): string
    {
        if (in_array($a['estado'], ['vigente', 'con_anticipo'], true) && $a['fecha_limite'] && $a['fecha_limite'] < date('Y-m-d')) return 'vencido';
        return $a['estado'];
    }

    public static function obtenerApartado(int $emp, int $id): array
    {
        $a = Db::one(
            'SELECT a.*, c.nombre cli_nombre, al.nombre alm_nombre, e.nombre vend_nombre, e.apellido vend_apellido
             FROM apartados a
             JOIN clientes c ON c.id_empresa = a.id_empresa AND c.id = a.id_cliente
             JOIN almacenes al ON al.id_empresa = a.id_empresa AND al.id = a.id_almacen
             LEFT JOIN empleados e ON e.id_empresa = a.id_empresa AND e.id = a.id_vendedor
             WHERE a.id = ? AND a.id_empresa = ?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        return [
            '_id'          => (int) $a['id'],
            'folio'        => $a['folio'],
            'fecha'        => $a['fecha'],
            'fecha_limite' => $a['fecha_limite'],
            'id_cliente'   => ['_id' => (int) $a['id_cliente'], 'nombre' => $a['cli_nombre']],
            'id_almacen'   => ['_id' => (int) $a['id_almacen'], 'nombre' => $a['alm_nombre']],
            'id_vendedor'  => $a['id_vendedor'] ? ['_id' => (int) $a['id_vendedor'], 'nombre' => $a['vend_nombre'], 'apellido' => $a['vend_apellido']] : null,
            'total'        => (float) $a['total'],
            'anticipo'     => (float) $a['anticipo'],
            'estado'       => self::estadoMostrado($a),
            'id_venta'     => $a['id_venta'] ? (int) $a['id_venta'] : null,
            'notas'        => $a['notas'],
            'lineas'       => self::fmtLineas($emp, $id),
            'createdAt'    => $a['created_at'],
        ];
    }

    private static function bloquear(int $id, array $estados, string $msg): array
    {
        $a = Db::one('SELECT * FROM apartados WHERE id = ? AND id_empresa = ? FOR UPDATE', [$id, Tenant::id()]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        if (!in_array($a['estado'], $estados, true)) throw new ApiError($msg, 400, 'VALIDATION');
        return $a;
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 'a.id_empresa = ?'; $args = [$emp];
        if (!empty($_GET['id_cliente']))  { $where .= ' AND a.id_cliente = ?';  $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['id_vendedor'])) { $where .= ' AND a.id_vendedor = ?'; $args[] = (int) $_GET['id_vendedor']; }
        if (!empty($_GET['estado']))      { $where .= ' AND a.estado = ?';      $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))       { $where .= ' AND a.fecha >= ?';      $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))       { $where .= ' AND a.fecha <= ?';      $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT a.id FROM apartados a WHERE $where ORDER BY a.fecha DESC, a.id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($r) => self::obtenerApartado($emp, (int) $r['id']), $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerApartado(Tenant::id(), (int) $p['id']));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $idCli = Tenant::owns('clientes', $b['id_cliente'] ?? null, 'Cliente', true);
        $idAlm = Tenant::owns('almacenes', $b['id_almacen'] ?? null, 'Almacen', true);
        $idVend = Tenant::owns('empleados', $b['id_vendedor'] ?? null, 'Vendedor');
        $lineasIn = $b['lineas'] ?? [];
        if (!is_array($lineasIn) || count($lineasIn) === 0) throw new ApiError('El apartado no tiene lineas', 400, 'VALIDATION');
        $q = Pricing::cotizar($emp, $idCli, $lineasIn, (float) Tenant::empresa()['iva']);

        Db::begin();
        $folio = Db::folio($emp, 'apartado', 'APT', 5);
        $aptId = Db::insert(
            "INSERT INTO apartados (id_empresa, folio, fecha, fecha_limite, id_cliente, id_almacen, id_vendedor, id_usuario, total, anticipo, estado, notas)
             VALUES (?,?,NOW(),DATE_ADD(CURDATE(), INTERVAL ? DAY),?,?,?,?,?,0,'vigente',?)",
            [$emp, $folio, self::DIAS_LIMITE, $idCli, $idAlm, $idVend, $ctx['user']['id'], $q['total'], $b['notas'] ?? null]);
        self::guardarLineas($emp, $aptId, $idAlm, $q['lineas']);
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Apartado', $aptId, $folio);
        Http::created(self::obtenerApartado($emp, $aptId), 'Apartado');
    }

    public static function editar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $a = self::bloquear($id, ['vigente', 'con_anticipo'], 'Solo se puede editar un apartado vigente');
        $lineasIn = $b['lineas'] ?? null;
        if (is_array($lineasIn)) {
            if (count($lineasIn) === 0) throw new ApiError('El apartado no tiene lineas', 400, 'VALIDATION');
            self::liberarReserva($emp, $a);
            Db::run('DELETE FROM apartado_lineas WHERE id_empresa = ? AND id_apartado = ?', [$emp, $id]);
            $q = Pricing::cotizar($emp, (int) $a['id_cliente'], $lineasIn, (float) Tenant::empresa()['iva']);
            if ($q['total'] + 0.01 < (float) $a['anticipo']) throw new ApiError('El nuevo total es menor que lo ya anticipado', 400, 'VALIDATION');
            self::guardarLineas($emp, $id, (int) $a['id_almacen'], $q['lineas']);
            Db::run('UPDATE apartados SET total = ? WHERE id = ? AND id_empresa = ?', [$q['total'], $id, $emp]);
        }
        if (array_key_exists('notas', $b)) Db::run('UPDATE apartados SET notas = ? WHERE id = ? AND id_empresa = ?', [$b['notas'], $id, $emp]);
        Db::commit();
        Http::updated(self::obtenerApartado($emp, $id), 'Apartado');
    }

    public static function anticipo(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        $importe = num($b['importe'] ?? 0);
        $forma = (string) ($b['forma'] ?? 'efectivo');
        if ($importe <= 0) throw new ApiError('El importe debe ser mayor a cero', 400, 'VALIDATION');
        if (!in_array($forma, self::FORMAS_ANTICIPO, true)) throw new ApiError('Forma de pago no valida', 400, 'VALIDATION');
        Db::begin();
        $a = self::bloquear($id, ['vigente', 'con_anticipo'], 'El apartado no admite anticipos');
        $restante = round((float) $a['total'] - (float) $a['anticipo'], 2);
        if ($importe > $restante + 0.0001) throw new ApiError("El anticipo excede el saldo restante ($restante)", 400, 'VALIDATION');
        Db::insert('INSERT INTO apartado_anticipos (id_empresa, id_apartado, fecha, forma, importe, id_usuario) VALUES (?,?,NOW(),?,?,?)',
            [$emp, $id, $forma, $importe, $ctx['user']['id']]);
        Db::run("UPDATE apartados SET anticipo = anticipo + ?, estado = 'con_anticipo' WHERE id = ? AND id_empresa = ?", [$importe, $id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'anticipo', 'Apartado', $id, 'Anticipo ' . $importe);
        Http::ok(self::obtenerApartado($emp, $id), 'Anticipo registrado');
    }

    /** Liquida: libera la reserva, genera la venta (anticipo + pagos) y marca el apartado. Una sola transaccion. */
    public static function liquidar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        $pagos = is_array($b['pagos'] ?? null) ? $b['pagos'] : [];
        $pagado = 0.0;
        foreach ($pagos as $pg) $pagado += num($pg['importe'] ?? 0);

        Db::begin();
        try {
            $a = self::bloquear($id, ['vigente', 'con_anticipo'], 'El apartado no se puede liquidar');
            $restante = round((float) $a['total'] - (float) $a['anticipo'], 2);
            if ($pagado + 0.01 < $restante) throw new ApiError("El pago ($pagado) no cubre el restante ($restante)", 400, 'VALIDATION');

            $lineasVenta = array_map(fn($l) => [
                'id_articulo' => (int) $l['id_articulo'], 'id_color' => $l['id_valor1'], 'id_talla' => $l['id_valor2'], 'cantidad' => (float) $l['cantidad'],
            ], Db::all('SELECT * FROM apartado_lineas WHERE id_empresa = ? AND id_apartado = ?', [$emp, $id]));
            $pagosVenta = [];
            if ((float) $a['anticipo'] > 0) $pagosVenta[] = ['forma' => 'anticipo', 'importe' => (float) $a['anticipo']];
            foreach ($pagos as $pg) {
                if (($pg['forma'] ?? '') === 'anticipo') throw new ApiError('Forma de pago no valida', 400, 'VALIDATION');
                $pagosVenta[] = $pg;
            }

            self::liberarReserva($emp, $a);   // la venta descuenta la cantidad real
            $ventaId = VentaController::registrar([
                'id_almacen'  => (int) $a['id_almacen'],
                'id_cliente'  => (int) $a['id_cliente'],
                'id_vendedor' => $a['id_vendedor'] ? (int) $a['id_vendedor'] : null,
                'lineas'      => $lineasVenta,
                'pagos'       => $pagosVenta,
                'a_credito'   => false,
                'destino_cambio' => ($b['destino_cambio'] ?? 'monedero'),
                'notas'       => 'Liquidacion apartado ' . $a['folio'],
            ], $ctx, true, ['anticipo']);
            Db::run("UPDATE apartados SET estado = 'liquidado', id_venta = ? WHERE id = ? AND id_empresa = ?", [$ventaId, $id, $emp]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'liquidar', 'Apartado', $id, 'Liquidacion ' . $a['folio']);
        $out = self::obtenerApartado($emp, $id);
        $out['generatedVentaId'] = $ventaId;
        Http::ok($out, 'Apartado liquidado y venta generada');
    }

    public static function cancelar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $a = self::bloquear($id, ['vigente', 'con_anticipo'], 'El apartado no se puede cancelar');
        self::liberarReserva($emp, $a);
        // el anticipo se abona al monedero del cliente (no se devuelve efectivo)
        if ((float) $a['anticipo'] > 0) {
            Ledger::monederoMov($emp, (int) $a['id_cliente'], (float) $a['anticipo'], 'deposito', 'Anticipo apartado cancelado ' . $a['folio'], 'Apartado', $id);
        }
        Db::run("UPDATE apartados SET estado = 'cancelado' WHERE id = ? AND id_empresa = ?", [$id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'cancelar', 'Apartado', $id, $a['folio']);
        Http::ok(self::obtenerApartado($emp, $id), 'Apartado cancelado');
    }
}
