// Appairage de la tablette (jeton et description) et numérotation de la caisse, rangés dans IndexedDB.
// Le jeton n'est jamais mis dans une URL, ni dans le stockage local du navigateur.

const DB_NAME = "comptoir-des-etoiles";
const DB_VERSION = 2;
const STORE = "device";
/** Commandes encaissées sans réseau, en attente d'envoi (clé : UUID de la commande). */
const OUTBOX = "outbox";
const KEY = "current";
const NUMBERING_KEY = "numbering";
const MENU_KEY = "menu";

function openDatabase() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE);
      }
      if (!db.objectStoreNames.contains(OUTBOX)) {
        db.createObjectStore(OUTBOX, { keyPath: "uuid" });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function withStore(mode, action, storeName = STORE) {
  const db = await openDatabase();
  try {
    return await new Promise((resolve, reject) => {
      const transaction = db.transaction(storeName, mode);
      const request = action(transaction.objectStore(storeName));
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

/** Oublie l'appairage. Les commandes en attente d'envoi, elles, ne sont jamais effacées ici. */
export function clearPairing() {
  return withStore("readwrite", (store) => {
    store.delete(NUMBERING_KEY);
    store.delete(MENU_KEY);
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

/** Réglage propre à la tablette (son activé…), gardé d'une ouverture à l'autre. */
export async function getPreference(name, fallback) {
  return (await withStore("readonly", (store) => store.get(`pref:${name}`))) ?? fallback;
}

export function savePreference(name, value) {
  return withStore("readwrite", (store) => store.put(value, `pref:${name}`));
}

/** Dernier menu reçu, pour ouvrir la caisse sans réseau. */
export async function getMenuCache() {
  return (await withStore("readonly", (store) => store.get(MENU_KEY))) ?? null;
}

export function saveMenuCache(menu) {
  return withStore("readwrite", (store) => store.put(menu, MENU_KEY));
}

/** @returns {Promise<object[]>} commandes en attente, de la plus ancienne à la plus récente */
export async function getOutbox() {
  const entries = (await withStore("readonly", (store) => store.getAll(), OUTBOX)) ?? [];
  return entries.sort((a, b) => a.savedAt.localeCompare(b.savedAt));
}

export function saveOutboxEntry(entry) {
  return withStore("readwrite", (store) => store.put(entry), OUTBOX);
}

export function deleteOutboxEntry(uuid) {
  return withStore("readwrite", (store) => store.delete(uuid), OUTBOX);
}
