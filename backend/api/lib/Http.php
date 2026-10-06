<?php
// Helpers de request/response que replican el envelope { success, data, error, message, meta }

class ApiError extends Exception
{
    public $status;
    public $code;
    public function __construct(string $message, int $status = 400, string $code = 'ERROR')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->code = $code;
    }
}

class Http
{
    /** Respuesta exitosa */
    public static function ok($data = null, ?string $message = null, ?array $meta = null, int $status = 200): void
    {
        // Ids opacos: codifica todos los ids de la respuesta con la sal de la tienda/plataforma activa
        if (is_array($data) && class_exists('Tenant', false)) $data = Tenant::salida($data);
        $resp = ['success' => true, 'data' => $data, 'error' => null];
        if ($message !== null) $resp['message'] = $message;
        if ($meta !== null)    $resp['meta'] = $meta;
        self::send($resp, $status);
    }

    public static function created($data, string $recurso = 'Recurso'): void
    {
        self::ok($data, "$recurso creado exitosamente", null, 201);
    }
    public static function updated($data, string $recurso = 'Recurso'): void
    {
        self::ok($data, "$recurso actualizado exitosamente", null, 200);
    }
    public static function deleted(string $recurso = 'Recurso'): void
    {
        self::ok(null, "$recurso eliminado exitosamente", null, 200);
    }

    /** Respuesta de error */
    public static function fail(string $message, int $status = 400, string $code = 'ERROR'): void
    {
        self::send([
            'success' => false,
            'data'    => null,
            'error'   => ['code' => $code, 'message' => $message],
        ], $status);
    }

    private static function send(array $resp, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @var array|null body ya decodificado (con ids opacos convertidos a internos) */
    private static $body = null;

    /** Lee el body JSON. Los ids opacos llegan ya convertidos a ids internos (Tenant::entrada). */
    public static function body(): array
    {
        if (self::$body !== null) return self::$body;
        $raw = file_get_contents('php://input');
        $data = ($raw === '' || $raw === false) ? [] : json_decode($raw, true);
        $data = is_array($data) ? $data : [];
        return self::$body = class_exists('Tenant', false) ? Tenant::entrada($data) : $data;
    }

    /** Body crudo, SIN convertir ids (solo para login y casos sin sesion) */
    public static function bodyCrudo(): array
    {
        $raw = file_get_contents('php://input');
        $data = ($raw === '' || $raw === false) ? [] : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** Token Bearer del header Authorization */
    public static function bearerToken(): ?string
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$hdr && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            $hdr = $h['Authorization'] ?? $h['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) return trim($m[1]);
        return null;
    }
}

// ---- Helpers de tipos / ids ----
function id_or_null($v): ?int
{
    if ($v === null || $v === '' || $v === false) return null;
    return (int) $v;
}
function id_or_zero($v): int
{
    if ($v === null || $v === '' || $v === false) return 0;
    return (int) $v;
}
function num($v): float { return round((float) $v, 2); }

/** Convierte campos numericos de una fila a int/float para el JSON */
function cast_row(array $row, array $ints = [], array $floats = []): array
{
    foreach ($ints as $k)   if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    foreach ($floats as $k) if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (float) $row[$k];
    return $row;
}
