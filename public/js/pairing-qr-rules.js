// Règles de la page « Appairer » de l'administration (public/js/pairing-qr.js) :
// contenu du QR code et temps restant. Fonctions pures, testées dans tests/js/pairing-qr-rules.test.js.

import qrcode from "./vendor/qrcode-generator.js";

/** Marge blanche autour du QR code, en modules : 4 selon la norme, sinon certains appareils photo ne le lisent pas. */
export const QUIET_ZONE = 4;

/**
 * Grille du QR code d'une adresse : tableau de lignes, true = module foncé.
 * Taille choisie automatiquement, correction d'erreur M (15 %), mode octets.
 */
export function qrMatrix(text) {
  const qr = qrcode(0, "M");
  qr.addData(String(text), "Byte");
  qr.make();
  const count = qr.getModuleCount();
  const rows = [];
  for (let row = 0; row < count; row++) {
    const cells = [];
    for (let col = 0; col < count; col++) {
      cells.push(qr.isDark(row, col));
    }
    rows.push(cells);
  }
  return rows;
}

/**
 * Taille d'un module en pixels de l'écran, entière (des modules de tailles inégales gênent la lecture),
 * pour tenir dans `available` pixels avec la marge.
 */
export function moduleSize(moduleCount, available) {
  return Math.max(1, Math.floor(available / (moduleCount + 2 * QUIET_ZONE)));
}

/**
 * Secondes restantes, à partir de celles calculées par le serveur à l'affichage de la page
 * (l'horloge de l'ordinateur peut être fausse) et du temps écoulé depuis (horloge monotone).
 */
export function remainingSeconds(serverSeconds, elapsedMs) {
  const initial = Number.isFinite(serverSeconds) ? serverSeconds : 0;
  const elapsed = Number.isFinite(elapsedMs) && elapsedMs > 0 ? elapsedMs : 0;
  return Math.max(0, Math.ceil(initial - elapsed / 1000));
}

/** « 9 min 05 s », « 45 s », « 0 s ». */
export function formatRemaining(seconds) {
  const total = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0));
  const minutes = Math.floor(total / 60);
  const rest = total % 60;
  return minutes > 0 ? `${minutes} min ${String(rest).padStart(2, "0")} s` : `${rest} s`;
}
