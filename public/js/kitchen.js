// Écran Cuisine : tickets des commandes en cours, mis à jour toutes les 3 secondes
// (2 secondes pendant les 30 secondes qui suivent un changement).
// Chaque appui (« Je prépare », « Prête », « Remise au client ») part après 5 secondes,
// le temps de l'annuler en cas d'erreur.

import { apiFetch, ApiError } from "./api.js";
import { getPairing } from "./device-store.js";
import { el } from "./dom.js";
import { Poller } from "./poller.js";
import { keepScreenOn, Sound } from "./screen.js";

const LATE_MINUTES = 10;
const UNDO_SECONDS = 5;
const NEXT = { new: "prep", prep: "ready", ready: "served" };
const STATUS_LABELS = { new: "Nouvelle", prep: "En préparation", ready: "Prête", served: "Remise au client" };
const ACTIONS = {
  new: { label: "Je prépare", class: "act-prep" },
  prep: { label: "Prête", class: "act-ready" },
  ready: { label: "Remise au client", class: "act-served" },
};

const $ = (id) => document.getElementById(id);

/** Commandes telles que le serveur les a renvoyées, de la plus ancienne à la plus récente. */
let orders = [];
/** Écart entre l'heure du serveur et celle de la tablette, en millisecondes. */
let clockOffset = 0;
let firstLoad = true;
/**
 * Appuis en attente, par UUID de commande :
 * {status, deadline, timer, sending, error}. Le ticket affiche déjà l'état demandé.
 */
const pending = new Map();
/** Messages affichés quelques secondes sur un ticket (refus du serveur). */
const notices = new Map();

const sound = new Sound("son-cuisine");
const poller = new Poller("/api/orders", {
  kind: "kitchen",
  onData: receive,
  onSuccess: ({ clockOffset: offset }) => {
    // Les temps d'attente se calculent sur l'heure du serveur (en-tête Date), pas sur celle de la tablette
    if (offset !== null) {
      clockOffset = offset;
    }
    setOffline(null);
  },
  onError: (error) => setOffline(error),
  onFatal: (error) => showStatus(error.message, { pairLink: true }),
});

function showStatus(message, { pairLink = false } = {}) {
  $("kitchen-status-text").textContent = message;
  $("kitchen-status-link").hidden = !pairLink;
  $("kitchen-status").hidden = false;
  $("kitchen-main").hidden = true;
}

// --- Démarrage ---

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
  if (pairing.device?.role !== "cuisine") {
    showStatus("Cette tablette n'est pas une tablette cuisine. Ouvrez l'écran prévu pour son rôle.", { pairLink: true });
    return;
  }

  $("kitchen-who").textContent = [pairing.device.name, pairing.device.event?.name].filter(Boolean).join(" · ");
  renderSoundButton(await sound.load());
  $("kitchen-sound").hidden = false;
  keepScreenOn();
  $("kitchen-status").hidden = true;
  $("kitchen-main").hidden = false;
  poller.start();
  // Temps d'attente à jour même quand rien ne change
  setInterval(render, 15000);
}

// Chrome n'autorise le son qu'après un premier toucher sur la page
document.addEventListener("pointerdown", () => {
  sound.unlock();
  setTimeout(renderSoundHint, 300);
}, { once: true });

$("kitchen-sound").addEventListener("click", async () => {
  renderSoundButton(await sound.toggle());
  if (sound.enabled) {
    sound.play("bip");
  }
});

function renderSoundButton(enabled) {
  const button = $("kitchen-sound");
  button.textContent = enabled ? "Son activé" : "Son coupé";
  button.setAttribute("aria-pressed", String(enabled));
  renderSoundHint();
}

function renderSoundHint() {
  $("kitchen-sound-hint").hidden = !sound.enabled || sound.unlocked;
}

// --- Données du serveur ---

function receive(data) {
  const known = new Set(orders.map((order) => order.uuid));
  const arrived = data.orders.some((order) => order.status === "new" && !known.has(order.uuid));
  if (arrived && !firstLoad) {
    sound.play("bip");
  }
  firstLoad = false;

  orders = data.orders;
  // Un appui en attente sur une commande qui n'est plus en cours (remise par une autre tablette…) est abandonné
  for (const uuid of pending.keys()) {
    if (!orders.some((order) => order.uuid === uuid)) {
      cancelPending(uuid);
    }
  }
  render();
}

function setOffline(error) {
  const box = $("kitchen-offline");
  if (!error) {
    box.hidden = true;
    box.dataset.since = "";
    return;
  }
  if (!box.dataset.since) {
    box.dataset.since = new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }
  box.textContent = `Connexion au serveur perdue depuis ${box.dataset.since} : les commandes affichées ne sont peut-être plus à jour. Nouvel essai automatique…`;
  box.hidden = false;
}

// --- Appuis avec délai d'annulation ---

function press(order) {
  const status = NEXT[order.status];
  const entry = { status, deadline: Date.now() + UNDO_SECONDS * 1000, timer: null, sending: false, error: null };
  entry.timer = setInterval(() => {
    if (Date.now() >= entry.deadline) {
      clearInterval(entry.timer);
      send(order.uuid);
    } else {
      updateCountdowns();
    }
  }, 250);
  pending.set(order.uuid, entry);
  notices.delete(order.uuid);
  render();
}

function cancelPending(uuid) {
  const entry = pending.get(uuid);
  if (entry && !entry.sending) {
    clearInterval(entry.timer);
    pending.delete(uuid);
  }
}

async function send(uuid) {
  const entry = pending.get(uuid);
  if (!entry || entry.sending) {
    return;
  }
  entry.sending = true;
  entry.error = null;
  render();

  try {
    const order = await apiFetch(`/api/orders/${uuid}/status`, { method: "POST", body: { status: entry.status } });
    pending.delete(uuid);
    replaceOrder(order);
  } catch (error) {
    entry.sending = false;
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      poller.stop();
      showStatus(error.message, { pairLink: true });
      return;
    }
    if (error instanceof ApiError && error.status !== 0 && error.status < 500) {
      // Refus du serveur (déjà avancée ailleurs, annulée…) : on affiche son état à lui
      pending.delete(uuid);
      showNotice(uuid, error.message);
      if (error.data?.order) {
        replaceOrder(error.data.order);
      }
    } else {
      entry.error = "Pas envoyé : la connexion au serveur a échoué.";
    }
  }
  render();
  poller.noteChange();
  poller.poll();
}

function replaceOrder(order) {
  const index = orders.findIndex((o) => o.uuid === order.uuid);
  if (index === -1) {
    return;
  }
  if (order.status === "served" || order.status === "cancelled") {
    orders.splice(index, 1);
  } else {
    orders[index] = order;
  }
}

function showNotice(uuid, message) {
  notices.set(uuid, message);
  setTimeout(() => {
    if (notices.get(uuid) === message) {
      notices.delete(uuid);
      render();
    }
  }, 8000);
}

// --- Affichage ---

function minutesWaiting(order) {
  return Math.max(0, Math.floor((Date.now() + clockOffset - new Date(order.createdAt).getTime()) / 60000));
}

function render() {
  // Classement selon l'état connu du serveur : un ticket ne change pas de section pendant le délai d'annulation
  const toPrepare = orders.filter((order) => order.status !== "ready");
  const ready = orders.filter((order) => order.status === "ready");
  // Rien reçu du serveur (hors ligne au démarrage) : ne pas annoncer « aucune commande »
  $("kitchen-empty").hidden = firstLoad || toPrepare.length > 0;
  $("kitchen-tickets").replaceChildren(...toPrepare.map(ticket));
  $("kitchen-ready").hidden = ready.length === 0;
  $("kitchen-ready-title").textContent = `Prêtes · en attente du client (${ready.length})`;
  $("kitchen-ready-tickets").replaceChildren(...ready.map(ticket));
}

function ticket(order) {
  const entry = pending.get(order.uuid);
  // Le ticket montre tout de suite l'état demandé, même avant l'envoi
  const status = entry?.status ?? order.status;
  const minutes = minutesWaiting(order);
  const late = (status === "new" || status === "prep") && minutes >= LATE_MINUTES;
  const notice = notices.get(order.uuid);

  return el("article", {
    class: ["ticket", status, late ? "late" : "", entry ? "pending" : ""].filter(Boolean).join(" "),
    attrs: { "aria-label": `Commande ${order.displayNumber}` },
  }, [
    el("div", { class: "tk-head" }, [
      el("span", { class: "n num", text: order.displayNumber }),
      el("span", { class: "t num", text: `${minutes} min` }),
    ]),
    el("span", { class: `pill ${status}`, text: STATUS_LABELS[status] + (late ? " · En retard" : "") }),
    // Remarque du client : texte brut, inséré avec textContent
    order.note ? el("p", { class: "ticket-note", text: order.note, attrs: { "aria-label": `Remarque : ${order.note}` } }) : null,
    el("ul", { class: "kitchen-lines" }, order.kitchenLines.map(kitchenLine)),
    order.counterLines.length
      ? el("p", { class: "counter", text: `Au comptoir : ${order.counterLines.map((l) => `${l.quantity} × ${l.label}`).join(", ")}` })
      : null,
    notice ? el("p", { class: "alert alert-error", attrs: { role: "status" }, text: notice }) : null,
    entry ? pendingActions(order, entry) : actionButton(order),
  ]);
}

function kitchenLine(line) {
  return el("li", {}, [
    el("b", { class: "num", text: `${line.quantity} ×` }),
    el("span", { text: line.label }),
    line.components.length
      ? el("small", { text: line.components.map((c) => `${c.quantity} × ${c.label}`).join(", ") })
      : null,
  ]);
}

function actionButton(order) {
  const action = ACTIONS[order.status];
  return el("button", {
    class: `btn ${action.class}`,
    text: action.label,
    attrs: { type: "button", "aria-label": `${action.label} : commande ${order.displayNumber}` },
    on: { click: () => press(order) },
  });
}

function countdownText(entry) {
  const seconds = Math.max(1, Math.ceil((entry.deadline - Date.now()) / 1000));
  return `« ${STATUS_LABELS[entry.status]} » envoyé dans ${seconds} s`;
}

/** Décompte mis à jour sur place : redessiner le ticket ferait perdre un appui sur « Annuler ». */
function updateCountdowns() {
  for (const element of document.querySelectorAll("[data-countdown]")) {
    const entry = pending.get(element.dataset.countdown);
    if (entry) {
      element.textContent = countdownText(entry);
    }
  }
}

function pendingActions(order, entry) {
  let message;
  if (entry.sending) {
    message = "Envoi…";
  } else if (entry.error) {
    message = entry.error;
  } else {
    message = countdownText(entry);
  }

  return el("div", { class: "pending-actions" }, [
    el("p", {
      class: entry.error ? "alert alert-error" : "countdown",
      attrs: { role: "status", "data-countdown": entry.sending || entry.error ? null : order.uuid },
      text: message,
    }),
    el("div", { class: "row" }, [
      el("button", {
        class: "btn",
        text: "Annuler",
        attrs: { type: "button", disabled: entry.sending, "aria-label": `Annuler l'appui sur la commande ${order.displayNumber}` },
        on: { click: () => { cancelPending(order.uuid); render(); } },
      }),
      entry.error
        ? el("button", { class: "btn", text: "Réessayer", attrs: { type: "button" }, on: { click: () => send(order.uuid) } })
        : null,
    ]),
  ]);
}

start();
