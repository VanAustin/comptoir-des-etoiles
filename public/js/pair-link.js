// Décisions de la page /appairer, sans accès à la page ni au réseau (testées par node --test,
// tests/js/pair-link.test.js).
//
// Appairage par lien : l'adresse /appairer#code=K7QM-3XRP porte un code à usage unique après
// le « # ». Le navigateur n'envoie jamais cette partie au serveur. La page la retire de
// l'adresse dès qu'elle l'a lue. Jamais de jeton dans une adresse : seul le code y passe.

/** Mêmes caractères que les codes créés par le serveur (DevicePairing::CODE_ALPHABET). */
export const CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789";
export const CODE_LENGTH = 8;

/** « k7qm-3xrp » → « K7QM3XRP » ; null si ce n'est pas un code possible. */
export function normalizeCode(raw) {
  const code = String(raw ?? "").toUpperCase().replace(/[\s-]/g, "");
  if (code.length !== CODE_LENGTH) {
    return null;
  }
  for (const char of code) {
    if (!CODE_ALPHABET.includes(char)) {
      return null;
    }
  }
  return code;
}

/** « K7QM3XRP » → « K7QM-3XRP », comme dans l'administration. */
export function formatCode(code) {
  return `${code.slice(0, 4)}-${code.slice(4)}`;
}

/**
 * Lit le code dans la partie « # » de l'adresse (location.hash).
 * @returns {{present: false} | {present: true, code: string|null}} code null : lien abîmé
 */
export function readLinkCode(hash) {
  const params = new URLSearchParams(String(hash ?? "").replace(/^#/, ""));
  if (!params.has("code")) {
    return { present: false };
  }
  return { present: true, code: normalizeCode(params.get("code")) };
}

/**
 * Que faire d'un lien d'appairage valide ?
 * - tablette pas encore appairée : appairage tout de suite ;
 * - commandes en attente d'envoi : refus, comme pour le désappairage (elles ne pourraient plus partir) ;
 * - déjà appairée : demander confirmation avant de remplacer l'appairage actuel.
 *
 * @param {{pairing: {device?: {name?: string}}|null, waitingCount: number}} state
 * @returns {{action: "pair"} | {action: "blocked", count: number} | {action: "confirm", deviceName: string}}
 */
export function decideLinkPairing({ pairing, waitingCount }) {
  if (!pairing) {
    return { action: "pair" };
  }
  if (waitingCount > 0) {
    return { action: "blocked", count: waitingCount };
  }
  return { action: "confirm", deviceName: pairing.device?.name ?? "" };
}

/**
 * Après un échec d'appairage par lien, faut-il garder le code dans le champ pour réessayer ?
 * Non si le serveur l'a refusé (expiré, déjà utilisé, inconnu : statut 4xx) : le champ reste vide
 * pour une saisie à la main. Oui si le serveur n'a pas répondu (réseau, erreur 5xx) : le code est
 * peut-être encore bon, et il n'est plus dans l'adresse.
 *
 * @param {number|null} status statut HTTP, null sans réponse du serveur
 */
export function keepCodeAfterFailure(status) {
  return status === null || status === 0 || status >= 500;
}

/** Message affiché quand des commandes attendent encore d'être envoyées. */
export function waitingMessage(count, action) {
  const plural = count > 1;
  const orders = `${count} commande${plural ? "s" : ""} encaissée${plural ? "s" : ""} sur cette tablette ${plural ? "ne sont" : "n'est"} pas encore envoyée${plural ? "s" : ""}.`;
  return `${orders} Ouvrez la caisse : attendez leur envoi, ou traitez celles « À vérifier », avant de ${action}.`;
}

/** Message de la confirmation avant de remplacer l'appairage. */
export function replaceQuestion(deviceName) {
  return deviceName
    ? `Cette tablette est déjà appairée comme « ${deviceName} ». La remplacer ?`
    : "Cette tablette est déjà appairée. La remplacer ?";
}
