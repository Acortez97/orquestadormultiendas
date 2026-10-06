# Orquestador MultiTiendas

Plataforma **multi-tienda** de punto de venta, inventario y finanzas. Un solo proyecto, un solo dominio,
un solo hosting y **una sola base de datos**, con dos paneles:

- **Administración general** (`/admin`, superadmin): crea tiendas, habilita módulos, administra usuarios y lo ve todo.
- **Panel de tienda** (`/`): cada tienda opera con **sus propios** usuarios, almacenes, artículos, inventario,
  ventas, cortes, clientes, CxC, proveedores, CxP y bancos. **Ninguna tienda ve ni puede deducir que existen otras.**

Acceso con correo + contraseña, donde el dominio identifica la tienda:

| Quién | Correo de acceso |
|---|---|
| Superadmin | `admin@levotek.com` |
| Admin de una tienda | `admin@<tienda>.levotek.com` |
| Usuarios de esa tienda | `cajero1@<tienda>.levotek.com` (los crea el admin de la tienda) |

> Son identificadores para iniciar sesión, **no buzones de correo**: no hay que crear correos ni DNS.

- **Frontend:** React 19 + Vite + Tailwind (`frontend/`)
- **Backend:** PHP 8 sin Composer + MySQL 8.0.16+ / MariaDB 10.4+ (`backend/api/`), pensado para GoDaddy compartido.

📄 **Diseño completo:** [PLAN-ORQUESTADOR.md](PLAN-ORQUESTADOR.md) · 🤖 **Contexto para Claude Code:** [CLAUDE.md](CLAUDE.md)

---

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| F0 | Preparación: repo, BD propia, secretos fuera del repo | ✅ |
| F1 | Esquema v2 aislado por tienda + instalador + pruebas de BD | ✅ |
| F2 | Núcleo de seguridad: `Tenant`, login por dominio, permisos por acción, ids opacos | ⏳ |
| F3 | Adaptar los 21 controladores al esquema v2 + pruebas de aislamiento por API | ⏳ |
| F4–F7 | API de plataforma, panel `/admin`, panel de tienda, despliegue | ⏳ |

> ⚠️ **Mientras F2 y F3 no estén hechas, la API no funciona contra el esquema v2** (los controladores
> todavía usan las columnas de MultiTienda). La base, el instalador y sus pruebas sí funcionan.

---

## Desarrollo local (Windows + XAMPP)

Requisitos: PHP 8 con `pdo_mysql`, XAMPP (MariaDB), Node 20+.

1. **Configuración**
   ```bash
   cp backend/api/lib/config.local.example.php backend/api/lib/config.local.php   # y pon un jwt_secret
   cp frontend/.env.example frontend/.env
   ```
2. **Servicios:** doble clic en `iniciar.bat` (MariaDB en **3307**, backend en **8082**, frontend en **3001**).
   `detener.bat` los apaga. No se toca el MySQL del sistema en 3306.
3. **Base de datos** (la primera vez, o para empezar de cero):
   ```bash
   php backend/api/reset-db.php --go --demo
   ```
   Crea el esquema, el superadmin y dos tiendas demo (`demo1`, `demo2`) con admin y cajero.
   Las contraseñas se generan al azar y quedan en `backend/api/credenciales.local.txt` (ignorado por git).
4. **Pruebas**
   ```bash
   php backend/tests/esquema_test.php    # aislamiento entre tiendas a nivel BD (53 casos)
   php backend/tests/loginid_test.php    # correos de acceso
   ```

## Producción (GoDaddy)
Ver [DESPLIEGUE-GODADDY.md](DESPLIEGUE-GODADDY.md) (se actualiza en F7). Nunca subir `reset-db.php`,
`backend/tests/` ni `config.local.php` del entorno local.

## Estructura
```
backend/api/          API PHP (se sube a public_html/api/)
  index.php           router + autenticación + permisos
  install.php         instalador (solo sobre BD vacía)
  reset-db.php        reinstalación SOLO desarrollo (bloqueado fuera de localhost/debug)
  schema.sql          esquema v2
  lib/                Db, Jwt, Http, Ledger, Pricing, LoginId, Seeder, controllers/
backend/tests/        pruebas (no se suben al servidor)
frontend/             app React
docs/historial/       documentación de MultiTienda/LEVOTEK (origen de este proyecto)
```
