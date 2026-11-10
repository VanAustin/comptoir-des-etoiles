// Décisions de l'appairage par lien (public/js/pair-link.js).
// Lancement : docker compose run --rm jstest

import { test } from "node:test";
import assert from "node:assert/strict";

import {
  CODE_ALPHABET,
  decideLinkPairing,
  formatCode,
  keepCodeAfterFailure,
  normalizeCode,
  readLinkCode,
  replaceQuestion,
  waitingMessage,
} from "../../public/js/pair-link.js";

test("le code du lien est lu après le #, avec ou sans tiret, en minuscules ou majuscules", () => {
  assert.deepEqual(readLinkCode("#code=K7QM-3XRP"), { present: true, code: "K7QM3XRP" });
  assert.deepEqual(readLinkCode("#code=k7qm3xrp"), { present: true, code: "K7QM3XRP" });
  assert.deepEqual(readLinkCode("code=K7QM-3XRP"), { present: true, code: "K7QM3XRP" });
});

test("sans code dans l'adresse, rien n'est fait automatiquement", () => {
  assert.deepEqual(readLinkCode(""), { present: false });
  assert.deepEqual(readLinkCode("#"), { present: false });
  assert.deepEqual(readLinkCode("#autre=1"), { present: false });
  assert.deepEqual(readLinkCode(undefined), { present: false });
});

test("un lien abîmé est signalé, sans appairage", () => {
  assert.deepEqual(readLinkCode("#code="), { present: true, code: null });
  assert.deepEqual(readLinkCode("#code=K7QM-3XR"), { present: true, code: null }, "trop court");
  assert.deepEqual(readLinkCode("#code=K7QM-3XRPP"), { present: true, code: null }, "trop long");
  assert.deepEqual(readLinkCode("#code=K7QM-3XR0"), { present: true, code: null }, "0 n'est pas dans l'alphabet");
  assert.deepEqual(readLinkCode("#code=KIQM-3XRP"), { present: true, code: null }, "I n'est pas dans l'alphabet");
});

test("l'alphabet est celui du serveur : pas de I, L, O, 0 ni 1", () => {
  for (const char of "ILO01") {
    assert.equal(CODE_ALPHABET.includes(char), false, char);
  }
  assert.equal(normalizeCode("ABCD-EFGH"), "ABCDEFGH");
  assert.equal(formatCode("ABCDEFGH"), "ABCD-EFGH");
});

test("tablette pas encore appairée : appairage immédiat", () => {
  assert.deepEqual(decideLinkPairing({ pairing: null, waitingCount: 0 }), { action: "pair" });
});

test("tablette déjà appairée : confirmation avant de remplacer, avec son nom", () => {
  const pairing = { token: "t", device: { name: "Caisse buvette" } };
  assert.deepEqual(decideLinkPairing({ pairing, waitingCount: 0 }), { action: "confirm", deviceName: "Caisse buvette" });
  assert.equal(replaceQuestion("Caisse buvette"), "Cette tablette est déjà appairée comme « Caisse buvette ». La remplacer ?");
  assert.equal(replaceQuestion(""), "Cette tablette est déjà appairée. La remplacer ?");
});

test("commandes en attente d'envoi : remplacement refusé, comme le désappairage", () => {
  const pairing = { token: "t", device: { name: "Caisse buvette" } };
  assert.deepEqual(decideLinkPairing({ pairing, waitingCount: 2 }), { action: "blocked", count: 2 });
  // Stockage illisible : on ne sait pas combien, on refuse
  assert.equal(decideLinkPairing({ pairing, waitingCount: Number.POSITIVE_INFINITY }).action, "blocked");
});

test("le message d'attente explique quoi faire, au singulier comme au pluriel", () => {
  assert.equal(
    waitingMessage(1, "désappairer"),
    "1 commande encaissée sur cette tablette n'est pas encore envoyée. Ouvrez la caisse : attendez leur envoi, ou traitez celles « À vérifier », avant de désappairer.",
  );
  assert.match(waitingMessage(3, "remplacer l'appairage"), /^3 commandes encaissées sur cette tablette ne sont pas encore envoyées\. .*avant de remplacer l'appairage\.$/);
});

test("code expiré ou déjà utilisé (refus du serveur) : le champ reste vide", () => {
  for (const status of [400, 404, 422, 429]) {
    assert.equal(keepCodeAfterFailure(status), false, String(status));
  }
});

test("serveur injoignable : le code reste dans le champ pour réessayer", () => {
  for (const status of [null, 0, 500, 503]) {
    assert.equal(keepCodeAfterFailure(status), true, String(status));
  }
});
