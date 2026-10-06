# Despliegue de MultiTienda en GoDaddy (cPanel)

Guía paso a paso para publicar el sistema en un hosting GoDaddy (cPanel + Apache + MySQL).
Frontend (React) y backend (PHP) van en el **mismo dominio**: el front en la raíz y la API bajo `/api`.

> **Ya te dejé todo empaquetado** en la carpeta `deploy-godaddy/public_html/` de este proyecto.
> Solo tienes que: crear la BD, poner sus datos en un archivo, subir la carpeta y correr el instalador.

---

## 0. Qué se sube y dónde

El contenido de **`deploy-godaddy/public_html/`** va tal cual dentro del **`public_html`** de tu hosting:

```
public_html/
├── index.html            ← la app (React ya compilada)
├── assets/               ← JS/CSS de la app
├── favicon.svg, logo*.svg
├── .htaccess             ← ruteo del SPA (React Router)
└── api/                  ← el backend PHP
    ├── index.php
    ├── install.php       ← se corre UNA vez y se BORRA
    ├── schema.sql
    ├── .htaccess         ← ruteo de la API
    ├── lib/              ← aquí va config.php
    └── uploads/articulos ← fotos de artículos (debe poder escribirse)
```

> La app ya viene compilada apuntando a `/api/index.php/v1` (ruta relativa), así que **funciona en
> cualquier dominio sin recompilar**.

---

## 1. Crear la base de datos en cPanel

1. Entra a **cPanel** → sección **Bases de datos** → **Bases de datos MySQL**.
2. **Crear una base de datos** (ej. `multitienda`). cPanel le pone un prefijo → quedará algo como
   `micuenta_multitienda`. **Anota el nombre completo.**
3. **Crear un usuario de MySQL** (ej. `mtuser`) con una **contraseña fuerte**. Quedará `micuenta_mtuser`.
   **Anota usuario y contraseña.**
4. **Agregar el usuario a la base de datos** y dale **ALL PRIVILEGES** (todos los privilegios).

Tendrás 3 datos:
- **Nombre BD:** `micuenta_multitienda`
- **Usuario:** `micuenta_mtuser`
- **Contraseña:** la que pusiste

---

## 2. Poner los datos de la BD en la configuración

Abre **`deploy-godaddy/public_html/api/lib/config.php`** (edítalo aquí antes de subir, o en el File
Manager de cPanel después). Cambia SOLO estas 3 líneas por tus datos del paso 1:

```php
'name'    => getenv('DB_NAME') ?: 'REEMPLAZA_nombre_bd',    // -> 'micuenta_multitienda'
'user'    => getenv('DB_USER') ?: 'REEMPLAZA_usuario_bd',   // -> 'micuenta_mtuser'
'pass'    => getenv('DB_PASS') ?: 'REEMPLAZA_password_bd',  // -> tu contraseña
```

- `host` = `localhost` y `port` = `3306` **no se tocan** en GoDaddy.
- **Cambia también `jwt_secret`** por una cadena larga y propia (la que trae es un placeholder).
- Deja `cors_origin => ''` y `debug => false` (ya vienen así): front y API están en el mismo dominio.

> **Importante:** NO subas tu `config.local.php` (el de XAMPP). Ya lo excluí del paquete.

---

## 3. Subir los archivos

**Opción A — File Manager (cPanel):**
1. Comprime el **contenido** de `deploy-godaddy/public_html/` en un `.zip`
   (que el zip tenga dentro `index.html`, `assets/`, `.htaccess`, `api/`… **no** una carpeta extra que los envuelva).
2. cPanel → **Administrador de archivos** → entra a `public_html` → **Cargar** el zip → **Extraer** ahí.

**Opción B — FTP (FileZilla):** sube todo el contenido de `public_html/` a la carpeta `public_html` del servidor.

> Asegúrate de que se suban los archivos ocultos **`.htaccess`** (en FileZilla: *Servidor → Forzar mostrar
> archivos ocultos*). Sin ellos, el ruteo no funciona.

---

## 4. Crear las tablas y el usuario admin (instalador)

1. En el navegador abre: **`https://TU-DOMINIO/api/install.php?go=1`**
2. Debe decir *"Conexión a la base de datos OK"* y *"Instalación completa"*.
   - Si falla la conexión, revisa los 3 datos del paso 2 (nombre/usuario/pass exactos, **con** prefijo).
3. **Borra `api/install.php`** del servidor (por seguridad). El instalador te lo recuerda.

Esto crea las tablas + la semilla: empresa **MultiTienda**, **Tienda Principal** + **Bodega**,
categorías/atributos base (Ropa, Calzado, General) y el usuario administrador.

---

## 5. Entrar y asegurar

- Abre **`https://TU-DOMINIO/`** → login:
  - **Usuario:** `admin@levotek.mx`
  - **Contraseña:** `admin123`
- **Cambia la contraseña** al entrar.
- **Permisos de la carpeta de fotos:** si al subir fotos de artículos da error, en el File Manager pon
  permisos **755** a `api/uploads` (y a `api/uploads/articulos`). Normalmente se crea sola.

---

## 6. Checklist de seguridad (producción)

- [ ] `api/install.php` **borrado** del servidor.
- [ ] `jwt_secret` cambiado en `api/lib/config.php`.
- [ ] `debug => false` en `config.php` (así viene).
- [ ] Contraseña del admin cambiada.
- [ ] `config.local.php` **NO** está en el servidor (es solo de tu PC local).

---

## 7. Actualizaciones futuras (cuando cambie el código)

**Frontend** (cambios en React):
```bash
cd frontend
npm install        # solo la primera vez
npm run build      # genera frontend/dist con la config de .env.production
```
Sube el **contenido de `frontend/dist/`** a `public_html/` (reemplazando `index.html` y `assets/`).

**Backend** (cambios en PHP): sube los archivos cambiados de `backend/api/` a `public_html/api/`
(sin tocar tu `config.php` del servidor). Si agregaste columnas/tablas nuevas, vuelve a subir
`install.php`, ábrelo con `?go=1` (aplica migraciones idempotentes sin borrar datos) y **bórralo** otra vez.

---

## 8. Problemas comunes

| Síntoma | Causa / solución |
|---|---|
| **"Network Error"** al entrar | La API no responde. Abre `https://TU-DOMINIO/api/index.php/v1/` — debe dar un JSON (no 404). Revisa que `api/` se haya subido completo. |
| **Error 500** en la API | Casi siempre datos de BD mal en `config.php` (nombre/usuario/pass con prefijo). Actívalo temporal con `debug => true` para ver el detalle, luego regrésalo a `false`. |
| **Login dice "no autorizado" siempre** | El hosting quitó el header `Authorization`. El `api/.htaccess` ya lo reinyecta; verifica que ese `.htaccess` se haya subido. |
| **Rutas del front dan 404 al recargar** (ej. `/pos`) | Falta el `.htaccess` en la raíz `public_html`. Vuelve a subirlo (archivo oculto). |
| **La app carga sin estilos/JS** | Falta `assets/`. Revisa que exista `public_html/assets/`. |
| **PHP** | Requiere PHP 7.4+ con **pdo_mysql** (cPanel → "Select PHP Version"). |
| **Instalas en subcarpeta** (`midominio.com/tienda/`) | Requiere recompilar con `base` distinto en `vite.config.js` y ajustar `RewriteBase` en los dos `.htaccess`. Lo más simple: publicar en la **raíz** del dominio o en un **subdominio**. |

---

**Resumen ultra corto:** crea BD en cPanel → pon sus datos en `api/lib/config.php` → sube el contenido de
`deploy-godaddy/public_html/` a `public_html` → abre `/api/install.php?go=1` → borra `install.php` → entra.
