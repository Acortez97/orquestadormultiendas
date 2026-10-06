<?php
// Traspasos entre almacenes de la MISMA tienda: pendiente -> aceptado (mueve stock) / rechazado.
class TraspasoController
{
    private static function fmtLinea(array $l, array $nom): array
    {
        return [
            '_id'         => (int) $l['id'],
            'id_articulo' => ['_id' => (int) $l['id_articulo'], 'codigo' => $l['codigo'], 'descripcion' => $l['descripcion']],
            'codigo'      => $l['codigo'],
            'descripcion' => $l['descripcion'],
            'id_color'    => Variantes::ref($l['id_valor1'], $nom[(int) $l['id_valor1']] ?? null, null, 0, 'Unico'),
            'id_talla'    => Variantes::ref($l['id_valor2'], $nom[(int) $l['id_valor2']] ?? null, null, 0, 'Unica'),
            'cantidad'    => (float) $l['cantidad'],
        ];
    }

    private static function fmtRow(array $t, array $lineas): array
    {
        $nom = Variantes::nombres(array_merge(array_column($lineas, 'id_valor1'), array_column($lineas, 'id_valor2')));
        return [
            '_id'   => (int) $t['id'],
            'folio' => $t['folio'],
            'fecha' => $t['fecha'],
            'id_almacen_origen'  => ['_id' => (int) $t['id_almacen_origen'],  'nombre' => $t['origen_nombre'] ?? '',  'tipo' => $t['origen_tipo'] ?? null],
            'id_almacen_destino' => ['_id' => (int) $t['id_almacen_destino'], 'nombre' => $t['destino_nombre'] ?? '', 'tipo' => $t['destino_tipo'] ?? null],
            'estado' => $t['estado'],
            'notas'  => $t['notas'] ?? null,
            'creado_por'   => isset($t['creador_nombre']) ? ['nombre' => $t['creador_nombre'], 'apellido' => $t['creador_apellido']] : null,
            'aceptado_por' => isset($t['acept_nombre']) && $t['aceptado_por'] ? ['nombre' => $t['acept_nombre'], 'apellido' => $t['acept_apellido']] : null,
            'fecha_aceptacion' => $t['fecha_aceptacion'] ?? null,
            'lineas' => array_map(fn($l) => self::fmtLinea($l, $nom), $lineas),
            'createdAt' => $t['created_at'] ?? null,
        ];
    }

    public static function obtenerTraspaso(int $emp, int $id): array
    {
        $t = Db::one(
            'SELECT t.*, ao.nombre origen_nombre, ao.tipo origen_tipo, ad.nombre destino_nombre, ad.tipo destino_tipo,
                    uc.nombre creador_nombre, uc.apellido creador_apellido, ua.nombre acept_nombre, ua.apellido acept_apellido
             FROM traspasos t
             JOIN almacenes ao ON ao.id_empresa = t.id_empresa AND ao.id = t.id_almacen_origen
             JOIN almacenes ad ON ad.id_empresa = t.id_empresa AND ad.id = t.id_almacen_destino
             LEFT JOIN users uc ON uc.id_empresa = t.id_empresa AND uc.id = t.id_usuario
             LEFT JOIN users ua ON ua.id_empresa = t.id_empresa AND ua.id = t.aceptado_por
             WHERE t.id = ? AND t.id_empresa = ?', [$id, $emp]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        return self::fmtRow($t, Db::all('SELECT * FROM traspaso_lineas WHERE id_empresa = ? AND id_traspaso = ? ORDER BY id', [$emp, $id]));
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $where = 't.id_empresa = ?'; $args = [$emp];
        if (!empty($_GET['id_almacen_origen']))  { $where .= ' AND t.id_almacen_origen = ?';  $args[] = (int) $_GET['id_almacen_origen']; }
        if (!empty($_GET['id_almacen_destino'])) { $where .= ' AND t.id_almacen_destino = ?'; $args[] = (int) $_GET['id_almacen_destino']; }
        if (!empty($_GET['estado']))             { $where .= ' AND t.estado = ?';              $args[] = $_GET['estado']; }
        if (!empty($_GET['desde']))              { $where .= ' AND t.fecha >= ?';              $args[] = $_GET['desde']; }
        if (!empty($_GET['hasta']))              { $where .= ' AND t.fecha <= ?';              $args[] = $_GET['hasta'] . ' 23:59:59'; }
        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));
        $rows = Db::all(
            "SELECT t.*, ao.nombre origen_nombre, ad.nombre destino_nombre FROM traspasos t
             JOIN almacenes ao ON ao.id_empresa = t.id_empresa AND ao.id = t.id_almacen_origen
             JOIN almacenes ad ON ad.id_empresa = t.id_empresa AND ad.id = t.id_almacen_destino
             WHERE $where ORDER BY t.fecha DESC, t.id DESC LIMIT $limit", $args);

        $porTraspaso = [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT * FROM traspaso_lineas WHERE id_empresa = ? AND id_traspaso IN ($ph)", array_merge([$emp], $ids)) as $l) {
                $porTraspaso[(int) $l['id_traspaso']][] = $l;
            }
        }
        Http::ok(array_map(fn($t) => self::fmtRow($t, $porTraspaso[(int) $t['id']] ?? []), $rows));
    }

    public static function obtener(array $p, array $ctx): void
    {
        Http::ok(self::obtenerTraspaso(Tenant::id(), (int) $p['id']));
    }

    /** Valida cabecera + lineas; consolida por celda y valida existencia disponible en origen. */
    private static function preparar(array $b): array
    {
        $emp = Tenant::id();
        $idOrigen  = Tenant::owns('almacenes', $b['id_almacen_origen'] ?? null, 'Almacen origen', true);
        $idDestino = Tenant::owns('almacenes', $b['id_almacen_destino'] ?? null, 'Almacen destino', true);
        if ($idOrigen === $idDestino) throw new ApiError('El origen y el destino deben ser distintos', 400, 'VALIDATION');

        $raw = $b['lineas'] ?? [];
        if (!is_array($raw) || count($raw) === 0) throw new ApiError('El traspaso no tiene lineas', 400, 'VALIDATION');
        $lineas = [];
        foreach ($raw as $ln) {
            $cant = num($ln['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $idArt = Tenant::owns('articulos', $ln['id_articulo'] ?? null, 'Articulo', true);
            $art = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [$idArt, $emp]);
            [$v1, $v2] = Variantes::validar($art, $ln['id_color'] ?? null, $ln['id_talla'] ?? null);
            $key = $idArt . '-' . (int) $v1 . '-' . (int) $v2;
            if (!isset($lineas[$key])) {
                $lineas[$key] = ['id_articulo' => $idArt, 'codigo' => $art['codigo'], 'descripcion' => $art['descripcion'], 'v1' => $v1, 'v2' => $v2, 'cantidad' => 0.0];
            }
            $lineas[$key]['cantidad'] += $cant;
        }
        if (!$lineas) throw new ApiError('Captura al menos una cantidad mayor a cero', 400, 'VALIDATION');
        foreach ($lineas as $l) self::validarStock($emp, $l['id_articulo'], $l['v1'], $l['v2'], $idOrigen, $l['cantidad'], $l['codigo']);
        return [$idOrigen, $idDestino, array_values($lineas)];
    }

    private static function validarStock(int $emp, int $idArt, ?int $v1, ?int $v2, int $idAlm, float $cant, string $codigo): void
    {
        $disp = InventarioController::disponible($emp, $idArt, $v1, $v2, $idAlm);
        if ($cant > $disp + 0.0001) throw new ApiError("Stock insuficiente en origen para $codigo (disponible $disp, solicitado $cant)", 400, 'VALIDATION');
    }

    private static function insertarLineas(int $emp, int $tid, array $lineas): void
    {
        foreach ($lineas as $l) {
            Db::insert('INSERT INTO traspaso_lineas (id_empresa, id_traspaso, id_articulo, id_valor1, id_valor2, codigo, descripcion, cantidad) VALUES (?,?,?,?,?,?,?,?)',
                [$emp, $tid, $l['id_articulo'], $l['v1'], $l['v2'], $l['codigo'], $l['descripcion'], $l['cantidad']]);
        }
    }

    private static function bloquear(int $id): array
    {
        $t = Db::one('SELECT * FROM traspasos WHERE id = ? AND id_empresa = ? FOR UPDATE', [$id, Tenant::id()]);
        if (!$t) throw new ApiError('Traspaso no encontrado', 404, 'NOT_FOUND');
        if ($t['estado'] !== 'pendiente') throw new ApiError('El traspaso ya fue ' . $t['estado'], 400, 'VALIDATION');
        return $t;
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        [$idOrigen, $idDestino, $lineas] = self::preparar($b);
        Db::begin();
        $folio = Db::folio($emp, 'traspaso', 'T', 5);
        $tid = Db::insert("INSERT INTO traspasos (id_empresa, folio, fecha, id_almacen_origen, id_almacen_destino, id_usuario, estado, notas)
                           VALUES (?,?,NOW(),?,?,?,'pendiente',?)", [$emp, $folio, $idOrigen, $idDestino, $ctx['user']['id'], $b['notas'] ?? null]);
        self::insertarLineas($emp, $tid, $lineas);
        Db::commit();
        Ledger::audit($ctx, 'crear', 'Traspaso', $tid, 'Traspaso ' . $folio);
        Http::created(self::obtenerTraspaso($emp, $tid), 'Traspaso');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $t = self::bloquear($id);
        $b['id_almacen_origen']  = $b['id_almacen_origen']  ?? (int) $t['id_almacen_origen'];
        $b['id_almacen_destino'] = $b['id_almacen_destino'] ?? (int) $t['id_almacen_destino'];
        if (!array_key_exists('lineas', $b)) {
            $b['lineas'] = array_map(fn($l) => ['id_articulo' => (int) $l['id_articulo'], 'id_color' => $l['id_valor1'], 'id_talla' => $l['id_valor2'], 'cantidad' => $l['cantidad']],
                Db::all('SELECT * FROM traspaso_lineas WHERE id_empresa = ? AND id_traspaso = ?', [$emp, $id]));
        }
        [$idOrigen, $idDestino, $lineas] = self::preparar($b);
        Db::run('UPDATE traspasos SET id_almacen_origen = ?, id_almacen_destino = ?, notas = ? WHERE id = ? AND id_empresa = ?',
            [$idOrigen, $idDestino, array_key_exists('notas', $b) ? $b['notas'] : $t['notas'], $id, $emp]);
        Db::run('DELETE FROM traspaso_lineas WHERE id_empresa = ? AND id_traspaso = ?', [$emp, $id]);
        self::insertarLineas($emp, $id, $lineas);
        Db::commit();
        Http::updated(self::obtenerTraspaso($emp, $id), 'Traspaso');
    }

    public static function aceptar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $t = self::bloquear($id);
        $lineas = Db::all('SELECT * FROM traspaso_lineas WHERE id_empresa = ? AND id_traspaso = ?', [$emp, $id]);
        if (!$lineas) throw new ApiError('El traspaso no tiene lineas', 400, 'VALIDATION');
        $origen = (int) $t['id_almacen_origen']; $destino = (int) $t['id_almacen_destino'];
        $idUser = $ctx['user']['id'];
        foreach ($lineas as $l) {   // revalidar al aceptar: el stock pudo cambiar
            self::validarStock($emp, (int) $l['id_articulo'], Variantes::norm($l['id_valor1']), Variantes::norm($l['id_valor2']), $origen, (float) $l['cantidad'], $l['codigo']);
        }
        foreach ($lineas as $l) {
            $v1 = Variantes::norm($l['id_valor1']); $v2 = Variantes::norm($l['id_valor2']);
            InventarioController::aplicar($emp, (int) $l['id_articulo'], $v1, $v2, $origen, -1 * (float) $l['cantidad'], 'traspaso_salida',
                'Traspaso ' . $t['folio'] . ' (salida)', 'Traspaso', $id, $idUser, $t['folio']);
            InventarioController::aplicar($emp, (int) $l['id_articulo'], $v1, $v2, $destino, (float) $l['cantidad'], 'traspaso_entrada',
                'Traspaso ' . $t['folio'] . ' (entrada)', 'Traspaso', $id, $idUser, $t['folio']);
        }
        Db::run("UPDATE traspasos SET estado = 'aceptado', aceptado_por = ?, fecha_aceptacion = NOW() WHERE id = ? AND id_empresa = ?", [$idUser, $id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'aceptar', 'Traspaso', $id, 'Traspaso ' . $t['folio']);
        Http::ok(self::obtenerTraspaso($emp, $id), 'Traspaso aceptado');
    }

    public static function rechazar(array $p, array $ctx): void
    {
        $emp = Tenant::id(); $id = (int) $p['id'];
        Db::begin();
        $t = self::bloquear($id);
        Db::run("UPDATE traspasos SET estado = 'rechazado', aceptado_por = ?, fecha_aceptacion = NOW() WHERE id = ? AND id_empresa = ?", [$ctx['user']['id'], $id, $emp]);
        Db::commit();
        Ledger::audit($ctx, 'rechazar', 'Traspaso', $id, 'Traspaso ' . $t['folio']);
        Http::ok(self::obtenerTraspaso($emp, $id), 'Traspaso rechazado');
    }
}
