<?php
class ApartadoController
{
    const DIAS_LIMITE = 7;

    private static function ivaEmpresa(int $emp): float
    {
        $e = Db::one('SELECT iva FROM empresas WHERE id=?', [$emp]);
        return $e ? (float) $e['iva'] : 0.16;
    }

    private static function nextFolio(int $emp): string
    {
        $n = (int) (Db::one('SELECT COUNT(*) c FROM apartados WHERE id_empresa=?', [$emp])['c'] ?? 0) + 1;
        return 'APT-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    /** Reserva (delta>0) o libera (delta<0) stock en la celda. Bloquea si no hay disponible al reservar. */
    private static function reservar(int $emp, int $idArt, int $idColor, int $idTalla, int $idAlm, float $delta, ?string $codigo = null): void
    {
        $inv = Db::one('SELECT * FROM inventario WHERE id_articulo=? AND id_color=? AND id_talla=? AND id_almacen=?',
            [$idArt, $idColor, $idTalla, $idAlm]);
        if (!$inv) {
            $id = Db::insert('INSERT INTO inventario (id_empresa,id_articulo,id_color,id_talla,id_almacen,cantidad,reservado) VALUES (?,?,?,?,?,0,0)',
                [$emp, $idArt, $idColor, $idTalla, $idAlm]);
            $inv = Db::one('SELECT * FROM inventario WHERE id=?', [$id]);
        }
        if ($delta > 0) {
            $disponible = (float) $inv['cantidad'] - (float) $inv['reservado'];
            if ($delta > $disponible + 0.0001) {
                throw new ApiError("Stock insuficiente para apartar {$codigo} (disponible $disponible)", 400, 'VALIDATION');
            }
        }
        $nuevo = max(0, round((float) $inv['reservado'] + $delta, 2));
        Db::run('UPDATE inventario SET reservado=? WHERE id=?', [$nuevo, $inv['id']]);
    }

    private static function liberarReserva(int $emp, int $idApartado): void
    {
        $ap = Db::one('SELECT id_almacen FROM apartados WHERE id=?', [$idApartado]);
        foreach (Db::all('SELECT * FROM apartado_lineas WHERE id_apartado=?', [$idApartado]) as $l) {
            self::reservar($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                (int) $ap['id_almacen'], -1 * (float) $l['cantidad']);
        }
    }

    private static function fmtLineas(int $idApartado): array
    {
        $rows = Db::all(
            'SELECT al.*, c.nombre col_nombre, t.nombre tal_nombre
             FROM apartado_lineas al
             LEFT JOIN atributo_valores c ON c.id=al.id_color
             LEFT JOIN atributo_valores t ON t.id=al.id_talla
             WHERE al.id_apartado=?', [$idApartado]);
        return array_map(fn($l) => [
            'id_articulo'     => ['_id' => (string) $l['id_articulo']],
            'codigo'          => $l['codigo'],
            'descripcion'     => $l['descripcion'],
            'id_color'        => (int) $l['id_color'] === 0 ? ['_id' => '', 'nombre' => 'Unico'] : ['_id' => (string) $l['id_color'], 'nombre' => $l['col_nombre']],
            'id_talla'        => (int) $l['id_talla'] === 0 ? ['_id' => '', 'nombre' => 'Unica'] : ['_id' => (string) $l['id_talla'], 'nombre' => $l['tal_nombre']],
            'cantidad'        => (float) $l['cantidad'],
            'precio_unitario' => (float) $l['precio_unitario'],
            'importe'         => (float) $l['importe'],
        ], $rows);
    }

    private static function estadoMostrado(array $a): string
    {
        $e = $a['estado'];
        if (in_array($e, ['vigente', 'con_anticipo'], true) && $a['fecha_limite'] && $a['fecha_limite'] < date('Y-m-d')) {
            return 'vencido';
        }
        return $e;
    }

    public static function obtenerApartado(int $emp, int $id): array
    {
        $a = Db::one(
            'SELECT a.*, c.nombre cli_nombre, al.nombre alm_nombre, e.nombre vend_nombre, e.apellido vend_apellido
             FROM apartados a
             JOIN clientes c ON c.id=a.id_cliente
             JOIN almacenes al ON al.id=a.id_almacen
             LEFT JOIN empleados e ON e.id=a.id_vendedor
             WHERE a.id=? AND a.id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        return [
            '_id'          => (string) $a['id'],
            'folio'        => $a['folio'],
            'fecha'        => $a['fecha'],
            'fecha_limite' => $a['fecha_limite'],
            'id_cliente'   => ['_id' => (string) $a['id_cliente'], 'nombre' => $a['cli_nombre']],
            'id_almacen'   => ['_id' => (string) $a['id_almacen'], 'nombre' => $a['alm_nombre']],
            'id_vendedor'  => $a['id_vendedor'] ? ['_id' => (string) $a['id_vendedor'], 'nombre' => $a['vend_nombre'], 'apellido' => $a['vend_apellido']] : null,
            'total'        => (float) $a['total'],
            'anticipo'     => (float) $a['anticipo'],
            'estado'       => self::estadoMostrado($a),
            'id_venta'     => $a['id_venta'] ? (string) $a['id_venta'] : null,
            'notas'        => $a['notas'],
            'lineas'       => self::fmtLineas($id),
            'createdAt'    => $a['created_at'],
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'a.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_cliente']))  { $where .= ' AND a.id_cliente=?';  $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['id_vendedor'])) { $where .= ' AND a.id_vendedor=?'; $args[] = (int) $_GET['id_vendedor']; }
        if (!empty($_GET['estado']))      { $where .= ' AND a.estado=?';      $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))       { $where .= ' AND a.fecha>=?';      $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))       { $where .= ' AND a.fecha<=?';      $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT a.id FROM apartados a WHERE $where ORDER BY a.fecha DESC, a.id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($r) => self::obtenerApartado($emp, (int) $r['id']), $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerApartado((int) $ctx['user']['id_empresa'], (int) $p['id']));
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idCli = id_or_null($b['id_cliente'] ?? null);
        $idAlm = id_or_null($b['id_almacen'] ?? null);
        if (!$idCli) throw new ApiError('id_cliente es obligatorio', 400, 'VALIDATION');
        if (!$idAlm) throw new ApiError('id_almacen es obligatorio', 400, 'VALIDATION');
        if (!Db::one('SELECT id FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp])) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        if (!Db::one('SELECT id FROM almacenes WHERE id=? AND id_empresa=?', [$idAlm, $emp])) throw new ApiError('Almacen no encontrado', 404, 'NOT_FOUND');
        $lineasIn = $b['lineas'] ?? [];
        if (!is_array($lineasIn) || count($lineasIn) === 0) throw new ApiError('El apartado no tiene lineas', 400, 'VALIDATION');

        $q = Pricing::cotizar($emp, $idCli, $lineasIn, self::ivaEmpresa($emp));

        Db::begin();
        try {
            $folio = self::nextFolio($emp);
            $aptId = Db::insert(
                'INSERT INTO apartados (id_empresa,folio,fecha,fecha_limite,id_cliente,id_almacen,id_vendedor,id_usuario,total,anticipo,estado,notas)
                 VALUES (?,?,NOW(),DATE_ADD(CURDATE(), INTERVAL ? DAY),?,?,?,?,?,0,\'vigente\',?)',
                [$emp, $folio, self::DIAS_LIMITE, $idCli, $idAlm, id_or_null($b['id_vendedor'] ?? null),
                 (int) $ctx['user']['id'], $q['total'], $b['notas'] ?? null]);

            foreach ($q['lineas'] as $ln) {
                $idColor = (int) $ln['id_color']; $idTalla = (int) $ln['id_talla'];
                Db::insert(
                    'INSERT INTO apartado_lineas (id_apartado,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,precio_unitario,importe)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$aptId, (int) $ln['id_articulo'], $ln['codigo'], $ln['descripcion'], $idColor, $idTalla,
                     $ln['cantidad'], $ln['precio_unitario'], $ln['importe']]);
                self::reservar($emp, (int) $ln['id_articulo'], $idColor, $idTalla, $idAlm, (float) $ln['cantidad'], $ln['codigo']);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'crear', 'Apartado', $aptId, $folio);
        Http::created(self::obtenerApartado($emp, $aptId), 'Apartado');
    }

    public static function editar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM apartados WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        if (!in_array($a['estado'], ['vigente', 'con_anticipo'], true)) throw new ApiError('Solo se puede editar un apartado vigente', 400, 'VALIDATION');

        $lineasIn = $b['lineas'] ?? null;
        Db::begin();
        try {
            if (is_array($lineasIn)) {
                if (count($lineasIn) === 0) throw new ApiError('El apartado no tiene lineas', 400, 'VALIDATION');
                self::liberarReserva($emp, $id);          // soltar reserva anterior
                Db::run('DELETE FROM apartado_lineas WHERE id_apartado=?', [$id]);
                $q = Pricing::cotizar($emp, (int) $a['id_cliente'], $lineasIn, self::ivaEmpresa($emp));
                foreach ($q['lineas'] as $ln) {
                    $idColor = (int) $ln['id_color']; $idTalla = (int) $ln['id_talla'];
                    Db::insert(
                        'INSERT INTO apartado_lineas (id_apartado,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,precio_unitario,importe)
                         VALUES (?,?,?,?,?,?,?,?,?)',
                        [$id, (int) $ln['id_articulo'], $ln['codigo'], $ln['descripcion'], $idColor, $idTalla,
                         $ln['cantidad'], $ln['precio_unitario'], $ln['importe']]);
                    self::reservar($emp, (int) $ln['id_articulo'], $idColor, $idTalla, (int) $a['id_almacen'], (float) $ln['cantidad'], $ln['codigo']);
                }
                Db::run('UPDATE apartados SET total=? WHERE id=?', [$q['total'], $id]);
            }
            if (array_key_exists('notas', $b)) Db::run('UPDATE apartados SET notas=? WHERE id=?', [$b['notas'], $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::updated(self::obtenerApartado($emp, $id), 'Apartado');
    }

    public static function anticipo(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM apartados WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        if (!in_array($a['estado'], ['vigente', 'con_anticipo'], true)) throw new ApiError('El apartado no admite anticipos', 400, 'VALIDATION');
        $importe = num($b['importe'] ?? 0);
        if ($importe <= 0) throw new ApiError('El importe debe ser mayor a cero', 400, 'VALIDATION');
        $restante = round((float) $a['total'] - (float) $a['anticipo'], 2);
        if ($importe > $restante + 0.0001) throw new ApiError("El anticipo excede el saldo restante ($restante)", 400, 'VALIDATION');

        Db::begin();
        try {
            Db::insert('INSERT INTO apartado_anticipos (id_apartado,fecha,forma,importe,id_usuario) VALUES (?,NOW(),?,?,?)',
                [$id, $b['forma'] ?? 'efectivo', $importe, (int) $ctx['user']['id']]);
            Db::run('UPDATE apartados SET anticipo=anticipo+?, estado=\'con_anticipo\' WHERE id=?', [$importe, $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'anticipo', 'Apartado', $id, 'Anticipo ' . $importe);
        Http::ok(self::obtenerApartado($emp, $id), 'Anticipo registrado');
    }

    public static function liquidar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM apartados WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        if (!in_array($a['estado'], ['vigente', 'con_anticipo'], true)) throw new ApiError('El apartado no se puede liquidar', 400, 'VALIDATION');

        $pagos = is_array($b['pagos'] ?? null) ? $b['pagos'] : [];
        $pagado = 0.0;
        foreach ($pagos as $pg) $pagado += num($pg['importe'] ?? 0);
        $restante = round((float) $a['total'] - (float) $a['anticipo'], 2);
        if ($pagado + 0.01 < $restante) throw new ApiError("El pago ($pagado) no cubre el restante ($restante)", 400, 'VALIDATION');

        // construir lineas para la venta
        $lineasVenta = array_map(fn($l) => [
            'id_articulo' => (int) $l['id_articulo'], 'id_color' => (int) $l['id_color'],
            'id_talla' => (int) $l['id_talla'], 'cantidad' => (float) $l['cantidad'],
        ], Db::all('SELECT * FROM apartado_lineas WHERE id_apartado=?', [$id]));

        // pagos de la venta = anticipo previo + pagos de liquidacion (el excedente ira a monedero del cliente)
        $pagosVenta = [];
        if ((float) $a['anticipo'] > 0) $pagosVenta[] = ['forma' => 'anticipo', 'importe' => (float) $a['anticipo']];
        foreach ($pagos as $pg) $pagosVenta[] = ['forma' => $pg['forma'] ?? 'efectivo', 'importe' => num($pg['importe'] ?? 0)];

        // liberar la reserva ANTES de vender (la venta descuenta cantidad real)
        Db::begin();
        try { self::liberarReserva($emp, $id); Db::commit(); }
        catch (Throwable $e) { Db::rollback(); throw $e; }

        // crear la venta (gestiona su propia transaccion)
        $ventaId = VentaController::registrar([
            'id_almacen'  => (int) $a['id_almacen'],
            'id_cliente'  => (int) $a['id_cliente'],
            'id_vendedor' => $a['id_vendedor'] ? (int) $a['id_vendedor'] : null,
            'lineas'      => $lineasVenta,
            'pagos'       => $pagosVenta,
            'a_credito'   => false,
            'destino_cambio' => ($b['destino_cambio'] ?? 'monedero'),
            'notas'       => 'Liquidacion apartado ' . $a['folio'],
        ], $ctx);

        Db::begin();
        try {
            Db::run('UPDATE apartados SET estado=\'liquidado\', id_venta=? WHERE id=?', [$ventaId, $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'liquidar', 'Apartado', $id, 'Venta generada ' . $ventaId);
        $out = self::obtenerApartado($emp, $id);
        $out['generatedVentaId'] = (string) $ventaId;
        Http::ok($out, 'Apartado liquidado y venta generada');
    }

    public static function cancelar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM apartados WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Apartado no encontrado', 404, 'NOT_FOUND');
        if (!in_array($a['estado'], ['vigente', 'con_anticipo'], true)) throw new ApiError('El apartado no se puede cancelar', 400, 'VALIDATION');

        Db::begin();
        try {
            self::liberarReserva($emp, $id);
            // si hubo anticipo, se abona al monedero del cliente (no se devuelve efectivo)
            if ((float) $a['anticipo'] > 0) {
                Ledger::monederoMov($emp, (int) $a['id_cliente'], (float) $a['anticipo'], 'deposito',
                    'Anticipo apartado cancelado ' . $a['folio'], 'Apartado', $id);
            }
            Db::run('UPDATE apartados SET estado=\'cancelado\' WHERE id=?', [$id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'cancelar', 'Apartado', $id, $a['folio']);
        Http::ok(self::obtenerApartado($emp, $id), 'Apartado cancelado');
    }
}
