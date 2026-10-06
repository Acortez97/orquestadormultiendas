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

Fases **F0–F7 completas** (ver [PLAN-ORQUESTADOR.md](PLAN-ORQUESTADOR.md)): esquema aislado por tienda, núcleo de
seguridad (ids opacos, login por dominio, permisos por acción), los 21 controladores adaptados, API de plataforma,
panel `/admin`, panel de tienda y empaquetado para GoDaddy. **425 pruebas automáticas** en verde.

Cobros: cada pago con tarjeta pide la **terminal** (y el dinero queda en la cuenta de esa terminal), cada
transferencia o cheque pide la **cuenta destino**, y el efectivo queda en la caja de la tienda; el corte de caja,
"Bancos y cajas" y la conciliación cuadran al centavo.

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
4. **Pruebas** (ver la lista completa en [CLAUDE.md](CLAUDE.md#pruebas-todas-deben-dar-100--las-de-api-requieren-el-backend-en-8082))
   ```bash
   php backend/tests/esquema_test.php          # aislamiento entre tiendas en la BD
   php backend/tests/aislamiento_api_test.php  # flujo completo de una tienda + 70 ataques desde otra
   ```
5. **Datos de prueba** (3 tiendas con operaciones de todo tipo, verificadas):
   ```bash
   php backend/api/reset-db.php --go --demo && php herramientas/datos_prueba.php
   ```

## Producción (GoDaddy)
```bash
cd frontend && npm run build && cd .. && php herramientas/empaquetar.php
```
y sube `deploy-godaddy/public_html/`. Guía paso a paso: [DESPLIEGUE-GODADDY.md](DESPLIEGUE-GODADDY.md).

## Estructura
```
backend/api/          API PHP (se sube a public_html/api/)
  index.php           router + autenticación + permisos
  install.php         instalador (solo sobre BD vacía)
  reset-db.php        reinstalación SOLO desarrollo (bloqueado fuera de localhost/debug)
  schema.sql          esquema v2
  lib/                Db, Jwt, Http, Ledger, Pricing, LoginId, Seeder, controllers/
backend/tests/        pruebas (no se suben al servidor)
herramientas/         empaquetar.php (arma deploy-godaddy/public_html)
frontend/             app React
docs/historial/       documentación de MultiTienda/LEVOTEK (origen de este proyecto)
```
