// Service à table : pavé de la caisse, envoi en cuisine, messages (public/js/table-rules.js).
// Lancement : docker compose run --rm jstest

import { test } from "node:test";
import assert from "node:assert/strict";

import { counterTitle, eraseDigit, handOverLabel, matchesTracking, parseTable, pressDigit, tableForOrder, tableMessage, zoneBadge } from "../../public/js/table-rules.js";

const type = (keys) => [...keys].reduce((entry, key) => pressDigit(entry, key), "");

test("pavé : trois chiffres au plus", () => {
  assert.equal(type("304"), "304");
  assert.equal(type("3045"), "304");
  assert.equal(type("999"), "999");
});

test("pavé : pas de zéro en tête, mais des zéros ensuite", () => {
  assert.equal(type("0"), "");
  assert.equal(type("007"), "7");
  assert.equal(type("101"), "101");
  assert.equal(type("800"), "800");
});

test("pavé : une touche qui n'est pas un chiffre est ignorée", () => {
  assert.equal(pressDigit("3", "a"), "3");
  assert.equal(pressDigit("3", "12"), "3");
  assert.equal(pressDigit("3", ""), "3");
});

test("pavé : effacer retire le dernier chiffre", () => {
  assert.equal(eraseDigit("304"), "30");
  assert.equal(eraseDigit("3"), "");
  assert.equal(eraseDigit(""), "");
});

test("numéro de table : 1 à 999, rien d'autre", () => {
  assert.equal(parseTable("304"), 304);
  assert.equal(parseTable("1"), 1);
  assert.equal(parseTable("999"), 999);
  for (const invalid of ["", "0", "012", "1000", "3a", "-4", "4.5", null, undefined]) {
    assert.equal(parseTable(invalid), null, String(invalid));
  }
});

test("envoi : la table part seulement avec au moins un article cuisine", () => {
  assert.equal(tableForOrder(304, true), 304);
  assert.equal(tableForOrder(304, false), null, "boissons seules : commande au comptoir");
  assert.equal(tableForOrder(null, true), null, "sans numéro : commande au comptoir");
  assert.equal(tableForOrder(0, true), null);
  assert.equal(tableForOrder(1000, true), null);
});

test("messages de la caisse selon le contenu de la commande", () => {
  assert.equal(tableMessage({ table: 304, kitchen: true, counter: false }), "Servi à la table 304");
  assert.equal(tableMessage({ table: 304, kitchen: true, counter: true }), "Plats servis à la table 304 · boissons à remettre maintenant");
  assert.equal(tableMessage({ table: 304, kitchen: false, counter: true }), "Tout est remis au comptoir");
  assert.equal(tableMessage({ table: null, kitchen: true, counter: true }), null);
});

test("ticket cuisine : boissons d'une commande à table déjà remises", () => {
  assert.equal(counterTitle(304), "Déjà remis au comptoir");
  assert.equal(counterTitle(null), "Au comptoir");
  assert.equal(counterTitle(undefined), "Au comptoir");
});

test("suivi : recherche par numéro de commande ou de table, filtre « À table »", () => {
  const atTable = { displayNumber: "A12", tableNumber: 304 };
  const counter = { displayNumber: "B7", tableNumber: null };
  assert.ok(matchesTracking(atTable, {}));
  assert.ok(matchesTracking(counter, {}));
  assert.ok(matchesTracking(atTable, { search: "a1" }));
  assert.ok(matchesTracking(atTable, { search: "304" }));
  assert.ok(!matchesTracking(counter, { search: "304" }));
  assert.ok(matchesTracking(atTable, { tableOnly: true }));
  assert.ok(!matchesTracking(counter, { tableOnly: true }));
  assert.ok(!matchesTracking(atTable, { tableOnly: true, search: "B" }));
});

test("remise : au client au comptoir, au livreur pour une commande à table", () => {
  assert.equal(handOverLabel(null), "Remise au client");
  assert.equal(handOverLabel(undefined), "Remise au client");
  assert.equal(handOverLabel(304), "Remise au livreur");
  assert.equal(handOverLabel(7), "Remise au livreur");
});

test("zone : libellé écrit et couleur, qui se répète au-delà de trois zones", () => {
  assert.deepEqual(zoneBadge(1), { label: "Zone 1", className: "zone-1" });
  assert.deepEqual(zoneBadge(3), { label: "Zone 3", className: "zone-3" });
  assert.deepEqual(zoneBadge(4), { label: "Zone 4", className: "zone-1" });
  assert.deepEqual(zoneBadge(9), { label: "Zone 9", className: "zone-3" });
});

test("zone : rien sans zone (table sans rangée, commande au comptoir)", () => {
  for (const zone of [null, undefined, 0, -1, 1.5, "1"]) {
    assert.equal(zoneBadge(zone), null, String(zone));
  }
});
