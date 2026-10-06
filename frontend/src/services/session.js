// ============================================================
// Sesion del navegador (un solo lugar para token/usuario).
// - El JWT no trae ids legibles; los datos del usuario vienen de /auth/login y /auth/me.
// - Modo soporte: el superadmin guarda su sesion de plataforma aparte y opera con un
//   token temporal de la tienda; al salir de soporte se restaura.
// - Al cerrar sesion se limpia todo (tambien claves heredadas) para no mezclar sesiones
//   en una PC compartida.
// ============================================================
const TOKEN = 'ot_token';
const USER = 'ot_user';
const SOPORTE = 'ot_soporte';            // { token, user } de la sesion de plataforma mientras hay soporte
const HEREDADAS = ['levotek_token', 'levotek_user'];

function leerJSON(key) {
  try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}

export const session = {
  token: () => localStorage.getItem(TOKEN),
  user: () => leerJSON(USER),
  /** Correo de acceso del usuario actual (dueño de la cola offline) */
  owner: () => leerJSON(USER)?.login || null,

  guardar(token, user) {
    if (token) localStorage.setItem(TOKEN, token);
    if (user) localStorage.setItem(USER, JSON.stringify(user));
  },

  /** Superadmin entra a una tienda: guarda su sesion y usa la de soporte */
  entrarSoporte(tokenSoporte) {
    localStorage.setItem(SOPORTE, JSON.stringify({ token: this.token(), user: this.user() }));
    localStorage.setItem(TOKEN, tokenSoporte);
    localStorage.removeItem(USER);
  },

  enSoporte: () => !!localStorage.getItem(SOPORTE),

  /** Vuelve a la sesion de plataforma. Devuelve el usuario restaurado (o null). */
  salirSoporte() {
    const prev = leerJSON(SOPORTE);
    localStorage.removeItem(SOPORTE);
    if (!prev?.token) { this.limpiar(); return null; }
    this.guardar(prev.token, prev.user);
    return prev.user;
  },

  limpiar() {
    [TOKEN, USER, SOPORTE, ...HEREDADAS].forEach((k) => localStorage.removeItem(k));
    try { sessionStorage.clear(); } catch { /* sin sessionStorage */ }
  },
};

export default session;
