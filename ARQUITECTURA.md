# MultiTienda — Arquitectura y plan (sistema de tienda general)

> **Nombre de trabajo provisional: "MultiTienda"** (fácil de renombrar después).
> Fork de LEVOTEK: reutiliza todo el núcleo probado (login/usuarios, almacenes, POS, compras,
> traspasos, apartados, devoluciones, finanzas, cortes, reportes, bitácora) y **generaliza el
> modelo de producto** para vender de todo: ropa, calzado, bisutería, papelería, electrónica
> (accesorios), belleza, juguetes, fiestas, etc.

## 1. Objetivo
Una tienda que vende de todo, **bien diferenciada por categoría y tipo**, con creación, compra,
venta, traspasos, etc. correctos para cada tipo de producto — incluyendo productos **sin variantes**
(la mayoría) y con variantes configurables (ropa=color+talla, calzado=color+número, …).

## 2. Decisión de diseño (elegida)
- **Fork de LEVOTEK** (proyecto/BD nuevos, marca aparte).
- **Modelo de variantes: Opción A — 2 ejes configurables por categoría.**
  El motor `color × talla` se generaliza a **atributos genéricos** (Color, Talla, Número, Material,
  Tono, Tamaño…). Cada **categoría** define su Eje 1 y Eje 2 (o ninguno). Los productos sin variante
  usan el "único" que ya existe. Máx. 2 ejes (cubre ~todo el catálogo real).
- **Códigos:** cada artículo/variante lleva **SKU interno automático** (siempre) + **código de barras
  de fábrica (EAN/UPC) opcional**. Para productos sin código físico → se **genera etiqueta con código
  de barras** (a partir del SKU interno) para imprimir/pegar, o se busca por nombre en el POS.
- **Extras:** campos por categoría (ficha), unidades/paquetes, kits (producto compuesto).

## 3. Modelo de datos (nuevo / generalizado)

### Catálogo de atributos (reemplaza colores/tallas fijos)
```
atributos            (id, id_empresa, nombre, tipo_valor['texto'|'color'|'numero'], orden)
   -- ej: Color, Talla, Número, Material, Tono, Tamaño, Capacidad...
atributo_valores     (id, id_empresa, id_atributo, nombre, extra[hex/orden], is_active)
   -- ej: (Color: Rojo #f00), (Talla: M), (Número: 26), (Material: Plata)
```
> **Migración desde LEVOTEK:** `colores` → `atributo_valores` del atributo "Color";
> `tallas` → valores del atributo "Talla". Se conservan ids donde se pueda.

### Categorías y tipos
```
categorias   (id, id_empresa, nombre, prefijo_sku['ROP','CAL','BIS','PAP','ELE','BEL','JUG','FIE'...],
              id_atributo_eje1 NULL, id_atributo_eje2 NULL,   -- ejes de variante de la categoría
              ficha_schema JSON NULL,   -- define campos propios (ej. garantia, lote, caducidad, material)
              is_active)
```
- Eje1/Eje2 NULL → categoría **sin variantes** (producto simple).
- `ficha_schema`: lista de campos `[{key,label,tipo}]` que el alta de producto mostrará para esa categoría.

### Artículos (generalizado)
```
articulos: + id_categoria, + sku (interno, auto, único por empresa),
           + codigo_barras (EAN/UPC de fábrica, opcional),
           + unidad ['pieza'|'paquete'|'set'...], + contenido_paquete INT (piezas por paquete),
           + es_kit TINYINT, + ficha JSON (valores de ficha_schema de su categoría)
           (se mantiene: costo, listas 1..5, oferta, marca, etc.)
```
- `id_coleccion`/`id_corte` (de LEVOTEK) quedan como catálogos opcionales; el eje real lo da la categoría.

### Variantes / inventario (se conserva la estructura de 2 slots)
- `inventario`, `*_lineas`, kardex ya tienen **2 slots** (`id_color`,`id_talla`).
  Se **renombran conceptualmente** a `id_eje1_valor`, `id_eje2_valor` (apuntan a `atributo_valores`).
  0 = "sin ese eje" (como el "único/única" actual). **Cero cambios de plomería**, solo de significado y etiquetas.
- **Código de barras por variante:** `articulo_variante_codigos (id, id_articulo, id_eje1, id_eje2, sku, codigo_barras)`
  para que al escanear caiga la variante exacta.

### Kits (producto compuesto)
```
articulo_componentes (id, id_kit, id_componente, cantidad)
```
Vender/comprar un kit descuenta/afecta sus componentes en inventario.

## 4. Fases de implementación
- **F1 — Motor de atributos + categorías** (aditivo, no rompe nada): tablas `atributos`,
  `atributo_valores`, `categorias`; `CategoriaController`, `AtributoController`; rutas; pantallas de catálogo.
- **F2 — Artículos generalizados:** `id_categoria`, `sku` auto, `codigo_barras`, `unidad`, `ficha` por categoría;
  el alta de producto se vuelve **adaptativa** (muestra ejes/ficha según la categoría).
- **F3 — Flujo adaptativo:** POS, inventario, compras, traspasos, apartados, devoluciones y matrices
  usan "ejes de la categoría" en vez de color/talla fijos (rejilla si hay ejes; cantidad simple si no).
- **F4 — Códigos de barras:** SKU/EAN por variante, **búsqueda por escaneo** en POS, y **generación e
  impresión de etiqueta** con código de barras (render en el navegador + imprimir).
- **F5 — Unidades y kits.**
- **F6 — Rebrand** (cuando haya nombre definitivo): marca, logo, tokens, colores.

## 5. Etiquetas con código de barras (F4)
- Render **100% en el navegador** (sin dependencias externas prohibidas): se genera el código de barras
  (Code128 a partir del SKU interno) como SVG y se arma una etiqueta imprimible (nombre, precio, SKU,
  código). Botón "Imprimir etiqueta(s)" desde el artículo o desde una compra (para etiquetar lo que llega).
- Sirve justo para lo que **no trae código de fábrica** (bolsas de fiesta, bisutería a granel, etc.).

## 6. Desarrollo local (coexiste con LEVOTEK)
- **BD nueva** (ej. `multitienda`) en el mismo MySQL de XAMPP (3307), distinta de `levotek`.
- **Puertos distintos** para no chocar: backend PHP **8081**, frontend Vite **3010**.
- `config.local.php` propio (no se sube). El resto del despliegue a GoDaddy es idéntico a LEVOTEK.

## 7. Estado
- [x] Fork de LEVOTEK creado en `C:\Users\Jaqueline\Documents\Github\MultiTienda`.
- [x] **F1** motor de atributos + categorías (probado end-to-end).
- [x] **F2** artículos generalizados: `id_categoria`, **SKU interno automático** (prefijo de categoría),
      código de fábrica opcional (`ean`), unidad, ficha, y **ejes de variante por categoría**
      (los valores viven en `atributo_valores`; `articulo_colores/tallas` = eje1/eje2). Productos simples
      usan la celda única (0,0). Probado: producto simple (SKU auto) y con variantes (Calzado = Color×Número).
- [ ] **F3** flujo adaptativo (repuntar joins color/talla→atributo_valores en inventario/POS/compras/
      traspasos/apartados/devoluciones/kardex + matriz genérica en el frontend). **Siguiente.**
- [ ] F4 códigos de barras + etiquetas · F5 unidades/kits · F6 rebrand.

> Nota técnica F3: el catálogo de artículos ya es genérico, pero los módulos que **muestran** nombres de
> color/talla (POS, existencias, kardex, detalles) aún hacen JOIN a las tablas viejas `colores`/`tallas`.
> Como el inventario ahora guarda ids de `atributo_valores`, F3 repunta esos JOIN a `atributo_valores`.
