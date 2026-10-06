<?php
// ============================================================
// Siembra de plataforma y de tiendas.
// La usan install.php y (F4) PlataformaController al dar de alta una tienda.
// Todo lo que crea una tienda lleva SU id_empresa: nada se comparte entre tiendas.
// ============================================================
require_once __DIR__ . '/LoginId.php';

class Seeder
{
    /** Contrasena aleatoria legible (sin caracteres ambiguos) */
    public static function passwordAleatorio(int $len = 12): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
        return $out;
    }

    /** Crea un superadmin (sin tienda). Devuelve su id. */
    public static function superadmin(string $usuario, string $nombre, string $password, string $dominio): int
    {
        $usuario = LoginId::usuario($usuario);
        return Db::insert(
            'INSERT INTO users (id_empresa, usuario, login, nombre, password, rol, debe_cambiar_password)
             VALUES (NULL, ?, ?, ?, ?, \'superadmin\', 1)',
            [$usuario, LoginId::armar($usuario, null, $dominio), $nombre, password_hash($password, PASSWORD_BCRYPT)]
        );
    }

    /**
     * Crea una tienda completa y su primer admin_tienda, en una transaccion.
     * $d = [slug, nombre, rfc?, iva?, modulos? (array de claves; null = todos), admin_nombre?, admin_password]
     * Devuelve ['id_empresa', 'id_almacen', 'id_admin', 'login_admin'].
     */
    public static function crearTienda(array $d, string $dominio): array
    {
        $slug = LoginId::slug($d['slug']);
        $propia = !Db::pdo()->inTransaction();
        if ($propia) Db::begin();
        try {
            if (Db::one('SELECT id FROM empresas WHERE slug = ?', [$slug])) {
                throw new InvalidArgumentException('Ya existe una tienda con ese subdominio');
            }
            $emp = Db::insert(
                'INSERT INTO empresas (slug, nombre, rfc, iva, uploads_token, id_salt) VALUES (?,?,?,?,?,?)',
                [$slug, $d['nombre'], $d['rfc'] ?? null, $d['iva'] ?? 0.16,
                 bin2hex(random_bytes(16)), bin2hex(random_bytes(16))]
            );

            // Modulos habilitados
            $todos = array_column(Db::all('SELECT clave FROM modulos ORDER BY orden'), 'clave');
            $modulos = $d['modulos'] ?? $todos;
            foreach ($modulos as $m) {
                if (!in_array($m, $todos, true)) throw new InvalidArgumentException("Modulo desconocido: $m");
                Db::run('INSERT INTO empresa_modulos (id_empresa, modulo, activo) VALUES (?,?,1)', [$emp, $m]);
            }

            // Almacen principal
            $alm = Db::insert(
                'INSERT INTO almacenes (id_empresa, codigo, nombre, tipo, vende_publico, serie_folio) VALUES (?,?,?,?,?,?)',
                [$emp, 'PRINCIPAL', 'Tienda principal', 'tienda', 1, 'A']
            );

            // Primer administrador de la tienda
            $admin = Db::insert(
                'INSERT INTO users (id_empresa, usuario, login, nombre, password, rol, id_almacen_default, debe_cambiar_password)
                 VALUES (?, \'admin\', ?, ?, ?, \'admin_tienda\', ?, 1)',
                [$emp, LoginId::armar('admin', $slug, $dominio), $d['admin_nombre'] ?? 'Administrador',
                 password_hash($d['admin_password'], PASSWORD_BCRYPT), $alm]
            );

            self::catalogosBase($emp);
            Db::insert('INSERT INTO clientes (id_empresa, nombre, lista_precios, es_publico_general, id_almacen) VALUES (?,?,1,1,?)',
                [$emp, 'Publico General', $alm]);
            // Cuenta bancaria principal y su terminal: los cobros con tarjeta/transferencia necesitan destino.
            // (El efectivo no es una cuenta: vive en la caja de cada almacen.)
            $cuenta = Db::insert('INSERT INTO bancos (id_empresa, nombre, moneda) VALUES (?,?,?)', [$emp, 'Cuenta principal', 'MXN']);
            Db::insert('INSERT INTO terminales (id_empresa, nombre, id_banco) VALUES (?,?,?)', [$emp, 'Terminal 1', $cuenta]);

            if ($propia) Db::commit();
        } catch (Throwable $e) {
            if ($propia) Db::rollback();
            throw $e;
        }
        return ['id_empresa' => $emp, 'id_almacen' => $alm, 'id_admin' => $admin,
                'login_admin' => LoginId::armar('admin', $slug, $dominio)];
    }

    /** Atributos (Color/Talla/Numero) y categorias base, PROPIOS de la tienda */
    public static function catalogosBase(int $emp): void
    {
        $color  = Db::insert('INSERT INTO atributos (id_empresa, nombre, tipo_valor, orden) VALUES (?,?,?,?)', [$emp, 'Color', 'color', 1]);
        $talla  = Db::insert('INSERT INTO atributos (id_empresa, nombre, tipo_valor, orden) VALUES (?,?,?,?)', [$emp, 'Talla', 'texto', 2]);
        $numero = Db::insert('INSERT INTO atributos (id_empresa, nombre, tipo_valor, orden) VALUES (?,?,?,?)', [$emp, 'Numero', 'numero', 3]);

        foreach ([['Negro', '#111827'], ['Blanco', '#f9fafb'], ['Azul', '#1d4ed8'], ['Rojo', '#dc2626']] as $i => [$n, $hex]) {
            Db::insert('INSERT INTO atributo_valores (id_empresa, id_atributo, nombre, extra, orden) VALUES (?,?,?,?,?)', [$emp, $color, $n, $hex, $i]);
        }
        foreach (['CH', 'M', 'G', 'XG'] as $i => $n) {
            Db::insert('INSERT INTO atributo_valores (id_empresa, id_atributo, nombre, orden) VALUES (?,?,?,?)', [$emp, $talla, $n, $i]);
        }
        foreach (['22', '23', '24', '25', '26', '27', '28'] as $i => $n) {
            Db::insert('INSERT INTO atributo_valores (id_empresa, id_atributo, nombre, orden) VALUES (?,?,?,?)', [$emp, $numero, $n, $i]);
        }

        $sql = 'INSERT INTO categorias (id_empresa, nombre, prefijo_sku, id_atributo_eje1, id_atributo_eje2) VALUES (?,?,?,?,?)';
        Db::insert($sql, [$emp, 'Ropa', 'ROP', $color, $talla]);
        Db::insert($sql, [$emp, 'Calzado', 'CAL', $color, $numero]);
        Db::insert($sql, [$emp, 'General', 'GEN', null, null]);
    }

    /**
     * Datos de ejemplo para desarrollo/pruebas: bodega, marca, 2 articulos con inventario y un cajero.
     * Devuelve ['login_cajero', 'id_cajero'].
     */
    public static function datosDemo(int $emp, string $slug, string $dominio, string $passCajero): array
    {
        $alm = (int) Db::one('SELECT id FROM almacenes WHERE id_empresa = ? AND codigo = ?', [$emp, 'PRINCIPAL'])['id'];
        Db::insert('INSERT INTO almacenes (id_empresa, codigo, nombre, tipo, vende_publico, serie_folio) VALUES (?,?,?,?,?,?)',
            [$emp, 'BODEGA', 'Bodega', 'bodega', 0, 'B']);
        $marca = Db::insert('INSERT INTO marcas (id_empresa, nombre) VALUES (?,?)', [$emp, 'Marca demo']);
        $banco2 = Db::insert('INSERT INTO bancos (id_empresa, nombre, moneda, cuenta) VALUES (?,?,?,?)', [$emp, 'Banco secundario', 'MXN', '0123456789']);
        Db::insert('INSERT INTO terminales (id_empresa, nombre, proveedor, id_banco, comision_pct) VALUES (?,?,?,?,?)', [$emp, 'Terminal 2', 'Clip', $banco2, 3.6]);
        $fam   = Db::insert('INSERT INTO familias (id_empresa, nombre) VALUES (?,?)', [$emp, 'Basicos']);

        $catRopa = (int) Db::one('SELECT id FROM categorias WHERE id_empresa = ? AND nombre = ?', [$emp, 'Ropa'])['id'];
        $catGen  = (int) Db::one('SELECT id FROM categorias WHERE id_empresa = ? AND nombre = ?', [$emp, 'General'])['id'];
        $colores = array_column(Db::all(
            'SELECT v.id FROM atributo_valores v JOIN atributos a ON a.id_empresa = v.id_empresa AND a.id = v.id_atributo
             WHERE v.id_empresa = ? AND a.nombre = ? ORDER BY v.orden LIMIT 2', [$emp, 'Color']), 'id');
        $tallas = array_column(Db::all(
            'SELECT v.id FROM atributo_valores v JOIN atributos a ON a.id_empresa = v.id_empresa AND a.id = v.id_atributo
             WHERE v.id_empresa = ? AND a.nombre = ? ORDER BY v.orden LIMIT 3', [$emp, 'Talla']), 'id');

        // Articulo con variantes (Color x Talla)
        $art = Db::insert(
            'INSERT INTO articulos (id_empresa, codigo, sku, descripcion, id_categoria, id_familia, id_marca, costo, lista1, lista2, lista3, lista4, lista5)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, 'ROP-00001', 'ROP-00001', 'Playera basica (demo)', $catRopa, $fam, $marca, 80, 199, 189, 179, 169, 159]
        );
        foreach ($colores as $c) Db::run('INSERT INTO articulo_eje_valores (id_empresa, id_articulo, eje, id_valor) VALUES (?,?,1,?)', [$emp, $art, $c]);
        foreach ($tallas as $t)  Db::run('INSERT INTO articulo_eje_valores (id_empresa, id_articulo, eje, id_valor) VALUES (?,?,2,?)', [$emp, $art, $t]);
        foreach ($colores as $c) foreach ($tallas as $t) {
            Db::insert('INSERT INTO inventario (id_empresa, id_almacen, id_articulo, id_valor1, id_valor2, cantidad) VALUES (?,?,?,?,?,?)',
                [$emp, $alm, $art, $c, $t, 10]);
        }

        // Articulo sin variantes
        $art2 = Db::insert(
            'INSERT INTO articulos (id_empresa, codigo, sku, descripcion, id_categoria, costo, lista1, lista2, lista3, lista4, lista5)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$emp, 'GEN-00001', 'GEN-00001', 'Producto simple (demo)', $catGen, 20, 49, 45, 42, 39, 35]
        );
        Db::insert('INSERT INTO inventario (id_empresa, id_almacen, id_articulo, cantidad) VALUES (?,?,?,?)', [$emp, $alm, $art2, 25]);

        // Cajero con permisos limitados
        $login = LoginId::armar('cajero1', $slug, $dominio);
        $cajero = Db::insert(
            'INSERT INTO users (id_empresa, usuario, login, nombre, password, rol, id_almacen_default, debe_cambiar_password)
             VALUES (?, \'cajero1\', ?, ?, ?, \'usuario\', ?, 1)',
            [$emp, $login, 'Cajero demo', password_hash($passCajero, PASSWORD_BCRYPT), $alm]
        );
        foreach ([['ventas', 'ver'], ['ventas', 'crear'], ['clientes', 'ver'], ['cortes', 'ver'], ['cortes', 'crear'], ['apartados', 'ver']] as [$m, $a]) {
            Db::run('INSERT INTO user_permisos (id_empresa, id_user, modulo, accion) VALUES (?,?,?,?)', [$emp, $cajero, $m, $a]);
        }
        return ['login_cajero' => $login, 'id_cajero' => $cajero];
    }
}
