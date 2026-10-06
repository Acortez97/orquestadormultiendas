import { openDB } from 'idb';

const DB_NAME  = 'levotek_offline';
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
 * Encola una operación offline.
 * @param {{ type: 'venta', payload: object, id_referencia: string }} op
 */
export async function enqueue(op) {
  const db = await getDB();
  await db.add(STORE, { ...op, status: 'pending', createdAt: Date.now() });
}

export async function getPending() {
  const db = await getDB();
  return db.getAll(STORE);
}

export async function markDone(id) {
  const db = await getDB();
  await db.delete(STORE, id);
}

export async function getCount() {
  const db = await getDB();
  return db.count(STORE);
}
