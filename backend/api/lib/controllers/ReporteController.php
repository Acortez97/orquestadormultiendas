<?php
class ReporteController
{
    private static function ivaEmpresa(int $emp): float
    {
        $e = Db::one('SELECT iva FROM empresas WHERE id=?', [$emp]);
        return $e ? (float) $e['iva'] : 0.16;
    }

    /** Filtro estandar sobre la tabla de ventas (alias v). Devuelve [whereExtra, args]. */
    private static function filtroVentas(): array
    {
        $w = ''; $a = [];
        if (!empty($_GET['desde']))       { $w .= ' AND v.fecha>=?';      $a[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))       { $w .= ' AND v.fecha<=?';      $a[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen']))  { $w .= ' AND v.id_almacen=?';  $a[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_vendedor'])) { $w .= ' AND v.id_vendedor=?'; $a[] = (int) $_GET['id_vendedor']; }
        return [$w, $a];
    }

    // ============================== VENTAS ==============================
    public static function ventas(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        [$wx, $ax] = self::filtroVentas();
        $args = array_merge([$emp], $ax);

        $ventas = Db::all(
            "SELECT v.*, al.nombre tienda, COALESCE(c.nombre,'Publico General') cliente,
                    TRIM(CONCAT(COALESCE(e.nombre,''),' ',COALESCE(e.apellido,''))) vendedor,
                    (SELECT COALESCE(SUM(vl.cantidad),0) FROM venta_lineas vl WHERE vl.id_venta=v.id) prendas
             FROM ventas v
             JOIN almacenes al ON al.id=v.id_almacen
             LEFT JOIN clientes c ON c.id=v.id_cliente
             LEFT JOIN empleados e ON e.id=v.id_vendedor
             WHERE v.id_empresa=? AND v.estado='completada' $wx
             ORDER BY v.fecha DESC, v.id DESC", $args);

        $agrupar = $_GET['agrupar'] ?? 'dia';
        if (!in_array($agrupar, ['dia', 'tienda', 'vendedor'], true)) $agrupar = 'dia';
        $tot = ['total' => 0.0, 'num_notas' => 0, 'prendas' => 0.0, 'ticket_promedio' => 0.0, 'credito' => 0.0];
        $grp = []; $filas = [];
        foreach ($ventas as $v) {
            $tot['total'] += (float) $v['total']; $tot['num_notas']++; $tot['prendas'] += (float) $v['prendas'];
            $tot['credito'] += (float) $v['monto_credito'];
            $clave = $agrupar === 'dia' ? substr($v['fecha'], 0, 10) : ($agrupar === 'tienda' ? $v['tienda'] : ($v['vendedor'] ?: 'Sin vendedor'));
            if (!isset($grp[$clave])) $grp[$clave] = ['clave' => $clave, 'num_notas' => 0, 'prendas' => 0.0, 'total' => 0.0];
            $grp[$clave]['num_notas']++; $grp[$clave]['prendas'] += (float) $v['prendas']; $grp[$clave]['total'] += (float) $v['total'];
            $filas[] = [
                'folio' => $v['folio'], 'fecha' => $v['fecha'], 'tienda' => $v['tienda'], 'cliente' => $v['cliente'],
                'vendedor' => $v['vendedor'] ?: '—', 'prendas' => (float) $v['prendas'],
                'subtotal' => (float) $v['subtotal'], 'iva' => (float) $v['iva'], 'total' => (float) $v['total'],
            ];
        }
        $tot['ticket_promedio'] = $tot['num_notas'] > 0 ? round($tot['total'] / $tot['num_notas'], 2) : 0.0;
        foreach (['total', 'prendas', 'credito'] as $k) $tot[$k] = round($tot[$k], 2);
        foreach ($grp as &$g) { $g['total'] = round($g['total'], 2); $g['prendas'] = round($g['prendas'], 2); } unset($g);

        Http::ok(['totales' => $tot, 'agrupar' => $agrupar, 'agrupado' => array_values($grp), 'filas' => $filas]);
    }

    // ============================== UTILIDAD ==============================
    public static function utilidad(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        [$wx, $ax] = self::filtroVentas();
        $iva = self::ivaEmpresa($emp);
        $rows = Db::all(
            "SELECT vl.id_articulo, vl.codigo, vl.descripcion,
                    SUM(vl.cantidad) cantidad, SUM(vl.importe) importe, SUM(vl.cantidad*vl.costo_unitario) costo
             FROM venta_lineas vl JOIN ventas v ON v.id=vl.id_venta
             WHERE v.id_empresa=? AND v.estado='completada' $wx
             GROUP BY vl.id_articulo, vl.codigo, vl.descripcion
             ORDER BY (SUM(vl.importe)/(1+$iva) - SUM(vl.cantidad*vl.costo_unitario)) DESC",
            array_merge([$emp], $ax));

        $tot = ['ingreso' => 0.0, 'costo' => 0.0, 'utilidad' => 0.0, 'margen' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $ingreso = round((float) $r['importe'] / (1 + $iva), 2);
            $costo = round((float) $r['costo'], 2);
            $util = round($ingreso - $costo, 2);
            $margen = $ingreso > 0 ? round($util / $ingreso * 100, 2) : 0.0;
            $tot['ingreso'] += $ingreso; $tot['costo'] += $costo; $tot['utilidad'] += $util;
            $filas[] = ['codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'cantidad' => (float) $r['cantidad'],
                        'ingreso' => $ingreso, 'costo' => $costo, 'utilidad' => $util, 'margen' => $margen];
        }
        foreach (['ingreso', 'costo', 'utilidad'] as $k) $tot[$k] = round($tot[$k], 2);
        $tot['margen'] = $tot['ingreso'] > 0 ? round($tot['utilidad'] / $tot['ingreso'] * 100, 2) : 0.0;
        Http::ok(['totales' => $tot, 'filas' => $filas]);
    }

    // ============================== POR LISTA ==============================
    public static function porLista(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        [$wx, $ax] = self::filtroVentas();
        $rows = Db::all(
            "SELECT vl.lista_aplicada lista, SUM(vl.cantidad) prendas, COUNT(*) num_lineas, SUM(vl.importe) importe
             FROM venta_lineas vl JOIN ventas v ON v.id=vl.id_venta
             WHERE v.id_empresa=? AND v.estado='completada' $wx
             GROUP BY vl.lista_aplicada ORDER BY importe DESC", array_merge([$emp], $ax));
        $totImporte = 0.0; $totPrendas = 0.0;
        foreach ($rows as $r) { $totImporte += (float) $r['importe']; $totPrendas += (float) $r['prendas']; }
        $filas = array_map(fn($r) => [
            'lista' => $r['lista'], 'prendas' => (float) $r['prendas'], 'num_lineas' => (int) $r['num_lineas'],
            'importe' => round((float) $r['importe'], 2),
            'porcentaje' => $totImporte > 0 ? round((float) $r['importe'] / $totImporte * 100, 2) : 0.0,
        ], $rows);
        Http::ok(['totales' => ['importe' => round($totImporte, 2), 'prendas' => round($totPrendas, 2)], 'filas' => $filas]);
    }

    // ============================== TOP PRODUCTOS ==============================
    public static function topProductos(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        [$wx, $ax] = self::filtroVentas();
        $orden = ($_GET['orden'] ?? 'cantidad') === 'importe' ? 'importe' : 'cantidad';
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 20)));
        $rows = Db::all(
            "SELECT vl.codigo, vl.descripcion, SUM(vl.cantidad) cantidad, SUM(vl.importe) importe, COUNT(DISTINCT vl.id_venta) num_ventas
             FROM venta_lineas vl JOIN ventas v ON v.id=vl.id_venta
             WHERE v.id_empresa=? AND v.estado='completada' $wx
             GROUP BY vl.codigo, vl.descripcion ORDER BY $orden DESC LIMIT $limit", array_merge([$emp], $ax));
        Http::ok(['filas' => array_map(fn($r) => [
            'codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'cantidad' => (float) $r['cantidad'],
            'importe' => round((float) $r['importe'], 2), 'num_ventas' => (int) $r['num_ventas'],
        ], $rows)]);
    }

    // ============================== CORTES POR PERIODO ==============================
    public static function cortesPeriodo(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $wx = ''; $ax = [];
        if (!empty($_GET['desde']))      { $wx .= ' AND v.fecha>=?';     $ax[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $wx .= ' AND v.fecha<=?';     $ax[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen'])) { $wx .= ' AND v.id_almacen=?'; $ax[] = (int) $_GET['id_almacen']; }
        $agrupar = ($_GET['agrupar'] ?? 'dia') === 'tienda' ? 'tienda' : 'dia';

        $ventas = Db::all(
            "SELECT v.id, v.fecha, v.monto_credito, v.cambio_efectivo, al.nombre tienda
             FROM ventas v JOIN almacenes al ON al.id=v.id_almacen
             WHERE v.id_empresa=? AND v.estado='completada' $wx", array_merge([$emp], $ax));

        $formasKeys = ['efectivo', 'tdc', 'tdb', 'transferencia', 'monedero'];
        $extra = ['credito' => 0.0, 'cambio_efectivo' => 0.0, 'efectivo_neto' => 0.0];
        $tot = array_merge(['total' => 0.0, 'num_notas' => 0], $extra, array_fill_keys($formasKeys, 0.0));
        $grp = [];
        foreach ($ventas as $v) {
            $clave = $agrupar === 'dia' ? substr($v['fecha'], 0, 10) : $v['tienda'];
            if (!isset($grp[$clave])) $grp[$clave] = array_merge(['clave' => $clave, 'num_notas' => 0, 'total' => 0.0], $extra, array_fill_keys($formasKeys, 0.0));
            $grp[$clave]['num_notas']++; $tot['num_notas']++;
            $grp[$clave]['credito'] += (float) $v['monto_credito']; $tot['credito'] += (float) $v['monto_credito'];
            $grp[$clave]['cambio_efectivo'] += (float) $v['cambio_efectivo']; $tot['cambio_efectivo'] += (float) $v['cambio_efectivo'];
            foreach (Db::all('SELECT forma, importe FROM venta_pagos WHERE id_venta=?', [$v['id']]) as $pg) {
                $f = in_array($pg['forma'], $formasKeys, true) ? $pg['forma'] : 'efectivo';
                $grp[$clave][$f] += (float) $pg['importe']; $tot[$f] += (float) $pg['importe'];
                $grp[$clave]['total'] += (float) $pg['importe']; $tot['total'] += (float) $pg['importe'];
            }
        }
        // efectivo neto = efectivo recibido - cambio entregado en efectivo
        $tot['efectivo_neto'] = $tot['efectivo'] - $tot['cambio_efectivo'];
        foreach ($grp as &$g) { $g['efectivo_neto'] = $g['efectivo'] - $g['cambio_efectivo']; } unset($g);
        foreach ($tot as $k => $val) if (is_float($val)) $tot[$k] = round($val, 2);
        foreach ($grp as &$g) foreach ($g as $k => $val) if (is_float($val)) $g[$k] = round($val, 2); unset($g);
        Http::ok(['totales' => $tot, 'agrupar' => $agrupar, 'filas' => array_values($grp)]);
    }

    // ============================== CxC ANTIGUEDAD ==============================
    public static function cxcAntiguedad(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $rows = Db::all(
            "SELECT c.id, c.nombre cliente, c.plazo_dias, c.saldo_credito, al.nombre tienda,
                    (SELECT MAX(m.fecha) FROM cliente_movimientos m WHERE m.id_cliente=c.id AND m.efecto='cargo') ultimo_cargo
             FROM clientes c LEFT JOIN almacenes al ON al.id=c.id_tienda
             WHERE c.id_empresa=? AND c.saldo_credito>0.0001 ORDER BY c.saldo_credito DESC", [$emp]);
        $tot = ['saldo' => 0.0, 'd0_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_mas' => 0.0];
        $filas = [];
        $hoy = new DateTime(date('Y-m-d'));
        foreach ($rows as $r) {
            $saldo = (float) $r['saldo_credito'];
            $dias = 0;
            if ($r['ultimo_cargo']) { $d = new DateTime(substr($r['ultimo_cargo'], 0, 10)); $dias = (int) $hoy->diff($d)->days; }
            if ($dias <= 30) $tot['d0_30'] += $saldo; elseif ($dias <= 60) $tot['d31_60'] += $saldo; elseif ($dias <= 90) $tot['d61_90'] += $saldo; else $tot['d90_mas'] += $saldo;
            $tot['saldo'] += $saldo;
            $filas[] = ['cliente' => $r['cliente'], 'tienda' => $r['tienda'] ?: '—', 'saldo' => round($saldo, 2),
                        'antiguedad_dias' => $dias, 'plazo_dias' => (int) $r['plazo_dias'],
                        'vencido' => $dias > (int) $r['plazo_dias'], 'ultimo_cargo' => $r['ultimo_cargo']];
        }
        foreach ($tot as $k => $val) $tot[$k] = round($val, 2);
        Http::ok(['totales' => $tot, 'filas' => $filas]);
    }

    // ============================== CxP PROVEEDORES ==============================
    public static function cxpProveedores(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $rows = Db::all(
            "SELECT pr.id, pr.nombre proveedor, m.moneda,
                    SUM(CASE WHEN m.tipo='cargo' THEN m.monto ELSE -m.monto END) saldo
             FROM proveedores pr JOIN proveedor_movimientos m ON m.id_proveedor=pr.id
             WHERE pr.id_empresa=? GROUP BY pr.id, pr.nombre, m.moneda", [$emp]);
        $byProv = []; $totMxn = 0.0; $totUsd = 0.0;
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            if (!isset($byProv[$pid])) $byProv[$pid] = ['proveedor' => $r['proveedor'], 'tipo' => 'Proveedor', 'saldo_mxn' => 0.0, 'saldo_usd' => 0.0];
            if ($r['moneda'] === 'USD') { $byProv[$pid]['saldo_usd'] = round((float) $r['saldo'], 2); $totUsd += (float) $r['saldo']; }
            else { $byProv[$pid]['saldo_mxn'] = round((float) $r['saldo'], 2); $totMxn += (float) $r['saldo']; }
        }
        Http::ok(['totales' => ['mxn' => round($totMxn, 2), 'usd' => round($totUsd, 2)], 'filas' => array_values($byProv)]);
    }

    // ============================== COMISIONES ==============================
    public static function comisiones(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $wx = ''; $ax = [];
        if (!empty($_GET['desde'])) { $wx .= ' AND co.created_at>=?'; $ax[] = $_GET['desde']; }
        if (!empty($_GET['hasta'])) { $wx .= ' AND co.created_at<=?'; $ax[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_vendedor'])) { $wx .= ' AND co.id_empleado=?'; $ax[] = (int) $_GET['id_vendedor']; }
        $rows = Db::all(
            "SELECT TRIM(CONCAT(COALESCE(e.nombre,''),' ',COALESCE(e.apellido,''))) vendedor,
                    COUNT(*) num_ventas, SUM(co.base) base, SUM(co.importe) importe,
                    SUM(CASE WHEN co.pagada='Si' THEN co.importe ELSE 0 END) pagado,
                    SUM(CASE WHEN co.pagada='No' THEN co.importe ELSE 0 END) pendiente
             FROM comisiones co JOIN empleados e ON e.id=co.id_empleado
             WHERE co.id_empresa=? AND co.anulada=0 $wx
             GROUP BY co.id_empleado, vendedor ORDER BY importe DESC", array_merge([$emp], $ax));
        $tot = ['importe' => 0.0, 'pagado' => 0.0, 'pendiente' => 0.0, 'num_ventas' => 0];
        $filas = [];
        foreach ($rows as $r) {
            $tot['importe'] += (float) $r['importe']; $tot['pagado'] += (float) $r['pagado'];
            $tot['pendiente'] += (float) $r['pendiente']; $tot['num_ventas'] += (int) $r['num_ventas'];
            $filas[] = ['vendedor' => $r['vendedor'] ?: '—', 'num_ventas' => (int) $r['num_ventas'], 'base' => round((float) $r['base'], 2),
                        'importe' => round((float) $r['importe'], 2), 'pagado' => round((float) $r['pagado'], 2), 'pendiente' => round((float) $r['pendiente'], 2)];
        }
        foreach (['importe', 'pagado', 'pendiente'] as $k) $tot[$k] = round($tot[$k], 2);
        Http::ok(['totales' => $tot, 'filas' => $filas]);
    }

    // ============================== EXISTENCIAS VALORIZADAS ==============================
    public static function existencias(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $wx = ''; $ax = [];
        if (!empty($_GET['id_almacen'])) { $wx .= ' AND i.id_almacen=?'; $ax[] = (int) $_GET['id_almacen']; }
        $rows = Db::all(
            "SELECT a.codigo, a.descripcion, a.costo,
                    SUM(i.cantidad) cantidad, SUM(i.reservado) reservado
             FROM inventario i JOIN articulos a ON a.id=i.id_articulo
             WHERE i.id_empresa=? $wx
             GROUP BY i.id_articulo, a.codigo, a.descripcion, a.costo
             HAVING cantidad <> 0 ORDER BY (SUM(i.cantidad)*a.costo) DESC", array_merge([$emp], $ax));
        $tot = ['valor' => 0.0, 'articulos' => 0, 'unidades' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $cant = (float) $r['cantidad']; $resv = (float) $r['reservado']; $costo = (float) $r['costo'];
            $valor = round($cant * $costo, 2);
            $tot['valor'] += $valor; $tot['articulos']++; $tot['unidades'] += $cant;
            $filas[] = ['codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'cantidad' => $cant,
                        'reservado' => $resv, 'disponible' => round($cant - $resv, 2), 'costo' => $costo, 'valor' => $valor];
        }
        $tot['valor'] = round($tot['valor'], 2); $tot['unidades'] = round($tot['unidades'], 2);
        Http::ok(['totales' => $tot, 'filas' => $filas]);
    }

    // ============================== KARDEX ==============================
    public static function kardex(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $idArt = id_or_null($_GET['id_articulo'] ?? null);
        if (!$idArt) throw new ApiError('id_articulo es obligatorio', 400, 'VALIDATION');
        $wx = ''; $ax = [];
        if (!empty($_GET['desde']))      { $wx .= ' AND m.created_at>=?'; $ax[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $wx .= ' AND m.created_at<=?'; $ax[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen'])) { $wx .= ' AND m.id_almacen=?';  $ax[] = (int) $_GET['id_almacen']; }
        $rows = Db::all(
            "SELECT m.*, al.nombre almacen, c.nombre color, t.nombre talla
             FROM inventario_movimientos m
             JOIN almacenes al ON al.id=m.id_almacen
             LEFT JOIN atributo_valores c ON c.id=m.id_color
             LEFT JOIN atributo_valores t ON t.id=m.id_talla
             WHERE m.id_empresa=? AND m.id_articulo=? $wx
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1000", array_merge([$emp, $idArt], $ax));
        $tot = ['movimientos' => 0, 'entradas' => 0.0, 'salidas' => 0.0, 'neto' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $cant = (float) $r['cantidad'];
            $tot['movimientos']++; if ($cant >= 0) $tot['entradas'] += $cant; else $tot['salidas'] += abs($cant); $tot['neto'] += $cant;
            $filas[] = ['fecha' => $r['created_at'], 'tipo' => $r['tipo'], 'folio' => $r['folio'], 'almacen' => $r['almacen'],
                        'color' => (int) $r['id_color'] === 0 ? 'Unico' : ($r['color'] ?? ''), 'talla' => (int) $r['id_talla'] === 0 ? 'Unica' : ($r['talla'] ?? ''),
                        'cantidad' => $cant, 'saldo_resultante' => (float) $r['saldo_resultante'], 'motivo' => $r['motivo']];
        }
        foreach (['entradas', 'salidas', 'neto'] as $k) $tot[$k] = round($tot[$k], 2);
        $art = Db::one('SELECT codigo, descripcion FROM articulos WHERE id=?', [$idArt]);
        Http::ok(['articulo' => $art ? ['codigo' => $art['codigo'], 'descripcion' => $art['descripcion']] : null, 'totales' => $tot, 'filas' => $filas]);
    }

    // ============================== COMPRAS ==============================
    public static function compras(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $wx = ''; $ax = [];
        if (!empty($_GET['desde']))        { $wx .= ' AND c.fecha>=?';       $ax[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))        { $wx .= ' AND c.fecha<=?';       $ax[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen']))   { $wx .= ' AND c.id_almacen=?';   $ax[] = (int) $_GET['id_almacen']; }
        if (!empty($_GET['id_proveedor'])) { $wx .= ' AND c.id_proveedor=?'; $ax[] = (int) $_GET['id_proveedor']; }
        if (!empty($_GET['estado']))       { $wx .= ' AND c.estado=?';       $ax[] = $_GET['estado']; }
        $rows = Db::all(
            "SELECT c.folio, c.fecha, c.estado, c.total, pr.nombre proveedor, al.nombre almacen,
                    (SELECT COALESCE(SUM(cl.cantidad),0) FROM compra_lineas cl WHERE cl.id_compra=c.id) piezas
             FROM compras c JOIN proveedores pr ON pr.id=c.id_proveedor JOIN almacenes al ON al.id=c.id_almacen
             WHERE c.id_empresa=? $wx ORDER BY c.fecha DESC, c.id DESC", array_merge([$emp], $ax));
        $tot = ['total' => 0.0, 'num_compras' => 0, 'piezas' => 0.0];
        $filas = [];
        foreach ($rows as $r) {
            $tot['total'] += (float) $r['total']; $tot['num_compras']++; $tot['piezas'] += (float) $r['piezas'];
            $filas[] = ['folio' => $r['folio'], 'fecha' => $r['fecha'], 'proveedor' => $r['proveedor'], 'almacen' => $r['almacen'],
                        'piezas' => (float) $r['piezas'], 'estado' => $r['estado'], 'total' => (float) $r['total']];
        }
        $tot['total'] = round($tot['total'], 2); $tot['piezas'] = round($tot['piezas'], 2);
        Http::ok(['totales' => $tot, 'filas' => $filas]);
    }

    // ============================== DEVOLUCIONES ==============================
    public static function devoluciones(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $wx = ''; $ax = [];
        if (!empty($_GET['desde']))      { $wx .= ' AND d.fecha>=?';     $ax[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $wx .= ' AND d.fecha<=?';     $ax[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen'])) { $wx .= ' AND d.id_almacen=?'; $ax[] = (int) $_GET['id_almacen']; }

        $devs = Db::all(
            "SELECT d.folio, d.fecha, d.folio_venta, d.total, d.destino_saldo, al.nombre tienda
             FROM devoluciones d LEFT JOIN almacenes al ON al.id=d.id_almacen
             WHERE d.id_empresa=? $wx ORDER BY d.fecha DESC, d.id DESC", array_merge([$emp], $ax));
        // cambios usan el mismo filtro de almacen/fechas
        $wc = str_replace('d.', 'ca.', $wx);
        $cambios = Db::all(
            "SELECT ca.folio, ca.fecha, ca.folio_venta, ca.total_devuelto, ca.total_nuevo, ca.diferencia, al.nombre tienda
             FROM cambios ca LEFT JOIN almacenes al ON al.id=ca.id_almacen
             WHERE ca.id_empresa=? $wc ORDER BY ca.fecha DESC, ca.id DESC", array_merge([$emp], $ax));

        $totalDev = 0.0;
        $devOut = array_map(function ($d) use (&$totalDev) {
            $totalDev += (float) $d['total'];
            return ['folio' => $d['folio'], 'fecha' => $d['fecha'], 'folio_venta' => $d['folio_venta'],
                    'tienda' => $d['tienda'] ?: '—', 'total' => (float) $d['total'], 'destino_saldo' => $d['destino_saldo']];
        }, $devs);
        $cambiosOut = array_map(fn($c) => [
            'folio' => $c['folio'], 'fecha' => $c['fecha'], 'folio_venta' => $c['folio_venta'], 'tienda' => $c['tienda'] ?: '—',
            'total_devuelto' => (float) $c['total_devuelto'], 'total_nuevo' => (float) $c['total_nuevo'], 'diferencia' => (float) $c['diferencia'],
        ], $cambios);

        // tasa de devolucion = total devuelto / total vendido del periodo (ventas)
        $wv = ''; $av = [];
        if (!empty($_GET['desde']))      { $wv .= ' AND v.fecha>=?';     $av[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))      { $wv .= ' AND v.fecha<=?';     $av[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['id_almacen'])) { $wv .= ' AND v.id_almacen=?'; $av[] = (int) $_GET['id_almacen']; }
        $totalVendido = (float) (Db::one("SELECT COALESCE(SUM(v.total),0) t FROM ventas v WHERE v.id_empresa=? AND v.estado='completada' $wv", array_merge([$emp], $av))['t'] ?? 0);
        $tasa = $totalVendido > 0 ? round($totalDev / $totalVendido * 100, 2) : 0.0;

        Http::ok([
            'totales' => ['total_devoluciones' => round($totalDev, 2), 'num_devoluciones' => count($devOut),
                          'tasa_devolucion' => $tasa, 'num_cambios' => count($cambiosOut)],
            'devoluciones' => $devOut, 'cambios' => $cambiosOut,
        ]);
    }

    // ============================== DASHBOARD DE PRODUCTOS ==============================
    /**
     * Indicadores de producto para cualquier tipo de artículo:
     *  - Ventas: más vendidos, menos vendidos, en la media, sin ventas (stock parado).
     *  - Stock: agotados y bajos (<= umbral).
     *  - Caducidad: caducados y por caducar, leyendo el campo de ficha cuyo nombre
     *    contenga "caduc"/"vence"/"expira" (tipo fecha). Los kits no cuentan para stock.
     * Params: desde, hasta, id_almacen, umbral_bajo (def 5), dias_caducar (def 30).
     */
    public static function dashboardProductos(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $desde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days'));
        $hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');
        $idAlm = id_or_null($_GET['id_almacen'] ?? null);
        $umbral = max(0, (float) ($_GET['umbral_bajo'] ?? 5));
        $diasCad = max(1, (int) ($_GET['dias_caducar'] ?? 30));

        // ---- Ventas por artículo en el periodo ----
        $wv = 'v.id_empresa=? AND v.estado=\'completada\' AND v.fecha>=? AND v.fecha<=?';
        $av = [$emp, $desde, $hasta . ' 23:59:59'];
        if ($idAlm) { $wv .= ' AND v.id_almacen=?'; $av[] = $idAlm; }
        $ventasRows = Db::all(
            "SELECT vl.id_articulo, vl.codigo, vl.descripcion, SUM(vl.cantidad) cantidad, SUM(vl.importe) importe
             FROM venta_lineas vl JOIN ventas v ON v.id=vl.id_venta
             WHERE $wv GROUP BY vl.id_articulo, vl.codigo, vl.descripcion", $av);

        $ventasPorArt = []; $unidadesTot = 0.0; $importeTot = 0.0; $conVentas = [];
        foreach ($ventasRows as $r) {
            $c = (float) $r['cantidad'];
            $fila = ['codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'cantidad' => $c, 'importe' => round((float) $r['importe'], 2)];
            $ventasPorArt[(int) $r['id_articulo']] = $fila; $conVentas[] = $fila;
            $unidadesTot += $c; $importeTot += (float) $r['importe'];
        }
        usort($conVentas, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);
        $top = array_slice($conVentas, 0, 10);
        $bottom = array_slice(array_reverse($conVentas), 0, 10);
        $promedio = count($conVentas) > 0 ? round($unidadesTot / count($conVentas), 2) : 0.0;
        $media = $promedio > 0
            ? array_slice(array_values(array_filter($conVentas, fn($x) => abs($x['cantidad'] - $promedio) <= $promedio * 0.25)), 0, 15)
            : [];

        // ---- Stock por artículo ----
        $ws = 'i.id_empresa=?'; $as = [$emp];
        if ($idAlm) { $ws .= ' AND i.id_almacen=?'; $as[] = $idAlm; }
        $stockPorArt = [];
        foreach (Db::all("SELECT i.id_articulo, SUM(i.cantidad) stock FROM inventario i WHERE $ws GROUP BY i.id_articulo", $as) as $r)
            $stockPorArt[(int) $r['id_articulo']] = (float) $r['stock'];

        // ---- Recorrer artículos activos ----
        $arts = Db::all("SELECT id, codigo, descripcion, costo, es_kit, ficha FROM articulos WHERE id_empresa=? AND is_active='Si'", [$emp]);
        $agotados = []; $bajos = []; $sinVentas = []; $valorInv = 0.0;
        $caducados = []; $porCaducar = []; $hayCampoCad = false;
        $hoy = new DateTime(date('Y-m-d'));
        foreach ($arts as $a) {
            $id = (int) $a['id'];
            $stock = $stockPorArt[$id] ?? 0.0;
            $valorInv += $stock * (float) $a['costo'];
            if (!(int) $a['es_kit']) {
                if ($stock <= 0) $agotados[] = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'stock' => $stock];
                elseif ($stock <= $umbral) $bajos[] = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'stock' => $stock];
            }
            if (!isset($ventasPorArt[$id])) $sinVentas[] = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'stock' => $stock];
            if (!empty($a['ficha'])) {
                $ficha = json_decode($a['ficha'], true);
                if (is_array($ficha)) {
                    foreach ($ficha as $k => $val) {
                        if (!$val || !preg_match('/caduc|vence|expir/i', (string) $k)) continue;
                        $hayCampoCad = true;
                        $ts = strtotime((string) $val);
                        if ($ts === false) break;
                        $fd = new DateTime(date('Y-m-d', $ts));
                        $dias = (int) $hoy->diff($fd)->format('%r%a');
                        $item = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'fecha' => date('Y-m-d', $ts), 'dias' => $dias];
                        if ($dias < 0) $caducados[] = $item;
                        elseif ($dias <= $diasCad) $porCaducar[] = $item;
                        break;
                    }
                }
            }
        }
        $sinVentasCount = count($sinVentas);
        usort($bajos, fn($a, $b) => $a['stock'] <=> $b['stock']);
        usort($sinVentas, fn($a, $b) => $b['stock'] <=> $a['stock']);
        usort($caducados, fn($a, $b) => $a['dias'] <=> $b['dias']);
        usort($porCaducar, fn($a, $b) => $a['dias'] <=> $b['dias']);
        $sinVentas = array_slice($sinVentas, 0, 50);

        Http::ok([
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'params'  => ['umbral_bajo' => $umbral, 'dias_caducar' => $diasCad],
            'kpis' => [
                'articulos_activos' => count($arts),
                'con_ventas'        => count($conVentas),
                'sin_ventas'        => $sinVentasCount,
                'unidades_vendidas' => round($unidadesTot, 2),
                'importe_vendido'   => round($importeTot, 2),
                'valor_inventario'  => round($valorInv, 2),
                'promedio_unidades' => $promedio,
                'agotados'          => count($agotados),
                'bajos'             => count($bajos),
                'caducados'         => count($caducados),
                'por_caducar'       => count($porCaducar),
            ],
            'top' => $top, 'bottom' => $bottom, 'media' => $media,
            'sin_ventas' => $sinVentas, 'agotados' => $agotados, 'bajos' => $bajos,
            'caducidad' => ['hay_campo' => $hayCampoCad, 'caducados' => $caducados, 'por_caducar' => $porCaducar],
        ]);
    }
}
