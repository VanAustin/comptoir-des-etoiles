// File d'attente de la caisse : décisions en fonctions pures, sans navigateur ni réseau,
// testées par node --test (tests/js/outbox-rules.test.js). till-outbox.js les applique.
// Même reprise que les interrogations (poll-rules.js) : 3 s, 6 s, 12 s, puis 30 s au plus.

import { backoffDelay, REQUEST_TIMEOUT_MS } from "./poll-rules.js";

/** Une commande sans réponse du serveur au bout de ce délai reste dans la file. */
export const SEND_TIMEOUT_MS = REQUEST_TIMEOUT_MS;
/** Après un échec d'envoi, les nouvelles commandes vont directement dans la file pendant ce délai. */
export const OFFLINE_GRACE_MS = 30000;

/** Délai avant de renvoyer la file, après `failures` échecs de suite (0 : commande tout juste gardée). */
export function retryDelay(failures) {
  return backoffDelay(Math.max(1, failures));
}

/**
 * Que faire d'une commande après un échec d'envoi ?
 * - "retry" : pas de réponse utile (réseau, serveur en panne, trop de requêtes) ; rien n'est perdu ;
 * - "block" : refusée par le serveur, mise « À vérifier », on passe à la suivante ;
 * - "block-and-stop" : tablette refusée (retirée, expirée), plus rien ne peut partir.
 *
 * @param {number|undefined} status code HTTP, 0 ou absent sans réponse du serveur
 */
export function sendFailure(status) {
  if (!status || status >= 500 || status === 429) {
    return "retry";
  }
  return status === 401 || status === 403 ? "block-and-stop" : "block";
}

/**
 * Message affiché sur une commande refusée par le serveur.
 *
 * @param {{displayNumber: string}} entry
 * @param {{status: number, message: string, data?: object}} error
 */
export function refusalMessage(entry, error) {
  if (error.status === 409 && error.data?.nextNumber) {
    return `Le numéro ${entry.displayNumber} existe déjà sur le serveur pour une autre commande. Ressaisissez celle-ci et donnez son nouveau numéro au client.`;
  }
  return error.message;
}

/** La commande n'est envoyée qu'avec l'appairage qui l'a encaissée : la lettre du numéro ne doit pas changer. */
export function sameOwner(a, b) {
  return Boolean(a && b) && a.tillLetter === b.tillLetter && a.event === b.event;
}

export function otherOwnerMessage(entry) {
  return `Encaissée par la caisse ${entry.owner?.tillLetter ?? "?"} avant un nouvel appairage : à ressaisir.`;
}

/** Le dernier envoi a échoué il y a peu : inutile de faire attendre le caissier. */
export function isOffline({ failures, lastFailureAt, now }) {
  return failures > 0 && now - lastFailureAt < OFFLINE_GRACE_MS;
}
