// Espèces à la caisse : addition des billets et pièces reçus, décomposition de la monnaie.
// Montants en centimes. Mêmes résultats que App\Service\Money::changeBreakdown, sur les
// cas communs de tests/fixtures/cash-cases.json.

import { formatEuros } from "./money.js";

/** Billets et pièces rendus par la caisse, du plus grand au plus petit (en centimes). */
export const DENOMINATIONS = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10];
/** Plafond accepté par le serveur pour le montant reçu (1 000 €). */
export const MAX_RECEIVED_CENTS = 100000;

/** 2000 → « 20 € » ; 50 → « 50 c » */
export function formatDenomination(cents) {
  return cents >= 100 ? `${cents / 100} €` : `${cents} c`;
}

/**
 * Monnaie à rendre, les plus gros billets et pièces d'abord (algorithme glouton).
 * @returns {{parts: {cents: number, count: number}[], rest: number}}
 */
export function changeBreakdown(cents) {
  const parts = [];
  let rest = Math.max(0, cents);
  for (const value of DENOMINATIONS) {
    const count = Math.floor(rest / value);
    if (count > 0) {
      parts.push({ cents: value, count });
      rest -= count * value;
    }
  }
  return { parts, rest };
}

/** « 1 × 10 € · 1 × 2 € · 1 × 50 c » (+ petite monnaie si un reste subsiste) */
export function describeBreakdown({ parts, rest }) {
  const text = parts.map((p) => `${p.count} × ${formatDenomination(p.cents)}`);
  if (rest > 0) {
    text.push(`${formatEuros(rest)} en petite monnaie`);
  }
  return text.join(" · ");
}

/**
 * Montant reçu du client : billets et pièces additionnés, ou « compte juste ».
 * Un billet touché après « compte juste » repart de zéro.
 */
export class CashReceived {
  constructor() {
    this.clear();
  }

  clear() {
    this.presses = [];
    this.exact = false;
  }

  /** @returns {boolean} false si le plafond serait dépassé */
  add(cents) {
    if (this.exact) {
      this.clear();
    }
    if (this.sum() + cents > MAX_RECEIVED_CENTS) {
      return false;
    }
    this.presses.push(cents);
    return true;
  }

  setExact() {
    this.presses = [];
    this.exact = true;
  }

  isEmpty() {
    return !this.exact && this.presses.length === 0;
  }

  sum() {
    return this.presses.reduce((total, cents) => total + cents, 0);
  }

  /** Montant reçu, ou null si rien n'est encore indiqué. */
  amount(total) {
    if (this.exact) {
      return total;
    }
    return this.presses.length ? this.sum() : null;
  }

  /** « 2 × 20 € + 5 € = 45,00 € », du plus gros au plus petit. */
  detail(total) {
    if (this.exact) {
      return `compte juste = ${formatEuros(total)}`;
    }
    const counts = new Map();
    for (const cents of [...this.presses].sort((a, b) => b - a)) {
      counts.set(cents, (counts.get(cents) ?? 0) + 1);
    }
    const parts = [...counts].map(([cents, count]) => (count > 1 ? `${count} × ` : "") + formatDenomination(cents));
    return `${parts.join(" + ")} = ${formatEuros(this.sum())}`;
  }
}
