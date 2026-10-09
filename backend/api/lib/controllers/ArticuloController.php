<?php
// ============================================================
// Articulos de la tienda: variantes (2 ejes de atributos), kits, codigos por variante y fotos.
// - SKU interno: prefijo de categoria + consecutivo POR TIENDA (folio_series tipo 'sku').
// - Codigos por variante: articulo_variante_codigos (se generan al pedir /variantes).
// - Fotos: uploads/<empresas.uploads_token>/<nombre aleatorio> (no revela la tienda).
// ============================================================
class ArticuloController
{
    const EXT_FOTO = ['jpg', 'png', 'webp', 'gif'];   // sin SVG: puede contener scripts

    // ---------- lecturas pobladas ----------

    /** Valores del eje 1 o 2 del articulo: [{_id, nombre, hex, orden}] */
    public static function valoresEje(int $idArt, int $eje): array
    {
        return array_map(fn($r) => ['_id' => (int) $r['id'], 'nombre' => $r['nombre'], 'hex' => $r['extra'], 'orden' => (int) $r['orden']],
            Db::all('SELECT v.id, v.nombre, v.extra, v.orden FROM articulo_eje_valores x
                     JOIN atributo_valores v ON v.id_empresa = x.id_empresa AND v.id = x.id_valor
                     WHERE x.id_empresa = ? AND x.id_articulo = ? AND x.eje = ? ORDER BY v.orden, v.nombre',
                [Tenant::id(), $idArt, $eje]));
    }
    public static function coloresDe(int $idArt): array { return self::valoresEje($idArt, 1); }
    public static function tallasDe(int $idArt): array  { return self::valoresEje($idArt, 2); }

    /** Componentes de un kit: [{_id, codigo, descripcion, cantidad}] */
    public static function componentesDe(int $idKit): array
    {
        return array_map(fn($r) => [
            '_id'         => (int) $r['id_componente'],
            'codigo'      => $r['codigo'],
            'descripcion' => $r['descripcion'],
            'cantidad'    => (float) $r['cantidad'],
        ], Db::all('SELECT c.id_componente, c.cantidad, a.codigo, a.descripcion
                    FROM articulo_componentes c JOIN articulos a ON a.id_empresa = c.id_empresa AND a.id = c.id_componente
                    WHERE c.id_empresa = ? AND c.id_kit = ? ORDER BY c.id', [Tenant::id(), $idKit]));
    }

    /** Referencia poblada {_id, nombre} de un catalogo de la tienda (o null) */
    private static function ref(string $tabla, $id): ?array
    {
        if ($id === null || $id === '') return null;
        $r = Db::one("SELECT nombre FROM $tabla WHERE id = ? AND id_empresa = ?", [(int) $id, Tenant::id()]);
        return ['_id' => (int) $id, 'nombre' => $r['nombre'] ?? null];
    }

    /** Categoria poblada con sus ejes (para que el front sepa que matriz mostrar) */
    private static function categoriaRef($id): ?array
    {
        if ($id === null || $id === '') return null;
        $c = Db::one('SELECT c.*, a1.nombre eje1_nombre, a2.nombre eje2_nombre FROM categorias c
                      LEFT JOIN atributos a1 ON a1.id_empresa = c.id_empresa AND a1.id = c.id_atributo_eje1
                      LEFT JOIN atributos a2 ON a2.id_empresa = c.id_empresa AND a2.id = c.id_atributo_eje2
                      WHERE c.id = ? AND c.id_empresa = ?', [(int) $id, Tenant::id()]);
        if (!$c) return null;
        return [
            '_id'    => (int) $id,
            'nombre' => $c['nombre'],
            'eje1'   => $c['id_atributo_eje1'] ? ['_id' => (int) $c['id_atributo_eje1'], 'nombre' => $c['eje1_nombre']] : null,
            'eje2'   => $c['id_atributo_eje2'] ? ['_id' => (int) $c['id_atributo_eje2'], 'nombre' => $c['eje2_nombre']] : null,
            'maneja_variantes' => ($c['id_atributo_eje1'] !== null || $c['id_atributo_eje2'] !== null),
            'ficha_schema' => !empty($c['ficha_schema']) ? json_decode($c['ficha_schema'], true) : [],
        ];
    }

    /** Fotos del articulo: [{key, url, nombre}] */
    public static function fotosDe(int $idArt): array
    {
        return array_map(fn($f) => ['key' => $f['archivo'], 'url' => $f['url'], 'nombre' => $f['nombre']],
            Db::all('SELECT * FROM articulo_fotos WHERE id_empresa = ? AND id_articulo = ? ORDER BY orden, id', [Tenant::id(), $idArt]));
    }

    public static function fmt(array $a, bool $variantes = true): array
    {
        $o = [
            '_id'         => (int) $a['id'],
            'codigo'      => $a['codigo'],
            'sku'         => $a['sku'],
            'descripcion' => $a['descripcion'],
            'ean'         => $a['ean'],
            'id_categoria'      => self::categoriaRef($a['id_categoria']),
            'unidad'            => $a['unidad'] ?? 'pieza',
            'contenido_paquete' => (int) $a['contenido_paquete'],
            'es_kit'            => (bool) $a['es_kit'],
            'ficha'             => !empty($a['ficha']) ? json_decode($a['ficha'], true) : [],
            'id_familia'   => self::ref('familias', $a['id_familia']),
            'id_linea'     => self::ref('lineas', $a['id_linea']),
            'id_corte'     => self::ref('cortes_catalogo', $a['id_corte']),
            'id_marca'     => self::ref('marcas', $a['id_marca']),
            'fotos'        => self::fotosDe((int) $a['id']),
            'costo'        => Permisos::$verCostos ? (float) $a['costo'] : null,
            'precios'      => [
                'lista1' => (float) $a['lista1'], 'lista2' => (float) $a['lista2'], 'lista3' => (float) $a['lista3'],
                'lista4' => (float) $a['lista4'], 'lista5' => (float) $a['lista5'],
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

    public static function articulo(int $id): array
    {
        $a = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [$id, Tenant::id()]);
        if (!$a) throw new ApiError('Articulo no encontrado', 404, 'NOT_FOUND');
        return $a;
    }

    // ---------- codigos por variante ----------

    /**
     * Asegura un SKU/codigo de barras por cada combinacion de variante del articulo y los devuelve.
     * Formato: <sku del articulo>-<consecutivo de 3 digitos por articulo>. Nunca contiene ids internos.
     */
    private static function codigosVariantes(array $a): array
    {
        $emp = Tenant::id(); $id = (int) $a['id'];
        $base = trim((string) $a['sku']) !== '' ? $a['sku'] : $a['codigo'];
        $e1 = self::coloresDe($id) ?: [['_id' => null, 'nombre' => '']];
        $e2 = self::tallasDe($id) ?: [['_id' => null, 'nombre' => '']];
        if ($e1[0]['_id'] === null && $e2[0]['_id'] === null) return [];  // producto sin variantes: usa el SKU del articulo

        $existentes = [];
        foreach (Db::all('SELECT * FROM articulo_variante_codigos WHERE id_empresa = ? AND id_articulo = ?', [$emp, $id]) as $r) {
            $existentes[$r['v1_key'] . '_' . $r['v2_key']] = $r;
        }
        $n = count($existentes);
        $out = [];
        foreach ($e1 as $c) foreach ($e2 as $t) {
            $k = (int) $c['_id'] . '_' . (int) $t['_id'];
            if (!isset($existentes[$k])) {
                do { $sku = $base . '-' . str_pad((string) ++$n, 3, '0', STR_PAD_LEFT); }
                while (Db::one('SELECT 1 FROM articulo_variante_codigos WHERE id_empresa = ? AND (sku = ? OR codigo_barras = ?)', [$emp, $sku, $sku]));
                Db::insert('INSERT INTO articulo_variante_codigos (id_empresa, id_articulo, id_valor1, id_valor2, sku, codigo_barras) VALUES (?,?,?,?,?,?)',
                    [$emp, $id, $c['_id'], $t['_id'], $sku, $sku]);
                $existentes[$k] = ['sku' => $sku, 'codigo_barras' => $sku];
            }
            $nombre = trim($c['nombre'] . ($c['nombre'] && $t['nombre'] ? ' / ' : '') . $t['nombre']);
            $out[] = ['id_eje1' => (int) $c['_id'], 'id_eje2' => (int) $t['_id'], 'nombre' => $nombre,
                      'sku' => $existentes[$k]['sku'], 'codigo_barras' => $existentes[$k]['codigo_barras'] ?: $existentes[$k]['sku']];
        }
        return $out;
    }

    /** Combinaciones de variante de un articulo con su SKU / codigo de barras (para etiquetas) */
    public static function variantes(array $p, array $ctx): void
    {
        $a = self::articulo((int) $p['id']);
        Db::begin();
        $vars = self::codigosVariantes($a);
        Db::commit();
        $base = trim((string) $a['sku']) !== '' ? $a['sku'] : $a['codigo'];
        if (!$vars) $vars = [['id_eje1' => 0, 'id_eje2' => 0, 'nombre' => '', 'sku' => $base, 'codigo_barras' => $a['ean'] ?: $base]];
        Http::ok([
            'articulo'  => ['_id' => (int) $a['id'], 'sku' => $base, 'codigo' => $a['codigo'], 'descripcion' => $a['descripcion'],
                            'precios' => ['lista1' => (float) $a['lista1'], 'lista5' => (float) $a['lista5']],
                            'es_oferta' => (bool) $a['es_oferta'], 'precio_oferta' => (float) $a['precio_oferta']],
            'variantes' => $vars,
        ]);
    }

    /** Resuelve un codigo escaneado (codigo de variante, SKU, EAN o codigo) a articulo + variante */
    public static function scan(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $code = trim((string) ($_GET['code'] ?? $_GET['q'] ?? ''));
        if ($code === '') throw new ApiError('Codigo requerido', 400, 'VALIDATION');

        $vc = Db::one('SELECT * FROM articulo_variante_codigos WHERE id_empresa = ? AND (codigo_barras = ? OR sku = ?) LIMIT 1', [$emp, $code, $code]);
        if ($vc) {
            $a = Db::one("SELECT * FROM articulos WHERE id = ? AND id_empresa = ? AND is_active = 'Si'", [(int) $vc['id_articulo'], $emp]);
            if ($a) {
                $o = self::fmt($a, true);
                $o['scan'] = ['id_eje1' => (int) $vc['id_valor1'], 'id_eje2' => (int) $vc['id_valor2']];
                Http::ok($o);
            }
        }
        $a = Db::one("SELECT * FROM articulos WHERE id_empresa = ? AND is_active = 'Si' AND (sku = ? OR ean = ? OR codigo = ?) LIMIT 1", [$emp, $code, $code, $code]);
        if ($a) { $o = self::fmt($a, true); $o['scan'] = ['id_eje1' => 0, 'id_eje2' => 0]; Http::ok($o); }
        throw new ApiError('Codigo no encontrado', 404, 'NOT_FOUND');
    }

    // ---------- CRUD ----------

    public static function listar(array $p, array $ctx): void
    {
        $where = 'id_empresa = ?'; $args = [Tenant::id()];
        $estado = $_GET['is_active'] ?? 'Si';
        if ($estado !== 'todos' && $estado !== '') { $where .= ' AND is_active = ?'; $args[] = ($estado === 'No' ? 'No' : 'Si'); }
        $q = (string) ($_GET['search'] ?? $_GET['q'] ?? '');
        if ($q !== '') { $where .= ' AND (codigo LIKE ? OR sku LIKE ? OR descripcion LIKE ? OR ean LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
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
        Http::ok(self::fmt(self::articulo((int) $p['id']), true));
    }

    /** Ids de valores validos para un eje: deben ser de la tienda y del atributo del eje de la categoria */
    private static function valoresValidos($lista, ?int $idAtributo, string $eje): array
    {
        $ids = [];
        foreach ((array) ($lista ?? []) as $v) {
            $id = is_array($v) ? ($v['_id'] ?? $v['id'] ?? null) : $v;
            if ($id === null || $id === '' || (int) $id === 0) continue;
            $val = Db::one('SELECT id_atributo FROM atributo_valores WHERE id = ? AND id_empresa = ?', [(int) $id, Tenant::id()]);
            if (!$val) throw new ApiError("Valor de $eje no encontrado", 404, 'NOT_FOUND');
            if ($idAtributo === null) throw new ApiError("La categoria no maneja $eje", 400, 'VALIDATION');
            if ((int) $val['id_atributo'] !== $idAtributo) throw new ApiError("Un valor no corresponde al atributo de $eje de la categoria", 400, 'VALIDATION');
            $ids[(int) $id] = true;
        }
        return array_keys($ids);
    }

    private static function setVariantes(int $idArt, ?int $idCategoria, $eje1, $eje2): void
    {
        $emp = Tenant::id();
        $cat = $idCategoria ? Db::one('SELECT id_atributo_eje1, id_atributo_eje2 FROM categorias WHERE id = ? AND id_empresa = ?', [$idCategoria, $emp]) : null;
        $v1 = self::valoresValidos($eje1, $cat && $cat['id_atributo_eje1'] ? (int) $cat['id_atributo_eje1'] : null, 'eje 1');
        $v2 = self::valoresValidos($eje2, $cat && $cat['id_atributo_eje2'] ? (int) $cat['id_atributo_eje2'] : null, 'eje 2');
        Db::run('DELETE FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ?', [$emp, $idArt]);
        foreach ([1 => $v1, 2 => $v2] as $eje => $ids) foreach ($ids as $vid) {
            Db::run('INSERT INTO articulo_eje_valores (id_empresa, id_articulo, eje, id_valor) VALUES (?,?,?,?)', [$emp, $idArt, $eje, $vid]);
        }
    }

    private static function setComponentes(int $idKit, $componentes): void
    {
        $emp = Tenant::id();
        Db::run('DELETE FROM articulo_componentes WHERE id_empresa = ? AND id_kit = ?', [$emp, $idKit]);
        foreach ((array) ($componentes ?? []) as $c) {
            $cid = is_array($c) ? ($c['id_componente'] ?? $c['_id'] ?? $c['id'] ?? null) : $c;
            $cid = Tenant::owns('articulos', $cid, 'Componente');
            $cant = num(is_array($c) ? ($c['cantidad'] ?? 1) : 1);
            if (!$cid) continue;
            if ($cid === $idKit) throw new ApiError('Un kit no puede contenerse a si mismo', 400, 'VALIDATION');
            if ($cant <= 0) throw new ApiError('La cantidad de cada componente debe ser mayor a cero', 400, 'VALIDATION');
            // el kit mueve sus componentes "sin variante": no se aceptan kits dentro de kits ni articulos con variantes
            $comp = Db::one('SELECT codigo, es_kit FROM articulos WHERE id = ? AND id_empresa = ?', [$cid, $emp]);
            if ((int) $comp['es_kit']) throw new ApiError("El componente {$comp['codigo']} es un kit; un kit no puede contener otro kit", 400, 'VALIDATION');
            if (Db::one('SELECT 1 FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ? LIMIT 1', [$emp, $cid]))
                throw new ApiError("El componente {$comp['codigo']} tiene variantes (color/talla); un kit solo puede llevar articulos sin variantes", 400, 'VALIDATION');
            Db::run('INSERT INTO articulo_componentes (id_empresa, id_kit, id_componente, cantidad) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)', [$emp, $idKit, $cid, $cant]);
        }
    }

    /** Componentes actuales de un kit como [id_componente => cantidad] */
    private static function mapaComponentes(int $idKit): array
    {
        $m = [];
        foreach (Db::all('SELECT id_componente, cantidad FROM articulo_componentes WHERE id_empresa = ? AND id_kit = ?', [Tenant::id(), $idKit]) as $r)
            $m[(int) $r['id_componente']] = round((float) $r['cantidad'], 2);
        ksort($m);
        return $m;
    }

    /** El articulo ya se vendio (en ventas o como prenda nueva de un cambio) */
    private static function tieneVentas(int $idArt): bool
    {
        $emp = Tenant::id();
        return (bool) Db::one("SELECT 1 FROM venta_lineas WHERE id_empresa = ? AND id_articulo = ? LIMIT 1", [$emp, $idArt])
            || (bool) Db::one("SELECT 1 FROM cambio_lineas WHERE id_empresa = ? AND id_articulo = ? AND rol = 'nueva' LIMIT 1", [$emp, $idArt]);
    }

    /** Sincroniza fotos: solo acepta archivos que existan en la carpeta de ESTA tienda */
    private static function syncFotos(int $idArt, $fotos): void
    {
        if (!is_array($fotos)) return;
        $emp = Tenant::id();
        $dir = self::dirFotos();
        Db::run('DELETE FROM articulo_fotos WHERE id_empresa = ? AND id_articulo = ?', [$emp, $idArt]);
        $orden = 0;
        foreach ($fotos as $f) {
            $key = is_array($f) ? basename((string) ($f['key'] ?? '')) : '';
            if ($key === '' || !preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $key) || !is_file("$dir/$key")) continue;
            Db::insert('INSERT INTO articulo_fotos (id_empresa, id_articulo, archivo, url, nombre, orden) VALUES (?,?,?,?,?,?)',
                [$emp, $idArt, $key, self::urlFoto($key), $f['nombre'] ?? null, $orden++]);
        }
    }

    private static function dirFotos(): string
    {
        return __DIR__ . '/../../uploads/' . Tenant::empresa()['uploads_token'];
    }
    private static function urlFoto(string $key): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        return "$scheme://$host$base/uploads/" . Tenant::empresa()['uploads_token'] . "/$key";
    }

    /** Valida catalogos referenciados (todos de la tienda) */
    private static function refs(array $b, array $actual = []): array
    {
        $r = [];
        foreach (['id_categoria' => ['categorias', 'Categoria'], 'id_familia' => ['familias', 'Familia'], 'id_linea' => ['lineas', 'Linea'],
                  'id_corte' => ['cortes_catalogo', 'Corte'], 'id_marca' => ['marcas', 'Marca']] as $campo => [$tabla, $etq]) {
            $r[$campo] = array_key_exists($campo, $b)
                ? self::ownsCatalogo($tabla, $b[$campo], $etq)
                : ($actual[$campo] ?? null);
        }
        return $r;
    }
    private static function ownsCatalogo(string $tabla, $id, string $etq): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) return null;
        if (!Db::one("SELECT 1 FROM $tabla WHERE id = ? AND id_empresa = ?", [(int) $id, Tenant::id()])) throw new ApiError("$etq no encontrada", 404, 'NOT_FOUND');
        return (int) $id;
    }

    public static function crear(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = Tenant::id();
        $codigo = strtoupper(trim((string) ($b['codigo'] ?? '')));   // opcional: si va vacio, codigo = SKU
        $sku = strtoupper(trim((string) ($b['sku'] ?? '')));
        $desc = trim((string) ($b['descripcion'] ?? ''));
        if ($desc === '') throw new ApiError('La descripcion es obligatoria', 400, 'VALIDATION');
        if ($codigo !== '' && Db::one('SELECT id FROM articulos WHERE id_empresa = ? AND codigo = ?', [$emp, $codigo]))
            throw new ApiError('Ya existe un articulo con ese codigo', 409, 'CONFLICT');
        if ($sku !== '' && Db::one('SELECT id FROM articulos WHERE id_empresa = ? AND sku = ?', [$emp, $sku]))
            throw new ApiError('Ya existe un articulo con ese SKU', 409, 'CONFLICT');
        $refs = self::refs($b);
        $pr = $b['precios'] ?? [];

        Db::begin();
        if ($sku === '') {
            $prefijo = 'ART';
            if ($refs['id_categoria']) {
                $c = Db::one('SELECT prefijo_sku FROM categorias WHERE id = ? AND id_empresa = ?', [$refs['id_categoria'], $emp]);
                if ($c && trim((string) $c['prefijo_sku']) !== '') $prefijo = strtoupper(trim($c['prefijo_sku']));
            }
            do { $sku = Db::folio($emp, 'sku', $prefijo, 5); }       // consecutivo propio de la tienda
            while (Db::one('SELECT 1 FROM articulos WHERE id_empresa = ? AND (sku = ? OR codigo = ?)', [$emp, $sku, $sku]));
        }
        $id = Db::insert(
            'INSERT INTO articulos (id_empresa, codigo, sku, descripcion, ean, id_categoria, id_familia, id_linea, id_corte, id_marca,
               unidad, contenido_paquete, es_kit, ficha, costo, lista1, lista2, lista3, lista4, lista5, es_oferta, precio_oferta, piezas_por_caja)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, $codigo !== '' ? $codigo : $sku, $sku, $desc, ($b['ean'] ?? '') !== '' ? $b['ean'] : null,
             $refs['id_categoria'], $refs['id_familia'], $refs['id_linea'], $refs['id_corte'], $refs['id_marca'],
             $b['unidad'] ?? 'pieza', max(1, (int) ($b['contenido_paquete'] ?? 1)), !empty($b['es_kit']) ? 1 : 0,
             isset($b['ficha']) ? json_encode($b['ficha'], JSON_UNESCAPED_UNICODE) : null,
             num($b['costo'] ?? 0), num($pr['lista1'] ?? 0), num($pr['lista2'] ?? 0), num($pr['lista3'] ?? 0),
             num($pr['lista4'] ?? 0), num($pr['lista5'] ?? 0), !empty($b['es_oferta']) ? 1 : 0, num($b['precio_oferta'] ?? 0),
             max(1, (int) ($b['piezas_por_caja'] ?? 1))]);
        self::setVariantes($id, $refs['id_categoria'], $b['colores'] ?? [], $b['tallas'] ?? []);
        if (!empty($b['es_kit'])) {
            if (Db::one('SELECT 1 FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ? LIMIT 1', [$emp, $id]))
                throw new ApiError('Un kit no puede tener variantes (color/talla)', 400, 'VALIDATION');
            self::setComponentes($id, $b['componentes'] ?? []);
        }
        if (array_key_exists('fotos', $b)) self::syncFotos($id, $b['fotos']);
        Db::commit();
        Http::created(self::fmt(self::articulo($id), true), 'Articulo');
    }

    public static function actualizar(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $a = self::articulo((int) $p['id']);
        $id = (int) $a['id'];
        $b = Http::body();
        $pr = $b['precios'] ?? [];
        $codigo = array_key_exists('codigo', $b) && trim((string) $b['codigo']) !== '' ? strtoupper(trim((string) $b['codigo'])) : $a['codigo'];
        $sku = array_key_exists('sku', $b) && trim((string) $b['sku']) !== '' ? strtoupper(trim((string) $b['sku'])) : $a['sku'];
        if ($codigo !== $a['codigo'] && Db::one('SELECT id FROM articulos WHERE id_empresa = ? AND codigo = ? AND id <> ?', [$emp, $codigo, $id]))
            throw new ApiError('Ya existe un articulo con ese codigo', 409, 'CONFLICT');
        if ($sku !== $a['sku'] && Db::one('SELECT id FROM articulos WHERE id_empresa = ? AND sku = ? AND id <> ?', [$emp, $sku, $id]))
            throw new ApiError('Ya existe un articulo con ese SKU', 409, 'CONFLICT');
        $refs = self::refs($b, $a);
        $desc = array_key_exists('descripcion', $b) && trim((string) $b['descripcion']) !== '' ? trim((string) $b['descripcion']) : $a['descripcion'];

        Db::begin();
        Db::run(
            'UPDATE articulos SET codigo = ?, sku = ?, descripcion = ?, ean = ?, id_categoria = ?, id_familia = ?, id_linea = ?, id_corte = ?, id_marca = ?,
               unidad = ?, contenido_paquete = ?, es_kit = ?, ficha = ?, costo = ?, lista1 = ?, lista2 = ?, lista3 = ?, lista4 = ?, lista5 = ?,
               es_oferta = ?, precio_oferta = ?, piezas_por_caja = ?, is_active = ?
             WHERE id = ? AND id_empresa = ?',
            [$codigo, $sku, $desc, array_key_exists('ean', $b) ? (($b['ean'] ?? '') !== '' ? $b['ean'] : null) : $a['ean'],
             $refs['id_categoria'], $refs['id_familia'], $refs['id_linea'], $refs['id_corte'], $refs['id_marca'],
             array_key_exists('unidad', $b) ? ($b['unidad'] ?: 'pieza') : $a['unidad'],
             array_key_exists('contenido_paquete', $b) ? max(1, (int) $b['contenido_paquete']) : $a['contenido_paquete'],
             array_key_exists('es_kit', $b) ? (!empty($b['es_kit']) ? 1 : 0) : $a['es_kit'],
             array_key_exists('ficha', $b) ? json_encode($b['ficha'], JSON_UNESCAPED_UNICODE) : $a['ficha'],
             array_key_exists('costo', $b) ? num($b['costo']) : $a['costo'],
             isset($pr['lista1']) ? num($pr['lista1']) : $a['lista1'], isset($pr['lista2']) ? num($pr['lista2']) : $a['lista2'],
             isset($pr['lista3']) ? num($pr['lista3']) : $a['lista3'], isset($pr['lista4']) ? num($pr['lista4']) : $a['lista4'],
             isset($pr['lista5']) ? num($pr['lista5']) : $a['lista5'],
             array_key_exists('es_oferta', $b) ? (!empty($b['es_oferta']) ? 1 : 0) : $a['es_oferta'],
             array_key_exists('precio_oferta', $b) ? num($b['precio_oferta']) : $a['precio_oferta'],
             array_key_exists('piezas_por_caja', $b) ? max(1, (int) $b['piezas_por_caja']) : $a['piezas_por_caja'],
             array_key_exists('is_active', $b) ? (($b['is_active'] === 'No') ? 'No' : 'Si') : $a['is_active'],
             $id, $emp]);
        if (array_key_exists('colores', $b) || array_key_exists('tallas', $b) || $refs['id_categoria'] !== ($a['id_categoria'] !== null ? (int) $a['id_categoria'] : null)) {
            self::setVariantes($id, $refs['id_categoria'],
                array_key_exists('colores', $b) ? $b['colores'] : array_column(self::coloresDe($id), '_id'),
                array_key_exists('tallas', $b) ? $b['tallas'] : array_column(self::tallasDe($id), '_id'));
        }
        $esKit = array_key_exists('es_kit', $b) ? !empty($b['es_kit']) : !empty($a['es_kit']);
        $antes = self::mapaComponentes($id);
        if (array_key_exists('componentes', $b)) self::setComponentes($id, $esKit ? $b['componentes'] : []);
        elseif (!$esKit) Db::run('DELETE FROM articulo_componentes WHERE id_empresa = ? AND id_kit = ?', [$emp, $id]);
        // D5: un kit ya vendido no cambia de composicion (cancelaciones y devoluciones reponen lo que lleva el kit)
        if (($esKit !== !empty($a['es_kit']) || self::mapaComponentes($id) != $antes) && self::tieneVentas($id))
            throw new ApiError('Este articulo ya se vendio: no se puede cambiar si es kit ni lo que contiene. Crea un kit nuevo', 400, 'VALIDATION');
        if ($esKit && Db::one('SELECT 1 FROM articulo_componentes WHERE id_empresa = ? AND id_componente = ? LIMIT 1', [$emp, $id]))
            throw new ApiError('Este articulo es componente de otro kit; no puede ser kit', 400, 'VALIDATION');
        if ($esKit && Db::one('SELECT 1 FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ? LIMIT 1', [$emp, $id]))
            throw new ApiError('Un kit no puede tener variantes (color/talla)', 400, 'VALIDATION');
        if (Db::one('SELECT 1 FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ? LIMIT 1', [$emp, $id])
            && Db::one('SELECT 1 FROM articulo_componentes WHERE id_empresa = ? AND id_componente = ? LIMIT 1', [$emp, $id]))
            throw new ApiError('Este articulo es componente de un kit; no puede tener variantes', 400, 'VALIDATION');
        if (array_key_exists('fotos', $b)) self::syncFotos($id, $b['fotos']);
        Db::commit();
        Http::updated(self::fmt(self::articulo($id), true), 'Articulo');
    }

    /** Recibe una imagen (data URL base64) y la guarda en la carpeta de la tienda. Devuelve {key, url}. */
    public static function subirFoto(array $p, array $ctx): void
    {
        $dataUrl = (string) (Http::body()['dataUrl'] ?? '');
        if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m))
            throw new ApiError('Imagen invalida (se espera data URL base64)', 400, 'VALIDATION');
        $ext = strtolower($m[1]);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!in_array($ext, self::EXT_FOTO, true)) throw new ApiError('Formato de imagen no permitido (jpg, png, webp, gif)', 400, 'VALIDATION');
        $bin = base64_decode($m[2], true);
        if ($bin === false) throw new ApiError('No se pudo decodificar la imagen', 400, 'VALIDATION');
        if (strlen($bin) > 8 * 1024 * 1024) throw new ApiError('La imagen excede 8 MB', 400, 'VALIDATION');
        if (@getimagesizefromstring($bin) === false) throw new ApiError('El archivo no es una imagen valida', 400, 'VALIDATION');

        $dir = self::dirFotos();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('No se pudo crear la carpeta de imagenes', 500, 'SERVER_ERROR');
        $key = bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents("$dir/$key", $bin) === false) throw new ApiError('No se pudo guardar la imagen', 500, 'SERVER_ERROR');
        Http::created(['key' => $key, 'url' => self::urlFoto($key)], 'Foto');
    }

    public static function eliminar(array $p, array $ctx): void
    {
        $a = self::articulo((int) $p['id']);
        Db::run("UPDATE articulos SET is_active = 'No' WHERE id = ? AND id_empresa = ?", [$a['id'], Tenant::id()]);
        Http::deleted('Articulo');
    }
}
