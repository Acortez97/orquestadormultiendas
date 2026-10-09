<?php
// ============================================================
// Carga masiva de la tienda (desde Excel / CSV que lee el navegador).
//
//  POST /importar/articulos   carga inicial: articulos (+ variantes, codigos de barras) y sus existencias.
//                             Lo que falte se crea: categorias, marcas, familias, lineas, atributos y valores.
//                             Articulo que ya existe (mismo codigo): se actualizan costo y precios y se suman existencias.
//  POST /importar/existencias solo existencias: «reemplazar» (conteo fisico) o «sumar».
//
// Dos pasos con la MISMA logica: aplicar=false revisa (todo se hace dentro de una transaccion que se
// revierte) y devuelve el resumen y los errores por renglon; aplicar=true guarda TODO o NADA.
// Todo es de la tienda activa (Tenant::id()); nada se toma ni se busca en otra tienda.
// ============================================================
class ImportController
{
    const MAX_FILAS = 3000;

    // ------------------------------------------------------------ utilidades
    private static function txt($v, int $max = 200): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
        return preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s);   // corta por caracteres (sin mbstring)
    }

    /** Numero opcional: '' -> null; '1,250.50' / '$199' -> float; texto invalido -> false */
    private static function numero($v)
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float) $v;
        $s = trim(str_replace(['$', ',', ' '], '', (string) $v));
        if ($s === '') return null;
        return is_numeric($s) ? (float) $s : false;
    }

    private static function clave(string $s): string
    {
        $s = strtolower(trim($s));
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']);
    }

    private static function filas(array $b): array
    {
        $filas = $b['filas'] ?? null;
        if (!is_array($filas) || !$filas) throw new ApiError('El archivo no tiene renglones', 400, 'VALIDATION');
        if (count($filas) > self::MAX_FILAS) throw new ApiError('Máximo ' . self::MAX_FILAS . ' renglones por archivo; divídelo en varios', 400, 'VALIDATION');
        return array_values($filas);
    }

    /** Almacenes de la tienda por nombre o codigo (sin acentos ni mayusculas) */
    private static function almacenes(int $emp): array
    {
        $m = [];
        foreach (Db::all("SELECT id, codigo, nombre FROM almacenes WHERE id_empresa = ? AND is_active = 'Si'", [$emp]) as $a) {
            $m[self::clave($a['nombre'])] = (int) $a['id'];
            $m[self::clave($a['codigo'])] = (int) $a['id'];
        }
        return $m;
    }

    /** Busca por nombre en un catalogo simple (marcas, familias, lineas) y lo crea si no existe */
    private static function catalogo(int $emp, string $tabla, string $nombre, array &$cache, array &$nuevos): int
    {
        $k = self::clave($nombre);
        if (isset($cache[$tabla][$k])) return $cache[$tabla][$k];
        $r = Db::one("SELECT id FROM $tabla WHERE id_empresa = ? AND LOWER(nombre) = LOWER(?) ORDER BY is_active = 'Si' DESC, id LIMIT 1", [$emp, $nombre]);
        if ($r) return $cache[$tabla][$k] = (int) $r['id'];
        $nuevos[$tabla][] = $nombre;
        return $cache[$tabla][$k] = Db::insert("INSERT INTO $tabla (id_empresa, nombre) VALUES (?,?)", [$emp, $nombre]);
    }

    /** Atributo (Color, Talla, ...) por nombre; lo crea si no existe */
    private static function atributo(int $emp, string $nombre, array &$cache, array &$nuevos): int
    {
        $k = self::clave($nombre);
        if (isset($cache['atr'][$k])) return $cache['atr'][$k];
        $r = Db::one('SELECT id FROM atributos WHERE id_empresa = ? AND LOWER(nombre) = LOWER(?) LIMIT 1', [$emp, $nombre]);
        if ($r) return $cache['atr'][$k] = (int) $r['id'];
        $nuevos['atributos'][] = $nombre;
        return $cache['atr'][$k] = Db::insert('INSERT INTO atributos (id_empresa, nombre, tipo_valor) VALUES (?,?,?)',
            [$emp, $nombre, self::clave($nombre) === 'color' ? 'color' : 'texto']);
    }

    /** Valor de un atributo (Negro, M, 26...) por nombre; lo crea si no existe */
    private static function valor(int $emp, int $idAtr, string $nombre, array &$cache, array &$nuevos, bool $crear = true): ?int
    {
        $k = $idAtr . '|' . self::clave($nombre);
        if (isset($cache['val'][$k])) return $cache['val'][$k];
        $r = Db::one('SELECT id FROM atributo_valores WHERE id_empresa = ? AND id_atributo = ? AND LOWER(nombre) = LOWER(?) LIMIT 1', [$emp, $idAtr, $nombre]);
        if ($r) return $cache['val'][$k] = (int) $r['id'];
        if (!$crear) return null;
        $nuevos['valores'][] = $nombre;
        $orden = (int) Db::one('SELECT COALESCE(MAX(orden), 0) + 1 o FROM atributo_valores WHERE id_empresa = ? AND id_atributo = ?', [$emp, $idAtr])['o'];
        return $cache['val'][$k] = Db::insert('INSERT INTO atributo_valores (id_empresa, id_atributo, nombre, orden) VALUES (?,?,?,?)', [$emp, $idAtr, $nombre, $orden]);
    }

    /**
     * Categoria por nombre. Si no existe se crea; si el archivo trae variantes para ella, sus ejes son
     * «Color» (variante 1) y «Talla» (variante 2), que tambien se crean si faltan.
     * Devuelve la fila [id, id_atributo_eje1, id_atributo_eje2, nombre].
     */
    private static function categoria(int $emp, string $nombre, bool $usaV1, bool $usaV2, array &$cache, array &$nuevos): array
    {
        $k = self::clave($nombre);
        if (isset($cache['cat'][$k])) return $cache['cat'][$k];
        $r = Db::one('SELECT id, id_atributo_eje1, id_atributo_eje2, nombre FROM categorias WHERE id_empresa = ? AND LOWER(nombre) = LOWER(?) LIMIT 1', [$emp, $nombre]);
        if (!$r) {
            $e1 = ($usaV1 || $usaV2) ? self::atributo($emp, 'Color', $cache, $nuevos) : null;
            $e2 = $usaV2 ? self::atributo($emp, 'Talla', $cache, $nuevos) : null;
            $pref = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', strtr($nombre, ['Ñ' => 'N', 'ñ' => 'n'])) ?: 'ART', 0, 3));
            $id = Db::insert('INSERT INTO categorias (id_empresa, nombre, prefijo_sku, id_atributo_eje1, id_atributo_eje2) VALUES (?,?,?,?,?)',
                [$emp, $nombre, $pref, $e1, $e2]);
            $nuevos['categorias'][] = $nombre;
            $r = ['id' => $id, 'id_atributo_eje1' => $e1, 'id_atributo_eje2' => $e2, 'nombre' => $nombre];
        }
        return $cache['cat'][$k] = $r;
    }

    private static function resumenBase(): array
    {
        return ['renglones' => 0, 'articulos_nuevos' => 0, 'articulos_actualizados' => 0, 'variantes_nuevas' => 0,
                'existencias_renglones' => 0, 'existencias_piezas' => 0.0];
    }

    private static function responder(bool $aplicar, array $resumen, array $nuevos, array $errores): void
    {
        $ok = !$errores;
        if ($aplicar && $ok) Db::commit(); else Db::rollback();
        $nuevos = array_map(fn($l) => array_values(array_unique($l)), $nuevos);
        Http::ok([
            'aplicado' => $aplicar && $ok,
            'resumen'  => $resumen,
            'nuevos'   => $nuevos,
            'errores'  => array_slice($errores, 0, 300),
            'total_errores' => count($errores),
        ], $aplicar ? ($ok ? 'Carga aplicada' : 'No se guardó nada: corrige los errores') : 'Revisión lista');
    }

    // ------------------------------------------------------------ carga inicial
    public static function articulos(array $p, array $ctx): void
    {
        $b = Http::bodyCrudo();   // los valores de las celdas son texto: no se tratan como ids opacos
        $aplicar = !empty($b['aplicar']);
        $filas = self::filas($b);
        $emp = Tenant::id();
        $idUser = $ctx['user']['id'];
        $puedeEditar = Permisos::tiene($ctx, 'catalogos.editar');
        $puedeStock = Permisos::tiene($ctx, 'almacen.ajustar');
        $almacenes = self::almacenes($emp);

        // 1) normalizar y agrupar por codigo (cada grupo = un articulo)
        $errores = []; $grupos = [];
        foreach ($filas as $i => $f) {
            $n = (int) ($f['fila'] ?? ($i + 2));
            $cod = strtoupper(self::txt($f['codigo'] ?? '', 60));
            $conDatos = array_filter((array) $f, fn($v, $k) => $k !== 'fila' && is_scalar($v) && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH);
            if (!$conDatos) continue;   // renglon vacio
            if ($cod === '') { $errores[] = ['fila' => $n, 'mensaje' => 'Falta el código']; continue; }
            $precios = [];
            foreach (['costo', 'precio1', 'precio2', 'precio3', 'precio4', 'precio5', 'existencia'] as $c) {
                $v = self::numero($f[$c] ?? null);
                if ($v === false || ($v !== null && $v < 0)) { $errores[] = ['fila' => $n, 'mensaje' => "«$c» debe ser un número mayor o igual a 0"]; $v = null; }
                $precios[$c] = $v;
            }
            $grupos[$cod][] = [
                'fila' => $n, 'codigo' => $cod,
                'descripcion' => self::txt($f['descripcion'] ?? ''), 'categoria' => self::txt($f['categoria'] ?? '', 120),
                'marca' => self::txt($f['marca'] ?? '', 120), 'familia' => self::txt($f['familia'] ?? '', 120), 'linea' => self::txt($f['linea'] ?? '', 120),
                'v1' => self::txt($f['variante1'] ?? '', 80), 'v2' => self::txt($f['variante2'] ?? '', 80),
                'barras' => self::txt($f['codigo_barras'] ?? '', 50), 'almacen' => self::txt($f['almacen'] ?? '', 120),
            ] + $precios;
        }

        $resumen = self::resumenBase();
        $resumen['renglones'] = array_sum(array_map('count', $grupos));
        $nuevos = ['categorias' => [], 'marcas' => [], 'familias' => [], 'lineas' => [], 'atributos' => [], 'valores' => []];
        $cache = [];
        $almacenesUsados = [];

        Db::begin();
        foreach ($grupos as $cod => $rs) {
            $f0 = $rs[0];
            try {
                $art = Db::one('SELECT * FROM articulos WHERE id_empresa = ? AND codigo = ?', [$emp, $cod]);
                if ($art && (int) $art['es_kit']) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod es un kit: los kits no se cargan masivamente"]; continue; }
                $usaV1 = (bool) array_filter($rs, fn($r) => $r['v1'] !== '');
                $usaV2 = (bool) array_filter($rs, fn($r) => $r['v2'] !== '');

                // categoria (del articulo existente o la del archivo)
                $cat = null;
                if ($art && $art['id_categoria']) {
                    $cat = Db::one('SELECT id, id_atributo_eje1, id_atributo_eje2, nombre FROM categorias WHERE id = ? AND id_empresa = ?', [(int) $art['id_categoria'], $emp]);
                } elseif ($f0['categoria'] !== '') {
                    $cat = self::categoria($emp, $f0['categoria'], $usaV1, $usaV2, $cache, $nuevos);
                }
                if (($usaV1 || $usaV2) && !$cat) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod trae color/talla pero no tiene categoría: indícala"]; continue; }
                if ($usaV1 && $cat && !$cat['id_atributo_eje1']) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "La categoría «{$cat['nombre']}» no maneja variantes: quita color/talla o usa otra categoría"]; continue; }
                if ($usaV2 && $cat && !$cat['id_atributo_eje2']) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "La categoría «{$cat['nombre']}» solo maneja una variante: deja vacía la columna de talla"]; continue; }

                if ($art) {
                    // existente: se actualizan costo y precios que vengan en el archivo
                    $cambios = array_filter(['costo' => $f0['costo'], 'lista1' => $f0['precio1'], 'lista2' => $f0['precio2'], 'lista3' => $f0['precio3'],
                                             'lista4' => $f0['precio4'], 'lista5' => $f0['precio5']], fn($v) => $v !== null);
                    if ($cambios) {
                        if (!$puedeEditar) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod ya existe y no tienes permiso para editar artículos"]; continue; }
                        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cambios)));
                        Db::run("UPDATE articulos SET $set WHERE id = ? AND id_empresa = ?", array_merge(array_values($cambios), [(int) $art['id'], $emp]));
                    }
                    $idArt = (int) $art['id'];
                    $resumen['articulos_actualizados']++;
                } else {
                    if ($f0['descripcion'] === '') { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod es nuevo: falta la descripción"]; continue; }
                    if (!($f0['precio1'] > 0)) { $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod es nuevo: falta el precio de lista 1"]; continue; }
                    $prefijo = 'ART';
                    if ($cat) {
                        $pc = Db::one('SELECT prefijo_sku FROM categorias WHERE id = ? AND id_empresa = ?', [(int) $cat['id'], $emp]);
                        if ($pc && trim((string) $pc['prefijo_sku']) !== '') $prefijo = strtoupper(trim($pc['prefijo_sku']));
                    }
                    do { $sku = Db::folio($emp, 'sku', $prefijo, 5); }
                    while (Db::one('SELECT 1 FROM articulos WHERE id_empresa = ? AND (sku = ? OR codigo = ?)', [$emp, $sku, $sku]));
                    $ean = (!$usaV1 && !$usaV2 && $f0['barras'] !== '') ? $f0['barras'] : null;
                    $idArt = Db::insert(
                        'INSERT INTO articulos (id_empresa, codigo, sku, ean, descripcion, id_categoria, id_familia, id_linea, id_marca, costo, lista1, lista2, lista3, lista4, lista5)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$emp, $cod, $sku, $ean, $f0['descripcion'], $cat ? (int) $cat['id'] : null,
                         $f0['familia'] !== '' ? self::catalogo($emp, 'familias', $f0['familia'], $cache, $nuevos) : null,
                         $f0['linea'] !== '' ? self::catalogo($emp, 'lineas', $f0['linea'], $cache, $nuevos) : null,
                         $f0['marca'] !== '' ? self::catalogo($emp, 'marcas', $f0['marca'], $cache, $nuevos) : null,
                         $f0['costo'] ?? 0, $f0['precio1'], $f0['precio2'] ?? 0, $f0['precio3'] ?? 0, $f0['precio4'] ?? 0, $f0['precio5'] ?? 0]);
                    $resumen['articulos_nuevos']++;
                }

                // variantes, codigos de barras y existencias de cada renglon
                foreach ($rs as $r) {
                    $v1 = $r['v1'] !== '' ? self::valor($emp, (int) $cat['id_atributo_eje1'], $r['v1'], $cache, $nuevos) : null;
                    $v2 = $r['v2'] !== '' ? self::valor($emp, (int) $cat['id_atributo_eje2'], $r['v2'], $cache, $nuevos) : null;
                    if (($usaV1 && !$v1) || ($usaV2 && !$v2)) { $errores[] = ['fila' => $r['fila'], 'mensaje' => "$cod: a este renglón le falta color o talla (las demás variantes del artículo sí lo traen)"]; continue; }
                    foreach ([1 => $v1, 2 => $v2] as $eje => $vid) {
                        if ($vid && Db::run('INSERT IGNORE INTO articulo_eje_valores (id_empresa, id_articulo, eje, id_valor) VALUES (?,?,?,?)', [$emp, $idArt, $eje, $vid]) > 0)
                            $resumen['variantes_nuevas']++;
                    }
                    if ($r['barras'] !== '' && ($v1 || $v2)) {
                        $otro = Db::one('SELECT id_articulo FROM articulo_variante_codigos WHERE id_empresa = ? AND codigo_barras = ? AND NOT (id_articulo = ? AND v1_key = ? AND v2_key = ?)',
                            [$emp, $r['barras'], $idArt, $v1 ?? 0, $v2 ?? 0]);
                        if ($otro) { $errores[] = ['fila' => $r['fila'], 'mensaje' => "El código de barras {$r['barras']} ya lo usa otro artículo"]; continue; }
                        Db::run('INSERT INTO articulo_variante_codigos (id_empresa, id_articulo, id_valor1, id_valor2, codigo_barras) VALUES (?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE codigo_barras = VALUES(codigo_barras)', [$emp, $idArt, $v1, $v2, $r['barras']]);
                    }
                    if ($r['existencia'] !== null && $r['existencia'] > 0) {
                        if (!$puedeStock) { $errores[] = ['fila' => $r['fila'], 'mensaje' => 'No tienes permiso para ajustar inventario: deja vacía la existencia'];  continue; }
                        if ($r['almacen'] === '') { $errores[] = ['fila' => $r['fila'], 'mensaje' => 'Indica el almacén de la existencia']; continue; }
                        $idAlm = $almacenes[self::clave($r['almacen'])] ?? null;
                        if (!$idAlm) { $errores[] = ['fila' => $r['fila'], 'mensaje' => "No existe el almacén «{$r['almacen']}» (créalo primero en Almacenes)"]; continue; }
                        InventarioController::aplicar($emp, $idArt, $v1, $v2, $idAlm, (float) $r['existencia'], 'carga_inicial',
                            'Carga masiva: inventario inicial', 'CargaMasiva', null, $idUser);
                        $resumen['existencias_renglones']++;
                        $resumen['existencias_piezas'] += (float) $r['existencia'];
                        $almacenesUsados[$r['almacen']] = true;
                    }
                }
            } catch (PDOException $e) {
                $errores[] = ['fila' => $f0['fila'], 'mensaje' => "$cod: datos inválidos o duplicados (revisa códigos de barras y SKU)"];
            }
        }
        $resumen['existencias_piezas'] = round($resumen['existencias_piezas'], 2);
        $resumen['almacenes'] = array_keys($almacenesUsados);
        usort($errores, fn($a, $c) => $a['fila'] <=> $c['fila']);
        if ($aplicar && !$errores) Ledger::audit($ctx, 'carga_masiva', 'articulos', null,
            "{$resumen['articulos_nuevos']} nuevos, {$resumen['articulos_actualizados']} actualizados, {$resumen['existencias_piezas']} piezas");
        self::responder($aplicar, $resumen, $nuevos, $errores);
    }

    // ------------------------------------------------------------ solo existencias
    public static function existencias(array $p, array $ctx): void
    {
        $b = Http::bodyCrudo();
        $aplicar = !empty($b['aplicar']);
        $modo = ($b['modo'] ?? 'reemplazar') === 'sumar' ? 'sumar' : 'reemplazar';
        $filas = self::filas($b);
        $emp = Tenant::id();
        $almacenes = self::almacenes($emp);
        $motivo = self::txt($b['motivo'] ?? '', 120) ?: ($modo === 'sumar' ? 'Carga masiva de existencias' : 'Conteo físico (carga masiva)');
        $errores = []; $cache = []; $nuevos = ['valores' => []];
        $resumen = ['renglones' => 0, 'con_cambio' => 0, 'sin_cambio' => 0, 'piezas_entrada' => 0.0, 'piezas_salida' => 0.0];
        $vistos = [];

        Db::begin();
        foreach ($filas as $i => $f) {
            $n = (int) ($f['fila'] ?? ($i + 2));
            $cod = strtoupper(self::txt($f['codigo'] ?? '', 60));
            if ($cod === '') continue;
            $resumen['renglones']++;
            $cant = self::numero($f['cantidad'] ?? null);
            if ($cant === null || $cant === false || $cant < 0) { $errores[] = ['fila' => $n, 'mensaje' => 'La cantidad debe ser un número mayor o igual a 0']; continue; }
            $idAlm = $almacenes[self::clave(self::txt($f['almacen'] ?? '', 120))] ?? null;
            if (!$idAlm) { $errores[] = ['fila' => $n, 'mensaje' => 'Almacén vacío o inexistente']; continue; }

            // el codigo puede ser el del articulo, su codigo de barras o el de una variante
            $art = Db::one('SELECT * FROM articulos WHERE id_empresa = ? AND (codigo = ? OR ean = ? OR sku = ?) LIMIT 1', [$emp, $cod, $cod, $cod]);
            $v1 = null; $v2 = null;
            if (!$art) {
                $vc = Db::one('SELECT id_articulo, id_valor1, id_valor2 FROM articulo_variante_codigos WHERE id_empresa = ? AND (codigo_barras = ? OR sku = ?) LIMIT 1', [$emp, $cod, $cod]);
                if ($vc) {
                    $art = Db::one('SELECT * FROM articulos WHERE id = ? AND id_empresa = ?', [(int) $vc['id_articulo'], $emp]);
                    $v1 = $vc['id_valor1'] !== null ? (int) $vc['id_valor1'] : null;
                    $v2 = $vc['id_valor2'] !== null ? (int) $vc['id_valor2'] : null;
                }
            }
            if (!$art) { $errores[] = ['fila' => $n, 'mensaje' => "No existe el artículo $cod (cárgalo primero con la plantilla de artículos)"]; continue; }
            if ((int) $art['es_kit']) { $errores[] = ['fila' => $n, 'mensaje' => "$cod es un kit: carga sus componentes"]; continue; }
            if ($v1 === null && $v2 === null) {
                $cat = $art['id_categoria'] ? Db::one('SELECT id_atributo_eje1, id_atributo_eje2 FROM categorias WHERE id = ? AND id_empresa = ?', [(int) $art['id_categoria'], $emp]) : null;
                $t1 = self::txt($f['variante1'] ?? '', 80); $t2 = self::txt($f['variante2'] ?? '', 80);
                if ($t1 !== '' && $cat && $cat['id_atributo_eje1']) $v1 = self::valor($emp, (int) $cat['id_atributo_eje1'], $t1, $cache, $nuevos, false);
                if ($t2 !== '' && $cat && $cat['id_atributo_eje2']) $v2 = self::valor($emp, (int) $cat['id_atributo_eje2'], $t2, $cache, $nuevos, false);
                if (($t1 !== '' && !$v1) || ($t2 !== '' && !$v2)) { $errores[] = ['fila' => $n, 'mensaje' => "$cod no maneja la variante «" . trim("$t1 $t2") . '»']; continue; }
            }
            try {
                [$v1, $v2] = Variantes::validar($art, $v1, $v2);
            } catch (ApiError $e) { $errores[] = ['fila' => $n, 'mensaje' => "$cod: " . $e->getMessage()]; continue; }
            $celda = "$idAlm|{$art['id']}|" . ($v1 ?? 0) . '|' . ($v2 ?? 0);
            if (isset($vistos[$celda]) && $modo === 'reemplazar') { $errores[] = ['fila' => $n, 'mensaje' => "$cod repetido en el mismo almacén (renglón {$vistos[$celda]})"]; continue; }
            $vistos[$celda] = $n;

            $actual = (float) (Db::one('SELECT cantidad FROM inventario WHERE id_empresa = ? AND id_almacen = ? AND id_articulo = ? AND v1_key = ? AND v2_key = ?',
                [$emp, $idAlm, (int) $art['id'], $v1 ?? 0, $v2 ?? 0])['cantidad'] ?? 0);
            $delta = round($modo === 'sumar' ? $cant : $cant - $actual, 2);
            if (abs($delta) < 0.001) { $resumen['sin_cambio']++; continue; }
            InventarioController::aplicar($emp, (int) $art['id'], $v1, $v2, $idAlm, $delta, 'ajuste', $motivo, 'CargaMasiva', null, $ctx['user']['id']);
            $resumen['con_cambio']++;
            if ($delta > 0) $resumen['piezas_entrada'] += $delta; else $resumen['piezas_salida'] += -$delta;
        }
        $resumen['piezas_entrada'] = round($resumen['piezas_entrada'], 2);
        $resumen['piezas_salida'] = round($resumen['piezas_salida'], 2);
        usort($errores, fn($a, $c) => $a['fila'] <=> $c['fila']);
        if ($aplicar && !$errores) Ledger::audit($ctx, 'carga_masiva', 'inventario', null, "$modo: {$resumen['con_cambio']} cambios");
        self::responder($aplicar, $resumen, $nuevos, $errores);
    }
}
