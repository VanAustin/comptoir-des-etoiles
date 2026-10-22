// Montants en centimes (entiers), comme sur le serveur : jamais de calcul en virgule flottante.

const NBSP = " ";

/** 250 → « 2,50 € » ; -100 → « -1,00 € » */
export function formatEuros(cents) {
  const sign = cents < 0 ? "-" : "";
  const abs = Math.abs(cents);
  const euros = Math.floor(abs / 100);
  const rest = String(abs % 100).padStart(2, "0");
  return `${sign}${euros},${rest}${NBSP}€`;
}

/**
 * « 2,50 », « 2.5 », « 20 € » → centimes ; null si la saisie n'est pas un montant.
 * Mêmes règles que App\Service\Money::parseEuros.
 */
export function parseEuros(input) {
  const value = String(input).replace(/[\s €]/g, "").replace(",", ".");
  const match = /^(\d{1,4})(?:\.(\d{1,2}))?$/.exec(value);
  if (!match) {
    return null;
  }
  return Number(match[1]) * 100 + Number((match[2] ?? "0").padEnd(2, "0"));
}
