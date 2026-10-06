# MultiTienda — Handoff (retomar la conversación aquí)

> Documento para **continuar el proyecto en la ventana de MultiTienda sin perder contexto**.
> Léelo junto con **[ARQUITECTURA.md](ARQUITECTURA.md)** (plan y modelo de datos completos).
> Última actualización: **2026-07-07**.

## 1. Qué es este proyecto
**Fork de LEVOTEK** para una **tienda que vende de todo**:
ropa, calzado, bisutería, papelería, electrónica (accesorios), belleza, juguetes, fiestas, etc.
Reutiliza todo el núcleo probado de LEVOTEK y **generaliza el modelo de producto** con
**categorías + atributos de variante configurables** (Opción A: 2 ejes por categoría).
Nombre de trabajo provisional: **"MultiTienda"** (fácil de renombrar; falta la fase de rebrand).

## 2. Estado actual (todo lo hecho está PROBADO end-to-end)
- [x] **Fork** creado (`C:\Users\Jaqueline\Documents\Github\MultiTienda`), sin `node_modules`/`.git`/`dist`.
- [x] **F1** — Motor de atributos + categorías: tablas `atributos`, `atributo_valores`, `categorias`;
      `AtributoController`, `CategoriaController`; rutas; permisos.
- [x] **F2** — Artículos generalizados: `articulos` con `id_categoria`, `sku` (interno auto por prefijo de
      categoría), `ean` (código de fábrica opcional), `unidad`, `contenido_paquete`, `es_kit`, `ficha` (JSON).
      Código de artículo ahora es **opcional** (si va vacío = el SKU). `ArticuloController` generalizado.
- [x] **F3-backend** — Todos los controladores que muestran variantes repuntados de `colores`/`tallas`
      a **`atributo_valores`** (Inventario existencias+kardex, Compra, Traspaso, Apartado, Devolucion, Reporte kardex).
- [x] **F3-frontend** — Matriz genérica + selects de variante por eje de categoría (probado contra la API real).
      `MatrizColorTalla` etiqueta los ejes con `art.id_categoria.eje1/eje2.nombre` (fallback Color/Talla),
      pinta swatch cuando el valor trae hex, y para productos **sin variantes** captura una **cantidad simple**
      (celda 0,0). Nuevo helper `VariantesInline` (selects por eje, oculta el eje ausente, "Sin variante" si es
      simple) usado en **POS, Apartados, Devoluciones/Cambios**. Tablas de detalle (Compras, Traspasos,
      Inventario existencias+kardex, Devoluciones) muestran una sola columna **"Variante"**. **ArticulosPage**:
      selector de **Categoría** (define ejes + ficha), muestra **SKU** (auto, read-only) y **EAN**, los pickers de
      variante ahora son los **valores del atributo del eje** de la categoría, y renderiza la **ficha** por categoría.
      Nuevos servicios `categoriasApi`/`atributosApi` en `services/api/endpoints.js`. `npm run build` (vite) pasa limpio.
- [x] **F4** — Códigos de barras + escaneo + etiquetas (probado end-to-end). Backend: `GET /articulos/scan?code=`
      (resuelve SKU/EAN/código, o SKU de variante `<sku>-<eje1>-<eje2>`) y `GET /articulos/:id/variantes`
      (combinaciones con SKU/código de barras por variante). Frontend: `utils/barcode.js` (Code128 → SVG, sin deps),
      `utils/labels.js` (imprime etiquetas: descripción, variante, precio, SKU + barras), botón **Etiquetas** en
      ArticulosPage (elige variantes + cantidades + precio) e **input de escaneo** en POS (agrega la variante exacta).
- [x] **F5** — Unidades / paquetes / **kits** (probado end-to-end). `ArticuloController`: `componentes` en el
      artículo (CRUD vía payload), `es_kit`/`unidad`/`contenido_paquete`. `VentaController::afectarInventario`:
      al **vender** un kit descuenta sus **componentes** (cantidad × pieza), no el kit; **cancelar** los repone.
      UI en ArticulosPage: unidad, contenido del paquete, "Es kit" + captura de componentes con cantidad.
      *Limitación:* la expansión de kit está en la **venta/cancelación**; apartados/traspasos/compras de un kit
      aún mueven el propio artículo (los kits se piensan como paquetes que se **venden** en POS).
- [x] **F6 (light)** — Rebrand visible a **MultiTienda**: logos (`logo.svg`/`logo-light.svg` wordmark),
      `index.html`, `package.json`, `capacitor.config.ts`, LoginPage, Sidebar, Dashboard, pie del corte,
      installer y **nombre de empresa** (seed + BD viva). **No** se tocaron claves internas de storage
      (`levotek_token`/`levotek_user`/IndexedDB) ni la credencial sembrada `admin@levotek.mx` (para no romper login).

## 3. Modelo de datos clave (cómo quedó la generalización)
- **`atributos`** (Color, Talla, Numero, Material…) + **`atributo_valores`** (Rojo #f00, M, 26…).
- **`categorias`**: `prefijo_sku`, `id_atributo_eje1`, `id_atributo_eje2` (NULL = sin ese eje), `ficha_schema` (JSON).
  Seed: **Ropa**(Color×Talla), **Calzado**(Color×Numero), **General**(sin ejes).
- **Los 2 slots de variante NO se renombraron**: `inventario.id_color`/`id_talla`, `*_lineas.id_color`/`id_talla`,
  `articulo_colores.id_color`, `articulo_tallas.id_talla` **ahora guardan ids de `atributo_valores`**
  (antes eran ids de `colores`/`tallas`). `0` = "sin ese eje" (celda única, producto simple).
- **`articulo.colores`/`articulo.tallas`** en el JSON de la API = valores de **eje1**/**eje2** del artículo.
- **`articulo.id_categoria`** en la API viene poblado con `{nombre, eje1{nombre}, eje2{nombre}, maneja_variantes, ficha_schema}`.
- Tablas ya creadas para fases próximas: `articulo_componentes` (kits, F5), `articulo_variante_codigos` (F4).
- `colores`/`tallas` quedan como catálogos **legacy** (ya no se usan para variantes; el CatalogoController aún los expone).

## 4. Cómo correr en local (XAMPP)
- **BD:** `multitienda` en el MySQL de XAMPP (**puerto 3307**). Ya existe y está sembrada.
  `lib/config.local.php` ya apunta a `multitienda` (root, sin contraseña) — NO se sube a producción.
- **Backend:** `php -S 127.0.0.1:8081 -t backend/api`  (puerto **8081** para no chocar con LEVOTEK en 8080).
- **Frontend:** primero **`npm install`** en `frontend/` (no se copió `node_modules`), luego `npm run dev`.
  `frontend/.env` ya apunta a `http://127.0.0.1:8081/index.php/v1`.
- **Login:** `admin@levotek.mx` / `admin123` (rebrand del login/marca es F6).
- **Instalar/migrar (BD nueva o vacía):** abrir `http://127.0.0.1:8081/install.php?go=1`.

## 5. SIGUIENTE TAREA — F4 (códigos de barras + etiqueta imprimible)
(F3-frontend ya está hecho y probado — ver sección 2.)
1. **Código por variante**: usar la tabla `articulo_variante_codigos` (ya creada) para guardar SKU/EAN por
   combinación de ejes; al escanear caer en la variante exacta (o al artículo si no maneja variantes).
2. **Búsqueda por escaneo en POS**: que `ArticuloAutocomplete` (o un input de escaneo) resuelva por `sku`/`ean`
   y agregue la variante correcta al carrito.
3. **Etiqueta imprimible**: render **100% en el navegador** (sin dependencias externas prohibidas): generar el
   código de barras **Code128 como SVG** a partir del SKU interno y armar la etiqueta (nombre, precio, SKU, código).
   Botón "Imprimir etiqueta(s)" desde el artículo y desde una compra (para etiquetar lo que llega).

Después: **F5** (unidades/paquetes/kits — tabla `articulo_componentes` ya creada) y **F6** (rebrand).

### Notas de F3-frontend (para referencia)
- Helpers/《componentes》 nuevos viven en `frontend/src/components/common/MatrizColorTalla.jsx`:
  `nombreEje1/nombreEje2`, `tieneEje1/tieneEje2`, `esSimple`, y `<VariantesInline>` (selects de variante en carritos).
- La matriz usa clave de celda `${eje1Id}_${eje2Id}`; producto simple = celda `_` (0,0). `expandirMatriz`/`sumarMatriz`
  no cambiaron de contrato, así que POS/Compras/Traspasos/Inventario/Apartados/Devoluciones siguen enviando lo mismo.

## 6. Archivos tocados en F1–F3 (backend)
- Nuevos: `lib/controllers/AtributoController.php`, `lib/controllers/CategoriaController.php`, `lib/config.local.php`, `ARQUITECTURA.md`.
- Modificados: `schema.sql` (tablas nuevas + columnas de `articulos`), `install.php` (seed de atributos/categorías + 2 artículos demo),
  `index.php` (rutas + permisos), `lib/controllers/ArticuloController.php` (generalizado),
  `InventarioController.php`, `CompraController.php`, `TraspasoController.php`, `ApartadoController.php`,
  `DevolucionController.php`, `ReporteController.php` (repunte a `atributo_valores`).
- `frontend/.env` (puerto 8081).

### Frontend tocado en F3
- `services/api/endpoints.js` (+`categoriasApi`, +`atributosApi`), `components/common/MatrizColorTalla.jsx` (generalizado
  + `VariantesInline` + helpers), `pages/articulos/ArticulosPage.jsx` (categoría/SKU/EAN/ejes/ficha),
  `pages/pos/POSPage.jsx`, `pages/apartados/ApartadosPage.jsx`, `pages/devoluciones/DevolucionesPage.jsx`,
  `pages/compras/ComprasPage.jsx`, `pages/traspasos/TraspasosPage.jsx`, `pages/inventario/InventarioPage.jsx`.

## 7. Para retomar en la ventana de MultiTienda
Solo di: **"retoma el proyecto MultiTienda, lee HANDOFF.md"** y se continúa con **F4** (códigos de barras + etiquetas).
