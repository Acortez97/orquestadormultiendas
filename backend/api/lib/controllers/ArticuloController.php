<?php
class ArticuloController
{
    // Valores del EJE 1 del articulo (antes "colores"; ahora apuntan a atributo_valores)
    public static function coloresDe(int $idArt): array
    {
        return array_map(fn($r) => ['_id' => (string) $r['id'], 'nombre' => $r['nombre'], 'hex' => $r['extra']],
            Db::all('SELECT v.id,v.nombre,v.extra FROM articulo_colores ac
                     JOIN atributo_valores v ON v.id=ac.id_color
                     WHERE ac.id_articulo=? ORDER BY v.orden,v.nombre', [$idArt]));
    }
    // Valores del EJE 2 del articulo (antes "tallas"; ahora apuntan a atributo_valores)
    public static function tallasDe(int $idArt): array
    {
        return array_map(fn($r) => ['_id' => (string) $r['id'], 'nombre' => $r['nombre'], 'orden' => (int) $r['orden']],
            Db::all('SELECT v.id,v.nombre,v.orden FROM articulo_tallas at
                     JOIN atributo_valores v ON v.id=at.id_talla
                     WHERE at.id_articulo=? ORDER BY v.orden,v.nombre', [$idArt]));
    }

    /** Componentes de un kit (F5): [{_id, codigo, descripcion, cantidad}] */
    public static function componentesDe(int $idKit): array
    {
        return array_map(fn($r) => [
            '_id'         => (string) $r['id_componente'],
            'codigo'      => $r['codigo'],
            'descripcion' => $r['descripcion'],
            'cantidad'    => (float) $r['cantidad'],
        ], Db::all('SELECT c.id_componente, c.cantidad, a.codigo, a.descripcion
                    FROM articulo_componentes c JOIN articulos a ON a.id=c.id_componente
                    WHERE c.id_kit=? ORDER BY c.id', [$idKit]));
    }

    private static function setComponentes(int $idKit, $componentes): void
    {
        if (!is_array($componentes)) return;
        Db::run('DELETE FROM articulo_componentes WHERE id_kit=?', [$idKit]);
        foreach ($componentes as $c) {
            $cid = id_or_null(is_array($c) ? ($c['id_componente'] ?? $c['_id'] ?? $c['id'] ?? null) : $c);
            $cant = num(is_array($c) ? ($c['cantidad'] ?? 1) : 1);
            if ($cid && $cid !== $idKit && $cant > 0)
                Db::insert('INSERT INTO articulo_componentes (id_kit,id_componente,cantidad) VALUES (?,?,?)', [$idKit, $cid, $cant]);
        }
    }

    /** SKU interno de una variante concreta: sku del articulo + sufijo de ejes (F4) */
    private static function skuVariante(string $sku, int $e1, int $e2): string
    {
        return ($e1 || $e2) ? "$sku-$e1-$e2" : $sku;
    }

    /** Referencia poblada {_id,nombre} para un catalogo (o null) */
    private static function ref(string $tabla, $id): ?array
    {
        if ($id === null || $id === '') return null;
        $r = Db::one("SELECT id,nombre FROM $tabla WHERE id=?", [(int) $id]);
        return ['_id' => (string) $id, 'nombre' => $r['nombre'] ?? null];
    }

    /** Categoria poblada con sus ejes de variante (para que el front sepa qué matriz mostrar) */
    private static function categoriaRef($id): ?array
    {
        if ($id === null || $id === '') return null;
        $c = Db::one('SELECT c.*, a1.nombre eje1_nombre, a2.nombre eje2_nombre
                      FROM categorias c
                      LEFT JOIN atributos a1 ON a1.id=c.id_atributo_eje1
                      LEFT JOIN atributos a2 ON a2.id=c.id_atributo_eje2
                      WHERE c.id=?', [(int) $id]);
        if (!$c) return ['_id' => (string) $id, 'nombre' => null, 'eje1' => null, 'eje2' => null, 'maneja_variantes' => false];
        return [
            '_id'    => (string) $id,
            'nombre' => $c['nombre'],
            'eje1'   => $c['id_atributo_eje1'] ? ['_id' => (string) $c['id_atributo_eje1'], 'nombre' => $c['eje1_nombre']] : null,
            'eje2'   => $c['id_atributo_eje2'] ? ['_id' => (string) $c['id_atributo_eje2'], 'nombre' => $c['eje2_nombre']] : null,
            'maneja_variantes' => ($c['id_atributo_eje1'] !== null || $c['id_atributo_eje2'] !== null),
            'ficha_schema' => !empty($c['ficha_schema']) ? json_decode($c['ficha_schema'], true) : [],
        ];
    }

    /** Genera el SKU interno: prefijo de categoria + correlativo (usa el id del articulo) */
    private static function generarSku(int $id, ?int $idCategoria): string
    {
        $prefijo = 'ART';
        if ($idCategoria) {
            $c = Db::one('SELECT prefijo_sku FROM categorias WHERE id=?', [$idCategoria]);
            if ($c && trim((string) $c['prefijo_sku']) !== '') $prefijo = strtoupper(trim($c['prefijo_sku']));
        }
        return $prefijo . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** Fotos del articulo: [{key,url,nombre}] */
    public static function fotosDe(int $idArt): array
    {
        return array_map(fn($f) => ['key' => $f['imgkey'], 'url' => $f['url'], 'nombre' => $f['nombre']],
            Db::all('SELECT * FROM articulo_fotos WHERE id_articulo=? ORDER BY orden, id', [$idArt]));
    }

    private static function syncFotos(int $emp, int $idArt, $fotos): void
    {
        if (!is_array($fotos)) return;
        Db::run('DELETE FROM articulo_fotos WHERE id_articulo=?', [$idArt]);
        $orden = 0;
        foreach ($fotos as $f) {
            $key = is_array($f) ? ($f['key'] ?? null) : null;
            $url = is_array($f) ? ($f['url'] ?? null) : null;
            if (!$url) continue;
            Db::insert('INSERT INTO articulo_fotos (id_empresa,id_articulo,imgkey,url,nombre,orden) VALUES (?,?,?,?,?,?)',
                [$emp, $idArt, $key, $url, is_array($f) ? ($f['nombre'] ?? null) : null, $orden++]);
        }
    }

    public static function fmt(array $a, bool $variantes = true): array
    {
        $o = [
            '_id'         => (string) $a['id'],
            'codigo'      => $a['codigo'],
            'sku'         => $a['sku'] ?? null,
            'descripcion' => $a['descripcion'],
            'ean'         => $a['ean'],
            'id_categoria'      => self::categoriaRef($a['id_categoria'] ?? null),
            'unidad'            => $a['unidad'] ?? 'pieza',
            'contenido_paquete' => (int) ($a['contenido_paquete'] ?? 1),
            'es_kit'            => (bool) ($a['es_kit'] ?? 0),
            'ficha'             => !empty($a['ficha']) ? json_decode($a['ficha'], true) : [],
            'id_familia'   => self::ref('familias', $a['id_familia']),
            'id_linea'     => self::ref('lineas', $a['id_linea']),
            'id_corte'     => self::ref('cortes_catalogo', $a['id_corte'] ?? $a['id_coleccion'] ?? null),
            'id_marca'     => self::ref('marcas', $a['id_marca']),
            'fotos'        => self::fotosDe((int) $a['id']),
            'costo'        => (float) $a['costo'],
            'precios'      => [
                'lista1' => (float) $a['lista1'], 'lista2' => (float) $a['lista2'],
                'lista3' => (float) $a['lista3'], 'lista4' => (float) $a['lista4'],
                'lista5' => (float) $a['lista5'],
            ],
            'es_oferta'       => (bool) $a['es_oferta'],
            'precio_oferta'   => (float) $a['precio_oferta'],
            'piezas_por_caja' => (int) $a['piezas_por_caja'],
            'is_active'       => $a['is_active'],
            'createdAt'       => $a['created_at'] ?? null,
            'updatedAt'       => $a['updated_at'] ?? null,
        ];
        if ($variantes) {
            $o['colores'] = self::coloresDe((int) $a['id']);
            $o['tallas']  = self::tallasDe((int) $a['id']);
            if (!empty($a['es_kit'])) $o['componentes'] = self::componentesDe((int) $a['id']);
        }
        return $o;
    }

    /** Combinaciones de variante de un articulo, cada una con su SKU/codigo de barras (F4). */
    public static function variantes(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [(int) $p['id'], $emp]);
        if (!$a) throw new ApiError('Articulo no encontrado', 404, 'NOT_FOUND');
        $id = (int) $a['id'];
        $sku = trim((string) ($a['sku'] ?? '')) !== '' ? $a['sku'] : $a['codigo'];
        $e1 = self::coloresDe($id);
        $e2 = self::tallasDe($id);
        $rows1 = $e1 ?: [['_id' => '', 'nombre' => '']];
        $rows2 = $e2 ?: [['_id' => '', 'nombre' => '']];
        $out = [];
        foreach ($rows1 as $c) {
            foreach ($rows2 as $t) {
                $ce = (int) ($c['_id'] ?: 0);
                $te = (int) ($t['_id'] ?: 0);
                $nombre = trim(($c['nombre'] ?? '') . (($c['nombre'] ?? '') && ($t['nombre'] ?? '') ? ' / ' : '') . ($t['nombre'] ?? ''));
                $vsku = self::skuVariante($sku, $ce, $te);
                $out[] = [
                    'id_eje1' => (string) $ce, 'id_eje2' => (string) $te,
                    'nombre'  => $nombre, 'sku' => $vsku, 'codigo_barras' => $vsku,
                ];
            }
        }
        Http::ok([
            'articulo'  => ['_id' => (string) $id, 'sku' => $sku, 'codigo' => $a['codigo'], 'descripcion' => $a['descripcion'],
                            'precios' => ['lista1' => (float) $a['lista1'], 'lista5' => (float) $a['lista5']],
                            'es_oferta' => (bool) $a['es_oferta'], 'precio_oferta' => (float) $a['precio_oferta']],
            'variantes' => $out,
        ]);
    }

    /** Resuelve un codigo escaneado (sku/ean/codigo o sku de variante) a un articulo + variante (F4). */
    public static function scan(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $code = trim($_GET['code'] ?? $_GET['q'] ?? '');
        if ($code === '') throw new ApiError('Parametro code requerido', 400, 'VALIDATION');

        // 1) codigo de barras/sku por variante registrado
        $vc = Db::one('SELECT * FROM articulo_variante_codigos WHERE id_empresa=? AND (codigo_barras=? OR sku=?) LIMIT 1', [$emp, $code, $code]);
        if ($vc) {
            $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [(int) $vc['id_articulo'], $emp]);
            if ($a) { $o = self::fmt($a, true); $o['scan'] = ['id_eje1' => (string) $vc['id_eje1'], 'id_eje2' => (string) $vc['id_eje2']]; Http::ok($o); return; }
        }
        // 2) codigo a nivel articulo (sku interno, ean de fabrica o codigo)
        $a = Db::one('SELECT * FROM articulos WHERE id_empresa=? AND is_active=\'Si\' AND (sku=? OR ean=? OR codigo=?) LIMIT 1', [$emp, $code, $code, $code]);
        if ($a) { $o = self::fmt($a, true); $o['scan'] = ['id_eje1' => '0', 'id_eje2' => '0']; Http::ok($o); return; }
        // 3) sku de variante calculado: <sku>-<eje1>-<eje2>
        if (preg_match('/^(.*)-(\d+)-(\d+)$/', $code, $m)) {
            $a = Db::one('SELECT * FROM articulos WHERE id_empresa=? AND is_active=\'Si\' AND (sku=? OR codigo=?) LIMIT 1', [$emp, $m[1], $m[1]]);
            if ($a) { $o = self::fmt($a, true); $o['scan'] = ['id_eje1' => $m[2], 'id_eje2' => $m[3]]; Http::ok($o); return; }
        }
        throw new ApiError('Codigo no encontrado', 404, 'NOT_FOUND');
    }

    public static function listar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $where = 'id_empresa=?'; $args = [$emp];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active=?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        $q = $_GET['search'] ?? $_GET['q'] ?? '';
        if ($q !== '') { $where .= ' AND (codigo LIKE ? OR descripcion LIKE ? OR ean LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }

        $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));
        $rows = Db::all("SELECT * FROM articulos WHERE $where ORDER BY descripcion ASC LIMIT $limit", $args);
        Http::ok(array_map(fn($a) => self::fmt($a, true), $rows));
    }

    public static function buscar(array $p, array $ctx): void
    {
        $_GET['limit'] = $_GET['limit'] ?? 50;
        self::listar($p, $ctx);
    }

    public static function obtener(array $p, array $ctx): void
    {
        $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        if (!$a) throw new ApiError('Articulo no encontrado', 404, 'NOT_FOUND');
        Http::ok(self::fmt($a, true));
    }

    private static function setVariantes(int $idArt, $colores, $tallas): void
    {
        Db::run('DELETE FROM articulo_colores WHERE id_articulo=?', [$idArt]);
        Db::run('DELETE FROM articulo_tallas WHERE id_articulo=?', [$idArt]);
        foreach ((array) ($colores ?? []) as $c) {
            $cid = id_or_null(is_array($c) ? ($c['_id'] ?? $c['id'] ?? null) : $c);
            if ($cid) Db::run('INSERT IGNORE INTO articulo_colores (id_articulo,id_color) VALUES (?,?)', [$idArt, $cid]);
        }
        foreach ((array) ($tallas ?? []) as $t) {
            $tid = id_or_null(is_array($t) ? ($t['_id'] ?? $t['id'] ?? null) : $t);
            if ($tid) Db::run('INSERT IGNORE INTO articulo_tallas (id_articulo,id_talla) VALUES (?,?)', [$idArt, $tid]);
        }
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $codigo = strtoupper(trim($b['codigo'] ?? ''));   // opcional: si va vacio, se usa el SKU
        $desc = trim($b['descripcion'] ?? '');
        if ($desc === '') throw new ApiError('La descripcion es obligatoria', 400, 'VALIDATION');
        $emp = (int) $ctx['user']['id_empresa'];
        if ($codigo !== '' && Db::one('SELECT id FROM articulos WHERE id_empresa=? AND codigo=?', [$emp, $codigo]))
            throw new ApiError('Ya existe un articulo con ese codigo', 409, 'CONFLICT');

        $pr = $b['precios'] ?? [];
        $idCorte = id_or_null($b['id_corte'] ?? $b['id_coleccion'] ?? null);
        $idCategoria = id_or_null($b['id_categoria'] ?? null);
        $codigoTmp = $codigo !== '' ? $codigo : ('TMP-' . bin2hex(random_bytes(4)));
        $id = Db::insert(
            'INSERT INTO articulos (id_empresa,codigo,descripcion,ean,id_familia,id_linea,id_corte,id_marca,
              id_categoria,unidad,contenido_paquete,es_kit,ficha,
              costo,lista1,lista2,lista3,lista4,lista5,es_oferta,precio_oferta,piezas_por_caja)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $emp, $codigoTmp, $desc, $b['ean'] ?? null,
                id_or_null($b['id_familia'] ?? null), id_or_null($b['id_linea'] ?? null),
                $idCorte, id_or_null($b['id_marca'] ?? null),
                $idCategoria, $b['unidad'] ?? 'pieza', (int) ($b['contenido_paquete'] ?? 1),
                !empty($b['es_kit']) ? 1 : 0, isset($b['ficha']) ? json_encode($b['ficha'], JSON_UNESCAPED_UNICODE) : null,
                num($b['costo'] ?? 0),
                num($pr['lista1'] ?? 0), num($pr['lista2'] ?? 0), num($pr['lista3'] ?? 0),
                num($pr['lista4'] ?? 0), num($pr['lista5'] ?? 0),
                !empty($b['es_oferta']) ? 1 : 0, num($b['precio_oferta'] ?? 0),
                (int) ($b['piezas_por_caja'] ?? 1),
            ]
        );
        // SKU interno automatico (o el que mande el usuario); si no hubo codigo, el codigo = SKU
        $sku = strtoupper(trim($b['sku'] ?? ''));
        if ($sku === '') $sku = self::generarSku($id, $idCategoria);
        $codigoFinal = $codigo !== '' ? $codigo : $sku;
        Db::run('UPDATE articulos SET sku=?, codigo=? WHERE id=?', [$sku, $codigoFinal, $id]);

        self::setVariantes($id, $b['colores'] ?? [], $b['tallas'] ?? []);
        if (!empty($b['es_kit'])) self::setComponentes($id, $b['componentes'] ?? []);
        if (array_key_exists('fotos', $b)) self::syncFotos($emp, $id, $b['fotos']);
        Http::created(self::fmt(Db::one('SELECT * FROM articulos WHERE id=?', [$id]), true), 'Articulo');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa'];
        $id = (int) $p['id'];
        $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [$id, $emp]);
        if (!$a) throw new ApiError('Articulo no encontrado', 404, 'NOT_FOUND');
        $b = Http::body();
        $pr = $b['precios'] ?? [];

        $idCorteActual = $a['id_corte'] ?? $a['id_coleccion'] ?? null;
        $idCorte = (array_key_exists('id_corte', $b) || array_key_exists('id_coleccion', $b))
            ? id_or_null($b['id_corte'] ?? $b['id_coleccion']) : $idCorteActual;
        Db::run(
            'UPDATE articulos SET codigo=?, sku=?, descripcion=?, ean=?, id_familia=?, id_linea=?, id_corte=?, id_marca=?,
              id_categoria=?, unidad=?, contenido_paquete=?, es_kit=?, ficha=?,
              costo=?, lista1=?, lista2=?, lista3=?, lista4=?, lista5=?, es_oferta=?, precio_oferta=?, piezas_por_caja=?, is_active=?
             WHERE id=?',
            [
                array_key_exists('codigo', $b) && trim($b['codigo']) !== '' ? strtoupper(trim($b['codigo'])) : $a['codigo'],
                array_key_exists('sku', $b) && trim($b['sku']) !== '' ? strtoupper(trim($b['sku'])) : $a['sku'],
                array_key_exists('descripcion', $b) ? trim($b['descripcion']) : $a['descripcion'],
                array_key_exists('ean', $b) ? $b['ean'] : $a['ean'],
                array_key_exists('id_familia', $b) ? id_or_null($b['id_familia']) : $a['id_familia'],
                array_key_exists('id_linea', $b) ? id_or_null($b['id_linea']) : $a['id_linea'],
                $idCorte,
                array_key_exists('id_marca', $b) ? id_or_null($b['id_marca']) : $a['id_marca'],
                array_key_exists('id_categoria', $b) ? id_or_null($b['id_categoria']) : $a['id_categoria'],
                array_key_exists('unidad', $b) ? ($b['unidad'] ?: 'pieza') : $a['unidad'],
                array_key_exists('contenido_paquete', $b) ? (int) $b['contenido_paquete'] : $a['contenido_paquete'],
                array_key_exists('es_kit', $b) ? (!empty($b['es_kit']) ? 1 : 0) : $a['es_kit'],
                array_key_exists('ficha', $b) ? json_encode($b['ficha'], JSON_UNESCAPED_UNICODE) : $a['ficha'],
                array_key_exists('costo', $b) ? num($b['costo']) : $a['costo'],
                isset($pr['lista1']) ? num($pr['lista1']) : $a['lista1'],
                isset($pr['lista2']) ? num($pr['lista2']) : $a['lista2'],
                isset($pr['lista3']) ? num($pr['lista3']) : $a['lista3'],
                isset($pr['lista4']) ? num($pr['lista4']) : $a['lista4'],
                isset($pr['lista5']) ? num($pr['lista5']) : $a['lista5'],
                array_key_exists('es_oferta', $b) ? (!empty($b['es_oferta']) ? 1 : 0) : $a['es_oferta'],
                array_key_exists('precio_oferta', $b) ? num($b['precio_oferta']) : $a['precio_oferta'],
                array_key_exists('piezas_por_caja', $b) ? (int) $b['piezas_por_caja'] : $a['piezas_por_caja'],
                array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $a['is_active'],
                $id,
            ]
        );
        if (array_key_exists('colores', $b) || array_key_exists('tallas', $b)) {
            self::setVariantes($id,
                array_key_exists('colores', $b) ? $b['colores'] : self::coloresDe($id),
                array_key_exists('tallas', $b) ? $b['tallas'] : self::tallasDe($id));
        }
        // Kit: sincroniza componentes (si deja de ser kit, se limpian)
        $esKitFinal = array_key_exists('es_kit', $b) ? !empty($b['es_kit']) : !empty($a['es_kit']);
        if (array_key_exists('componentes', $b)) self::setComponentes($id, $esKitFinal ? $b['componentes'] : []);
        elseif (!$esKitFinal) Db::run('DELETE FROM articulo_componentes WHERE id_kit=?', [$id]);
        if (array_key_exists('fotos', $b)) self::syncFotos($emp, $id, $b['fotos']);
        Http::updated(self::fmt(Db::one('SELECT * FROM articulos WHERE id=?', [$id]), true), 'Articulo');
    }

    /** Recibe una imagen en Data URL base64, la guarda en /uploads/articulos y devuelve {key,url} */
    public static function subirFoto(array $p, array $ctx): void
    {
        $b = Http::body();
        $dataUrl = $b['dataUrl'] ?? '';
        if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m))
            throw new ApiError('Imagen invalida (se espera data URL base64)', 400, 'VALIDATION');
        $ext = strtolower($m[1]);
        $ext = ['jpeg' => 'jpg', 'svg+xml' => 'svg'][$ext] ?? $ext;
        if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'svg'], true)) throw new ApiError('Formato de imagen no permitido', 400, 'VALIDATION');
        $bin = base64_decode($m[2], true);
        if ($bin === false) throw new ApiError('No se pudo decodificar la imagen', 400, 'VALIDATION');
        if (strlen($bin) > 8 * 1024 * 1024) throw new ApiError('La imagen excede 8 MB', 400, 'VALIDATION');

        $dir = __DIR__ . '/../../uploads/articulos';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('No se pudo crear la carpeta de imagenes', 500, 'SERVER_ERROR');
        $key = 'art_' . $ctx['user']['id_empresa'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents("$dir/$key", $bin) === false) throw new ApiError('No se pudo guardar la imagen', 500, 'SERVER_ERROR');

        // URL absoluta valida tanto en local (docroot=api) como en GoDaddy (/api)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        $url    = "$scheme://$host$base/uploads/articulos/$key";
        Http::created(['key' => $key, 'url' => $url], 'Foto');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        Db::run('UPDATE articulos SET is_active=\'No\' WHERE id=? AND id_empresa=?',
            [(int) $p['id'], (int) $ctx['user']['id_empresa']]);
        Http::deleted('Articulo');
    }
}
