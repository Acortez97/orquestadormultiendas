<?php
// ============================================================
// Devoluciones y cambios sobre ventas de la tienda.
// Los importes NUNCA se toman del front: lo devuelto se valora al precio de la linea vendida
// y lo nuevo (en cambios) se cotiza en el servidor con Pricing.
// ============================================================
class DevolucionController
{
    const DIAS_LIMITE_DEVOLUCION = 30;

    private static function validarPlazo(array $v): void
    {
        $f = $v['fecha'] ?: $v['created_at'];
        if ($f && (time() - strtotime($f)) / 86400 > self::DIAS_LIMITE_DEVOLUCION) {
            throw new ApiError('Fuera de plazo: solo se permiten devoluciones de ventas de los ultimos ' . self::DIAS_LIMITE_DEVOLUCION . ' dias', 400, 'VALIDATION');
        }
    }

    /** Venta completada de la tienda por folio, bloqueada para la operacion */
    private static function ventaPorFolio(string $folio, bool $bloquear = false): array
    {
        if ($folio === '') throw new ApiError('Indica el folio de la venta', 400, 'VALIDATION');
        $v = Db::one("SELECT * FROM ventas WHERE folio = ? AND id_empresa = ? AND estado = 'completada'" . ($bloquear ? ' FOR UPDATE' : ''), [$folio, Tenant::id()]);
        if (!$v) throw new ApiError('Venta no encontrada o no esta completada', 404, 'NOT_FOUND');
        return $v;
    }

    private static function clave(int $idArt, $v1, $v2): string
    {
        return $idArt . '-' . (int) Variantes::norm($v1) . '-' . (int) Variantes::norm($v2);
    }

    /** Detalle del articulo (precios y variantes) para la pantalla de cambios */
    private static function articuloDetalle(int $idArt): array
    {
        $a = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [$idArt, Tenant::id()]);
        if (!$a) return ['_id' => $idArt];
        return [
            '_id'           => (int) $a['id'],
            'codigo'        => $a['codigo'],
            'descripcion'   => $a['descripcion'],
            'es_oferta'     => (bool) $a['es_oferta'],
            'precio_oferta' => (float) $a['precio_oferta'],
            'precios'       => ['lista1' => (float) $a['lista1'], 'lista2' => (float) $a['lista2'], 'lista3' => (float) $a['lista3'],
                                'lista4' => (float) $a['lista4'], 'lista5' => (float) $a['lista5']],
            'colores' => array_map(fn($c) => ['_id' => $c['_id'], 'nombre' => $c['nombre']], ArticuloController::coloresDe($idArt)),
            'tallas'  => array_map(fn($t) => ['_id' => $t['_id'], 'nombre' => $t['nombre']], ArticuloController::tallasDe($idArt)),
        ];
    }

    public static function buscarVenta(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $v = self::ventaPorFolio(trim((string) ($_GET['folio'] ?? '')));
        self::validarPlazo($v);
        $cli = $v['id_cliente'] ? Db::one('SELECT id, nombre, lista_precios, saldo_favor, saldo_credito FROM clientes WHERE id = ? AND id_empresa = ?', [$v['id_cliente'], $emp]) : null;
        $alm = Db::one('SELECT id, nombre, tipo FROM almacenes WHERE id = ? AND id_empresa = ?', [$v['id_almacen'], $emp]);
        $filas = Db::all('SELECT * FROM venta_lineas WHERE id_empresa = ? AND id_venta = ? ORDER BY id', [$emp, $v['id']]);
        $nom = Variantes::nombres(array_merge(array_column($filas, 'id_valor1'), array_column($filas, 'id_valor2')));
        $yaDev = self::yaDevuelto((int) $v['id']);

        Http::ok([
            '_id'       => (int) $v['id'],
            'folio'     => $v['folio'],
            'fecha'     => $v['fecha'],
            'createdAt' => $v['created_at'],
            'id_cliente'=> $cli ? ['_id' => (int) $cli['id'], 'nombre' => $cli['nombre'], 'lista_precios' => (int) $cli['lista_precios'],
                                   'saldo_favor' => (float) $cli['saldo_favor'], 'saldo_credito' => (float) $cli['saldo_credito']] : null,
            'id_almacen'    => ['_id' => (int) $alm['id'], 'nombre' => $alm['nombre'], 'tipo' => $alm['tipo']],
            'lista_cliente' => (int) $v['lista_cliente'],
            'nivel_cantidad'=> (int) $v['nivel_cantidad'],
            'lineas'        => array_map(fn($l) => [
                'id_articulo'     => self::articuloDetalle((int) $l['id_articulo']),
                'codigo'          => $l['codigo'],
                'descripcion'     => $l['descripcion'],
                'id_color'        => Variantes::ref($l['id_valor1'], $nom[(int) $l['id_valor1']] ?? '', null, 0, 'Unico'),
                'id_talla'        => Variantes::ref($l['id_valor2'], $nom[(int) $l['id_valor2']] ?? '', null, 0, 'Unica'),
                'cantidad'        => (float) $l['cantidad'],
                'devuelto'        => (float) ($yaDev[self::clave((int) $l['id_articulo'], $l['id_valor1'], $l['id_valor2'])] ?? 0),
                'precio_unitario' => (float) $l['precio_unitario'],
                'importe'         => (float) $l['importe'],
            ], $filas),
            'total'  => (float) $v['total'],
            'estado' => $v['estado'],
        ]);
    }

    /** Cantidad ya devuelta (devoluciones + cambios) por celda de una venta */
    private static function yaDevuelto(int $idVenta): array
    {
        $emp = Tenant::id();
        $map = [];
        $q = [
            'SELECT dl.id_articulo, dl.id_valor1, dl.id_valor2, SUM(dl.cantidad) c FROM devolucion_lineas dl
             JOIN devoluciones d ON d.id_empresa = dl.id_empresa AND d.id = dl.id_devolucion
             WHERE d.id_empresa = ? AND d.id_venta = ? GROUP BY dl.id_articulo, dl.id_valor1, dl.id_valor2',
            "SELECT cl.id_articulo, cl.id_valor1, cl.id_valor2, SUM(cl.cantidad) c FROM cambio_lineas cl
             JOIN cambios ca ON ca.id_empresa = cl.id_empresa AND ca.id = cl.id_cambio
             WHERE ca.id_empresa = ? AND ca.id_venta = ? AND cl.rol = 'devuelta' GROUP BY cl.id_articulo, cl.id_valor1, cl.id_valor2",
        ];
        foreach ($q as $sql) foreach (Db::all($sql, [$emp, $idVenta]) as $r) {
            $k = self::clave((int) $r['id_articulo'], $r['id_valor1'], $r['id_valor2']);
            $map[$k] = ($map[$k] ?? 0) + (float) $r['c'];
        }
        return $map;
    }

    /**
     * Valida lo que se devuelve contra lo vendido (menos lo ya devuelto) y lo VALORA al precio vendido.
     * Devuelve [lineas[], total].
     */
    private static function lineasDevueltas(array $v, array $lineas): array
    {
        $vendidas = [];
        foreach (Db::all('SELECT * FROM venta_lineas WHERE id_empresa = ? AND id_venta = ?', [Tenant::id(), $v['id']]) as $l) {
            $k = self::clave((int) $l['id_articulo'], $l['id_valor1'], $l['id_valor2']);
            $vendidas[$k] = $vendidas[$k] ?? ['cantidad' => 0.0, 'precio' => (float) $l['precio_unitario'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion']];
            $vendidas[$k]['cantidad'] += (float) $l['cantidad'];
        }
        $yaDev = self::yaDevuelto((int) $v['id']);
        $acum = []; $out = []; $total = 0.0;
        foreach ($lineas as $ln) {
            $cant = num($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $idArt = (int) ($ln['id_articulo'] ?? 0);
            $k = self::clave($idArt, $ln['id_color'] ?? null, $ln['id_talla'] ?? null);
            if (!isset($vendidas[$k])) throw new ApiError('Un articulo no corresponde a la venta', 400, 'VALIDATION');
            $disp = $vendidas[$k]['cantidad'] - ($yaDev[$k] ?? 0) - ($acum[$k] ?? 0);
            if ($cant > $disp + 0.0001) throw new ApiError("No puedes devolver mas de lo vendido de {$vendidas[$k]['codigo']} (disponible $disp)", 400, 'VALIDATION');
            $acum[$k] = ($acum[$k] ?? 0) + $cant;
            $importe = round($vendidas[$k]['precio'] * $cant, 2);
            $total += $importe;
            $out[] = ['id_articulo' => $idArt, 'v1' => Variantes::norm($ln['id_color'] ?? null), 'v2' => Variantes::norm($ln['id_talla'] ?? null),
                      'codigo' => $vendidas[$k]['codigo'], 'descripcion' => $vendidas[$k]['descripcion'], 'cantidad' => $cant,
                      'precio_unitario' => $vendidas[$k]['precio'], 'importe' => $importe];
        }
        if (!$out) throw new ApiError('Sin lineas a devolver', 400, 'VALIDATION');
        return [$out, round($total, 2)];
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        Db::begin();
        $v = self::ventaPorFolio(trim((string) ($b['folio_venta'] ?? '')), true);
        self::validarPlazo($v);
        [$lineas, $total] = self::lineasDevueltas($v, is_array($b['lineas'] ?? null) ? $b['lineas'] : []);
        $destino = (float) $v['monto_credito'] > 0 ? 'cxc' : 'monedero';   // venta sin cliente: solo reingresa mercancia
        $idUser = $ctx['user']['id'];
        $folio = Db::folio($emp, 'devolucion', 'DEV', 5);
        $devId = Db::insert('INSERT INTO devoluciones (id_empresa, folio, fecha, id_venta, id_cliente, id_almacen, total, destino_saldo, id_usuario)
                             VALUES (?,?,NOW(),?,?,?,?,?,?)',
            [$emp, $folio, (int) $v['id'], $v['id_cliente'], (int) $v['id_almacen'], $total, $destino, $idUser]);
        foreach ($lineas as $l) {
            Db::insert('INSERT INTO devolucion_lineas (id_empresa, id_devolucion, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad, precio_unitario, importe)
                        VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$emp, $devId, $l['id_articulo'], $l['v1'], $l['v2'], $l['codigo'], $l['descripcion'], $l['cantidad'], $l['precio_unitario'], $l['importe']]);
            VentaController::afectarInventario($emp, $l['id_articulo'], $l['v1'], $l['v2'], (int) $v['id_almacen'], $l['cantidad'], 1,
                'devolucion', 'Devolucion ' . $folio, 'Devolucion', $devId, $idUser, $folio);
        }
        if ($v['id_cliente'] && $total > 0) {
            if ($destino === 'cxc') Ledger::clienteMov($emp, (int) $v['id_cliente'], 'devolucion', 'Devolucion ' . $folio, $total, 'abono', 'MXN', null, 'Devolucion', $devId);
            else Ledger::monederoMov($emp, (int) $v['id_cliente'], $total, 'deposito', 'Devolucion ' . $folio, 'Devolucion', $devId);
        }
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Devolucion', $devId, $folio);
        Http::created(self::fmtDevolucion($emp, $devId), 'Devolucion');
    }

    private static function fmtLineas(array $filas): array
    {
        $nom = Variantes::nombres(array_merge(array_column($filas, 'id_valor1'), array_column($filas, 'id_valor2')));
        return array_map(fn($l) => [
            'id_articulo' => (int) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion'],
            'id_color' => (int) $l['id_valor1'], 'id_talla' => (int) $l['id_valor2'],
            'color' => $nom[(int) $l['id_valor1']] ?? null, 'talla' => $nom[(int) $l['id_valor2']] ?? null,
            'cantidad' => (float) $l['cantidad'], 'precio_unitario' => (float) $l['precio_unitario'], 'importe' => (float) $l['importe'],
        ], $filas);
    }

    private static function fmtDevolucion(int $emp, int $id): array
    {
        $d = Db::one('SELECT d.*, v.folio folio_venta FROM devoluciones d LEFT JOIN ventas v ON v.id_empresa = d.id_empresa AND v.id = d.id_venta
                      WHERE d.id = ? AND d.id_empresa = ?', [$id, $emp]);
        return [
            '_id' => (int) $d['id'], 'folio' => $d['folio'], 'fecha' => $d['fecha'], 'createdAt' => $d['created_at'],
            'folio_venta' => $d['folio_venta'], 'total' => (float) $d['total'], 'destino_saldo' => $d['destino_saldo'],
            'lineas' => self::fmtLineas(Db::all('SELECT * FROM devolucion_lineas WHERE id_empresa = ? AND id_devolucion = ? ORDER BY id', [$emp, $id])),
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $where = 'd.id_empresa = ?'; $args = [Tenant::id()];
        if (!empty($_GET['id_cliente'])) { $where .= ' AND d.id_cliente = ?'; $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['desde'])) { $where .= ' AND d.fecha >= ?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND d.fecha <= ?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT d.*, v.folio folio_venta FROM devoluciones d LEFT JOIN ventas v ON v.id_empresa = d.id_empresa AND v.id = d.id_venta
                         WHERE $where ORDER BY d.fecha DESC, d.id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($d) => [
            '_id' => (int) $d['id'], 'folio' => $d['folio'], 'fecha' => $d['fecha'], 'createdAt' => $d['created_at'],
            'folio_venta' => $d['folio_venta'], 'total' => (float) $d['total'], 'destino_saldo' => $d['destino_saldo'],
        ], $rows));
    }

    // ============================== CAMBIOS ==============================
    public static function crearCambio(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $nuevasIn = $b['lineas_nuevas'] ?? [];
        if (!is_array($nuevasIn) || count($nuevasIn) === 0) throw new ApiError('Sin prendas nuevas', 400, 'VALIDATION');

        Db::begin();
        $v = self::ventaPorFolio(trim((string) ($b['folio_venta'] ?? '')), true);
        [$devueltas, $totalDev] = self::lineasDevueltas($v, is_array($b['lineas_devueltas'] ?? null) ? $b['lineas_devueltas'] : []);
        $q = Pricing::cotizar($emp, $v['id_cliente'] !== null ? (int) $v['id_cliente'] : null, $nuevasIn, (float) Tenant::empresa()['iva']);
        $totalNuevo = $q['total'];
        $diferencia = round($totalNuevo - $totalDev, 2);
        $pagoDif = max(0, num($b['pago_diferencia'] ?? 0));
        $formaDif = (string) ($b['forma_diferencia'] ?? 'efectivo');
        if (!in_array($formaDif, ['efectivo', 'tdc', 'tdb', 'transferencia', 'cheque'], true)) throw new ApiError('Forma de pago no valida', 400, 'VALIDATION');
        if ($pagoDif > max(0, $diferencia) + 0.01) throw new ApiError('El pago excede la diferencia a cubrir', 400, 'VALIDATION');
        [$idBancoDif, $idTermDif] = $pagoDif > 0 ? Cobros::destino($formaDif, $b['id_banco'] ?? null, $b['id_terminal'] ?? null) : [null, null];
        if ($diferencia > 0.0001 && !$v['id_cliente'] && $pagoDif + 0.01 < $diferencia) {
            throw new ApiError('Sin cliente, la diferencia se debe pagar completa', 400, 'VALIDATION');
        }
        $idAlm = (int) $v['id_almacen']; $idUser = $ctx['user']['id'];
        $folio = Db::folio($emp, 'cambio', 'CAMB', 5);
        $cambioId = Db::insert('INSERT INTO cambios (id_empresa, folio, fecha, id_venta, id_cliente, id_almacen, total_devuelto, total_nuevo, diferencia, pago_diferencia,
                                  forma_diferencia, id_banco, id_terminal, id_usuario)
                                VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, $folio, (int) $v['id'], $v['id_cliente'], $idAlm, $totalDev, $totalNuevo, $diferencia, $pagoDif,
             $pagoDif > 0 ? $formaDif : null, $idBancoDif, $idTermDif, $idUser]);
        // lo que el cliente paga de diferencia entra a la caja / cuenta
        if ($pagoDif > 0) Cobros::entrada($emp, $formaDif, $pagoDif, $idBancoDif, $idTermDif, $idAlm, 'Cambio ' . $folio, 'Cambio', $cambioId, $idUser);

        $sql = "INSERT INTO cambio_lineas (id_empresa, id_cambio, rol, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad, precio_unitario, importe)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        foreach ($devueltas as $l) {
            Db::insert($sql, [$emp, $cambioId, 'devuelta', $l['id_articulo'], $l['v1'], $l['v2'], $l['codigo'], $l['descripcion'], $l['cantidad'], $l['precio_unitario'], $l['importe']]);
            VentaController::afectarInventario($emp, $l['id_articulo'], $l['v1'], $l['v2'], $idAlm, $l['cantidad'], 1, 'devolucion',
                'Cambio ' . $folio . ' (devuelta)', 'Cambio', $cambioId, $idUser, $folio);
        }
        foreach ($q['lineas'] as $l) {
            $v1 = Variantes::norm($l['id_color']); $v2 = Variantes::norm($l['id_talla']);
            Db::insert($sql, [$emp, $cambioId, 'nueva', (int) $l['id_articulo'], $v1, $v2, $l['codigo'], $l['descripcion'], $l['cantidad'], $l['precio_unitario'], $l['importe']]);
            VentaController::afectarInventario($emp, (int) $l['id_articulo'], $v1, $v2, $idAlm, (float) $l['cantidad'], -1, 'cambio',
                'Cambio ' . $folio . ' (nueva)', 'Cambio', $cambioId, $idUser, $folio);
        }
        if ($v['id_cliente']) {
            $cid = (int) $v['id_cliente'];
            if ($diferencia < -0.0001) {
                Ledger::monederoMov($emp, $cid, -1 * $diferencia, 'deposito', 'Cambio ' . $folio, 'Cambio', $cambioId);   // a favor del cliente
            } elseif ($diferencia > 0.0001) {
                $resto = round($diferencia - $pagoDif, 2);   // lo no pagado va a su cuenta por cobrar
                if ($resto > 0.0001) Ledger::clienteMov($emp, $cid, 'cambio', 'Cambio ' . $folio . ' (saldo)', $resto, 'cargo', 'MXN', null, 'Cambio', $cambioId);
            }
        }
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Cambio', $cambioId, $folio);
        Http::created(self::fmtCambio($emp, $cambioId), 'Cambio');
    }

    private static function fmtCambio(int $emp, int $id): array
    {
        $c = Db::one('SELECT c.*, v.folio folio_venta FROM cambios c LEFT JOIN ventas v ON v.id_empresa = c.id_empresa AND v.id = c.id_venta
                      WHERE c.id = ? AND c.id_empresa = ?', [$id, $emp]);
        $filas = Db::all('SELECT * FROM cambio_lineas WHERE id_empresa = ? AND id_cambio = ? ORDER BY id', [$emp, $id]);
        $dev = self::fmtLineas(array_values(array_filter($filas, fn($l) => $l['rol'] === 'devuelta')));
        $nue = self::fmtLineas(array_values(array_filter($filas, fn($l) => $l['rol'] === 'nueva')));
        return [
            '_id' => (int) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'], 'createdAt' => $c['created_at'],
            'folio_venta' => $c['folio_venta'], 'total_devuelto' => (float) $c['total_devuelto'], 'total_nuevo' => (float) $c['total_nuevo'],
            'diferencia' => (float) $c['diferencia'], 'pago_diferencia' => (float) $c['pago_diferencia'], 'forma_diferencia' => $c['forma_diferencia'],
            'lineas_devueltas' => $dev, 'lineas_nuevas' => $nue,
        ];
    }

    public static function listarCambios(array $p, array $ctx): void
    {
        $where = 'c.id_empresa = ?'; $args = [Tenant::id()];
        if (!empty($_GET['desde'])) { $where .= ' AND c.fecha >= ?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND c.fecha <= ?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT c.*, v.folio folio_venta FROM cambios c LEFT JOIN ventas v ON v.id_empresa = c.id_empresa AND v.id = c.id_venta
                         WHERE $where ORDER BY c.fecha DESC, c.id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($c) => [
            '_id' => (int) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'], 'createdAt' => $c['created_at'],
            'folio_venta' => $c['folio_venta'], 'total_devuelto' => (float) $c['total_devuelto'],
            'total_nuevo' => (float) $c['total_nuevo'], 'diferencia' => (float) $c['diferencia'], 'pago_diferencia' => (float) $c['pago_diferencia'],
        ], $rows));
    }
}
