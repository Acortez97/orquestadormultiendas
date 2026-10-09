<?php
class ConfigController
{
    // ---- Apariencia: color de la tienda en la interfaz ----
    public static function cambiarColor(array $p, array $ctx): void
    {
        $color = Tenant::colorTienda(Http::bodyCrudo()['color'] ?? null);
        Db::run('UPDATE empresas SET color = ? WHERE id = ?', [$color, Tenant::id()]);
        Ledger::audit($ctx, 'cambiar', 'ConfigColor', null, 'Color de la tienda: ' . ($color ?? 'predeterminado'));
        Http::updated(['color' => $color], 'Color');
    }

    // ---- Logo de la tienda (tickets, cortes, reportes y menu) ----
    public static function subirLogo(array $p, array $ctx): void
    {
        $url = Logos::subirTienda(Tenant::empresa(), (string) (Http::bodyCrudo()['dataUrl'] ?? ''));
        Ledger::audit($ctx, 'cambiar', 'ConfigLogo', null, 'Logo de la tienda actualizado');
        Http::updated(['logo_url' => $url], 'Logo');
    }

    public static function quitarLogo(array $p, array $ctx): void
    {
        Logos::quitarTienda(Tenant::empresa());
        Ledger::audit($ctx, 'cambiar', 'ConfigLogo', null, 'Logo de la tienda quitado');
        Http::ok(['logo_url' => null], 'Logo quitado');
    }

    /** Logos para imprimir (data URL): el de la tienda y el de la plataforma */
    public static function logos(array $p, array $ctx): void
    {
        $e = Tenant::empresa();
        Http::ok([
            'tienda' => Logos::dataUrl(Logos::archivoTienda()),
            'tienda_url' => $e['logo_url'] ?? null,   // por si el logo es una URL externa
            'plataforma' => Logos::dataUrl(Logos::actual(Logos::dirPlataforma())),
        ]);
    }

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

    const PIN_MAX_FALLOS = 5;
    const PIN_VENTANA_MIN = 15;

    /**
     * Valida un PIN contra el configurado. Si no hay PIN configurado, no restringe (true).
     * Tras 5 PIN equivocados en 15 minutos (por tienda) se bloquea: evita adivinarlo probando.
     */
    public static function validarPin(int $emp, ?string $pin): bool
    {
        $e = Db::one('SELECT pin_lista_alta FROM empresas WHERE id=?', [$emp]);
        if (empty($e['pin_lista_alta'])) return true;           // sin PIN configurado => sin restriccion
        if ($pin === null || $pin === '') return false;
        $clave = 'pin:' . $emp;
        $fallos = (int) Db::one('SELECT COUNT(*) n FROM login_intentos WHERE login = ? AND exito = 0 AND created_at > (NOW() - INTERVAL ' . self::PIN_VENTANA_MIN . ' MINUTE)', [$clave])['n'];
        if ($fallos >= self::PIN_MAX_FALLOS)
            throw new ApiError('Demasiados PIN incorrectos. Espera ' . self::PIN_VENTANA_MIN . ' minutos e intenta de nuevo.', 429, 'TOO_MANY_ATTEMPTS');
        $ok = password_verify((string) $pin, $e['pin_lista_alta']);
        if (!$ok) Db::run('INSERT INTO login_intentos (login, ip, exito) VALUES (?,?,0)', [$clave, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        return $ok;
    }
}
