import { getPending, markDone, getCount } from './offlineQueue';
import { ventasApi } from '../api/endpoints';

let setPendingCount = null;

export function initSyncManager(setCount) {
  setPendingCount = setCount;
  // Actualizar contador al iniciar
  getCount().then((n) => setCount?.(n));
  // Sincronizar cuando vuelve la red
  window.addEventListener('online', syncPendingOps);
}

export async function syncPendingOps() {
  const pending = await getPending();
  if (pending.length === 0) return;

  for (const op of pending) {
    try {
      if (op.type === 'venta') await ventasApi.crear(op.payload);
      await markDone(op.id);
    } catch (err) {
      console.error('[SyncManager] Error sincronizando operación:', op.type, err.message);
      // Mantener en cola — se reintentará en el siguiente ciclo
    }
  }

  const remaining = await getCount();
  setPendingCount?.(remaining);
}
