<?php
class TraspasoController
{
    private static function nextFolio(int $emp): string
    {
        $n = (int) (Db::one('SELECT COUNT(*) c FROM traspasos WHERE id_empresa=?', [$emp])['c'] ?? 0) + 1;
        return 'T-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    /** Existencia actual de una celda articulo+color+talla en un almacen */
    private static function stockCelda(int $idArt, int $idColor, int $idTalla, int $idAlm): float
    {
        $inv = Db::one('SELECT cantidad FROM inventario WHERE id_articulo=? AND id_color=? AND id_talla=? AND id_almacen=?',
            [$idArt, $idColor, $idTalla, $idAlm]);
        return $inv ? (float) $inv['cantidad'] : 0.0;
    }

    private static function fmtLinea(array $l): array
    {
        return [
            '_id'         => (string) $l['id'],
            'id_articulo' => ['_id' => (string) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion']],
            'codigo'      => $l['codigo'],
            'descripcion' => $l['descripcion'],
            'id_color'    => (int) $l['id_color'] === 0 ? ['_id' => '', 'nombre' => 'Unico'] : ['_id' => (string) $l['id_color'], 'nombre' => $l['col_nombre'] ?? null],
            'id_talla'    => (int) $l['id_talla'] === 0 ? ['_id' => '', 'nombre' => 'Unica'] : ['_id' => (string) $l['id_talla'], 'nombre' => $l['tal_nombre'] ?? null],
            'cantidad'    => (float) $l['cantidad'],
        ];
    }

    private static function fmtRow(array $t, array $lineas): array
    {
        return [
            '_id'   => (string) $t['id'],
            'folio' => $t['folio'],
            'fecha' => $t['fecha'],
            'id_almacen_origen'  => ['_id' => (string) $t['id_almacen_origen'],  'nombre' => $t['origen_nombre']  ?? '', 'tipo' => $t['origen_tipo']  ?? null],
            'id_almacen_destino' => ['_id' => (string) $t['id_almacen_destino'], 'nombre' => $t['destino_nombre'] ?? '', 'tipo' => $t['destino_tipo'] ?? null],
            'estado' => $t['estado'],
            'notas'  => $t['notas'] ?? null,
            'creado_por'   => isset($t['creador_nombre']) ? ['nombre' => $t['creador_nombre'], 'apellido' => $t['creador_apellido']] : null,
            'aceptado_por' => isset($t['acept_nombre']) && $t['aceptado_por'] ? ['nombre' => $t['acept_nombre'], 'apellido' => $t['acept_apellido']] : null,
            'fecha_aceptacion' => $t['fecha_aceptacion'] ?? null,
            'lineas' => array_map([self::class, 'fmtLinea'], $lineas),
            'createdAt' => $t['created_at'] ?? null,
        ];
    }

    public static function obtenerTraspaso(int $emp, int $id): array
    {
        $t = Db::one(
            'SELECT t.*, ao.nombre origen_nombre, ao.tipo origen_tipo, ad.nombre destino_nombre, ad.tipo destino_tipo,
                    uc.nombre creador_nombre, uc.apellido creador_apellido, ua.nombre acept_nombre, ua.apellido acept_apellido
             FROM traspasos t
             JOIN almacenes ao ON ao.id=t.id_almacen_origen
             JOIN almacenes ad ON ad.id=t.id_almacen_destino
             LEFT JOIN users uc ON uc.id=t.id_usuario
             LEFT JOIN users ua ON ua.id=t.aceptado_por
             WHERE t.id=? AND t.id_empresa=?', [$id, $emp]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        $lineas = Db::all(
            'SELECT tl.*, c.nombre col_nombre, t.nombre tal_nombre
             FROM traspaso_lineas tl
             LEFT JOIN atributo_valores c ON c.id=tl.id_color
             LEFT JOIN atributo_valores t ON t.id=tl.id_talla
             WHERE tl.id_traspaso=?', [$id]);
        return self::fmtRow($t, $lineas);
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 't.id_empresa=?'; $args = [$emp];
        if (!empty($_GET['id_almacen_origen']))  { $where .= ' AND t.id_almacen_origen=?';  $args[] = (int) $_GET['id_almacen_origen']; }
        if (!empty($_GET['id_almacen_destino'])) { $where .= ' AND t.id_almacen_destino=?'; $args[] = (int) $_GET['id_almacen_destino']; }
        if (!empty($_GET['estado']))             { $where .= ' AND t.estado=?';              $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))              { $where .= ' AND t.fecha>=?';              $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))              { $where .= ' AND t.fecha<=?';              $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));

        $rows = Db::all(
            "SELECT t.*, ao.nombre origen_nombre, ad.nombre destino_nombre
             FROM traspasos t
             JOIN almacenes ao ON ao.id=t.id_almacen_origen
             JOIN almacenes ad ON ad.id=t.id_almacen_destino
             WHERE $where ORDER BY t.fecha DESC, t.id DESC LIMIT $limit", $args);

        // lineas de todos los traspasos listados en una sola consulta (sin N+1)
        $porTraspaso = [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        if (count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT * FROM traspaso_lineas WHERE id_traspaso IN ($ph)", $ids) as $l) {
                $porTraspaso[(int) $l['id_traspaso']][] = $l;
            }
        }

        $out = array_map(fn($t) => self::fmtRow($t, $porTraspaso[(int) $t['id']] ?? []), $rows);
        Http::ok($out);
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerTraspaso((int) $ctx['user']['id_empresa'], (int) $p['id']));
    }

    /** Valida cabecera + lineas; devuelve [idOrigen, idDestino, lineas[]] */
    private static function preparar(int $emp, array $b): array
    {
        $idOrigen  = id_or_null($b['id_almacen_origen'] ?? null);
        $idDestino = id_or_null($b['id_almacen_destino'] ?? null);
        if (!$idOrigen || !$idDestino) throw new ApiError('Origen y destino son obligatorios', 400, 'VALIDATION');
        if ($idOrigen === $idDestino)  throw new ApiError('El origen y el destino deben ser distintos', 400, 'VALIDATION');
        foreach ([[$idOrigen, 'origen'], [$idDestino, 'destino']] as [$idAlm, $rol]) {
            if (!Db::one('SELECT id FROM almacenes WHERE id=? AND id_empresa=?', [$idAlm, $emp]))
                throw new ApiError("Almacen $rol no encontrado", 404, 'NOT_FOUND');
        }

        $raw = $b['lineas'] ?? [];
        if (!is_array($raw) || count($raw) === 0) throw new ApiError('El traspaso no tiene lineas', 400, 'VALIDATION');

        // consolidar cantidades por celda (por si llega repetida) y validar stock en origen
        $lineas = [];
        foreach ($raw as $ln) {
            $idArt = id_or_null($ln['id_articulo'] ?? null);
            if (!$idArt) throw new ApiError('Linea sin id_articulo', 400, 'VALIDATION');
            $cant = (float) ($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $art = Db::one('SELECT id,codigo,descripcion FROM articulos WHERE id=? AND id_empresa=?', [$idArt, $emp]);
            if (!$art) throw new ApiError("Articulo $idArt no encontrado", 404, 'NOT_FOUND');
            $idColor = id_or_zero($ln['id_color'] ?? 0);
            $idTalla = id_or_zero($ln['id_talla'] ?? 0);
            $key = "$idArt-$idColor-$idTalla";
            if (!isset($lineas[$key])) {
                $lineas[$key] = ['id_articulo' => $idArt, 'codigo' => $art['codigo'], 'descripcion' => $art['descripcion'],
                                 'id_color' => $idColor, 'id_talla' => $idTalla, 'cantidad' => 0.0];
            }
            $lineas[$key]['cantidad'] += $cant;
        }
        if (count($lineas) === 0) throw new ApiError('Captura al menos una cantidad mayor a cero', 400, 'VALIDATION');

        foreach ($lineas as $l) {
            $disp = self::stockCelda($l['id_articulo'], $l['id_color'], $l['id_talla'], $idOrigen);
            if ($l['cantidad'] > $disp + 0.0001) {
                throw new ApiError("Stock insuficiente en origen para {$l['codigo']} (disponible $disp, solicitado {$l['cantidad']})", 400, 'VALIDATION');
            }
        }
        return [$idOrigen, $idDestino, array_values($lineas)];
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        [$idOrigen, $idDestino, $lineas] = self::preparar($emp, $b);

        Db::begin();
        try {
            $folio = self::nextFolio($emp);
            $tid = Db::insert(
                'INSERT INTO traspasos (id_empresa,folio,fecha,id_almacen_origen,id_almacen_destino,id_usuario,estado,notas)
                 VALUES (?,?,NOW(),?,?,?,\'pendiente\',?)',
                [$emp, $folio, $idOrigen, $idDestino, (int) $ctx['user']['id'], $b['notas'] ?? null]);
            foreach ($lineas as $l) {
                Db::insert(
                    'INSERT INTO traspaso_lineas (id_traspaso,id_articulo,codigo,descripcion,id_color,id_talla,cantidad)
                     VALUES (?,?,?,?,?,?,?)',
                    [$tid, $l['id_articulo'], $l['codigo'], $l['descripcion'], $l['id_color'], $l['id_talla'], $l['cantidad']]);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::created(self::obtenerTraspaso($emp, $tid), 'Traspaso');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $t = Db::one('SELECT * FROM traspasos WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        if ($t['estado'] !== 'pendiente') throw new ApiError('Solo se puede editar un traspaso pendiente', 400, 'VALIDATION');

        // si no mandan cabecera, conservar la actual para validar
        $b['id_almacen_origen']  = $b['id_almacen_origen']  ?? $t['id_almacen_origen'];
        $b['id_almacen_destino'] = $b['id_almacen_destino'] ?? $t['id_almacen_destino'];
        [$idOrigen, $idDestino, $lineas] = self::preparar($emp, $b);

        Db::begin();
        try {
            Db::run('UPDATE traspasos SET id_almacen_origen=?, id_almacen_destino=?, notas=? WHERE id=?',
                [$idOrigen, $idDestino, array_key_exists('notas', $b) ? $b['notas'] : $t['notas'], $id]);
            Db::run('DELETE FROM traspaso_lineas WHERE id_traspaso=?', [$id]);
            foreach ($lineas as $l) {
                Db::insert(
                    'INSERT INTO traspaso_lineas (id_traspaso,id_articulo,codigo,descripcion,id_color,id_talla,cantidad)
                     VALUES (?,?,?,?,?,?,?)',
                    [$id, $l['id_articulo'], $l['codigo'], $l['descripcion'], $l['id_color'], $l['id_talla'], $l['cantidad']]);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::updated(self::obtenerTraspaso($emp, $id), 'Traspaso');
    }

    public static function aceptar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $t = Db::one('SELECT * FROM traspasos WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        if ($t['estado'] !== 'pendiente') throw new ApiError('El traspaso ya fue ' . $t['estado'], 400, 'VALIDATION');

        $lineas = Db::all('SELECT * FROM traspaso_lineas WHERE id_traspaso=?', [$id]);
        if (count($lineas) === 0) throw new ApiError('El traspaso no tiene lineas', 400, 'VALIDATION');

        $origen  = (int) $t['id_almacen_origen'];
        $destino = (int) $t['id_almacen_destino'];

        Db::begin();
        try {
            // revalidar stock en origen al momento de aceptar (pudo cambiar)
            foreach ($lineas as $l) {
                $disp = self::stockCelda((int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'], $origen);
                if ((float) $l['cantidad'] > $disp + 0.0001) {
                    throw new ApiError("Stock insuficiente en origen para {$l['codigo']} (disponible $disp)", 400, 'VALIDATION');
                }
            }
            foreach ($lineas as $l) {
                InventarioController::aplicar($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                    $origen, -1 * (float) $l['cantidad'], 'traspaso_salida', 'Traspaso ' . $t['folio'] . ' (salida)',
                    'Traspaso', $id, (int) $ctx['user']['id'], $t['folio']);
                InventarioController::aplicar($emp, (int) $l['id_articulo'], (int) $l['id_color'], (int) $l['id_talla'],
                    $destino, (float) $l['cantidad'], 'traspaso_entrada', 'Traspaso ' . $t['folio'] . ' (entrada)',
                    'Traspaso', $id, (int) $ctx['user']['id'], $t['folio']);
            }
            Db::run('UPDATE traspasos SET estado=\'aceptado\', aceptado_por=?, fecha_aceptacion=NOW() WHERE id=?',
                [(int) $ctx['user']['id'], $id]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Http::ok(self::obtenerTraspaso($emp, $id), 'Traspaso aceptado');
    }

    public static function rechazar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $id = (int) $p['id'];
        $t = Db::one('SELECT * FROM traspasos WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        if ($t['estado'] !== 'pendiente') throw new ApiError('El traspaso ya fue ' . $t['estado'], 400, 'VALIDATION');
        // pendiente no movio stock; solo se marca rechazado
        Db::run('UPDATE traspasos SET estado=\'rechazado\', aceptado_por=?, fecha_aceptacion=NOW() WHERE id=?',
            [(int) $ctx['user']['id'], $id]);
        Http::ok(self::obtenerTraspaso($emp, $id), 'Traspaso rechazado');
    }
}
