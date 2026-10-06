<?php
class ConfigController
{
    // ---- PIN de autorizacion para listas de precio 4 y 5 ----
    public static function estadoPin(array $p, array $ctx): void
    {
        $emp = Tenant::id();
        $e = Db::one('SELECT pin_lista_alta, pin_lista_alta_at FROM empresas WHERE id=?', [$emp]);
        Http::ok([
            'configurado'    => !empty($e['pin_lista_alta']),
            'actualizado_en' => $e['pin_lista_alta_at'] ?? null,
        ]);
    }

    public static function cambiarPin(array $p, array $ctx): void
    {
        $b = Http::body();
        $pin = (string) ($b['pin'] ?? '');
        if (!preg_match('/^\d{4,6}$/', $pin)) throw new ApiError('El PIN debe ser de 4 a 6 digitos', 400, 'VALIDATION');
        $emp = Tenant::id();
        Db::run('UPDATE empresas SET pin_lista_alta=?, pin_lista_alta_at=NOW() WHERE id=?',
            [password_hash($pin, PASSWORD_BCRYPT), $emp]);
        Ledger::audit($ctx, 'cambiar', 'ConfigPIN', null, 'PIN de listas 4/5 actualizado');
        $e = Db::one('SELECT pin_lista_alta_at FROM empresas WHERE id=?', [$emp]);
        Http::updated(['configurado' => true, 'actualizado_en' => $e['pin_lista_alta_at']], 'PIN');
    }

    /** Valida un PIN contra el configurado. Si no hay PIN configurado, no restringe (true). */
    public static function validarPin(int $emp, ?string $pin): bool
    {
        $e = Db::one('SELECT pin_lista_alta FROM empresas WHERE id=?', [$emp]);
        if (empty($e['pin_lista_alta'])) return true;           // sin PIN configurado => sin restriccion
        if ($pin === null || $pin === '') return false;
        return password_verify((string) $pin, $e['pin_lista_alta']);
    }
}
