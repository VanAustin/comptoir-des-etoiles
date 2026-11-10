// Appels à l'API des tablettes : jeton dans l'en-tête Authorization, réponses JSON.

import { getPairing } from "./device-store.js";
import { REQUEST_TIMEOUT_MS } from "./poll-rules.js";

const BASE_PATH = document.documentElement.dataset.basePath ?? "";

export class ApiError extends Error {
  /**
   * @param {number} status 0 si le serveur n'a pas répondu
   * @param {object} data corps de la réponse (par exemple nextNumber sur un conflit de numéro)
   */
  constructor(message, status, data = {}) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

async function send(path, { method = "GET", body, auth = true, headers: extra = {}, cache = "default", timeout }) {
  const headers = { Accept: "application/json", ...extra };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }
  if (auth) {
    const pairing = await getPairing();
    if (pairing?.token) {
      headers.Authorization = `Bearer ${pairing.token}`;
    }
  }

  try {
    return await fetch(BASE_PATH + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      cache,
      // Réseau 4G instable : une requête sans réponse est abandonnée au bout du délai
      signal: timeout ? AbortSignal.timeout(timeout) : undefined,
      // Les tablettes n'utilisent jamais le cookie de session de l'administration
      credentials: "omit",
    });
  } catch {
    throw new ApiError("Le serveur ne répond pas. Vérifiez la connexion de la tablette, puis réessayez.", 0);
  }
}

async function read(response) {
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(data.error ?? "Une erreur est survenue. Réessayez dans un instant.", response.status, data);
  }

  return data;
}

/**
 * @param {string} path par exemple "/api/me"
 * @param {{method?: string, body?: object, auth?: boolean, timeout?: number}} options timeout en millisecondes
 */
export async function apiFetch(path, options = {}) {
  return read(await send(path, options));
}

/**
 * Lecture d'une route interrogée en boucle (cuisine, Arrivées, suivi et menu de la caisse).
 * La tablette renvoie l'empreinte reçue : tant que rien n'a changé, le serveur répond 304
 * sans contenu, et data vaut null. Abandonnée sans réponse au bout de 8 s.
 *
 * @param {string} path
 * @param {string|null} etag empreinte de la réponse précédente
 * @returns {Promise<{data: object|null, etag: string|null, date: string|null}>}
 *   date : en-tête Date, l'heure du serveur (aussi sur une 304)
 */
export async function apiPoll(path, etag) {
  // no-store : le navigateur ne répond pas lui-même 200 depuis son cache, le 304 arrive jusqu'ici
  const response = await send(path, { headers: etag ? { "If-None-Match": etag } : {}, cache: "no-store", timeout: REQUEST_TIMEOUT_MS });
  const date = response.headers.get("Date");
  if (response.status === 304) {
    return { data: null, etag, date };
  }
  return { data: await read(response), etag: response.headers.get("ETag"), date };
}
