# LEVOTEK — Sistema de gestión y ventas

Tienda / punto de venta con inventario por **color y talla**, clientes, ventas (POS) y cortes de caja.

- **Frontend:** React 19 + Vite + Tailwind (carpeta `frontend/`)
- **Backend:** PHP 7.4+ / 8.x + MySQL (carpeta `backend/`) — pensado para **GoDaddy** (hosting compartido)

> Es una versión **núcleo**: login/usuarios, catálogos, artículos, almacenes, inventario color‑talla, clientes, ventas/POS y cortes de caja. El frontend incluye más páginas (compras, traspasos, finanzas, facturación, etc.) que aún **no** tienen backend; quedan listas para una segunda fase.

---

## Estructura

```
sistematienda/
├─ frontend/            App React (se compila y se sube el resultado)
│  ├─ src/  public/  package.json ...
│  └─ dist/             ← lo que se sube a GoDaddy (se genera con "npm run build")
└─ backend/
   ├─ api/              ← se sube a public_html/api/
   │  ├─ index.php  install.php  schema.sql  .htaccess
   │  └─ lib/  (config.php, Db.php, Jwt.php, controladores...)
   └─ database/schema.sql   (copia del esquema, por si lo importas a mano)
```

En GoDaddy el resultado final queda así dentro de `public_html/`:

```
public_html/
├─ index.html, assets/, logo.svg ...   (contenido de frontend/dist)
├─ .htaccess                            (de frontend/dist; enruta el SPA)
└─ api/                                 (contenido de backend/api)
   ├─ index.php, .htaccess, lib/ ...
```

Así el frontend queda en `https://tudominio.com/` y el API en `https://tudominio.com/api/v1/...`.

---

## Despliegue en GoDaddy (paso a paso)

### 1. Base de datos
1. cPanel → **Bases de datos MySQL**.
2. Crea una base (ej. `levotek`), un usuario y asígnalo a la base con **todos los privilegios**.
3. Anota: **host** (casi siempre `localhost`), **nombre de BD**, **usuario** y **contraseña**.

### 2. Configurar el backend
Edita `backend/api/lib/config.php` con tus datos:
```php
'db' => [
  'host' => 'localhost',
  'name' => 'TU_BD',
  'user' => 'TU_USUARIO',
  'pass' => 'TU_PASSWORD',
],
'jwt_secret' => 'pon-aqui-una-cadena-larga-y-aleatoria',
```
> En producción (mismo dominio) deja `cors_origin => '*'` o, mejor, el dominio exacto.

### 3. Subir archivos
1. Sube **todo el contenido de `backend/api/`** a `public_html/api/` (vía Administrador de archivos o FTP).
2. Compila el frontend (ver abajo) y sube **todo el contenido de `frontend/dist/`** a `public_html/`.

### 4. Instalar (crear tablas + admin)
Abre en el navegador:
```
https://tudominio.com/api/install.php?go=1
```
Crea las tablas y un usuario inicial:
- **Usuario:** `admin@levotek.mx`
- **Contraseña:** `admin123`

➡️ **Después: BORRA `api/install.php`**, entra y **cambia la contraseña**.

### 5. Listo
Entra a `https://tudominio.com/`, inicia sesión y empieza a cargar tus catálogos, artículos e inventario.

---

## Compilar el frontend

Necesitas Node 20+ instalado en tu PC.

```bash
cd frontend
npm install
npm run build      # genera frontend/dist
```

`frontend/.env.production` ya apunta el API a `/api/v1` (mismo dominio), así que no hay que tocar nada para GoDaddy.

### Desarrollo local
```bash
# Backend (requiere PHP con pdo_mysql y un MySQL local):
cd backend/api
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=levotek DB_USER=root DB_PASS= APP_DEBUG=true \
  php -S 127.0.0.1:8080
#   instalar: http://127.0.0.1:8080/install.php?go=1
#   las rutas quedan en  http://127.0.0.1:8080/index.php/v1/...

# Frontend (en otra terminal):
cd frontend
# crea .env con: VITE_API_URL=http://127.0.0.1:8080/index.php/v1
npm run dev        # abre http://localhost:3001
```

---

## Notas técnicas
- El backend replica el **mismo contrato JSON** que esperaba la app original: envoltura `{ success, data, error, message, meta }`, **JWT Bearer**, IDs expuestos como `_id`.
- Contraseñas con `password_hash` (bcrypt). El JWT es HS256 sin dependencias externas (compatible con hosting compartido, sin Composer).
- **Socket.io / tiempo real** está desactivado (no aplica en hosting compartido). Se reactiva poniendo `VITE_SOCKET_URL` si algún día hay un servidor de sockets.
- Marca: **LEVOTEK** (logo en `frontend/public/logo.svg`). Paleta índigo + ámbar.

## Credenciales por defecto
| | |
|---|---|
| Usuario | `admin@levotek.mx` |
| Contraseña | `admin123` (cámbiala al entrar) |
