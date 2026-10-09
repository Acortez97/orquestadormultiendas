<?php
// ============================================================
// A donde va el dinero de cada cobro o pago.
//   efectivo                 -> caja del almacen (caja_movimientos)
//   tdc / tdb                -> terminal (obligatoria) -> cuenta bancaria de esa terminal
//   transferencia / cheque   -> cuenta bancaria (obligatoria)
//   monedero / anticipo      -> no es dinero nuevo: no se registra movimiento
// Todo cobro entra con Cobros::entrada y todo pago sale con Cobros::salida, para que
// los saldos de cajas y cuentas siempre cuadren con sus libros.
// ============================================================
class Cobros
{
    const TARJETA  = ['tdc', 'tdb'];
    const CUENTA   = ['transferencia', 'cheque'];
    const SIN_DINERO = ['monedero', 'anticipo'];
    const ETIQUETA = ['efectivo' => 'Efectivo', 'tdc' => 'Tarjeta de credito', 'tdb' => 'Tarjeta de debito',
                      'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'monedero' => 'Saldo a favor', 'anticipo' => 'Anticipo'];

    /**
     * Valida el destino del dinero segun la forma de pago. Devuelve [id_banco, id_terminal].
     * Tarjeta: la terminal es obligatoria y define la cuenta. Transferencia/cheque: la cuenta es obligatoria.
     * $moneda: moneda que debe tener la cuenta (todo cobro / pago de la tienda es en pesos); null = cualquiera.
     */
    public static function destino(string $forma, $idBanco = null, $idTerminal = null, ?string $moneda = 'MXN'): array
    {
        if (in_array($forma, self::TARJETA, true)) {
            $idTerminal = Tenant::owns('terminales', $idTerminal, 'Terminal');
            if (!$idTerminal) throw new ApiError('Selecciona la terminal con la que se cobro con tarjeta', 400, 'VALIDATION');
            $t = Db::one('SELECT id_banco, is_active FROM terminales WHERE id = ? AND id_empresa = ?', [$idTerminal, Tenant::id()]);
            if ($t['is_active'] !== 'Si') throw new ApiError('La terminal esta desactivada', 400, 'VALIDATION');
            self::validarMoneda((int) $t['id_banco'], $moneda);
            return [(int) $t['id_banco'], $idTerminal];
        }
        if (in_array($forma, self::CUENTA, true)) {
            $idBanco = Tenant::owns('bancos', $idBanco, 'Cuenta');
            if (!$idBanco) throw new ApiError('Selecciona la cuenta a la que llego la ' . strtolower(self::ETIQUETA[$forma]), 400, 'VALIDATION');
            self::validarMoneda($idBanco, $moneda);
            return [$idBanco, null];
        }
        return [null, null];   // efectivo -> caja; monedero / anticipo -> sin dinero
    }

    private static function validarMoneda(int $idBanco, ?string $moneda): void
    {
        if ($moneda === null) return;
        $m = Db::one('SELECT moneda FROM bancos WHERE id = ? AND id_empresa = ?', [$idBanco, Tenant::id()])['moneda'] ?? 'MXN';
        if ($m !== $moneda) throw new ApiError("La cuenta es en $m; este movimiento es en $moneda", 400, 'VALIDATION');
    }

    /** Registra la ENTRADA de un cobro (ingreso a caja o a la cuenta). */
    public static function entrada(int $emp, string $forma, float $monto, ?int $idBanco, ?int $idTerminal, ?int $idAlmacen,
        string $concepto, string $refTipo, ?int $idRef, ?int $idUser): void
    {
        self::mover('ingreso', $emp, $forma, $monto, $idBanco, $idTerminal, $idAlmacen, $concepto, $refTipo, $idRef, $idUser);
    }

    /** Registra la SALIDA de dinero (pago a proveedor, reverso de un cobro). */
    public static function salida(int $emp, string $forma, float $monto, ?int $idBanco, ?int $idTerminal, ?int $idAlmacen,
        string $concepto, string $refTipo, ?int $idRef, ?int $idUser): void
    {
        self::mover('egreso', $emp, $forma, $monto, $idBanco, $idTerminal, $idAlmacen, $concepto, $refTipo, $idRef, $idUser);
    }

    private static function mover(string $tipo, int $emp, string $forma, float $monto, ?int $idBanco, ?int $idTerminal, ?int $idAlmacen,
        string $concepto, string $refTipo, ?int $idRef, ?int $idUser): void
    {
        if ($monto <= 0 || in_array($forma, self::SIN_DINERO, true)) return;
        if ($forma === 'efectivo') {
            if (!$idAlmacen) throw new LogicException('Movimiento de efectivo sin caja (almacen)');
            Ledger::cajaMov($emp, $idAlmacen, $tipo, $monto, $concepto, $refTipo, $idRef, $idUser);
            return;
        }
        if (!$idBanco) throw new LogicException("Cobro con $forma sin cuenta destino");
        Ledger::bancoMov($emp, $idBanco, $tipo, $monto, $concepto, $forma, $idTerminal, $idAlmacen, $refTipo, $idRef, $idUser);
    }
}
