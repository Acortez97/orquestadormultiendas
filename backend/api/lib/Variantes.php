<?php
// ============================================================
// Variantes de articulo (eje 1 / eje 2 -> atributo_valores). NULL = "sin ese eje".
// En la API se conservan los nombres id_color / id_talla (contrato del front);
// en la BD son id_valor1 / id_valor2.
// ============================================================
class Variantes
{
    /** '', '0', 0, null -> null; si no, int */
    public static function norm($v): ?int
    {
        return ($v === null || $v === '' || $v === false || (int) $v === 0) ? null : (int) $v;
    }

    /**
     * Valida la variante de una linea para el articulo $art (fila de articulos de la tienda).
     * Reglas: el valor debe estar entre los que maneja el articulo en ese eje; si el articulo
     * maneja el eje, la variante es obligatoria; si no lo maneja, debe ir vacia.
     * Devuelve [v1, v2].
     */
    public static function validar(array $art, $v1, $v2): array
    {
        $v1 = self::norm($v1); $v2 = self::norm($v2);
        static $cache = [];
        $id = (int) $art['id'];
        if (!isset($cache[$id])) {
            $cache[$id] = [1 => [], 2 => []];
            foreach (Db::all('SELECT eje, id_valor FROM articulo_eje_valores WHERE id_empresa = ? AND id_articulo = ?', [Tenant::id(), $id]) as $r) {
                $cache[$id][(int) $r['eje']][(int) $r['id_valor']] = true;
            }
        }
        $desc = $art['descripcion'];
        foreach ([1 => $v1, 2 => $v2] as $eje => $v) {
            $validos = $cache[$id][$eje];
            if (!$validos && $v !== null) throw new ApiError("\"$desc\" no maneja variante en el eje $eje", 400, 'VALIDATION');
            if ($validos && $v === null)  throw new ApiError("Selecciona la variante (eje $eje) de \"$desc\"", 400, 'VALIDATION');
            if ($v !== null && !isset($validos[$v])) throw new ApiError("Variante no valida para \"$desc\"", 400, 'VALIDATION');
        }
        return [$v1, $v2];
    }

    /** Referencia poblada para la API: {_id, nombre, hex, orden}; vacia = 'Unico'/'Unica' */
    public static function ref($id, ?string $nombre, $extra = null, $orden = 0, string $vacio = 'Unico'): array
    {
        if (self::norm($id) === null) return ['_id' => '', 'nombre' => $vacio, 'hex' => null, 'orden' => 0];
        return ['_id' => (int) $id, 'nombre' => $nombre, 'hex' => $extra, 'orden' => (int) $orden];
    }

    /** Nombres de valores de la tienda: [id => nombre] */
    public static function nombres(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map([self::class, 'norm'], $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all("SELECT id, nombre FROM atributo_valores WHERE id_empresa = ? AND id IN ($in)", array_merge([Tenant::id()], $ids)) as $r) {
            $out[(int) $r['id']] = $r['nombre'];
        }
        return $out;
    }
}
