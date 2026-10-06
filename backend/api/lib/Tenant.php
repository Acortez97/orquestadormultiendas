<?php
// ============================================================
// Tenant — contexto de la tienda de la peticion + ids opacos.
//
// * La tienda SIEMPRE sale del usuario autenticado (nunca del body/query).
// * Ids opacos: la API nunca expone ids internos. Cada id se codifica con la sal
//   de SU tienda (empresas.id_salt): un id de otra tienda no decodifica -> 404.
//   Codificacion: permutacion Feistel de 32 bits + etiqueta HMAC de 16 bits, en base62 (9 caracteres).
// * La conversion es automatica: Http::body(), $_GET y los parametros de ruta se
//   decodifican al entrar; Http::ok() codifica la respuesta al salir. Ver CLAVES_ID.
// ============================================================
class Tenant
{
    /** @var array|null fila de empresas de la tienda activa */
    private static $empresa = null;
    /** @var string|null sal activa para codificar ids (tienda o plataforma) */
    private static $salt = null;
    /** @var string sal de plataforma (derivada de jwt_secret; nunca sale del servidor) */
    private static $saltPlataforma = '';
    private static $cacheEnc = [];
    private static $cacheDec = [];

    const B62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    const LARGO = 9;

    /** Claves (ademas de id, _id, id_* y *_por) cuyos valores son ids o listas de ids */
    const CLAVES_ID = ['colores', 'tallas', 'valores'];

    /** Tablas de negocio que se pueden validar con owns() */
    const TABLAS = [
        'almacenes', 'users', 'familias', 'lineas', 'cortes_catalogo', 'marcas', 'conceptos_gasto',
        'atributos', 'atributo_valores', 'categorias', 'articulos', 'empleados', 'clientes', 'proveedores',
        'bancos', 'inventario', 'ventas', 'compras', 'traspasos', 'apartados', 'devoluciones', 'cambios',
        'comisiones', 'cortes',
    ];

    public static function configurar(array $cfg): void
    {
        self::$saltPlataforma = hash_hmac('sha256', 'ids-plataforma', (string) $cfg['jwt_secret']);
    }

    /** Activa la tienda de la peticion (usuario de tienda o superadmin "entrando como tienda") */
    public static function activarTienda(array $empresa): void
    {
        self::$empresa = $empresa;
        self::$salt = $empresa['id_salt'];
        self::$cacheEnc = self::$cacheDec = [];
    }

    /** Contexto de plataforma (superadmin en /plataforma/*): ids con la sal de plataforma */
    public static function activarPlataforma(): void
    {
        self::$empresa = null;
        self::$salt = self::$saltPlataforma;
        self::$cacheEnc = self::$cacheDec = [];
    }

    public static function hayTienda(): bool { return self::$empresa !== null; }
    public static function empresa(): ?array { return self::$empresa; }

    /** id_empresa de la peticion. Lanza si no hay tienda activa (error de programacion). */
    public static function id(): int
    {
        if (self::$empresa === null) throw new ApiError('Operacion no disponible', 404, 'NOT_FOUND');
        return (int) self::$empresa['id'];
    }

    /**
     * Verifica que $id pertenezca a la tienda activa. Devuelve $id (o null si viene vacio).
     * Si no es de la tienda: 404 "No encontrado" (igual que si no existiera).
     */
    public static function owns(string $tabla, $id, string $etiqueta = 'Registro', bool $requerido = false): ?int
    {
        if (!in_array($tabla, self::TABLAS, true)) throw new LogicException("Tabla no permitida en owns(): $tabla");
        if ($id === null || $id === '' || (int) $id === 0) {
            if ($requerido) throw new ApiError("$etiqueta es obligatorio", 400, 'VALIDATION');
            return null;
        }
        $id = (int) $id;
        if (!Db::one("SELECT 1 FROM `$tabla` WHERE id = ? AND id_empresa = ?", [$id, self::id()])) {
            throw new ApiError("$etiqueta no encontrado", 404, 'NOT_FOUND');
        }
        return $id;
    }

    // ---------------------------------------------------------------
    // Ids opacos
    // ---------------------------------------------------------------

    public static function enc(int $id, ?string $salt = null): string
    {
        $salt = $salt ?? self::$salt;
        if ($salt === null) return (string) $id;
        if ($id <= 0 || $id > 0xFFFFFFFF) throw new LogicException('Id fuera de rango');
        $k = $salt . ':' . $id;
        if (isset(self::$cacheEnc[$k])) return self::$cacheEnc[$k];

        $l = ($id >> 16) & 0xFFFF; $r = $id & 0xFFFF;
        for ($i = 0; $i < 4; $i++) { [$l, $r] = [$r, $l ^ self::ronda($salt, $i, $r)]; }
        $v = ((($l << 16) | $r) << 16) | self::etiqueta($salt, $id);

        $s = '';
        for ($i = 0; $i < self::LARGO; $i++) { $s = self::B62[$v % 62] . $s; $v = intdiv($v, 62); }
        return self::$cacheEnc[$k] = $s;
    }

    /** Decodifica un id opaco. Devuelve null si no es valido para la sal activa. */
    public static function dec(string $s, ?string $salt = null): ?int
    {
        $salt = $salt ?? self::$salt;
        if ($salt === null) return ctype_digit($s) ? (int) $s : null;
        if (strlen($s) !== self::LARGO) return null;
        $k = $salt . ':' . $s;
        if (array_key_exists($k, self::$cacheDec)) return self::$cacheDec[$k];

        $v = 0;
        for ($i = 0; $i < self::LARGO; $i++) {
            $p = strpos(self::B62, $s[$i]);
            if ($p === false) return self::$cacheDec[$k] = null;
            $v = $v * 62 + $p;
        }
        if ($v >= (1 << 48)) return self::$cacheDec[$k] = null;
        $tag = $v & 0xFFFF; $x = $v >> 16;
        $l = ($x >> 16) & 0xFFFF; $r = $x & 0xFFFF;
        for ($i = 3; $i >= 0; $i--) { [$l, $r] = [$r ^ self::ronda($salt, $i, $l), $l]; }
        $id = ($l << 16) | $r;
        if ($id <= 0 || !hash_equals((string) self::etiqueta($salt, $id), (string) $tag)) return self::$cacheDec[$k] = null;
        return self::$cacheDec[$k] = $id;
    }

    private static function ronda(string $salt, int $i, int $mitad): int
    {
        return unpack('n', substr(hash_hmac('sha256', "r$i:$mitad", $salt, true), 0, 2))[1];
    }
    private static function etiqueta(string $salt, int $id): int
    {
        return unpack('n', substr(hash_hmac('sha256', "t:$id", $salt, true), 0, 2))[1];
    }

    /** Ids de sesion (JWT): siempre con la sal de plataforma, independientes de la tienda */
    public static function encSesion(int $id): string { return self::enc($id, self::$saltPlataforma); }
    public static function decSesion(string $s): ?int { return self::dec($s, self::$saltPlataforma); }

    // ---------------------------------------------------------------
    // Conversion automatica de entrada / salida
    // ---------------------------------------------------------------

    public static function esClaveId($clave): bool
    {
        if (!is_string($clave)) return false;
        return $clave === 'id' || $clave === '_id' || strncmp($clave, 'id_', 3) === 0
            || substr($clave, -4) === '_por' || in_array($clave, self::CLAVES_ID, true);
    }

    /** Decodifica un valor de id que viene del front. ''/null/'0' = vacio. Invalido -> 404. */
    public static function decEntrada($v)
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0' || $v === false) return $v === false ? null : $v;
        if (is_array($v)) return self::esAsociativo($v) ? self::entrada($v) : array_map([self::class, 'decEntrada'], $v);
        if (!is_string($v) && !is_int($v)) return $v;
        $id = self::dec((string) $v);
        if ($id === null) throw new ApiError('Registro no encontrado', 404, 'NOT_FOUND');
        return $id;
    }

    /** Recorre datos de entrada y decodifica los ids. id_empresa del cliente se descarta. */
    public static function entrada($data)
    {
        if (!is_array($data)) return $data;
        $out = [];
        foreach ($data as $k => $v) {
            if ($k === 'id_empresa') continue;
            if (self::esClaveId($k) && !self::esListaDeObjetos($v)) $out[$k] = self::decEntrada($v);
            else $out[$k] = is_array($v) ? self::entrada($v) : $v;
        }
        return $out;
    }

    /** Recorre la respuesta y codifica los ids. Nunca se expone id_empresa. */
    public static function salida($data)
    {
        if (!is_array($data)) return $data;
        $out = [];
        foreach ($data as $k => $v) {
            if ($k === 'id_empresa' || $k === 'id_salt' || $k === 'uploads_token') continue;
            if (self::esClaveId($k) && !self::esListaDeObjetos($v)) $out[$k] = self::encSalida($v);
            else $out[$k] = is_array($v) ? self::salida($v) : $v;
        }
        return $out;
    }

    private static function encSalida($v)
    {
        if (is_array($v)) return self::esAsociativo($v) ? self::salida($v) : array_map([self::class, 'encSalida'], $v);
        if (is_int($v) || (is_string($v) && $v !== '' && ctype_digit($v))) {
            $n = (int) $v;
            return $n > 0 ? self::enc($n) : '';
        }
        return $v;
    }

    private static function esAsociativo(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }
    private static function esListaDeObjetos($v): bool
    {
        return is_array($v) && !self::esAsociativo($v) && $v !== [] && is_array(reset($v));
    }
}
