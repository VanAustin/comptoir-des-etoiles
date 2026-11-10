// File d'attente des caisses (public/js/outbox-rules.js) : aucune commande perdue.
// Lancement : docker compose run --rm jstest

import { test } from "node:test";
import assert from "node:assert/strict";

import {
  isOffline,
  OFFLINE_GRACE_MS,
  otherOwnerMessage,
  refusalMessage,
  retryDelay,
  sameOwner,
  sendFailure,
  SEND_TIMEOUT_MS,
} from "../../public/js/outbox-rules.js";

test("renvoi de la file : 3 s, 6 s, 12 s, puis toutes les 30 s, comme les interrogations", () => {
  assert.deepEqual([0, 1, 2, 3, 4, 9].map(retryDelay), [3000, 3000, 6000, 12000, 30000, 30000]);
  assert.equal(SEND_TIMEOUT_MS, 8000);
});

test("sans réponse utile du serveur, la commande reste dans la file et sera renvoyée", () => {
  for (const status of [undefined, 0, 500, 502, 503, 429]) {
    assert.equal(sendFailure(status), "retry", `statut ${status}`);
  }
});

test("refus du serveur : « À vérifier », et on passe à la suivante", () => {
  for (const status of [400, 404, 409, 413, 422]) {
    assert.equal(sendFailure(status), "block", `statut ${status}`);
  }
});

test("tablette retirée ou expirée : « À vérifier », et plus rien ne part", () => {
  assert.equal(sendFailure(401), "block-and-stop");
  assert.equal(sendFailure(403), "block-and-stop");
});

test("numéro déjà pris sur le serveur : message pour ressaisir et redonner un numéro", () => {
  const entry = { displayNumber: "A12" };
  assert.match(refusalMessage(entry, { status: 409, message: "Conflit", data: { nextNumber: 13 } }), /numéro A12 existe déjà/);
  assert.equal(refusalMessage(entry, { status: 422, message: "Article inconnu." }), "Article inconnu.");
  assert.equal(refusalMessage(entry, { status: 409, message: "Autre conflit", data: {} }), "Autre conflit");
});

test("une commande ne part qu'avec l'appairage qui l'a encaissée", () => {
  const owner = { tillLetter: "A", event: "Loto" };
  assert.equal(sameOwner(owner, { tillLetter: "A", event: "Loto" }), true);
  assert.equal(sameOwner(owner, { tillLetter: "B", event: "Loto" }), false);
  assert.equal(sameOwner(owner, { tillLetter: "A", event: "Carnaval" }), false);
  assert.equal(sameOwner(null, owner), false);
  assert.match(otherOwnerMessage({ owner }), /caisse A avant un nouvel appairage/);
  assert.match(otherOwnerMessage({}), /caisse \? /);
});

test("après un échec récent, les commandes vont directement dans la file", () => {
  const now = 100_000;
  assert.equal(isOffline({ failures: 1, lastFailureAt: now - 1000, now }), true);
  assert.equal(isOffline({ failures: 1, lastFailureAt: now - OFFLINE_GRACE_MS, now }), false);
  assert.equal(isOffline({ failures: 0, lastFailureAt: now, now }), false);
});
