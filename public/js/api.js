// Appels à l'API des tablettes : jeton dans l'en-tête Authorization, réponses JSON.

import { getPairing } from "./device-store.js";

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

/**
 * @param {string} path par exemple "/api/me"
 * @param {{method?: string, body?: object, auth?: boolean}} options
 */
export async function apiFetch(path, { method = "GET", body, auth = true } = {}) {
  const headers = { Accept: "application/json" };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }
  if (auth) {
    const pairing = await getPairing();
    if (pairing?.token) {
      headers.Authorization = `Bearer ${pairing.token}`;
    }
  }

  let response;
  try {
    response = await fetch(BASE_PATH + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      // Les tablettes n'utilisent jamais le cookie de session de l'administration
      credentials: "omit",
    });
  } catch {
    throw new ApiError("Le serveur ne répond pas. Vérifiez la connexion de la tablette, puis réessayez.", 0);
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(data.error ?? "Une erreur est survenue. Réessayez dans un instant.", response.status, data);
  }

  return data;
}
