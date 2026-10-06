<?php
class DevolucionController
{
    /** Plazo maximo (dias) para aceptar una devolucion respecto a la fecha de la venta original. */
    const DIAS_LIMITE_DEVOLUCION = 30;

    /** Verifica que la venta este dentro del plazo permitido para devolucion. */
    private static function validarPlazoDevolucion(array $v): void
    {
        $fechaVenta = $v['fecha'] ?? null;
        if ($fechaVenta === null || $fechaVenta === '') $fechaVenta = $v['created_at'] ?? null;
        if ($fechaVenta === null || $fechaVenta === '') return;
        $dias = (time() - strtotime($fechaVenta)) / 86400;
        if ($dias > self::DIAS_LIMITE_DEVOLUCION) {
            throw new ApiError('Fuera de plazo: solo se permiten devoluciones de ventas de los últimos 30 días', 400, 'VALIDATION');
        }
    }

    private static function nextFolio(int $emp, string $tabla, string $prefijo): string
    {
        $n = (int) (Db::one("SELECT COUNT(*) c FROM $tabla WHERE id_empresa=?", [$emp])['c'] ?? 0) + 1;
        return $prefijo . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    /** Detalle poblado del articulo (precios + colores + tallas) para la pantalla de cambios */
    private static function articuloDetalle(int $idArt): array
    {
        $a = Db::one('SELECT * FROM articulos WHERE id=?', [$idArt]);
        if (!$a) return ['_id' => (string) $idArt];
        $colores = Db::all('SELECT c.id, c.nombre FROM articulo_colores ac JOIN atributo_valores c ON c.id=ac.id_color WHERE ac.id_articulo=?', [$idArt]);
        $tallas  = Db::all('SELECT t.id, t.nombre, t.orden FROM articulo_tallas at JOIN atributo_valores t ON t.id=at.id_talla WHERE at.id_articulo=? ORDER BY t.orden', [$idArt]);
        return [
            '_id'           => (string) $a['id'],
            'codigo'        => $a['codigo'],
            'descripcion'   => $a['descripcion'],
            'es_oferta'     => (bool) $a['es_oferta'],
            'precio_oferta' => (float) $a['precio_oferta'],
            'precios'       => [
                'lista1' => (float) $a['lista1'], 'lista2' => (float) $a['lista2'], 'lista3' => (float) $a['lista3'],
                'lista4' => (float) $a['lista4'], 'lista5' => (float) $a['lista5'],
            ],
            'colores' => array_map(fn($c) => ['_id' => (string) $c['id'], 'nombre' => $c['nombre']], $colores),
            'tallas'  => array_map(fn($t) => ['_id' => (string) $t['id'], 'nombre' => $t['nombre']], $tallas),
        ];
    }

    public static function buscarVenta(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $folio = trim($_GET['folio'] ?? '');
        if ($folio === '') throw new ApiError('Indica el folio de la venta', 400, 'VALIDATION');
        $v = Db::one('SELECT * FROM ventas WHERE folio=? AND id_empresa=? AND estado=\'completada\'', [$folio, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada o no esta completada', 404, 'NOT_FOUND');
        self::validarPlazoDevolucion($v);

        $cli = $v['id_cliente'] ? Db::one('SELECT id,nombre,lista_precios,saldo_favor,saldo_credito FROM clientes WHERE id=?', [$v['id_cliente']]) : null;
        $alm = Db::one('SELECT id,nombre,tipo FROM almacenes WHERE id=?', [$v['id_almacen']]);

        $lineas = array_map(function ($l) {
            $col = (int) $l['id_color'] === 0 ? ['_id' => '', 'nombre' => 'Unico']
                 : (function ($id) { $c = Db::one('SELECT nombre FROM atributo_valores WHERE id=?', [$id]); return ['_id' => (string) $id, 'nombre' => $c['nombre'] ?? '']; })((int) $l['id_color']);
            $tal = (int) $l['id_talla'] === 0 ? ['_id' => '', 'nombre' => 'Unica']
                 : (function ($id) { $t = Db::one('SELECT nombre FROM atributo_valores WHERE id=?', [$id]); return ['_id' => (string) $id, 'nombre' => $t['nombre'] ?? '']; })((int) $l['id_talla']);
            return [
                'id_articulo'     => self::articuloDetalle((int) $l['id_articulo']),
                'codigo'          => $l['codigo'],
                'descripcion'     => $l['descripcion'],
                'id_color'        => $col,
                'id_talla'        => $tal,
                'cantidad'        => (float) $l['cantidad'],
                'precio_unitario' => (float) $l['precio_unitario'],
                'importe'         => (float) $l['importe'],
            ];
        }, Db::all('SELECT * FROM venta_lineas WHERE id_venta=?', [$v['id']]));

        Http::ok([
            '_id'       => (string) $v['id'],
            'folio'     => $v['folio'],
            'fecha'     => $v['fecha'],
            'createdAt' => $v['created_at'],
            'id_cliente'=> $cli ? [
                '_id' => (string) $cli['id'], 'nombre' => $cli['nombre'], 'lista_precios' => (int) $cli['lista_precios'],
                'saldo_favor' => (float) $cli['saldo_favor'], 'saldo_credito' => (float) $cli['saldo_credito'],
            ] : null,
            'id_almacen'    => ['_id' => (string) $alm['id'], 'nombre' => $alm['nombre'], 'tipo' => $alm['tipo']],
            'lista_cliente' => (int) $v['lista_cliente'],
            'nivel_cantidad'=> (int) $v['nivel_cantidad'],
            'lineas'        => $lineas,
            'total'         => (float) $v['total'],
            'estado'        => $v['estado'],
        ]);
    }

    /** Suma ya devuelta por celda para una venta (evita devolver de mas) */
    private static function yaDevuelto(int $idVenta): array
    {
        $map = [];
        $rows = Db::all(
            'SELECT dl.id_articulo, dl.id_color, dl.id_talla, SUM(dl.cantidad) c
             FROM devolucion_lineas dl JOIN devoluciones d ON d.id=dl.id_devolucion
             WHERE d.id_venta=? GROUP BY dl.id_articulo, dl.id_color, dl.id_talla', [$idVenta]);
        foreach ($rows as $r) $map["{$r['id_articulo']}-{$r['id_color']}-{$r['id_talla']}"] = (float) $r['c'];
        // tambien lo devuelto via cambios
        $rows2 = Db::all(
            'SELECT cl.id_articulo, cl.id_color, cl.id_talla, SUM(cl.cantidad) c
             FROM cambio_lineas cl JOIN cambios ca ON ca.id=cl.id_cambio
             WHERE ca.id_venta=? AND cl.rol=\'devuelta\' GROUP BY cl.id_articulo, cl.id_color, cl.id_talla', [$idVenta]);
        foreach ($rows2 as $r) {
            $k = "{$r['id_articulo']}-{$r['id_color']}-{$r['id_talla']}";
            $map[$k] = ($map[$k] ?? 0) + (float) $r['c'];
        }
        return $map;
    }

    /** Valida que las lineas a devolver existan en la venta y no excedan lo vendido (menos lo ya devuelto) */
    private static function validarDevueltas(array $v, array $lineas, array $yaDev): void
    {
        $vendido = [];
        foreach (Db::all('SELECT * FROM venta_lineas WHERE id_venta=?', [$v['id']]) as $l) {
            $vendido["{$l['id_articulo']}-{$l['id_color']}-{$l['id_talla']}"] = (float) $l['cantidad'];
        }
        $acum = [];
        foreach ($lineas as $ln) {
            $idArt = id_or_null($ln['id_articulo'] ?? null);
            if (!$idArt) throw new ApiError('Linea sin id_articulo', 400, 'VALIDATION');
            $k = $idArt . '-' . id_or_zero($ln['id_color'] ?? 0) . '-' . id_or_zero($ln['id_talla'] ?? 0);
            $cant = (float) ($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $disp = ($vendido[$k] ?? 0) - ($yaDev[$k] ?? 0) - ($acum[$k] ?? 0);
            if ($cant > $disp + 0.0001) {
                throw new ApiError("No puedes devolver mas de lo vendido en la celda (disponible $disp)", 400, 'VALIDATION');
            }
            $acum[$k] = ($acum[$k] ?? 0) + $cant;
        }
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $folioVenta = trim($b['folio_venta'] ?? '');
        $lineas = $b['lineas'] ?? [];
        if ($folioVenta === '') throw new ApiError('folio_venta es obligatorio', 400, 'VALIDATION');
        if (!is_array($lineas) || count($lineas) === 0) throw new ApiError('Sin lineas a devolver', 400, 'VALIDATION');
        $v = Db::one('SELECT * FROM ventas WHERE folio=? AND id_empresa=? AND estado=\'completada\'', [$folioVenta, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');
        self::validarPlazoDevolucion($v);

        self::validarDevueltas($v, $lineas, self::yaDevuelto((int) $v['id']));

        $esCredito = (float) $v['monto_credito'] > 0;
        $destino = $esCredito ? 'cxc' : 'monedero';

        Db::begin();
        try {
            $folio = self::nextFolio($emp, 'devoluciones', 'DEV');
            $total = 0.0;
            $devId = Db::insert(
                'INSERT INTO devoluciones (id_empresa,folio,fecha,id_venta,folio_venta,id_cliente,id_almacen,total,destino_saldo,id_usuario)
                 VALUES (?,?,NOW(),?,?,?,?,0,?,?)',
                [$emp, $folio, (int) $v['id'], $v['folio'], $v['id_cliente'], (int) $v['id_almacen'], $destino, (int) $ctx['user']['id']]);

            foreach ($lineas as $ln) {
                $cant = (float) ($ln['cantidad'] ?? 0);
                if ($cant <= 0) continue;
                $idArt = (int) $ln['id_articulo']; $idColor = id_or_zero($ln['id_color'] ?? 0); $idTalla = id_or_zero($ln['id_talla'] ?? 0);
                $precio = num($ln['precio_unitario'] ?? 0);
                $importe = isset($ln['importe']) ? num($ln['importe']) : round($precio * $cant, 2);
                $total += $importe;
                $art = Db::one('SELECT codigo,descripcion FROM articulos WHERE id=?', [$idArt]);
                Db::insert(
                    'INSERT INTO devolucion_lineas (id_devolucion,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,precio_unitario,importe)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$devId, $idArt, $art['codigo'] ?? null, $art['descripcion'] ?? null, $idColor, $idTalla, $cant, $precio, $importe]);
                // reingresar stock
                InventarioController::aplicar($emp, $idArt, $idColor, $idTalla, (int) $v['id_almacen'], $cant,
                    'devolucion', 'Devolucion ' . $folio, 'Devolucion', $devId, (int) $ctx['user']['id'], $folio);
            }
            $total = round($total, 2);
            Db::run('UPDATE devoluciones SET total=? WHERE id=?', [$total, $devId]);

            // acreditar al cliente
            if ($v['id_cliente']) {
                if ($destino === 'cxc') {
                    Ledger::clienteMov($emp, (int) $v['id_cliente'], 'devolucion', 'Devolucion ' . $folio, $total, 'abono', 'MXN', null, 'Devolucion', $devId);
                } else {
                    Ledger::monederoMov($emp, (int) $v['id_cliente'], $total, 'deposito', 'Devolucion ' . $folio, 'Devolucion', $devId);
                }
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'crear', 'Devolucion', $devId, $folio);
        Http::created(self::fmtDevolucion($emp, $devId), 'Devolucion');
    }

    private static function fmtDevolucion(int $emp, int $id): array
    {
        $d = Db::one('SELECT * FROM devoluciones WHERE id=? AND id_empresa=?', [$id, $emp]);
        $lineas = array_map(fn($l) => [
            'id_articulo' => (string) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion'],
            'id_color' => (string) $l['id_color'], 'id_talla' => (string) $l['id_talla'],
            'cantidad' => (float) $l['cantidad'], 'precio_unitario' => (float) $l['precio_unitario'], 'importe' => (float) $l['importe'],
        ], Db::all('SELECT * FROM devolucion_lineas WHERE id_devolucion=?', [$id]));
        return [
            '_id' => (string) $d['id'], 'folio' => $d['folio'], 'fecha' => $d['fecha'], 'createdAt' => $d['created_at'],
            'folio_venta' => $d['folio_venta'], 'total' => (float) $d['total'], 'destino_saldo' => $d['destino_saldo'],
            'lineas' => $lineas,
        ];
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_cliente'])) { $where .= ' AND id_cliente=?'; $args[] = (int) $_GET['id_cliente']; }
        if (!empty($_GET['desde'])) { $where .= ' AND fecha>=?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND fecha<=?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT * FROM devoluciones WHERE $where ORDER BY fecha DESC, id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($d) => [
            '_id' => (string) $d['id'], 'folio' => $d['folio'], 'fecha' => $d['fecha'], 'createdAt' => $d['created_at'],
            'folio_venta' => $d['folio_venta'], 'total' => (float) $d['total'], 'destino_saldo' => $d['destino_saldo'],
        ], $rows));
    }

    // ============================== CAMBIOS ==============================
    public static function crearCambio(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $folioVenta = trim($b['folio_venta'] ?? '');
        $devueltas = $b['lineas_devueltas'] ?? [];
        $nuevas    = $b['lineas_nuevas'] ?? [];
        if ($folioVenta === '') throw new ApiError('folio_venta es obligatorio', 400, 'VALIDATION');
        if (!is_array($devueltas) || count($devueltas) === 0) throw new ApiError('Sin prendas devueltas', 400, 'VALIDATION');
        if (!is_array($nuevas) || count($nuevas) === 0) throw new ApiError('Sin prendas nuevas', 400, 'VALIDATION');
        $v = Db::one('SELECT * FROM ventas WHERE folio=? AND id_empresa=? AND estado=\'completada\'', [$folioVenta, $emp]);
        if (!$v) throw new ApiError('Venta no encontrada', 404, 'NOT_FOUND');

        self::validarDevueltas($v, $devueltas, self::yaDevuelto((int) $v['id']));
        $idAlm = (int) $v['id_almacen'];

        Db::begin();
        try {
            $folio = self::nextFolio($emp, 'cambios', 'CAMB');
            $totalDev = 0.0; $totalNuevo = 0.0;
            $cambioId = Db::insert(
                'INSERT INTO cambios (id_empresa,folio,fecha,id_venta,folio_venta,id_cliente,id_almacen,total_devuelto,total_nuevo,diferencia,pago_diferencia,id_usuario)
                 VALUES (?,?,NOW(),?,?,?,?,0,0,0,?,?)',
                [$emp, $folio, (int) $v['id'], $v['folio'], $v['id_cliente'], $idAlm, num($b['pago_diferencia'] ?? 0), (int) $ctx['user']['id']]);

            foreach ($devueltas as $ln) {
                $cant = (float) ($ln['cantidad'] ?? 0); if ($cant <= 0) continue;
                $idArt = (int) $ln['id_articulo']; $idColor = id_or_zero($ln['id_color'] ?? 0); $idTalla = id_or_zero($ln['id_talla'] ?? 0);
                $precio = num($ln['precio_unitario'] ?? 0); $importe = isset($ln['importe']) ? num($ln['importe']) : round($precio * $cant, 2);
                $totalDev += $importe;
                $art = Db::one('SELECT codigo,descripcion FROM articulos WHERE id=?', [$idArt]);
                Db::insert('INSERT INTO cambio_lineas (id_cambio,rol,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,precio_unitario,importe) VALUES (?,\'devuelta\',?,?,?,?,?,?,?,?)',
                    [$cambioId, $idArt, $art['codigo'] ?? null, $art['descripcion'] ?? null, $idColor, $idTalla, $cant, $precio, $importe]);
                InventarioController::aplicar($emp, $idArt, $idColor, $idTalla, $idAlm, $cant, 'devolucion', 'Cambio ' . $folio . ' (devuelta)', 'Cambio', $cambioId, (int) $ctx['user']['id'], $folio);
            }
            foreach ($nuevas as $ln) {
                $cant = (float) ($ln['cantidad'] ?? 0); if ($cant <= 0) continue;
                $idArt = (int) $ln['id_articulo']; $idColor = id_or_zero($ln['id_color'] ?? 0); $idTalla = id_or_zero($ln['id_talla'] ?? 0);
                $precio = num($ln['precio_unitario'] ?? 0); $importe = isset($ln['importe']) ? num($ln['importe']) : round($precio * $cant, 2);
                $totalNuevo += $importe;
                $art = Db::one('SELECT codigo,descripcion FROM articulos WHERE id=?', [$idArt]);
                Db::insert('INSERT INTO cambio_lineas (id_cambio,rol,id_articulo,codigo,descripcion,id_color,id_talla,cantidad,precio_unitario,importe) VALUES (?,\'nueva\',?,?,?,?,?,?,?,?)',
                    [$cambioId, $idArt, $art['codigo'] ?? null, $art['descripcion'] ?? null, $idColor, $idTalla, $cant, $precio, $importe]);
                InventarioController::aplicar($emp, $idArt, $idColor, $idTalla, $idAlm, -1 * $cant, 'cambio', 'Cambio ' . $folio . ' (nueva)', 'Cambio', $cambioId, (int) $ctx['user']['id'], $folio);
            }
            $totalDev = round($totalDev, 2); $totalNuevo = round($totalNuevo, 2);
            $diferencia = round($totalNuevo - $totalDev, 2);
            $pagoDif = num($b['pago_diferencia'] ?? 0);
            Db::run('UPDATE cambios SET total_devuelto=?, total_nuevo=?, diferencia=? WHERE id=?', [$totalDev, $totalNuevo, $diferencia, $cambioId]);

            if ($v['id_cliente']) {
                $cid = (int) $v['id_cliente'];
                if ($diferencia < -0.0001) {
                    // a favor del cliente -> monedero
                    Ledger::monederoMov($emp, $cid, -1 * $diferencia, 'deposito', 'Cambio ' . $folio, 'Cambio', $cambioId);
                } elseif ($diferencia > 0.0001) {
                    // el cliente debe la diferencia; lo pagado se recibe y el resto va a CxC
                    $resto = round($diferencia - $pagoDif, 2);
                    if ($resto > 0.0001) {
                        Ledger::clienteMov($emp, $cid, 'cambio', 'Cambio ' . $folio . ' (saldo)', $resto, 'cargo', 'MXN', null, 'Cambio', $cambioId);
                    }
                }
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'crear', 'Cambio', $cambioId, $folio);
        Http::created(self::fmtCambio($emp, $cambioId), 'Cambio');
    }

    private static function fmtCambio(int $emp, int $id): array
    {
        $c = Db::one('SELECT * FROM cambios WHERE id=? AND id_empresa=?', [$id, $emp]);
        $dev = []; $nue = [];
        foreach (Db::all('SELECT * FROM cambio_lineas WHERE id_cambio=?', [$id]) as $l) {
            $row = [
                'id_articulo' => (string) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion'],
                'id_color' => (string) $l['id_color'], 'id_talla' => (string) $l['id_talla'],
                'cantidad' => (float) $l['cantidad'], 'precio_unitario' => (float) $l['precio_unitario'], 'importe' => (float) $l['importe'],
            ];
            if ($l['rol'] === 'devuelta') $dev[] = $row; else $nue[] = $row;
        }
        return [
            '_id' => (string) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'], 'createdAt' => $c['created_at'],
            'folio_venta' => $c['folio_venta'], 'total_devuelto' => (float) $c['total_devuelto'], 'total_nuevo' => (float) $c['total_nuevo'],
            'diferencia' => (float) $c['diferencia'], 'pago_diferencia' => (float) $c['pago_diferencia'],
            'lineas_devueltas' => $dev, 'lineas_nuevas' => $nue,
        ];
    }

    public static function listarCambios(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        if (!empty($_GET['desde'])) { $where .= ' AND fecha>=?'; $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $where .= ' AND fecha<=?'; $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $rows = Db::all("SELECT * FROM cambios WHERE $where ORDER BY fecha DESC, id DESC LIMIT 500", $args);
        Http::ok(array_map(fn($c) => [
            '_id' => (string) $c['id'], 'folio' => $c['folio'], 'fecha' => $c['fecha'], 'createdAt' => $c['created_at'],
            'folio_venta' => $c['folio_venta'], 'total_devuelto' => (float) $c['total_devuelto'],
            'total_nuevo' => (float) $c['total_nuevo'], 'diferencia' => (float) $c['diferencia'], 'pago_diferencia' => (float) $c['pago_diferencia'],
        ], $rows));
    }
}
