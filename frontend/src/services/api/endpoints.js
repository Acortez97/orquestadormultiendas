import apiClient from './apiClient';

// Helper: desempaqueta { success, data }
const get = (url, params) => apiClient.get(url, { params }).then((r) => r.data);
const post = (url, body) => apiClient.post(url, body).then((r) => r.data);
const put = (url, body) => apiClient.put(url, body).then((r) => r.data);
const patch = (url, body) => apiClient.patch(url, body).then((r) => r.data);
const del = (url) => apiClient.delete(url).then((r) => r.data);

// ---- Catálogos simples (factory) ----
function crudCatalogo(ruta) {
  return {
    listar: (params) => get(`/catalogos/${ruta}`, params),
    crear: (data) => post(`/catalogos/${ruta}`, data),
    actualizar: (id, data) => put(`/catalogos/${ruta}/${id}`, data),
    eliminar: (id) => del(`/catalogos/${ruta}/${id}`),
  };
}
export const familiasApi = crudCatalogo('familias');
export const lineasApi = crudCatalogo('lineas');
export const cortesCatalogoApi = crudCatalogo('cortes');
export const marcasApi = crudCatalogo('marcas');
export const conceptosGastoApi = crudCatalogo('conceptos-gasto');

// ---- Atributos de variante (Color, Talla, Número, Material…) y sus valores ----
export const atributosApi = {
  listar: (params) => get('/atributos', params),
  crear: (data) => post('/atributos', data),
  actualizar: (id, data) => put(`/atributos/${id}`, data),
  eliminar: (id) => del(`/atributos/${id}`),
  valores: (id) => get(`/atributos/${id}/valores`),
  crearValor: (id, data) => post(`/atributos/${id}/valores`, data),
  actualizarValor: (id, data) => put(`/atributos/valores/${id}`, data),
  eliminarValor: (id) => del(`/atributos/valores/${id}`),
};

// ---- Categorías (definen los ejes de variante y la ficha del producto) ----
export const categoriasApi = {
  listar: (params) => get('/categorias', params),
  obtener: (id) => get(`/categorias/${id}`),
  crear: (data) => post('/categorias', data),
  actualizar: (id, data) => put(`/categorias/${id}`, data),
  eliminar: (id) => del(`/categorias/${id}`),
};

// ---- Artículos ----
export const articulosApi = {
  listar: (params) => get('/articulos', params),
  buscar: (q) => get('/articulos/buscar', { q }),
  scan: (code) => get('/articulos/scan', { code }),
  variantes: (id) => get(`/articulos/${id}/variantes`),
  obtener: (id) => get(`/articulos/${id}`),
  crear: (data) => post('/articulos', data),
  actualizar: (id, data) => put(`/articulos/${id}`, data),
  eliminar: (id) => del(`/articulos/${id}`),
  subirFoto: (dataUrl) => post('/articulos/foto', { dataUrl }),
};

// ---- Clientes ----
export const clientesApi = {
  listar: (params) => get('/clientes', params),
  obtener: (id) => get(`/clientes/${id}`),
  crear: (data) => post('/clientes', data),
  actualizar: (id, data) => put(`/clientes/${id}`, data),
  autorizarCredito: (id, data) => patch(`/clientes/${id}/autorizar-credito`, data),
  eliminar: (id) => del(`/clientes/${id}`),
};

// ---- Proveedores / Empleados / Almacenes ----
export const proveedoresApi = {
  listar: (params) => get('/proveedores', params),
  crear: (data) => post('/proveedores', data),
  actualizar: (id, data) => put(`/proveedores/${id}`, data),
  eliminar: (id) => del(`/proveedores/${id}`),
};
export const empleadosApi = {
  listar: (params) => get('/empleados', params),
  crear: (data) => post('/empleados', data),
  actualizar: (id, data) => put(`/empleados/${id}`, data),
  eliminar: (id) => del(`/empleados/${id}`),
};
export const almacenesApi = {
  listar: (params) => get('/almacen/almacenes', params),
  crear: (data) => post('/almacen/almacenes', data),
  actualizar: (id, data) => put(`/almacen/almacenes/${id}`, data),
  eliminar: (id) => del(`/almacen/almacenes/${id}`),
};

// ---- Inventario ----
export const inventarioApi = {
  existencias: (params) => get('/almacen/inventario', params),
  kardex: (params) => get('/almacen/inventario/kardex', params),
  ajuste: (data) => post('/almacen/inventario/ajuste', data),
  ajusteLote: (data) => post('/almacen/inventario/ajuste-lote', data),
};

// ---- Ventas (POS) ----
export const ventasApi = {
  listar: (params) => get('/ventas', params),
  obtener: (id) => get(`/ventas/${id}`),
  cotizar: (data) => post('/ventas/cotizar', data),
  crear: (data) => post('/ventas', data),
  cancelar: (id) => patch(`/ventas/${id}/cancelar`, {}),
};

// ---- Compras ----
export const comprasApi = {
  listar: (params) => get('/compras', params),
  obtener: (id) => get(`/compras/${id}`),
  crear: (data) => post('/compras', data),
  actualizar: (id, data) => put(`/compras/${id}`, data),
  aprobar: (id) => patch(`/compras/${id}/aprobar`, {}),
  eliminar: (id) => del(`/compras/${id}`),
  pagos: (id) => get(`/compras/${id}/pagos`),
  registrarPago: (id, data) => post(`/compras/${id}/pagos`, data),
};

// ---- Traspasos ----
export const traspasosApi = {
  listar: (params) => get('/almacen/traspasos', params),
  obtener: (id) => get(`/almacen/traspasos/${id}`),
  crear: (data) => post('/almacen/traspasos', data),
  actualizar: (id, data) => put(`/almacen/traspasos/${id}`, data),
  aceptar: (id) => patch(`/almacen/traspasos/${id}/aceptar`, {}),
  rechazar: (id) => patch(`/almacen/traspasos/${id}/rechazar`, {}),
};

// ---- Apartados ----
export const apartadosApi = {
  listar: (params) => get('/apartados', params),
  obtener: (id) => get(`/apartados/${id}`),
  crear: (data) => post('/apartados', data),
  editar: (id, data) => put(`/apartados/${id}`, data),
  anticipo: (id, data) => patch(`/apartados/${id}/anticipo`, data),
  liquidar: (id, data) => patch(`/apartados/${id}/liquidar`, data || {}),
  cancelar: (id) => patch(`/apartados/${id}/cancelar`, {}),
};

// ---- Devoluciones / Cambios ----
export const devolucionesApi = {
  buscarVenta: (folio) => get('/devoluciones/buscar-venta', { folio }),
  listar: (params) => get('/devoluciones', params),
  crear: (data) => post('/devoluciones', data),
  listarCambios: (params) => get('/devoluciones/cambios', params),
  crearCambio: (data) => post('/devoluciones/cambios', data),
};

// ---- Cortes ----
export const cortesApi = {
  preview: (params) => get('/cortes/preview', params),
  cerrar: (data) => post('/cortes/cerrar', data),
  listar: (params) => get('/cortes', params),
  obtener: (id) => get(`/cortes/${id}`),
};

// ---- Comisiones ----
export const comisionesApi = {
  listar: (params) => get('/comisiones', params),
  resumen: () => get('/comisiones/resumen'),
  pagar: (id) => patch(`/comisiones/${id}/pagar`, {}),
};

// ---- Monedero ----
export const monederoApi = {
  estadoCuenta: (idCliente) => get(`/monedero/${idCliente}`),
  ajuste: (data) => post('/monedero/ajuste', data),
};

// ---- Cuentas (finanzas) ----
export const cuentasClienteApi = {
  listar: (params) => get('/finanzas/cuentas-cliente', params),
  movimientos: (idCliente, params) => get(`/finanzas/cuentas-cliente/${idCliente}/movimientos`, params),
  estadoCuenta: (idCliente, params) => get(`/finanzas/cuentas-cliente/${idCliente}/estado-cuenta`, params),
  abono: (data) => post('/finanzas/cuentas-cliente/abono', data),
};
export const cuentasProveedorApi = {
  listar: (params) => get('/finanzas/cuentas-proveedor', params),
  movimientos: (idProveedor, params) => get(`/finanzas/cuentas-proveedor/${idProveedor}/movimientos`, params),
  pago: (data) => post('/finanzas/cuentas-proveedor/pago', data),
};
export const bancosApi = {
  listar: (params) => get('/finanzas/bancos', params),
  crear: (data) => post('/finanzas/bancos', data),
  actualizar: (id, data) => put(`/finanzas/bancos/${id}`, data),
  movimientos: (id, params) => get(`/finanzas/bancos/${id}/movimientos`, params),
  // tipo: deposito (caja -> cuenta) | retiro (cuenta -> caja) | ingreso | egreso
  movimiento: (id, data) => post(`/finanzas/bancos/${id}/movimientos`, data),
};

// Terminales de cobro con tarjeta (cada una deposita en una cuenta)
export const terminalesApi = {
  listar: (params) => get('/finanzas/terminales', params),
  crear: (data) => post('/finanzas/terminales', data),
  actualizar: (id, data) => put(`/finanzas/terminales/${id}`, data),
};

// Efectivo por almacen (caja)
export const cajasApi = {
  listar: () => get('/finanzas/cajas'),
  movimientos: (idAlmacen, params) => get(`/finanzas/cajas/${idAlmacen}/movimientos`, params),
  movimiento: (idAlmacen, data) => post(`/finanzas/cajas/${idAlmacen}/movimientos`, data),
};

// ---- Reportes ----
export const reportesApi = {
  // Fase 1
  ventas: (params) => get('/reportes/ventas', params),
  utilidad: (params) => get('/reportes/utilidad', params),
  porLista: (params) => get('/reportes/por-lista', params),
  topProductos: (params) => get('/reportes/top-productos', params),
  // Fase 2
  cortesPeriodo: (params) => get('/reportes/cortes-periodo', params),
  cxcAntiguedad: (params) => get('/reportes/cxc-antiguedad', params),
  cxpProveedores: (params) => get('/reportes/cxp-proveedores', params),
  comisiones: (params) => get('/reportes/comisiones', params),
  // Fase 3
  existencias: (params) => get('/reportes/existencias-valorizadas', params),
  kardex: (params) => get('/reportes/kardex', params),
  compras: (params) => get('/reportes/compras', params),
  devoluciones: (params) => get('/reportes/devoluciones', params),
  // Dashboard de productos (más/menos vendidos, media, stock bajo/agotado, caducidad)
  dashboardProductos: (params) => get('/reportes/dashboard-productos', params),
};

// ---- Audit ----
export const auditApi = {
  listar: (params) => get('/audit-log', params),
};

// ---- Config del sistema ----
export const configApi = {
  // PIN de autorización para Lista de precios 4/5
  estadoPinListaAlta: () => get('/config-sistema/pin-lista-alta'),
  cambiarPinListaAlta: (pin) => put('/config-sistema/pin-lista-alta', { pin }),
  // Color de la tienda en la interfaz ('' = el predeterminado)
  cambiarColor: (color) => put('/config-sistema/color', { color }),
  // Logo de la tienda (data URL png/jpg/webp) y logos para imprimir
  subirLogo: (dataUrl) => put('/config-sistema/logo', { dataUrl }),
  quitarLogo: () => del('/config-sistema/logo'),
  logos: () => get('/tienda/logos'),
};

// ---- Carga masiva (aplicar=false revisa sin guardar) ----
export const importarApi = {
  articulos: (body) => post('/importar/articulos', body),
  existencias: (body) => post('/importar/existencias', body),
};

// ---- Inicio de la tienda: resumen del dia y pendientes ----
export const tiendaApi = {
  hoy: (params) => get('/tienda/hoy', params),
  pendientes: () => get('/tienda/pendientes'),
};

// ---- Usuarios de la tienda (solo admin de tienda) ----
export const usuariosTiendaApi = {
  modulos: () => get('/auth/modulos-tienda'),
  listar: () => get('/auth/usuarios'),
  crear: (data) => post('/auth/usuarios', data),
  actualizar: (id, data) => put(`/auth/usuarios/${id}`, data),
  desactivar: (id) => del(`/auth/usuarios/${id}`),
  resetPassword: (id, newPassword) => post(`/auth/usuarios/${id}/reset-password`, { newPassword }),
};

// ---- Plataforma (solo superadmin) ----
export const plataformaApi = {
  dashboard: () => get('/plataforma/dashboard'),
  logo: () => get('/plataforma/logo'),
  subirLogo: (dataUrl) => put('/plataforma/logo', { dataUrl }),
  quitarLogo: () => del('/plataforma/logo'),
  modulos: () => get('/plataforma/modulos'),
  tiendas: (params) => get('/plataforma/tiendas', params),
  tienda: (id) => get(`/plataforma/tiendas/${id}`),
  crearTienda: (data) => post('/plataforma/tiendas', data),
  editarTienda: (id, data) => put(`/plataforma/tiendas/${id}`, data),
  estadoTienda: (id, isActive) => patch(`/plataforma/tiendas/${id}/estado`, { is_active: isActive }),
  avisoPago: (id, mensaje) => put(`/plataforma/tiendas/${id}/aviso-pago`, { mensaje }),
  modulosTienda: (id) => get(`/plataforma/tiendas/${id}/modulos`),
  guardarModulos: (id, modulos) => put(`/plataforma/tiendas/${id}/modulos`, { modulos }),
  entrar: (id) => post(`/plataforma/tiendas/${id}/entrar`),
  conciliar: (id) => get(`/plataforma/tiendas/${id}/conciliar`),
  exportar: (id) => apiClient.get(`/plataforma/tiendas/${id}/export`, { responseType: 'blob' }),
  usuariosTienda: (id) => get(`/plataforma/tiendas/${id}/usuarios`),
  crearUsuario: (idTienda, data) => post(`/plataforma/tiendas/${idTienda}/usuarios`, data),
  usuarios: (params) => get('/plataforma/usuarios', params),
  editarUsuario: (id, data) => put(`/plataforma/usuarios/${id}`, data),
  desactivarUsuario: (id) => del(`/plataforma/usuarios/${id}`),
  resetPassword: (id, newPassword) => post(`/plataforma/usuarios/${id}/reset-password`, newPassword ? { newPassword } : {}),
  auditLog: (params) => get('/plataforma/audit-log', params),
};
