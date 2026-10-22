// Suivi des commandes à la caisse : résumé permanent dans l'en-tête, panneau avec
// les commandes prêtes et en préparation, remise au client au comptoir (avec 5 secondes
// pour annuler un appui).

import { apiFetch, ApiError } from "./api.js";
import { el } from "./dom.js";
import { Poller } from "./poller.js";

const LATE_MINUTES = 10;
const UNDO_SECONDS = 5;
const STATUS_LABELS = { new: "Nouvelle", prep: "En préparation" };

const $ = (id) => document.getElementById(id);

export class TillTracking {
  /**
   * @param {{onOpen: () => void, onClose: () => void, onFatal: (error: ApiError) => void}} handlers
   */
  constructor({ onOpen, onClose, onFatal }) {
    this.orders = [];
    this.clockOffset = 0;
    this.loaded = false;
    this.pending = new Map();
    this.notices = new Map();
    this.handlers = { onFatal };

    this.poller = new Poller("/api/till/orders", {
      interval: 5000,
      onData: (data) => this.receive(data),
      onSuccess: () => { $("track-offline").hidden = true; },
      onError: () => { $("track-offline").hidden = false; },
      onFatal: (error) => this.handlers.onFatal(error),
    });

    $("till-track").addEventListener("click", () => {
      onOpen();
      this.render();
      this.poller.poll();
    });
    $("track-close").addEventListener("click", () => onClose());
    $("track-search").addEventListener("input", () => this.render());
    // Temps d'attente à jour même quand rien ne change
    setInterval(() => this.render(), 15000);
  }

  start() {
    $("till-track").hidden = false;
    this.poller.start();
  }

  /** Après un encaissement : la nouvelle commande apparaît tout de suite. */
  refresh() {
    this.poller.poll();
  }

  /** Commandes en cuisine encore à préparer, hors celle du client qui vient de commander. */
  waitingInKitchen(exceptUuid) {
    if (!this.loaded) {
      return null;
    }
    return this.orders.filter((o) => (o.status === "new" || o.status === "prep") && o.uuid !== exceptUuid).length;
  }

  receive(data) {
    this.clockOffset = new Date(data.serverTime).getTime() - Date.now();
    this.orders = data.orders;
    this.loaded = true;
    for (const uuid of this.pending.keys()) {
      if (!this.orders.some((o) => o.uuid === uuid)) {
        this.cancelPending(uuid);
      }
    }
    this.render();
  }

  minutesSince(date) {
    return Math.max(0, Math.floor((Date.now() + this.clockOffset - new Date(date).getTime()) / 60000));
  }

  // --- Remise au client ---

  press(order) {
    const entry = { deadline: Date.now() + UNDO_SECONDS * 1000, sending: false, error: null };
    entry.timer = setInterval(() => {
      if (Date.now() >= entry.deadline) {
        clearInterval(entry.timer);
        this.send(order.uuid);
      } else {
        this.updateCountdowns();
      }
    }, 250);
    this.pending.set(order.uuid, entry);
    this.notices.delete(order.uuid);
    this.render();
  }

  cancelPending(uuid) {
    const entry = this.pending.get(uuid);
    if (entry && !entry.sending) {
      clearInterval(entry.timer);
      this.pending.delete(uuid);
    }
  }

  async send(uuid) {
    const entry = this.pending.get(uuid);
    if (!entry || entry.sending) {
      return;
    }
    entry.sending = true;
    entry.error = null;
    this.render();

    try {
      await apiFetch(`/api/till/orders/${uuid}/served`, { method: "POST" });
      this.pending.delete(uuid);
      this.orders = this.orders.filter((o) => o.uuid !== uuid);
    } catch (error) {
      entry.sending = false;
      if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
        this.poller.stop();
        this.handlers.onFatal(error);
        return;
      }
      if (error instanceof ApiError && error.status !== 0 && error.status < 500) {
        this.pending.delete(uuid);
        this.notices.set(uuid, error.message);
        setTimeout(() => { this.notices.delete(uuid); this.render(); }, 8000);
      } else {
        entry.error = "Pas envoyé : la connexion au serveur a échoué.";
      }
    }
    this.render();
    this.poller.poll();
  }

  countdownText(entry) {
    return `Remise au client dans ${Math.max(1, Math.ceil((entry.deadline - Date.now()) / 1000))} s`;
  }

  /** Décompte mis à jour sur place : redessiner ferait perdre un appui sur « Annuler ». */
  updateCountdowns() {
    for (const element of document.querySelectorAll("[data-track-countdown]")) {
      const entry = this.pending.get(element.dataset.trackCountdown);
      if (entry) {
        element.textContent = this.countdownText(entry);
      }
    }
  }

  // --- Affichage ---

  render() {
    this.renderSummary();
    if (!$("till-track-view").hidden) {
      this.renderPanel();
    }
  }

  renderSummary() {
    const ready = this.orders.filter((o) => o.status === "ready" && !this.pending.has(o.uuid));
    const kitchen = this.orders.filter((o) => o.status === "new" || o.status === "prep");
    const oldest = kitchen.length ? Math.max(...kitchen.map((o) => this.minutesSince(o.createdAt))) : 0;

    const parts = ["Suivi"];
    if (ready.length) {
      parts.push(`${ready.length} prête${ready.length > 1 ? "s" : ""}`);
    }
    parts.push(kitchen.length ? `${kitchen.length} en cuisine · ${oldest} min` : "rien en cuisine");
    const button = $("till-track");
    button.textContent = parts.join(" · ");
    button.classList.toggle("late", oldest >= LATE_MINUTES);
    button.classList.toggle("has-ready", ready.length > 0);
  }

  renderPanel() {
    const search = $("track-search").value.toUpperCase().replace(/\s/g, "");
    const visible = this.orders.filter((o) => !search || o.displayNumber.includes(search));
    // Prêtes : celle qui attend depuis le plus longtemps d'abord
    const ready = visible.filter((o) => o.status === "ready").sort((a, b) => a.updatedAt.localeCompare(b.updatedAt));
    const kitchen = visible.filter((o) => o.status !== "ready");

    $("track-ready").replaceChildren(...ready.map((o) => this.readyRow(o)));
    $("track-ready-none").hidden = ready.length > 0;
    $("track-kitchen").replaceChildren(...kitchen.map((o) => this.kitchenRow(o)));
    $("track-kitchen-none").hidden = kitchen.length > 0;
  }

  numberCell(order) {
    return el("span", { class: "track-number" }, [
      el("b", { class: "num", text: order.displayNumber }),
      order.mine ? el("span", { class: "tag", text: "Cette caisse" }) : null,
    ]);
  }

  kitchenRow(order) {
    const minutes = this.minutesSince(order.createdAt);
    const late = minutes >= LATE_MINUTES;
    return el("li", { class: late ? "track-row late" : "track-row" }, [
      this.numberCell(order),
      el("span", { class: "track-state", text: `${STATUS_LABELS[order.status]} · ${minutes} min${late ? " · En retard" : ""}` }),
    ]);
  }

  readyRow(order) {
    const entry = this.pending.get(order.uuid);
    const notice = this.notices.get(order.uuid);
    let action;
    if (entry) {
      const message = entry.sending ? "Envoi…" : entry.error ?? this.countdownText(entry);
      action = el("span", { class: "track-actions" }, [
        el("span", {
          class: entry.error ? "track-error" : "track-countdown",
          attrs: { role: "status", "data-track-countdown": entry.sending || entry.error ? null : order.uuid },
          text: message,
        }),
        el("button", {
          class: "btn btn-small",
          text: "Annuler",
          attrs: { type: "button", disabled: entry.sending, "aria-label": `Annuler la remise de la commande ${order.displayNumber}` },
          on: { click: () => { this.cancelPending(order.uuid); this.render(); } },
        }),
        entry.error
          ? el("button", { class: "btn btn-small", text: "Réessayer", attrs: { type: "button" }, on: { click: () => this.send(order.uuid) } })
          : null,
      ]);
    } else {
      action = el("button", {
        class: "btn btn-small act-served",
        text: "Remise au client",
        attrs: { type: "button", "aria-label": `Remise au client : commande ${order.displayNumber}` },
        on: { click: () => this.press(order) },
      });
    }

    return el("li", { class: "track-row ready" }, [
      this.numberCell(order),
      el("span", { class: "track-state", text: `Prête depuis ${this.minutesSince(order.updatedAt)} min` }),
      action,
      notice ? el("span", { class: "track-error", attrs: { role: "status" }, text: notice }) : null,
    ]);
  }
}
