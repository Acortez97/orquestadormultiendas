<?php
// JWT HS256 minimo, sin dependencias (compatible con jwt-decode del frontend)
class Jwt
{
    public static function encode(array $payload, string $secret, int $expSeconds): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expSeconds;

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = self::b64(json_encode($header, JSON_UNESCAPED_UNICODE));
        $p = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        return "$h.$p.$sig";
    }

    /** Devuelve el payload (array) si el token es valido y no expiro, o null */
    public static function decode(string $token, string $secret)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h, $p, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(self::unb64($p), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) return null;

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
