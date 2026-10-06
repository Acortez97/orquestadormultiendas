import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

// Cambio de contraseña propia. Obligatorio en el primer acceso (contraseña temporal).
export default function CambiarPasswordPage() {
  const { user, cambiarPassword, logout } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ actual: '', nueva: '', confirmar: '' });
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);
  const obligatorio = !!user?.debe_cambiar_password;

  async function enviar(e) {
    e.preventDefault();
    setError('');
    if (form.nueva.length < 8) return setError('La nueva contraseña debe tener al menos 8 caracteres.');
    if (form.nueva !== form.confirmar) return setError('La confirmación no coincide.');
    setGuardando(true);
    try {
      const u = await cambiarPassword(form.actual, form.nueva);
      navigate(u.es_superadmin ? '/admin' : '/dashboard', { replace: true });
    } catch (err) {
      setError(err.message || 'No se pudo cambiar la contraseña');
    } finally {
      setGuardando(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <form onSubmit={enviar} className="card w-full max-w-md space-y-4 p-8">
        <div>
          <h1 className="text-xl font-bold text-slate-900">Cambiar contraseña</h1>
          <p className="mt-1 text-sm text-slate-500">
            {obligatorio ? 'Por seguridad, define tu propia contraseña antes de continuar.' : user?.login}
          </p>
        </div>
        {error && <div className="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{error}</div>}
        <div>
          <label className="mb-1 block text-sm font-medium text-slate-700">Contraseña actual</label>
          <input type="password" className="input-base" value={form.actual} autoComplete="current-password" required
            onChange={(e) => setForm({ ...form, actual: e.target.value })} />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium text-slate-700">Nueva contraseña</label>
          <input type="password" className="input-base" value={form.nueva} autoComplete="new-password" required minLength={8}
            onChange={(e) => setForm({ ...form, nueva: e.target.value })} />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium text-slate-700">Confirmar nueva contraseña</label>
          <input type="password" className="input-base" value={form.confirmar} autoComplete="new-password" required
            onChange={(e) => setForm({ ...form, confirmar: e.target.value })} />
        </div>
        <button type="submit" className="btn-primary w-full py-2.5" disabled={guardando}>
          {guardando ? 'Guardando...' : 'Guardar contraseña'}
        </button>
        <button type="button" className="btn-ghost w-full" onClick={() => {
          if (obligatorio) { logout(); navigate('/login'); } else navigate(-1);
        }}>
          {obligatorio ? 'Cerrar sesión' : 'Cancelar'}
        </button>
      </form>
    </div>
  );
}
