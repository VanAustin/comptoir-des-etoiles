// Écran Caisse : grille d'articles, panier, paiement et numéro de commande.
// Le serveur recalcule prix, consigne, total et monnaie : les montants affichés
// après validation sont ceux de sa réponse.

import { apiFetch, ApiError } from "./api.js";
import { getNumbering, getPairing, saveNumbering } from "./device-store.js";
import { el } from "./dom.js";
import { CashReceived, changeBreakdown, describeBreakdown } from "./cash.js";
import { formatEuros } from "./money.js";
import { Cart, MAX_QUANTITY, indexMenu, newUuid } from "./till-cart.js";
import { TillTracking } from "./till-tracking.js";

const ALL = "Tout";
/** Relecture du menu : stocks des autres caisses, prix modifiés, articles retirés. */
const MENU_REFRESH_MS = 30000;

const $ = (id) => document.getElementById(id);
const views = { status: $("till-status"), main: $("till-main"), pay: $("till-pay"), track: $("till-track-view"), done: $("till-done") };

const cart = new Cart();
let menu = null;
let activeCategory = ALL;
let nextNumber = 1;
let numberingScope = "";
const payment = { method: "cash", received: new CashReceived() };
/** Dernière tentative d'envoi : renvoyée avec le même UUID si rien n'a changé (pas de doublon). */
let attempt = null;
let sending = false;

/** Vue affichée avant d'ouvrir le suivi, pour y revenir. */
let beforeTracking = "main";
const tracking = new TillTracking({
  onOpen: () => {
    if (!views.track.hidden || sending) {
      return;
    }
    beforeTracking = Object.keys(views).find((key) => !views[key].hidden) ?? "main";
    show("track");
    $("track-search").focus();
  },
  onClose: () => {
    show(beforeTracking);
    if (beforeTracking === "main") {
      render();
    }
  },
  onFatal: (error) => showStatus(error.message, { pairLink: true }),
});

function show(name) {
  for (const [key, view] of Object.entries(views)) {
    view.hidden = key !== name;
  }
  window.scrollTo(0, 0);
}

/** Message bloquant : tablette non appairée, serveur injoignable… */
function showStatus(message, { pairLink = false, retry = false } = {}) {
  $("till-status-text").textContent = message;
  $("till-status-link").hidden = !pairLink;
  $("till-status-retry").hidden = !retry;
  show("status");
}

function showMessage(element, message) {
  element.textContent = message;
  element.hidden = !message;
}

// --- Démarrage et menu ---

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
  if (pairing.device?.role !== "caisse") {
    showStatus("Cette tablette n'est pas une caisse. Ouvrez l'écran prévu pour son rôle.", { pairLink: true });
    return;
  }
  await loadMenu({ initial: true });
  tracking.start();
  setInterval(refreshMenu, MENU_REFRESH_MS);
  document.addEventListener("visibilitychange", refreshMenu);
}

/** Seulement sur la grille : pendant le paiement, la commande ne doit pas changer sous les yeux du caissier. */
function refreshMenu() {
  if (menu && !document.hidden && !views.main.hidden && !sending) {
    loadMenu({ background: true });
  }
}

async function loadMenu({ initial = false, background = false } = {}) {
  let data;
  try {
    data = await apiFetch("/api/menu");
    if (background && (views.main.hidden || sending)) {
      return; // Paiement commencé entre-temps : la commande ne change pas
    }
  } catch (error) {
    if (!initial) {
      return; // Le menu déjà affiché reste utilisable
    }
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      showStatus(error.message, { pairLink: true });
    } else {
      showStatus(error.message, { retry: true });
    }
    return;
  }

  menu = indexMenu(data);
  cart.keepOnly(menu);
  $("till-who").textContent = `Caisse ${menu.tillLetter} · ${menu.event.name}`;

  // Numérotation : on reprend après le plus grand numéro connu, ici ou sur le serveur
  numberingScope = `${menu.event.name}|${menu.event.date}|${menu.tillLetter}`;
  const stored = await getNumbering().catch(() => null);
  const local = stored?.scope === numberingScope ? stored.next : 1;
  nextNumber = Math.max(local, menu.lastNumber + 1);

  if (initial) {
    show("main");
  }
  render();
}

async function setNextNumber(number) {
  nextNumber = number;
  await saveNumbering({ scope: numberingScope, next: number }).catch(() => {});
}

// --- Affichage de la caisse ---

function render() {
  renderCategories();
  renderGrid();
  renderCart();
}

function categories() {
  return [ALL, ...new Set(menu.products.map((product) => product.category))];
}

function renderCategories() {
  const list = categories();
  if (!list.includes(activeCategory)) {
    activeCategory = ALL;
  }
  const container = $("till-cats");
  container.hidden = menu.products.length === 0 || list.length <= 2;
  container.replaceChildren(
    ...list.map((category) =>
      el("button", {
        text: category,
        attrs: { type: "button", "aria-pressed": String(category === activeCategory) },
        on: { click: () => { activeCategory = category; render(); } },
      }),
    ),
  );
}

function renderGrid() {
  $("till-empty-menu").hidden = menu.products.length > 0;
  const products = menu.products.filter((p) => activeCategory === ALL || p.category === activeCategory);
  $("till-grid").replaceChildren(...products.map(productButton));
}

function productButton(product) {
  const inCart = cart.quantities.get(product.id) ?? 0;
  const stock = cart.remainingStock(menu, product);
  // Information seulement : un article épuisé reste vendable
  const stockBadge = stock === null ? null
    : el("span", { class: stock <= 0 ? "stk out" : "stk", text: stock <= 0 ? "Épuisé" : `Reste ${stock}` });
  const label = `${product.name}, ${formatEuros(product.priceCents)}${inCart ? `, ${inCart} dans la commande` : ""}`;

  return el("button", {
    class: "item",
    attrs: { type: "button", "aria-label": label },
    on: { click: () => addProduct(product.id) },
  }, [
    // Clé d'icône validée par le serveur (catalogue), sinon l'icône par défaut
    el("span", { class: `icon icon-${product.icon ?? "default"}`, attrs: { "aria-hidden": "true" } }),
    el("span", { class: "nm", text: product.name }),
    el("span", { class: "pr num", text: formatEuros(product.priceCents) }),
    stockBadge,
    inCart ? el("span", { class: "qty num", text: String(inCart), attrs: { "aria-hidden": "true" } }) : null,
  ]);
}

function addProduct(id) {
  if (!cart.add(id)) {
    showMessage($("till-cart-error"), "Limite atteinte : 99 exemplaires par article et 50 articles différents par commande.");
    return;
  }
  showMessage($("till-cart-error"), "");
  render();
}

function stepper(label, quantity, { onLess, onMore, canMore = true }) {
  return el("span", { class: "q" }, [
    el("button", { text: "−", attrs: { type: "button", "aria-label": `Retirer un ${label}` }, on: { click: onLess } }),
    el("b", { class: "num", text: String(quantity) }),
    el("button", { text: "+", attrs: { type: "button", "aria-label": `Ajouter un ${label}`, disabled: !canMore }, on: { click: onMore } }),
  ]);
}

function renderCart() {
  const lines = cart.lines(menu);
  const rows = lines.map((line) => {
    let quantity;
    if (line.kind === "article") {
      quantity = stepper(line.label, line.quantity, {
        onLess: () => { cart.remove(line.productId); render(); },
        onMore: () => addProduct(line.productId),
        canMore: line.quantity < MAX_QUANTITY,
      });
    } else if (line.kind === "retour_consigne") {
      quantity = stepper("gobelet rendu", line.quantity, {
        onLess: () => { cart.changeReturns(-1); render(); },
        onMore: () => { cart.changeReturns(1); render(); },
        canMore: line.quantity < MAX_QUANTITY,
      });
    } else {
      // Consigne : ajoutée automatiquement, non modifiable
      quantity = el("span", { class: "q auto num", text: `${line.quantity} ×` });
    }
    return el("div", { class: `line line-${line.kind}` }, [
      el("span", { text: line.label }),
      quantity,
      el("span", { class: "num", text: formatEuros(line.totalCents) }),
    ]);
  });

  $("till-lines").replaceChildren(...(rows.length ? rows : [el("p", { class: "empty", text: "Touchez un article pour l'ajouter." })]));
  $("till-add-return").hidden = menu.depositCents === 0 || cart.depositReturns > 0;
  $("till-note-field").hidden = !cart.hasKitchenItem(menu);
  $("till-total").textContent = formatEuros(cart.total(menu));
  const count = lines.filter((line) => line.kind === "article").reduce((sum, line) => sum + line.quantity, 0);
  $("till-mini").hidden = cart.isEmpty();
  $("till-mini-text").textContent = `${count} article${count > 1 ? "s" : ""} · ${formatEuros(cart.total(menu))}`;
  $("till-next").textContent = `N° ${menu.tillLetter}${nextNumber}`;
  $("till-checkout").disabled = cart.isEmpty();
  $("till-clear").disabled = cart.isEmpty();
}

$("till-add-return").addEventListener("click", () => { cart.changeReturns(1); render(); });
$("till-clear").addEventListener("click", () => { clearCart(); showMessage($("till-cart-error"), ""); render(); });

/** Vide la commande en cours, remarque comprise. */
function clearCart() {
  cart.clear();
  $("till-note").value = "";
  renderNoteCount();
}

function renderNoteCount() {
  $("till-note-count").textContent = `${$("till-note").value.length}/140`;
}

$("till-note").addEventListener("input", renderNoteCount);
$("till-status-retry").addEventListener("click", () => {
  showStatus("Chargement du menu…");
  loadMenu({ initial: true });
});

// --- Paiement ---

$("till-checkout").addEventListener("click", () => {
  if (cart.isEmpty()) {
    return;
  }
  payment.method = "cash";
  payment.received.clear();
  showMessage($("pay-error"), "");
  renderPayment();
  show("pay");
});

/** Montant reçu en espèces ; un retour de consigne seul (total nul ou négatif) n'attend rien. */
function receivedCents(total) {
  return total <= 0 ? 0 : payment.received.amount(total);
}

function confirmLabel(total) {
  if (sending) {
    return "Envoi…";
  }
  const amount = total < 0 ? `rendre ${formatEuros(-total)}` : formatEuros(total);
  return `${attempt ? "Réessayer" : "Valider"} · ${amount}`;
}

function renderPayment() {
  const total = cart.total(menu);
  const refund = total <= 0;
  const cash = payment.method === "cash";

  $("pay-total").textContent = refund ? `À rendre : ${formatEuros(-total)}` : formatEuros(total);
  for (const button of views.pay.querySelectorAll("[data-method]")) {
    button.setAttribute("aria-pressed", String(button.dataset.method === payment.method));
    // Un total nul ou négatif se règle en espèces
    button.disabled = refund && button.dataset.method === "card";
  }
  $("pay-cash").hidden = !cash || refund;
  $("pay-card-help").hidden = cash;
  $("pay-refund-help").hidden = !refund;

  const received = payment.received;
  views.pay.querySelector("[data-exact]").setAttribute("aria-pressed", String(received.exact));
  $("pay-received").hidden = received.isEmpty();
  $("pay-received-detail").textContent = received.isEmpty() ? "" : received.detail(total);
  $("pay-cash-help").hidden = !received.isEmpty();

  const amount = cash && !refund ? receivedCents(total) : null;
  const change = amount === null ? null : amount - total;
  const changeBox = $("pay-change");
  changeBox.hidden = change === null;
  if (change !== null) {
    changeBox.classList.toggle("short", change < 0);
    $("pay-change-label").textContent = change < 0 ? "Il manque" : "À rendre";
    $("pay-change-amount").textContent = formatEuros(Math.abs(change));
    $("pay-change-breakdown").textContent = change > 0 ? describeBreakdown(changeBreakdown(change)) : "";
  }

  // Toujours actif : un montant insuffisant est expliqué dans la page au moment de valider
  $("pay-confirm").textContent = confirmLabel(total);
  $("pay-confirm").disabled = sending;
}

for (const button of views.pay.querySelectorAll("[data-method]")) {
  button.addEventListener("click", () => {
    payment.method = button.dataset.method;
    payment.received.clear();
    showMessage($("pay-error"), "");
    renderPayment();
  });
}

views.pay.querySelector("[data-exact]").addEventListener("click", () => {
  payment.received.setExact();
  showMessage($("pay-error"), "");
  renderPayment();
});

for (const button of views.pay.querySelectorAll(".cash button[data-cents]")) {
  button.addEventListener("click", () => {
    const added = payment.received.add(Number(button.dataset.cents));
    showMessage($("pay-error"), added ? "" : "Montant reçu trop élevé : 1 000 € au plus. Touchez « Effacer » pour recommencer.");
    renderPayment();
  });
}

$("pay-clear").addEventListener("click", () => {
  payment.received.clear();
  showMessage($("pay-error"), "");
  renderPayment();
});

$("pay-back").addEventListener("click", () => {
  if (!sending) {
    show("main");
    render();
  }
});

$("pay-confirm").addEventListener("click", () => {
  const total = cart.total(menu);
  if (payment.method === "cash" && total > 0) {
    const amount = receivedCents(total);
    if (amount === null) {
      showMessage($("pay-error"), "Indiquez le montant reçu : touchez les billets et pièces donnés par le client, ou « Compte juste ».");
      return;
    }
    if (amount < total) {
      showMessage($("pay-error"), `Il manque ${formatEuros(total - amount)} : complétez le montant reçu avant de valider.`);
      return;
    }
  }
  submitOrder();
});

// --- Envoi de la commande ---

function orderPayload() {
  return {
    paymentMethod: payment.method,
    ...(payment.method === "cash" ? { amountReceivedCents: receivedCents(cart.total(menu)) } : {}),
    lines: cart.payloadLines(),
    depositReturns: cart.depositReturns,
    // Remarque pour la cuisine : le serveur la nettoie et l'ignore sans article cuisine
    ...(cart.hasKitchenItem(menu) && $("till-note").value.trim() ? { note: $("till-note").value.trim() } : {}),
  };
}

async function submitOrder() {
  if (sending) {
    return; // Double appui
  }
  const content = orderPayload();
  const key = JSON.stringify(content);
  // Même contenu qu'un envoi resté sans réponse : même UUID, le serveur ne crée pas de doublon
  if (!attempt || attempt.key !== key) {
    attempt = { key, uuid: newUuid(), createdAt: new Date().toISOString() };
  }

  sending = true;
  showMessage($("pay-error"), "");
  renderPayment();

  try {
    const order = await sendWithNumbering(content);
    attempt = null;
    await setNextNumber(order.number + 1);
    showDone(order);
    tracking.refresh();
    clearCart();
    loadMenu(); // Stocks à jour pour la commande suivante
  } catch (error) {
    await handleSendError(error);
  } finally {
    sending = false;
    if (!views.pay.hidden) {
      renderPayment();
    }
  }
}

/** Envoie la commande ; si le numéro est déjà pris, reprend le numéro libre indiqué et renvoie une fois. */
async function sendWithNumbering(content) {
  const body = (number) => ({ uuid: attempt.uuid, number, createdAt: attempt.createdAt, ...content });
  try {
    return await apiFetch("/api/orders", { method: "POST", body: body(nextNumber) });
  } catch (error) {
    if (error instanceof ApiError && error.status === 409 && error.data?.nextNumber) {
      await setNextNumber(error.data.nextNumber);
      return apiFetch("/api/orders", { method: "POST", body: body(nextNumber) });
    }
    throw error;
  }
}

async function handleSendError(error) {
  if (!(error instanceof ApiError) || error.status === 0) {
    showMessage($("pay-error"), "Commande non enregistrée : la connexion au serveur a échoué. Touchez « Réessayer ».");
    return;
  }
  attempt = null;
  if (error.status === 401 || error.status === 403) {
    showStatus(error.message, { pairLink: true });
    return;
  }
  if (error.status === 422) {
    // Menu modifié entre-temps (article retiré…) : on le recharge
    await loadMenu();
  }
  showMessage($("pay-error"), error.message);
}

// --- Confirmation ---

function showDone(order) {
  const kitchen = order.status !== "served";
  $("done-lede").textContent = kitchen ? "Commande enregistrée, envoyée en cuisine" : "Commande enregistrée";
  $("done-number").textContent = order.displayNumber;
  $("done-help").textContent = kitchen ? "Donnez ce numéro au client." : "À servir directement au comptoir.";
  // Commandes déjà en cuisine avant celle-ci : de quoi annoncer l'attente au client
  const ahead = kitchen ? tracking.waitingInKitchen(order.uuid) : null;
  $("done-queue").hidden = ahead === null;
  if (ahead !== null) {
    $("done-queue").textContent = ahead === 0
      ? "Aucune commande avant celle-ci en cuisine."
      : `${ahead} commande${ahead > 1 ? "s" : ""} avant celle-ci en cuisine.`;
  }

  const change = order.changeCents;
  $("done-change").hidden = !change;
  if (change) {
    $("done-change-label").textContent = order.totalCents < 0 ? "À rendre au client" : "À rendre";
    $("done-change-amount").textContent = formatEuros(change);
    // Décomposition calculée par le serveur, qui fait référence
    $("done-change-breakdown").textContent = order.changeBreakdown ? describeBreakdown(order.changeBreakdown) : "";
  }
  show("done");
  $("done-next").focus();
}

$("done-next").addEventListener("click", () => {
  show("main");
  render();
});

start();
