<?php
class MonederoController
{
    public static function estadoCuenta(array $p, array $ctx): void
    {
        $emp = (int) $ctx['user']['id_empresa']; $idCli = (int) $p['id'];
        $c = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp]);
        if (!$c) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        $movs = Db::all('SELECT * FROM monedero_movimientos WHERE id_cliente=? ORDER BY created_at DESC, id DESC LIMIT 200', [$idCli]);
        Http::ok([
            'cliente' => ['saldo_favor' => (float) $c['saldo_favor']],
            'movimientos' => array_map(fn($m) => [
                '_id'       => (string) $m['id'],
                'createdAt' => $m['created_at'],
                'tipo'      => $m['tipo'],
                'importe'   => (float) $m['importe'],
                'origen'    => $m['origen'],
            ], $movs),
        ]);
    }

    public static function ajuste(array $p, array $ctx): void
    {
        $b = Http::body();
        $emp = (int) $ctx['user']['id_empresa'];
        $idCli = id_or_null($b['id_cliente'] ?? null);
        $importe = num($b['importe'] ?? 0);
        if (!$idCli) throw new ApiError('id_cliente es obligatorio', 400, 'VALIDATION');
        if ($importe == 0) throw new ApiError('El importe no puede ser cero', 400, 'VALIDATION');
        $c = Db::one('SELECT * FROM clientes WHERE id=? AND id_empresa=?', [$idCli, $emp]);
        if (!$c) throw new ApiError('Cliente no encontrado', 404, 'NOT_FOUND');
        if ($importe < 0 && (float) $c['saldo_favor'] + $importe < -0.0001)
            throw new ApiError('El ajuste dejaria el monedero en negativo (saldo actual ' . (float) $c['saldo_favor'] . ')', 400, 'VALIDATION');

        $tipo = $b['tipo'] ?? ($importe >= 0 ? 'deposito' : 'gasto');
        Db::begin();
        try {
            $movId = Ledger::monederoMov($emp, $idCli, $importe, $tipo, $b['origen'] ?? 'Ajuste manual', 'Ajuste', null);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Ledger::audit($ctx, 'ajuste', 'Monedero', $idCli, 'Ajuste ' . $importe);
        $nuevo = (float) Db::one('SELECT saldo_favor FROM clientes WHERE id=?', [$idCli])['saldo_favor'];
        Http::created(['_id' => (string) $movId, 'saldo_favor' => $nuevo], 'Movimiento');
    }
}
