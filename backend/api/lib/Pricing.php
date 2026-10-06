<?php
// Motor de precios — replica pricing.engine del backend original.
// Listas 1..5: lista1 = mas cara (1 pza), lista5 = mas barata (24+ pzas).
class Pricing
{
    /** Nivel de lista segun cantidad total de prendas (sin ofertas) */
    public static function nivelPorCantidad(int $totalPrendas): int
    {
        if ($totalPrendas >= 24) return 5;
        if ($totalPrendas >= 12) return 4;
        if ($totalPrendas >= 6)  return 3;
        if ($totalPrendas >= 3)  return 2;
        return 1;
    }

    /**
     * Cotiza un conjunto de lineas.
     * @param array $lineas  cada una: id_articulo, id_color, id_talla, cantidad
     * @return array { total_prendas_lista, nivel_cantidad, lista_cliente, lineas[], subtotal, iva, total }
     */
    public static function cotizar(int $idEmpresa, ?int $idCliente, array $lineas, float $iva = 0.16): array
    {
        // Lista del cliente
        $listaCliente = 1;
        if ($idCliente) {
            $cli = Db::one('SELECT lista_precios FROM clientes WHERE id=? AND id_empresa=?', [$idCliente, $idEmpresa]);
            if ($cli) $listaCliente = max(1, min(5, (int) $cli['lista_precios']));
        }

        // 1) cargar articulos y contar prendas que cuentan para nivel (no oferta)
        $arts = [];
        $totalPrendas = 0;
        foreach ($lineas as $ln) {
            $idArt = id_or_null($ln['id_articulo'] ?? null);
            if (!$idArt) throw new ApiError('Linea sin id_articulo', 400, 'VALIDATION');
            if (!isset($arts[$idArt])) {
                $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [$idArt, $idEmpresa]);
                if (!$a) throw new ApiError("Articulo $idArt no encontrado", 404, 'NOT_FOUND');
                $arts[$idArt] = $a;
            }
            $cant = max(0, (float) ($ln['cantidad'] ?? 0));
            if (!((int) $arts[$idArt]['es_oferta'])) $totalPrendas += $cant;
        }

        $nivel = self::nivelPorCantidad((int) round($totalPrendas));

        // 2) precio por linea
        $out = [];
        $total = 0.0;
        foreach ($lineas as $ln) {
            $idArt = (int) $ln['id_articulo'];
            $a = $arts[$idArt];
            $cant = max(0, (float) ($ln['cantidad'] ?? 0));

            if ((int) $a['es_oferta']) {
                $precio = (float) $a['precio_oferta'];
                $listaAplicada = 'OFERTA';
            } else {
                [$precio, $nl] = self::elegirPrecio($a, $listaCliente, $nivel);
                $listaAplicada = (string) $nl;
            }

            $importe = round($precio * $cant, 2);
            $total += $importe;

            $out[] = [
                'id_articulo'     => (string) $idArt,
                'codigo'          => $a['codigo'],
                'descripcion'     => $a['descripcion'],
                'id_color'        => (string) id_or_zero($ln['id_color'] ?? 0),
                'id_talla'        => (string) id_or_zero($ln['id_talla'] ?? 0),
                'cantidad'        => $cant,
                'precio_unitario' => round($precio, 2),
                'costo_unitario'  => (float) $a['costo'],
                'lista_aplicada'  => $listaAplicada,
                'comisiona'       => true,
                'importe'         => $importe,
            ];
        }

        $total   = round($total, 2);
        $subtotal = round($total / (1 + $iva), 2);
        $ivaMonto = round($total - $subtotal, 2);

        return [
            'total_prendas_lista' => (int) round($totalPrendas),
            'nivel_cantidad'      => $nivel,
            'lista_cliente'       => $listaCliente,
            'lineas'              => $out,
            'subtotal'            => $subtotal,
            'iva'                 => $ivaMonto,
            'total'               => $total,
        ];
    }

    /** Elige el menor precio entre la lista del cliente y la lista por cantidad. Empate -> lista mayor. */
    private static function elegirPrecio(array $a, int $listaCliente, int $nivel): array
    {
        $cand = array_unique([$listaCliente, $nivel]);
        $mejorPrecio = null; $mejorLista = 1;
        foreach ($cand as $n) {
            $p = (float) $a['lista' . $n];
            if ($p <= 0) continue;
            if ($mejorPrecio === null || $p < $mejorPrecio || ($p === $mejorPrecio && $n > $mejorLista)) {
                $mejorPrecio = $p; $mejorLista = $n;
            }
        }
        if ($mejorPrecio === null) { $mejorPrecio = (float) $a['lista1']; $mejorLista = 1; }
        return [$mejorPrecio, $mejorLista];
    }
}
