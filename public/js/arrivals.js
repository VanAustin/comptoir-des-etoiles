// Écran Arrivées : numéros prêts et en préparation, lus de loin, mis à jour toutes les 2,5 secondes.
// Un numéro qui vient d'être prêt est mis en évidence quelques secondes, avec un carillon.

import { getPairing } from "./device-store.js";
import { el } from "./dom.js";
import { Poller } from "./poller.js";
import { keepScreenOn, Sound } from "./screen.js";

const FRESH_SECONDS = 10;

const $ = (id) => document.getElementById(id);

let board = { ready: [], preparing: [] };
let firstLoad = true;
/** Numéros devenus prêts récemment, avec l'heure de fin de mise en évidence. */
const fresh = new Map();

const sound = new Sound("son-arrivees");
const poller = new Poller("/api/board", {
  onData: receive,
  onSuccess: () => { $("arrivals-offline").hidden = true; },
  onError: () => { $("arrivals-offline").hidden = false; },
  onFatal: (error) => showStatus(error.message, { pairLink: true }),
});

function showStatus(message, { pairLink = false } = {}) {
  document.documentElement.classList.remove("arrivals-started");
  $("arrivals-status-text").textContent = message;
  $("arrivals-status-link").hidden = !pairLink;
  $("arrivals-status").hidden = false;
  $("arrivals-start").hidden = true;
  $("arrivals-board").hidden = true;
}

async function start() {
  let pairing = null;
  try {
    pairing = await getPairing();
  } catch {
    showStatus("Ce navigateur ne permet pas d'enregistrer l'appairage. Utilisez Chrome, hors navigation privée.");
    return;
  }
  if (!pairing) {
    showStatus("Cette tablette n'est pas encore appairée.", { pairLink: true });
    return;
  }
  if (pairing.device?.role !== "affichage") {
    showStatus("Cette tablette n'est pas une tablette d'affichage. Ouvrez l'écran prévu pour son rôle.", { pairLink: true });
    return;
  }

  $("arrivals-who").textContent = [pairing.device.name, pairing.device.event?.name].filter(Boolean).join(" · ");
  renderSoundButton(await sound.load());
  $("arrivals-status").hidden = true;
  $("arrivals-start").hidden = false;
}

$("arrivals-start-button").addEventListener("click", () => {
  // Plein écran : refusé sans conséquence (navigateur, réglages)
  document.documentElement.requestFullscreen?.().catch(() => {});
  keepScreenOn();
  sound.unlock();
  document.documentElement.classList.add("arrivals-started");
  $("arrivals-start").hidden = true;
  $("arrivals-board").hidden = false;
  render();
  poller.start();
});

$("arrivals-sound").addEventListener("click", async () => {
  renderSoundButton(await sound.toggle());
  if (sound.enabled) {
    sound.play("carillon");
  }
});

function renderSoundButton(enabled) {
  const button = $("arrivals-sound");
  button.textContent = enabled ? "Son activé" : "Son coupé";
  button.setAttribute("aria-pressed", String(enabled));
}

function receive(data) {
  const before = new Set(board.ready);
  const arrived = data.ready.filter((number) => !before.has(number));
  if (!firstLoad && arrived.length > 0) {
    const until = Date.now() + FRESH_SECONDS * 1000;
    for (const number of arrived) {
      fresh.set(number, until);
    }
    sound.play("carillon");
    setTimeout(render, FRESH_SECONDS * 1000 + 100);
  }
  firstLoad = false;
  board = data;
  render();
}

function render() {
  const now = Date.now();
  for (const [number, until] of fresh) {
    if (until <= now) {
      fresh.delete(number);
    }
  }

  $("arrivals-ready").replaceChildren(
    ...board.ready.map((number) => el("span", { class: fresh.has(number) ? "num fresh" : "num", text: number })),
  );
  $("arrivals-prep").replaceChildren(...board.preparing.map((number) => el("span", { class: "num", text: number })));
  $("arrivals-ready-none").hidden = board.ready.length > 0;
  $("arrivals-prep-none").hidden = board.preparing.length > 0;
}

start();
