// Appairage de la tablette (jeton et description), rangé dans IndexedDB.
// Le jeton n'est jamais mis dans une URL, ni dans le stockage local du navigateur.

const DB_NAME = "comptoir-des-etoiles";
const DB_VERSION = 1;
const STORE = "device";
const KEY = "current";

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
  return withStore("readwrite", (store) => store.delete(KEY));
}
