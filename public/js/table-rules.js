// Service à table (Loto) : pavé numérique de la caisse, envoi en cuisine, messages.
// Le client paie à la caisse, donne son numéro de table, un bénévole lui apporte son plat.
// Les boissons sont toujours remises au comptoir. Fonctions pures : tests/js/table-rules.test.js.

export const TABLE_MIN = 1;
export const TABLE_MAX = 999;
const MAX_DIGITS = String(TABLE_MAX).length;

/** Touche du pavé : trois chiffres au plus, pas de zéro en tête. */
export function pressDigit(entry, digit) {
  const current = String(entry ?? "");
  const key = String(digit);
  if (!/^[0-9]$/.test(key) || current.length >= MAX_DIGITS || (current === "" && key === "0")) {
    return current;
  }
  return current + key;
}

/** Touche « effacer » : retire le dernier chiffre. */
export function eraseDigit(entry) {
  return String(entry ?? "").slice(0, -1);
}

/** Saisie du pavé → numéro de table (1 à 999), ou null si la saisie n'en est pas un. */
export function parseTable(entry) {
  const text = String(entry ?? "");
  if (!/^[1-9][0-9]{0,2}$/.test(text)) {
    return null;
  }
  const table = Number(text);
  return table >= TABLE_MIN && table <= TABLE_MAX ? table : null;
}

/**
 * Numéro de table envoyé au serveur : seulement si la commande passe en cuisine.
 * Boissons seules : remises au comptoir, ce n'est pas une commande à table.
 */
export function tableForOrder(table, hasKitchenItem) {
  return hasKitchenItem && Number.isInteger(table) && table >= TABLE_MIN && table <= TABLE_MAX ? table : null;
}

/**
 * Message de la caisse pour une table choisie (panier et écran du numéro), ou null sans table.
 * kitchen : au moins un article cuisine ; counter : au moins un article remis au comptoir.
 */
export function tableMessage({ table, kitchen, counter }) {
  if (!Number.isInteger(table)) {
    return null;
  }
  if (!kitchen) {
    return "Tout est remis au comptoir";
  }
  return counter
    ? `Plats servis à la table ${table} · boissons à remettre maintenant`
    : `Servi à la table ${table}`;
}

/** Ticket cuisine : les boissons d'une commande à table sont déjà remises, pas sur le plateau. */
export function counterTitle(tableNumber) {
  return tableNumber === null || tableNumber === undefined ? "Au comptoir" : "Déjà remis au comptoir";
}

/** Suivi de la caisse : recherche par numéro et filtre « À table ». */
export function matchesTracking(order, { search = "", tableOnly = false } = {}) {
  const wanted = String(search).toUpperCase().replace(/\s/g, "");
  if (tableOnly && (order.tableNumber === null || order.tableNumber === undefined)) {
    return false;
  }
  return !wanted || order.displayNumber.includes(wanted) || (order.tableNumber != null && String(order.tableNumber) === wanted);
}

/**
 * Remise d'une commande prête : au client au comptoir, ou au livreur pour une commande à table
 * (touchée quand le plateau part ; l'état reste « served », seul le libellé change).
 */
export function handOverLabel(tableNumber) {
  return tableNumber === null || tableNumber === undefined ? "Remise au client" : "Remise au livreur";
}

/** Nombre de couleurs de zone (--zone-1 à --zone-3) : au-delà, elles se répètent, le libellé fait foi. */
export const ZONE_COLORS = 3;

/** Zone du livreur, calculée par le serveur : libellé écrit et classe de couleur, ou null sans zone. */
export function zoneBadge(zone) {
  if (!Number.isInteger(zone) || zone < 1) {
    return null;
  }
  return { label: `Zone ${zone}`, className: `zone-${((zone - 1) % ZONE_COLORS) + 1}` };
}
