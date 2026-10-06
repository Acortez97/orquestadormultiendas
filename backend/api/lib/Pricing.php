<?php
// Motor de precios por tienda.
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
            if (!$cli) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
            $listaCliente = max(1, min(5, (int) $cli['lista_precios']));
        }

        // 1) cargar articulos y contar prendas que cuentan para nivel (no oferta)
        $arts = [];
        $totalPrendas = 0;
        foreach ($lineas as $ln) {
            $idArt = id_or_null($ln['id_articulo'] ?? null);
            if (!$idArt) throw new ApiError('Hay una linea sin articulo', 400, 'VALIDATION');
            if (!isset($arts[$idArt])) {
                $a = Db::one('SELECT * FROM articulos WHERE id=? AND id_empresa=?', [$idArt, $idEmpresa]);
                if (!$a) throw new ApiError('Articulo no encontrado', 404, 'NOT_FOUND');
                $arts[$idArt] = $a;
            }
            $cant = (float) ($ln['cantidad'] ?? 0);
            if ($cant <= 0) throw new ApiError('La cantidad de "' . $arts[$idArt]['descripcion'] . '" debe ser mayor a cero', 400, 'VALIDATION');
            if (!((int) $arts[$idArt]['es_oferta'])) $totalPrendas += $cant;
        }

        $nivel = self::nivelPorCantidad((int) round($totalPrendas));

        // 2) precio por linea
        $out = [];
        $total = 0.0;
        foreach ($lineas as $ln) {
            $idArt = (int) $ln['id_articulo'];
            $a = $arts[$idArt];
            $cant = (float) $ln['cantidad'];
            [$v1, $v2] = Variantes::validar($a, $ln['id_color'] ?? null, $ln['id_talla'] ?? null);

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
                'id_articulo'     => $idArt,
                'codigo'          => $a['codigo'],
                'descripcion'     => $a['descripcion'],
                'id_color'        => $v1 ?? 0,   // eje 1 (id_valor1); 0 = sin variante (sale como '')
                'id_talla'        => $v2 ?? 0,   // eje 2 (id_valor2)
                'cantidad'        => $cant,
                'precio_unitario' => round($precio, 2),
                'costo_unitario'  => (float) $a['costo'],
                'lista_aplicada'  => $listaAplicada,
                'comisiona'       => true,
                'es_kit'          => (bool) $a['es_kit'],
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
