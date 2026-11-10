// Rythme des interrogations des tablettes (public/js/poll-rules.js).
// Lancement : docker compose run --rm jstest

import { test } from "node:test";
import assert from "node:assert/strict";

import {
  backoffDelay,
  clockOffset,
  FAST_WINDOW_MS,
  isFatal,
  nextPollDelay,
  pollDecision,
  remainingDelay,
  REQUEST_TIMEOUT_MS,
  scheduleDelay,
} from "../../public/js/poll-rules.js";

test("cuisine et Arrivées : toutes les 3 s, décalage aléatoire vers le bas seulement (2,7 à 3 s)", () => {
  for (const kind of ["kitchen", "board"]) {
    assert.equal(nextPollDelay({ kind, random: 0 }), 3000);
    assert.equal(nextPollDelay({ kind, random: 1 }), 2700);
    assert.equal(nextPollDelay({ kind, random: 0.5 }), 2850);
  }
});

test("pendant les 30 s qui suivent un changement : toutes les 2 s, puis retour à 3 s", () => {
  assert.equal(nextPollDelay({ kind: "kitchen", sinceChangeMs: 0, random: 0 }), 2000);
  assert.equal(nextPollDelay({ kind: "kitchen", sinceChangeMs: FAST_WINDOW_MS - 1, random: 1 }), 1800);
  assert.equal(nextPollDelay({ kind: "kitchen", sinceChangeMs: FAST_WINDOW_MS, random: 0 }), 3000);
  assert.equal(nextPollDelay({ kind: "board", sinceChangeMs: 5000, random: 0 }), 2000);
});

test("suivi de la caisse toutes les 10 s, menu toutes les 30 s, sans accélération", () => {
  assert.equal(nextPollDelay({ kind: "tracking", random: 0 }), 10000);
  assert.equal(nextPollDelay({ kind: "tracking", sinceChangeMs: 0, random: 0 }), 10000);
  assert.equal(nextPollDelay({ kind: "menu", random: 0 }), 30000);
  assert.equal(nextPollDelay({ kind: "menu", random: 1 }), 27000);
});

test("un tirage aléatoire hors limites ne dépasse jamais l'intervalle", () => {
  assert.equal(nextPollDelay({ kind: "kitchen", random: -3 }), 3000);
  assert.equal(nextPollDelay({ kind: "kitchen", random: 7 }), 2700);
});

test("après une erreur : 3 s, 6 s, 12 s, puis 30 s au plus, pour tous les écrans", () => {
  assert.deepEqual([1, 2, 3, 4, 5, 50].map(backoffDelay), [3000, 6000, 12000, 30000, 30000, 30000]);
  for (const kind of ["kitchen", "board", "tracking", "menu"]) {
    assert.equal(nextPollDelay({ kind, failures: 2, random: 0.5 }), 6000);
  }
});

test("le délai est compté depuis le début de la requête précédente", () => {
  assert.equal(remainingDelay(3000, 400), 2600);
  assert.equal(remainingDelay(3000, 3500), 0, "réponse lente : la suivante part aussitôt, jamais deux à la fois");
  assert.equal(remainingDelay(3000, -10), 3000);
  assert.equal(scheduleDelay({ kind: "kitchen", elapsedSinceStartMs: 400, random: 0 }), 2600);
});

test("après un échec, l'attente compte depuis la fin : un réseau absent n'est pas relancé aussitôt", () => {
  // Requête abandonnée au bout de 8 s : on attend encore 3 s, pas 0
  assert.equal(scheduleDelay({ kind: "kitchen", failures: 1, elapsedSinceStartMs: REQUEST_TIMEOUT_MS }), 3000);
});

test("une heure de cuisine : jamais plus de 3 s entre deux débuts d'interrogation", () => {
  let now = 0;
  let lastChangeAt = -Infinity;
  const starts = [];
  while (now < 3600_000) {
    starts.push(now);
    const responseMs = 150 + (starts.length % 7) * 50; // 0,15 à 0,45 s en 4G
    now += responseMs;
    if (starts.length % 40 === 0) {
      lastChangeAt = now; // une commande de temps en temps
    }
    now += scheduleDelay({ kind: "kitchen", sinceChangeMs: now - lastChangeAt, elapsedSinceStartMs: responseMs, random: (starts.length * 0.37) % 1 });
  }
  const gaps = starts.slice(1).map((start, i) => start - starts[i]);
  assert.ok(Math.max(...gaps) <= 3000, `écart maximal ${Math.max(...gaps)} ms`);
  assert.ok(Math.min(...gaps) >= 1800, `écart minimal ${Math.min(...gaps)} ms`);
});

test("écran masqué : aucune requête ; requête en cours : relue juste après, jamais deux à la fois", () => {
  assert.equal(pollDecision({ running: true, hidden: false, busy: false }), "go");
  assert.equal(pollDecision({ running: true, hidden: true, busy: false }), "hidden");
  assert.equal(pollDecision({ running: true, hidden: false, busy: true }), "busy");
  assert.equal(pollDecision({ running: false, hidden: false, busy: false }), "stopped");
});

test("menu de la caisse : jamais pendant un paiement, mais l'écran masqué passe avant", () => {
  assert.equal(pollDecision({ running: true, hidden: false, busy: false, blocked: true }), "blocked");
  assert.equal(pollDecision({ running: true, hidden: true, busy: false, blocked: true }), "hidden");
});

test("tablette retirée ou expirée : on arrête ; les autres erreurs sont réessayées", () => {
  assert.equal(isFatal(401), true);
  assert.equal(isFatal(403), true);
  for (const status of [0, 404, 429, 500, 503]) {
    assert.equal(isFatal(status), false);
  }
});

test("heure du serveur lue dans l'en-tête Date", () => {
  const local = Date.parse("Fri, 18 Dec 2026 19:00:00 GMT");
  assert.equal(clockOffset("Fri, 18 Dec 2026 19:00:05 GMT", local), 5000);
  assert.equal(clockOffset("Fri, 18 Dec 2026 18:59:58 GMT", local), -2000);
  assert.equal(clockOffset(null, local), null);
  assert.equal(clockOffset("pas une date", local), null);
});
