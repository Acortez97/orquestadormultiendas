import { openDB } from 'idb';
import session from '../session';

// Cola de operaciones offline. Cada operacion guarda su dueño (correo de acceso):
// solo se sincroniza con la sesion de ESE usuario, para que en una PC compartida
// nunca se envien operaciones de una tienda con la sesion de otra.
const DB_NAME  = 'ot_offline';
const DB_VER   = 1;
const STORE    = 'pending_ops';

async function getDB() {
  return openDB(DB_NAME, DB_VER, {
    upgrade(db) {
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      }
    },
  });
}

/**
 * Encola una operación offline del usuario actual.
 * @param {{ type: 'venta', payload: object, id_referencia: string }} op
 */
export async function enqueue(op) {
  const owner = session.owner();
  if (!owner) throw new Error('Sin sesion: no se puede encolar la operacion');
  const db = await getDB();
  await db.add(STORE, { ...op, owner, status: 'pending', createdAt: Date.now() });
}

/** Operaciones pendientes del usuario actual */
export async function getPending() {
  const owner = session.owner();
  if (!owner) return [];
  const db = await getDB();
  return (await db.getAll(STORE)).filter((op) => op.owner === owner);
}

export async function markDone(id) {
  const db = await getDB();
  await db.delete(STORE, id);
}

export async function getCount() {
  return (await getPending()).length;
}
