// Page /appairer : échange du code contre le jeton, puis affichage de la tablette appairée.

import { apiFetch, ApiError } from "./api.js";
import { clearPairing, getPairing, savePairing } from "./device-store.js";

const ROLE_LABELS = { caisse: "Caisse", cuisine: "Cuisine", affichage: "Affichage" };
const CONFIRM_DELAY_MS = 4000;

const loading = document.getElementById("pair-loading");
const formSection = document.getElementById("pair-form-section");
const pairedSection = document.getElementById("paired-section");
const form = document.getElementById("pair-form");
const codeInput = document.getElementById("code");
const errorBox = document.getElementById("pair-error");
const offlineNote = document.getElementById("paired-offline");
const unpairButton = document.getElementById("unpair");
const continueLink = document.getElementById("continue");

function showError(message) {
  errorBox.textContent = message;
  errorBox.hidden = !message;
}

function showForm(message = "") {
  loading.hidden = true;
  pairedSection.hidden = true;
  formSection.hidden = false;
  showError(message);
  codeInput.focus();
}

function showPaired(device, offline = false) {
  const values = {
    name: device.name,
    role: ROLE_LABELS[device.role] ?? device.role,
    tillLetter: device.tillLetter ?? "—",
    event: device.event?.name ?? "",
  };
  for (const element of pairedSection.querySelectorAll("[data-info]")) {
    element.textContent = values[element.dataset.info];
  }
  offlineNote.hidden = !offline;
  // Une caisse continue vers son écran ; cuisine et affichage viendront à l'étape 2
  if (device.role === "caisse") {
    continueLink.href = continueLink.dataset.tillUrl;
  }
  loading.hidden = true;
  formSection.hidden = true;
  pairedSection.hidden = false;
}

// Saisie : majuscules, tiret ajouté après le 4e caractère
codeInput.addEventListener("input", () => {
  const raw = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 8);
  codeInput.value = raw.length > 4 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const code = codeInput.value.trim();
  if (code.replace("-", "").length !== 8) {
    showError("Le code comporte 8 caractères, par exemple K7QM-3XRP.");
    return;
  }

  const button = form.querySelector("button[type=submit]");
  button.disabled = true;
  showError("");
  try {
    const result = await apiFetch("/api/pair", { method: "POST", body: { code }, auth: false });
    await savePairing({ token: result.token, expiresAt: result.expiresAt, device: result.device });
    codeInput.value = "";
    showPaired(result.device);
  } catch (error) {
    showError(error instanceof ApiError ? error.message : "L'appairage n'a pas pu être enregistré sur la tablette. Réessayez.");
  } finally {
    button.disabled = false;
  }
});

// Désappairage en deux temps, confirmé dans la page (pas de fenêtre système)
unpairButton.addEventListener("click", async () => {
  if (unpairButton.dataset.armed !== "1") {
    unpairButton.dataset.armed = "1";
    unpairButton.textContent = "Toucher encore pour confirmer";
    setTimeout(() => {
      delete unpairButton.dataset.armed;
      unpairButton.textContent = "Désappairer cette tablette";
    }, CONFIRM_DELAY_MS);
    return;
  }
  delete unpairButton.dataset.armed;
  unpairButton.textContent = "Désappairer cette tablette";
  await clearPairing();
  showForm();
});

// Au chargement : la tablette est-elle déjà appairée, et le serveur la reconnaît-il encore ?
async function start() {
  if (!("indexedDB" in window)) {
    showForm("Ce navigateur ne permet pas d'enregistrer l'appairage. Utilisez Chrome, hors navigation privée.");
    return;
  }

  let pairing;
  try {
    pairing = await getPairing();
  } catch {
    showForm("Ce navigateur ne permet pas d'enregistrer l'appairage. Utilisez Chrome, hors navigation privée.");
    return;
  }
  if (!pairing) {
    showForm();
    return;
  }

  try {
    const device = await apiFetch("/api/me");
    await savePairing({ ...pairing, device });
    showPaired(device);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      // Jeton refusé (révoqué, expiré…) : on l'oublie et on explique pourquoi
      await clearPairing();
      showForm(error.message);
    } else {
      // Serveur injoignable ou indisponible : l'appairage est conservé
      showPaired(pairing.device, true);
    }
  }
}

start();
