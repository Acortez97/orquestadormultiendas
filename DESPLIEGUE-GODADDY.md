# Despliegue en GoDaddy (cPanel)

Frontend (React compilado) y API (PHP) van en el **mismo dominio**: la app en la raíz y la API en `/api`.
Todas las tiendas viven en la misma instalación y la misma base de datos.

---

## 0. Requisitos del hosting
- PHP 8.0 o superior con `pdo_mysql` (cPanel → *Select PHP Version*).
- MySQL **8.0.16+** o MariaDB **10.4+** (recomendado). Con MySQL 5.7 también funciona, pero las reglas `CHECK`
  de la base se ignoran (la API valida lo mismo y el aislamiento entre tiendas por llaves foráneas compuestas
  sí aplica). El instalador avisa si la versión es antigua.
- Apache con `mod_rewrite` (GoDaddy lo trae). Los `.htaccess` del paquete bloquean el listado de carpetas,
  la descarga de `.sql/.txt/.md` y el acceso a `api/lib/`.

## 1. Armar el paquete (en tu PC)
```bash
cd frontend
npm ci
npm run build
cd ..
php herramientas/empaquetar.php
```
Resultado: `deploy-godaddy/public_html/`. El empaquetador **excluye** `reset-db.php`, la configuración y las
credenciales locales, las fotos de prueba y las pruebas, y se detiene si algo peligroso quedó dentro.

## 2. Crear la base de datos
cPanel → **Bases de datos MySQL**:
1. Crea la base (cPanel le pone prefijo, p. ej. `micuenta_orquestador`).
2. Crea un usuario con contraseña fuerte y asígnalo a la base con **todos los privilegios**.

## 3. Subir archivos
Sube **el contenido** de `deploy-godaddy/public_html/` a `public_html/` (Administrador de archivos → Cargar,
o FTP). Debe quedar:
```
public_html/
├── index.html, assets/, .htaccess ...      ← la app
└── api/
    ├── index.php, install.php, schema.sql, .htaccess
    ├── lib/   (config.php, config.local.example.php, controladores...)
    └── uploads/.htaccess                    ← la carpeta uploads debe poder escribirse (permisos 755)
```

## 4. Configuración del servidor
En `public_html/api/lib/` copia `config.local.example.php` como **`config.local.php`** y edítalo:
```php
return [
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'micuenta_orquestador',   // con el prefijo de cPanel
        'user' => 'micuenta_usuario',
        'pass' => 'LA_CONTRASEÑA_DE_LA_BD',
    ],
    'jwt_secret'   => 'CADENA_ALEATORIA_DE_64+_CARACTERES',   // obligatoria
    'login_domain' => 'levotek.com',
    'debug'        => false,
    'cors_origin'  => '',
];
```
Para el `jwt_secret` usa una cadena aleatoria larga (p. ej. `openssl rand -hex 48`, o un generador de
contraseñas de 64 caracteres). **Sin ella la API no arranca.** Si algún día la cambias, todas las sesiones se cierran.

## 5. Instalar
Abre `https://TU-DOMINIO/api/install.php?go=1`.
- Crea las tablas y el **superadmin `admin@levotek.com`** con una contraseña aleatoria que se muestra **una sola vez**: cópiala.
- Solo funciona sobre una base vacía (no puede pisar datos existentes).
- **Después borra `public_html/api/install.php`.**

## 6. Primer acceso
1. Entra a `https://TU-DOMINIO/` con `admin@levotek.com` y la contraseña del paso 5; el sistema te pedirá cambiarla.
2. En **Tiendas → Nueva tienda** da de alta cada negocio: su subdominio de acceso (p. ej. `zapateriacentro`),
   sus módulos y su administrador. La contraseña temporal del admin se muestra una vez.
3. Entrega al dueño su acceso `admin@zapateriacentro.levotek.com`; él da de alta a su personal desde **Usuarios**.

> Los correos `usuario@tienda.levotek.com` son identificadores de acceso, **no buzones**: no hay que crear
> correos ni registros DNS.

## Checklist de seguridad
- [ ] `api/install.php` borrado.
- [ ] `config.local.php` con `debug => false` y `jwt_secret` propio.
- [ ] `https://TU-DOMINIO/api/schema.sql` y `https://TU-DOMINIO/api/lib/config.local.php` responden **403**.
- [ ] `https://TU-DOMINIO/api/uploads/` no lista archivos.
- [ ] Contraseña del superadmin cambiada en el primer acceso.

## Actualizar a una versión nueva
1. Arma el paquete otra vez (paso 1).
2. Sube y reemplaza `public_html/` **sin tocar** `api/lib/config.local.php` ni `api/uploads/`.
3. Si la versión trae cambios de base de datos, vendrán con su script de migración y sus instrucciones
   (el instalador no se vuelve a ejecutar sobre una base con datos).

## Problemas comunes
| Síntoma | Causa probable |
|---|---|
| Error 500 / "Servidor sin configurar" | Falta `config.local.php` o el `jwt_secret` tiene menos de 32 caracteres. |
| "Error de conexion a la base de datos" | Nombre de BD/usuario sin el prefijo de cPanel, o contraseña incorrecta. |
| La app carga pero todo da 404 | `mod_rewrite` desactivado: la app usa `/api/index.php/v1`, verifica que `api/index.php` exista. |
| Las fotos no se guardan | `api/uploads` sin permisos de escritura (755). |
| "Cuenta suspendida" | La tienda fue suspendida desde el panel de administración. |
