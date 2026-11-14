// QR code et temps restant de la page « Appairer » (public/js/pairing-qr-rules.js).
// Lancement : docker compose run --rm jstest

import { test } from "node:test";
import assert from "node:assert/strict";

import { formatRemaining, moduleSize, qrMatrix, QUIET_ZONE, remainingSeconds } from "../../public/js/pairing-qr-rules.js";

const LINK = "https://comptoir.example/appairer#code=K7QM-3XRP";

test("le QR code d'un lien d'appairage est une grille carrée de taille valable", () => {
  const matrix = qrMatrix(LINK);
  const count = matrix.length;
  assert.ok(count >= 21 && count <= 177);
  assert.equal((count - 17) % 4, 0, "21, 25, 29… modules de côté");
  for (const row of matrix) {
    assert.equal(row.length, count);
  }
});

test("les trois repères de coin sont présents (carrés 7 × 7 bordés de foncé)", () => {
  const matrix = qrMatrix(LINK);
  const n = matrix.length;
  for (const [top, left] of [[0, 0], [0, n - 7], [n - 7, 0]]) {
    for (let i = 0; i < 7; i++) {
      assert.equal(matrix[top][left + i], true);
      assert.equal(matrix[top + 6][left + i], true);
      assert.equal(matrix[top + i][left], true);
      assert.equal(matrix[top + i][left + 6], true);
    }
    assert.equal(matrix[top + 1][left + 1], false, "anneau clair");
    assert.equal(matrix[top + 3][left + 3], true, "centre foncé");
  }
});

test("le même lien donne toujours le même QR code, un autre code un QR code différent", () => {
  assert.deepEqual(qrMatrix(LINK), qrMatrix(LINK));
  assert.notDeepEqual(qrMatrix(LINK), qrMatrix(LINK.replace("K7QM", "K7QN")));
});

test("modules de taille entière, marge de 4 modules comprise", () => {
  assert.equal(QUIET_ZONE, 4);
  assert.equal(moduleSize(33, 520), 12); // 520 / (33 + 8) = 12,6
  assert.equal(moduleSize(33, 20), 1, "jamais moins d'un pixel");
});

test("temps restant : celui du serveur, moins le temps écoulé, jamais négatif", () => {
  assert.equal(remainingSeconds(600, 0), 600);
  assert.equal(remainingSeconds(600, 1500), 599);
  assert.equal(remainingSeconds(600, 600_000), 0);
  assert.equal(remainingSeconds(600, 900_000), 0);
  assert.equal(remainingSeconds(Number.NaN, 0), 0);
  assert.equal(remainingSeconds(600, -5000), 600, "horloge qui recule : ignorée");
});

test("temps restant affiché en minutes et secondes", () => {
  assert.equal(formatRemaining(600), "10 min 00 s");
  assert.equal(formatRemaining(545), "9 min 05 s");
  assert.equal(formatRemaining(45), "45 s");
  assert.equal(formatRemaining(0), "0 s");
  assert.equal(formatRemaining(-3), "0 s");
});
