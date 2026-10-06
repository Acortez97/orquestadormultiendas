<?php
// ============================================================
// Ledger: libros mayores de finanzas y bitacora.
// Centraliza inserciones de movimientos y mutacion de saldos
// para que CxC, monedero y CxP queden siempre consistentes.
// Las llamadas deben ocurrir dentro de una transaccion del caller.
// ============================================================
class Ledger
{
    /**
     * Movimiento de cuenta de cliente (CxC). Ajusta clientes.saldo_credito:
     *  - efecto 'cargo' => saldo_credito += monto (cliente debe mas)
     *  - efecto 'abono' => saldo_credito -= monto (cliente debe menos)
     *  - efecto 'info'  => no toca saldo
     */
    public static function clienteMov(int $emp, int $idCliente, string $tipo, ?string $concepto, float $monto,
        string $efecto = 'info', string $moneda = 'MXN', ?int $idBanco = null, ?string $refTipo = null, ?int $idRef = null): int
    {
        $id = Db::insert(
            'INSERT INTO cliente_movimientos (id_empresa,id_cliente,fecha,tipo,concepto,monto,efecto,moneda,id_banco,ref_tipo,id_referencia)
             VALUES (?,?,NOW(),?,?,?,?,?,?,?,?)',
            [$emp, $idCliente, $tipo, $concepto, round($monto, 2), $efecto, $moneda, $idBanco, $refTipo, $idRef]);
        if ($efecto === 'cargo')      Db::run('UPDATE clientes SET saldo_credito=saldo_credito+? WHERE id=? AND id_empresa=?', [round($monto, 2), $idCliente, $emp]);
        elseif ($efecto === 'abono')  Db::run('UPDATE clientes SET saldo_credito=saldo_credito-? WHERE id=? AND id_empresa=?', [round($monto, 2), $idCliente, $emp]);
        return $id;
    }

    /**
     * Movimiento de monedero (saldo a favor). importe con signo:
     *  positivo = deposito (sube saldo_favor), negativo = gasto (baja saldo_favor).
     */
    public static function monederoMov(int $emp, int $idCliente, float $importe, string $tipo, ?string $origen,
        ?string $refTipo = null, ?int $idRef = null): int
    {
        $importe = round($importe, 2);
        $id = Db::insert(
            'INSERT INTO monedero_movimientos (id_empresa,id_cliente,tipo,importe,origen,ref_tipo,id_referencia)
             VALUES (?,?,?,?,?,?,?)',
            [$emp, $idCliente, $tipo, $importe, $origen, $refTipo, $idRef]);
        Db::run('UPDATE clientes SET saldo_favor=saldo_favor+? WHERE id=? AND id_empresa=?', [$importe, $idCliente, $emp]);
        return $id;
    }

    /** Movimiento de cuenta de proveedor (CxP). Saldo se deriva sumando cargos-pagos. */
    public static function proveedorMov(int $emp, int $idProveedor, string $tipo, ?string $concepto, float $monto,
        string $moneda = 'MXN', ?int $idBanco = null, ?string $refTipo = null, ?int $idRef = null): int
    {
        return Db::insert(
            'INSERT INTO proveedor_movimientos (id_empresa,id_proveedor,fecha,tipo,concepto,monto,moneda,id_banco,ref_tipo,id_referencia)
             VALUES (?,?,NOW(),?,?,?,?,?,?,?)',
            [$emp, $idProveedor, $tipo, $concepto, round($monto, 2), $moneda, $idBanco, $refTipo, $idRef]);
    }

    /** Ajusta el saldo de un banco (si aplica). delta con signo. */
    public static function bancoDelta(int $emp, ?int $idBanco, float $delta): void
    {
        if (!$idBanco) return;
        Db::run('UPDATE bancos SET saldo_actual=saldo_actual+? WHERE id=? AND id_empresa=?', [round($delta, 2), $idBanco, $emp]);
    }

    /** Registra una entrada en la bitacora. Nunca lanza (best-effort). */
    public static function audit(array $ctx, string $accion, ?string $entidad = null, $idEntidad = null, ?string $descripcion = null): void
    {
        try {
            // id_empresa NULL = accion de plataforma; act_as = superadmin operando como tienda (soporte)
            $u = $ctx['user'] ?? null;
            $soporte = !empty($u['act_as']);
            Db::insert(
                'INSERT INTO audit_log (id_empresa,id_usuario,act_as,usuario,accion,entidad,id_entidad,descripcion,ip)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $u['id_empresa'] ?? null,
                    isset($u['id']) ? (int) $u['id'] : null,
                    $soporte ? (int) $u['act_as'] : null,
                    $soporte ? 'Soporte' : ($u ? trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')) : null),
                    $accion, $entidad, $idEntidad !== null ? (string) $idEntidad : null, $descripcion,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
        } catch (Throwable $e) { /* la bitacora no debe romper la operacion */ }
    }
}
