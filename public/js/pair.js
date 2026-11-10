// Page /appairer : échange du code contre le jeton, puis affichage de la tablette appairée.
//
// Le code se saisit à la main, ou arrive par un lien /appairer#code=K7QM-3XRP (commande
// app:dev:test-devices, plus tard QR code). Les décisions sont dans pair-link.js.

import { apiFetch, ApiError } from "./api.js";
import { clearPairing, getOutbox, getPairing, replacePairing, savePairing } from "./device-store.js";
import {
  decideLinkPairing,
  formatCode,
  keepCodeAfterFailure,
  normalizeCode,
  readLinkCode,
  replaceQuestion,
  waitingMessage,
} from "./pair-link.js";

const ROLE_LABELS = { caisse: "Caisse", cuisine: "Cuisine", affichage: "Affichage" };
const CONFIRM_DELAY_MS = 4000;
const STORAGE_ERROR = "Ce navigateur ne permet pas d'enregistrer l'appairage. Utilisez Chrome, hors navigation privée.";
const BROKEN_LINK = "Ce lien d'appairage est incomplet. Saisissez le code affiché dans l'administration.";

const loading = document.getElementById("pair-loading");
const notice = document.getElementById("pair-notice");
const formSection = document.getElementById("pair-form-section");
const replaceSection = document.getElementById("replace-section");
const pairedSection = document.getElementById("paired-section");
const form = document.getElementById("pair-form");
const codeInput = document.getElementById("code");
const errorBox = document.getElementById("pair-error");
const offlineNote = document.getElementById("paired-offline");
const unpairButton = document.getElementById("unpair");
const replaceConfirm = document.getElementById("replace-confirm");
const replaceCancel = document.getElementById("replace-cancel");
const continueLink = document.getElementById("continue");

/** Code du lien en attente de confirmation (tablette déjà appairée). */
let pendingLinkCode = null;

function showNotice(message, kind = "info") {
  notice.textContent = message;
  notice.className = `alert alert-${kind}`;
  notice.hidden = !message;
}

function showError(message) {
  errorBox.textContent = message;
  errorBox.hidden = !message;
}

function showSection(section) {
  loading.hidden = true;
  for (const element of [formSection, replaceSection, pairedSection]) {
    element.hidden = element !== section;
  }
}

function showForm(message = "", code = "") {
  showSection(formSection);
  codeInput.value = code ? formatCode(code) : "";
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
  // Chaque tablette continue vers l'écran de son rôle
  const screens = {
    caisse: continueLink.dataset.tillUrl,
    cuisine: continueLink.dataset.kitchenUrl,
    affichage: continueLink.dataset.arrivalsUrl,
  };
  if (screens[device.role]) {
    continueLink.href = screens[device.role];
  }
  showSection(pairedSection);
}

/**
 * Bouton à confirmer en deux appuis, dans la page (pas de fenêtre système) : le premier
 * appui l'arme pour quelques secondes, le second lance l'action.
 */
function twoStep(button, action) {
  const label = button.textContent;
  let timer = null;
  const disarm = () => {
    clearTimeout(timer);
    delete button.dataset.armed;
    button.textContent = label;
  };
  button.addEventListener("click", () => {
    if (button.dataset.armed !== "1") {
      button.dataset.armed = "1";
      button.textContent = "Toucher encore pour confirmer";
      timer = setTimeout(disarm, CONFIRM_DELAY_MS);
      return;
    }
    disarm();
    action();
  });
  return disarm;
}

async function waitingCount() {
  // Stockage illisible : on ne sait pas, donc on refuse par prudence
  return getOutbox().then((entries) => entries.length, () => Number.POSITIVE_INFINITY);
}

/** Échange le code contre un jeton. Rejette avec une ApiError (statut 0 sans réponse du serveur). */
function exchange(code) {
  return apiFetch("/api/pair", { method: "POST", body: { code: formatCode(code) }, auth: false });
}

function errorStatus(error) {
  return error instanceof ApiError ? error.status : null;
}

function errorText(error) {
  return error instanceof ApiError ? error.message : "L'appairage n'a pas pu être enregistré sur la tablette. Réessayez.";
}

// Saisie : majuscules, tiret ajouté après le 4e caractère
codeInput.addEventListener("input", () => {
  const raw = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 8);
  codeInput.value = raw.length > 4 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const code = normalizeCode(codeInput.value);
  if (!code) {
    showError("Le code comporte 8 caractères, par exemple K7QM-3XRP.");
    return;
  }

  const button = form.querySelector("button[type=submit]");
  button.disabled = true;
  showError("");
  showNotice("");
  try {
    const result = await exchange(code);
    await savePairing({ token: result.token, expiresAt: result.expiresAt, device: result.device });
    codeInput.value = "";
    showPaired(result.device);
  } catch (error) {
    showError(errorText(error));
  } finally {
    button.disabled = false;
  }
});

// Désappairage en deux temps
twoStep(unpairButton, async () => {
  // Des commandes encaissées sans réseau attendent encore : sans l'appairage, elles ne pourraient plus partir
  const count = await waitingCount();
  const unpairError = document.getElementById("unpair-error");
  if (count > 0) {
    unpairError.hidden = false;
    unpairError.textContent = Number.isFinite(count) ? waitingMessage(count, "désappairer") : STORAGE_ERROR;
    return;
  }
  unpairError.hidden = true;
  await clearPairing();
  showNotice("");
  showForm();
});

// Remplacement de l'appairage par celui du lien, en deux temps
const disarmReplace = twoStep(replaceConfirm, async () => {
  const code = pendingLinkCode;
  pendingLinkCode = null;
  const previous = await getPairing().catch(() => null);
  const count = await waitingCount();
  if (count > 0) {
    showNotice(Number.isFinite(count) ? waitingMessage(count, "remplacer l'appairage") : STORAGE_ERROR, "error");
    await verifyPairing(previous);
    return;
  }
  replaceConfirm.disabled = true;
  try {
    const result = await exchange(code);
    await replacePairing({ token: result.token, expiresAt: result.expiresAt, device: result.device });
    const oldName = previous?.device?.name;
    showNotice(oldName ? `Appairage remplacé. Pensez à révoquer « ${oldName} » dans l'administration, rubrique Tablettes.` : "Appairage remplacé.");
    showPaired(result.device);
  } catch (error) {
    // L'ancien appairage n'a pas été touché
    showNotice(`Appairage non remplacé : ${errorText(error)}`, "error");
    await verifyPairing(previous);
  } finally {
    replaceConfirm.disabled = false;
  }
});

replaceCancel.addEventListener("click", async () => {
  pendingLinkCode = null;
  disarmReplace();
  showNotice("");
  await verifyPairing(await getPairing().catch(() => null));
});

/** Tablette pas encore appairée, lien reçu : appairage tout de suite. */
async function pairFromLink(code) {
  loading.textContent = "Appairage en cours…";
  try {
    const result = await exchange(code);
    await savePairing({ token: result.token, expiresAt: result.expiresAt, device: result.device });
    showPaired(result.device);
  } catch (error) {
    // Code expiré ou déjà utilisé : champ vide, pour une saisie à la main.
    // Serveur injoignable : le code reste dans le champ, il n'est plus dans l'adresse.
    showForm(errorText(error), keepCodeAfterFailure(errorStatus(error)) ? code : "");
  }
}

/** Déjà appairée : le serveur la reconnaît-il encore ? */
async function verifyPairing(pairing) {
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

async function start() {
  // Le code du lien est retiré de l'adresse tout de suite : ni affiché, ni gardé dans l'historique
  const link = readLinkCode(window.location.hash);
  if (link.present) {
    history.replaceState(null, "", window.location.pathname + window.location.search);
  }

  if (!("indexedDB" in window)) {
    showForm(STORAGE_ERROR);
    return;
  }
  let pairing;
  try {
    pairing = await getPairing();
  } catch {
    showForm(STORAGE_ERROR);
    return;
  }

  if (!link.present) {
    await verifyPairing(pairing);
    return;
  }
  if (!link.code) {
    showNotice(BROKEN_LINK, "error");
    await verifyPairing(pairing);
    return;
  }

  const decision = decideLinkPairing({ pairing, waitingCount: pairing ? await waitingCount() : 0 });
  if (decision.action === "pair") {
    await pairFromLink(link.code);
  } else if (decision.action === "blocked") {
    showNotice(Number.isFinite(decision.count) ? waitingMessage(decision.count, "remplacer l'appairage") : STORAGE_ERROR, "error");
    await verifyPairing(pairing);
  } else {
    pendingLinkCode = link.code;
    document.getElementById("replace-question").textContent = replaceQuestion(decision.deviceName);
    showSection(replaceSection);
  }
}

start();
