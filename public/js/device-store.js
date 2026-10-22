// Appairage de la tablette (jeton et description) et numérotation de la caisse, rangés dans IndexedDB.
// Le jeton n'est jamais mis dans une URL, ni dans le stockage local du navigateur.

const DB_NAME = "comptoir-des-etoiles";
const DB_VERSION = 1;
const STORE = "device";
const KEY = "current";
const NUMBERING_KEY = "numbering";

function openDatabase() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function withStore(mode, action) {
  const db = await openDatabase();
  try {
    return await new Promise((resolve, reject) => {
      const transaction = db.transaction(STORE, mode);
      const request = action(transaction.objectStore(STORE));
      transaction.oncomplete = () => resolve(request.result);
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
  } finally {
    db.close();
  }
}

/** @returns {Promise<{token: string, expiresAt: string, device: object} | null>} */
export async function getPairing() {
  return (await withStore("readonly", (store) => store.get(KEY))) ?? null;
}

export function savePairing(pairing) {
  return withStore("readwrite", (store) => store.put(pairing, KEY));
}

export function clearPairing() {
  return withStore("readwrite", (store) => {
    store.delete(NUMBERING_KEY);
    return store.delete(KEY);
  });
}

/**
 * Prochain numéro de commande de la caisse. Il n'est valable que pour la même
 * lettre et le même événement : la numérotation repart à 1 à chaque événement.
 *
 * @returns {Promise<{scope: string, next: number} | null>}
 */
export async function getNumbering() {
  return (await withStore("readonly", (store) => store.get(NUMBERING_KEY))) ?? null;
}

export function saveNumbering(numbering) {
  return withStore("readwrite", (store) => store.put(numbering, NUMBERING_KEY));
}
