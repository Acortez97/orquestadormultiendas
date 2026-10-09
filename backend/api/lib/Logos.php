<?php
// ============================================================
// Logos que salen en tickets, cortes y reportes:
//  - el de cada tienda:      uploads/<uploads_token>/logo-<aleatorio>.<ext>  (empresas.logo_url)
//  - el de la plataforma:    uploads/plataforma/logo-<aleatorio>.<ext>       (LEVOTEK, lo sube el superadmin)
// Solo png, jpg o webp (nunca svg: podria llevar codigo). Al subir uno nuevo se borra el anterior.
// ============================================================
class Logos
{
    const MAX_BYTES = 2 * 1024 * 1024;
    const EXT = ['png', 'jpg', 'webp'];
    const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public static function dirUploads(): string
    {
        return __DIR__ . '/../uploads';
    }

    public static function dirTienda(): string
    {
        return self::dirUploads() . '/' . Tenant::empresa()['uploads_token'];
    }

    public static function dirPlataforma(): string
    {
        return self::dirUploads() . '/plataforma';
    }

    /** URL publica de un archivo dentro de uploads/ (misma forma que las fotos de articulos) */
    public static function url(string $relativo): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        return "$scheme://$host$base/uploads/$relativo";
    }

    /** Valida y guarda un logo (data URL base64) en $dir, borrando el anterior. Devuelve el nombre del archivo. */
    public static function guardar(string $dataUrl, string $dir): string
    {
        if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,(.+)$#s', $dataUrl, $m))
            throw new ApiError('El logo debe ser una imagen PNG, JPG o WEBP', 400, 'VALIDATION');
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $bin = base64_decode($m[2], true);
        if ($bin === false) throw new ApiError('No se pudo leer la imagen', 400, 'VALIDATION');
        if (strlen($bin) > self::MAX_BYTES) throw new ApiError('El logo no puede pesar más de 2 MB', 400, 'VALIDATION');
        if (@getimagesizefromstring($bin) === false) throw new ApiError('El archivo no es una imagen válida', 400, 'VALIDATION');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('No se pudo crear la carpeta de imágenes', 500, 'SERVER_ERROR');
        self::borrar($dir);
        $nombre = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents("$dir/$nombre", $bin) === false) throw new ApiError('No se pudo guardar el logo', 500, 'SERVER_ERROR');
        return $nombre;
    }

    /** Borra los logos de una carpeta */
    public static function borrar(string $dir): void
    {
        foreach (glob("$dir/logo-*.*") ?: [] as $f) @unlink($f);
    }

    /** Archivo del logo actual de una carpeta (o null) */
    public static function actual(string $dir): ?string
    {
        $f = glob("$dir/logo-*.*") ?: [];
        return $f ? $f[0] : null;
    }

    /** Contenido de un logo como data URL (para tickets y PDF sin problemas de origen) */
    public static function dataUrl(?string $archivo): ?string
    {
        if (!$archivo || !is_file($archivo)) return null;
        $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) return null;
        return 'data:' . self::MIME[$ext] . ';base64,' . base64_encode((string) file_get_contents($archivo));
    }

    /** Logo de la tienda activa (solo si se subio aqui; un logo_url externo se deja al navegador) */
    public static function archivoTienda(): ?string
    {
        return self::actual(self::dirTienda());
    }

    public static function urlPlataforma(): ?string
    {
        $f = self::actual(self::dirPlataforma());
        return $f ? self::url('plataforma/' . basename($f)) : null;
    }
}
