// Page « Appairer » de l'administration : QR code du lien d'appairage et temps restant.
// Le QR code est dessiné dans un <canvas> (aucun HTML injecté, compatible avec la CSP).
// On le scanne avec l'appareil photo de la tablette : l'application n'utilise pas la caméra.

import { formatRemaining, moduleSize, qrMatrix, QUIET_ZONE, remainingSeconds } from "./pairing-qr-rules.js";

const panel = document.querySelector("[data-pairing-panel]");

if (panel) {
  const canvas = panel.querySelector("canvas[data-pair-url]");
  const countdown = panel.querySelector("[data-countdown]");
  const expired = document.querySelector("[data-pairing-expired]");
  const serverSeconds = Number(panel.dataset.remainingSeconds);
  const startedAt = performance.now();

  if (canvas) {
    drawQr(canvas, canvas.dataset.pairUrl);
  }

  const tick = () => {
    const seconds = remainingSeconds(serverSeconds, performance.now() - startedAt);
    if (countdown) {
      countdown.textContent = formatRemaining(seconds);
    }
    if (seconds <= 0) {
      // Code expiré : on retire le QR code et le code de l'écran
      clearInterval(timer);
      panel.hidden = true;
      if (expired) {
        expired.hidden = false;
      }
    }
  };
  const timer = setInterval(tick, 1000);
  tick();
}

function drawQr(canvas, text) {
  const matrix = qrMatrix(text);
  const count = matrix.length;
  const style = getComputedStyle(canvas);
  const cssSize = canvas.clientWidth || 240;
  const ratio = window.devicePixelRatio || 1;
  const module = moduleSize(count, Math.floor(cssSize * ratio));
  const size = module * (count + 2 * QUIET_ZONE);

  canvas.width = size;
  canvas.height = size;
  const context = canvas.getContext("2d");
  context.fillStyle = style.getPropertyValue("--qr-fond").trim();
  context.fillRect(0, 0, size, size);
  context.fillStyle = style.getPropertyValue("--qr-encre").trim();
  for (let row = 0; row < count; row++) {
    for (let col = 0; col < count; col++) {
      if (matrix[row][col]) {
        context.fillRect((col + QUIET_ZONE) * module, (row + QUIET_ZONE) * module, module, module);
      }
    }
  }
  canvas.hidden = false;
}
