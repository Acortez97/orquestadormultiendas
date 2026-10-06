import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

function LoginPage() {
  const { login } = useAuth();
  const navigate  = useNavigate();
  const [form, setForm]       = useState({ email: '', password: '' });
  const [params] = useSearchParams();
  const [error, setError]     = useState(params.get('suspendida') ? 'Cuenta suspendida, contacta al administrador.' : '');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const u = await login(form.email, form.password);
      if (u.debe_cambiar_password) navigate('/cambiar-password');
      else navigate(u.es_superadmin ? '/admin' : '/dashboard');
    } catch (err) {
      setError(err.message || 'Credenciales inválidas');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="relative flex min-h-screen items-center overflow-hidden bg-gradient-to-br from-primary-950 via-primary-900 to-primary-700">
      {/* Acentos decorativos sobre el mismo fondo de marca */}
      <div className="pointer-events-none absolute -top-32 -left-24 h-96 w-96 rounded-full bg-primary-500/25 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-24 right-1/4 h-[28rem] w-[28rem] rounded-full bg-primary-400/10 blur-3xl" />

      <div className="relative z-10 mx-auto flex w-full max-w-6xl items-center justify-between gap-12 px-6 py-10">
        {/* Marca (escritorio) — sobre el fondo, sin panel */}
        <div className="hidden max-w-md text-white lg:block">
          <img src="/logo-light.svg" alt="MultiTienda" className="mb-10 h-12 object-contain" />
          <h1 className="text-4xl font-bold leading-tight">
            Gestiona tu tienda<br />con claridad.
          </h1>
          <p className="mt-5 leading-relaxed text-primary-200">
            Ventas, inventario, compras y finanzas en un solo lugar.
            Toma mejores decisiones con información en tiempo real.
          </p>
          <div className="mt-9 flex items-center gap-2">
            <span className="h-1.5 w-10 rounded-full bg-white/80" />
            <span className="h-1.5 w-2.5 rounded-full bg-accent-500" />
            <span className="h-1.5 w-2.5 rounded-full bg-white/25" />
          </div>
        </div>

        {/* Tarjeta del formulario (derecha, flotante) */}
        <div className="w-full max-w-md lg:ml-auto">
          <div className="rounded-2xl bg-white p-8 shadow-2xl shadow-primary-950/40 ring-1 ring-white/10">
            <div className="mb-7 flex justify-center lg:hidden">
              <img src="/logo.svg" alt="MultiTienda" className="h-12 object-contain" />
            </div>

            <div className="mb-7">
              <h2 className="text-2xl font-bold text-slate-900">Iniciar sesión</h2>
              <p className="mt-1 text-sm text-slate-500">Ingresa tus credenciales para continuar.</p>
            </div>

            {error && (
              <div className="mb-4 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">
                {error}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">
                  Correo de acceso
                </label>
                <input
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="input-base"
                  placeholder="usuario@tutienda.levotek.com"
                  required
                  autoComplete="email"
                />
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">
                  Contraseña
                </label>
                <input
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="input-base"
                  placeholder="••••••••"
                  required
                  autoComplete="current-password"
                />
              </div>

              <button
                type="submit"
                disabled={loading}
                className="btn-primary mt-2 w-full py-2.5"
              >
                {loading ? (
                  <><div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />Entrando...</>
                ) : 'Entrar'}
              </button>
            </form>
          </div>

          <p className="mt-6 text-center text-xs text-primary-200/70">
            © {new Date().getFullYear()} MultiTienda
          </p>
        </div>
      </div>
    </div>
  );
}

export default LoginPage;
