<?php
// ============================================================
// Correos de acceso: usuario@<slug>.<login_domain> (tienda) o usuario@<login_domain> (superadmin).
// Son identificadores para iniciar sesion, no buzones reales.
// ============================================================
class LoginId
{
    // Subdominios que no se pueden usar como tienda
    const SLUGS_RESERVADOS = ['www', 'api', 'admin', 'app', 'mail', 'correo', 'soporte', 'plataforma', 'login', 'demo'];

    /** Valida y normaliza la parte antes de la @ (minusculas, a-z 0-9 . _ -) */
    public static function usuario(string $u): string
    {
        $u = strtolower(trim($u));
        if (!preg_match('/^[a-z0-9]([a-z0-9._-]{0,58}[a-z0-9])?$/', $u)) {
            throw new InvalidArgumentException('Usuario invalido: usa solo minusculas, numeros, punto, guion o guion bajo');
        }
        return $u;
    }

    /** Valida y normaliza el subdominio de una tienda. $permitirReservados solo para semillas internas. */
    public static function slug(string $s, bool $permitirReservados = false): string
    {
        $s = strtolower(trim($s));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $s)) {
            throw new InvalidArgumentException('Subdominio invalido: usa solo minusculas, numeros y guiones (max. 40)');
        }
        if (!$permitirReservados && in_array($s, self::SLUGS_RESERVADOS, true)) {
            throw new InvalidArgumentException('Ese subdominio esta reservado');
        }
        return $s;
    }

    /** Arma el correo de acceso. $slug null = superadmin. */
    public static function armar(string $usuario, ?string $slug, string $dominio): string
    {
        $dominio = strtolower(trim($dominio));
        return $slug === null ? "{$usuario}@{$dominio}" : "{$usuario}@{$slug}.{$dominio}";
    }

    /**
     * Separa un correo de acceso. Devuelve ['usuario' => ..., 'slug' => string|null]
     * o null si no pertenece al dominio de la plataforma o el formato no es valido.
     */
    public static function separar(string $login, string $dominio): ?array
    {
        $login = strtolower(trim($login));
        $dominio = strtolower(trim($dominio));
        $at = strrpos($login, '@');
        if ($at === false) return null;
        $usuario = substr($login, 0, $at);
        $host = substr($login, $at + 1);
        try { $usuario = self::usuario($usuario); } catch (InvalidArgumentException $e) { return null; }

        if ($host === $dominio) return ['usuario' => $usuario, 'slug' => null];

        $sufijo = '.' . $dominio;
        if (substr($host, -strlen($sufijo)) !== $sufijo) return null;
        $slug = substr($host, 0, -strlen($sufijo));
        try { $slug = self::slug($slug, true); } catch (InvalidArgumentException $e) { return null; }
        return ['usuario' => $usuario, 'slug' => $slug];
    }
}
