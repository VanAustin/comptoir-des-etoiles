// Rythme des interrogations des tablettes : fonctions pures, sans navigateur ni réseau,
// testées par node --test (tests/js/poll-rules.test.js). poller.js les applique.

/**
 * Intervalle normal, et accéléré pendant FAST_WINDOW_MS après un changement.
 * Cuisine et Arrivées : jamais plus de 3 s entre deux débuts d'interrogation.
 */
export const INTERVALS = {
  kitchen: { normal: 3000, fast: 2000 },
  board: { normal: 3000, fast: 2000 },
  tracking: { normal: 10000 },
  menu: { normal: 30000 },
};
export const FAST_WINDOW_MS = 30000;
/** Après une erreur ou sans réseau : 3 s, 6 s, 12 s, puis 30 s au plus. */
export const BACKOFF_MS = [3000, 6000, 12000, 30000];
/** Une requête sans réponse est abandonnée au bout de ce délai. */
export const REQUEST_TIMEOUT_MS = 8000;
/** Décalage aléatoire, vers le bas seulement : 3 s devient 2,7 à 3 s. */
export const JITTER = 0.1;

/** Délai avant un nouvel essai, après `failures` échecs de suite (1 ou plus). */
export function backoffDelay(failures) {
  const index = Math.min(Math.max(1, failures), BACKOFF_MS.length) - 1;
  return BACKOFF_MS[index];
}

/**
 * Délai entre le début de la requête précédente et le début de la suivante.
 *
 * @param {{kind: keyof INTERVALS, failures?: number, sinceChangeMs?: number, random?: number}} state
 *   sinceChangeMs : temps écoulé depuis le dernier changement vu ; random : entre 0 et 1
 */
export function nextPollDelay({ kind, failures = 0, sinceChangeMs = Infinity, random = Math.random() }) {
  if (failures > 0) {
    return backoffDelay(failures);
  }
  const { normal, fast } = INTERVALS[kind];
  const base = fast !== undefined && sinceChangeMs < FAST_WINDOW_MS ? fast : normal;
  // Vers le bas seulement : les tablettes ne s'interrogent pas toutes à la même seconde,
  // et l'intervalle annoncé n'est jamais dépassé
  const spread = Math.min(1, Math.max(0, random));

  return Math.round(base * (1 - JITTER * spread));
}

/** Ce qu'il reste à attendre, le délai étant compté depuis le début de la requête précédente. */
export function remainingDelay(delay, elapsedSinceStartMs) {
  return Math.max(0, delay - Math.max(0, elapsedSinceStartMs));
}

/**
 * Attente avant la prochaine interrogation, une fois la précédente terminée.
 * En temps normal, comptée depuis le début de la précédente (le temps de réponse est inclus) ;
 * après un échec, depuis sa fin (un réseau qui ne répond pas n'est pas relancé aussitôt).
 */
export function scheduleDelay({ kind, failures = 0, sinceChangeMs = Infinity, elapsedSinceStartMs = 0, random = Math.random() }) {
  const delay = nextPollDelay({ kind, failures, sinceChangeMs, random });

  return failures > 0 ? delay : remainingDelay(delay, elapsedSinceStartMs);
}

/**
 * Que faire quand une interrogation est demandée (minuteur, retour de l'écran, réseau revenu, action) ?
 * - "stopped" : arrêtée (tablette refusée, écran fermé) ;
 * - "hidden" : écran masqué, rien jusqu'à son retour ;
 * - "busy" : une requête est en cours, on relira juste après (jamais deux à la fois) ;
 * - "blocked" : pas maintenant (menu pendant un paiement), relu dès que possible ;
 * - "go" : on interroge.
 */
export function pollDecision({ running, hidden, busy, blocked = false }) {
  if (!running) {
    return "stopped";
  }
  if (hidden) {
    return "hidden";
  }
  if (busy) {
    return "busy";
  }
  return blocked ? "blocked" : "go";
}

/** Tablette refusée par le serveur (retirée, appairage expiré) : on arrête d'interroger. */
export function isFatal(status) {
  return status === 401 || status === 403;
}

/**
 * Écart entre l'heure du serveur (en-tête Date, à la seconde près) et celle de la tablette,
 * en millisecondes, ou null si le serveur ne l'a pas donnée.
 */
export function clockOffset(serverDateHeader, localNowMs) {
  const server = Date.parse(serverDateHeader ?? "");

  return Number.isNaN(server) ? null : server - localNowMs;
}
