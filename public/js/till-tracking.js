// Suivi des commandes à la caisse : résumé permanent dans l'en-tête, panneau avec
// les commandes prêtes et en préparation, remise au client au comptoir (avec 5 secondes
// pour annuler un appui).

import { apiFetch, ApiError } from "./api.js";
import { el } from "./dom.js";
import { Lane, Poller } from "./poller.js";
import { matchesTracking } from "./table-rules.js";

const LATE_MINUTES = 10;
const UNDO_SECONDS = 5;
const STATUS_LABELS = { new: "Nouvelle", prep: "En préparation", ready: "Prête" };

const $ = (id) => document.getElementById(id);

export class TillTracking {
  /**
   * @param {{onOpen: () => void, onClose: () => void, onFatal: (error: ApiError) => void, lane?: Lane}} handlers
   *   lane : partagée avec le menu de la caisse, jamais deux requêtes à la fois
   */
  constructor({ onOpen, onClose, onFatal, lane = new Lane() }) {
    this.orders = [];
    this.clockOffset = 0;
    this.loaded = false;
    this.pending = new Map();
    this.notices = new Map();
    /** Service à table : filtre « À table » proposé, et activé ou non. */
    this.tableService = false;
    this.tableOnly = false;
    this.handlers = { onClose, onFatal };

    this.poller = new Poller("/api/till/orders", {
      kind: "tracking",
      lane,
      onData: (data) => this.receive(data),
      onSuccess: ({ clockOffset }) => {
        // Temps d'attente sur l'heure du serveur (en-tête Date), pas sur celle de la tablette
        if (clockOffset !== null) {
          this.clockOffset = clockOffset;
        }
        $("track-offline").hidden = true;
      },
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
    $("track-table-filter").addEventListener("click", () => {
      this.tableOnly = !this.tableOnly;
      this.render();
    });
    // Temps d'attente à jour même quand rien ne change
    setInterval(() => this.render(), 15000);
  }

  /** Pas de suivi pour un événement sans cuisine : tout est servi au comptoir. */
  setEnabled(enabled) {
    if (enabled === this.poller.running) {
      return;
    }
    $("till-track").hidden = !enabled;
    if (enabled) {
      this.poller.start();
      return;
    }
    this.poller.stop();
    if (!$("till-track-view").hidden) {
      this.handlers.onClose();
    }
  }

  /** Filtre « À table » : proposé avec le service à table (Loto). */
  setTableService(enabled) {
    this.tableService = enabled;
    if (!enabled) {
      this.tableOnly = false;
    }
    this.render();
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
    // Commandes à table encore en cours : le filtre reste proposé si le réglage a été décoché
    const filter = $("track-table-filter");
    filter.hidden = !this.tableService && !this.orders.some((o) => o.tableNumber != null);
    filter.setAttribute("aria-pressed", this.tableOnly && !filter.hidden ? "true" : "false");
    const tableOnly = this.tableOnly && !filter.hidden;
    const visible = this.orders.filter((o) => matchesTracking(o, { search, tableOnly }));
    // Prêtes d'abord (celle qui attend depuis le plus longtemps en tête), puis les autres de la plus ancienne à la plus récente
    const ready = visible.filter((o) => o.status === "ready").sort((a, b) => a.updatedAt.localeCompare(b.updatedAt));
    const kitchen = visible.filter((o) => o.status !== "ready");

    $("track-list").replaceChildren(...[...ready, ...kitchen].map((o) => this.row(o)));
    $("track-none").hidden = visible.length > 0;
    $("track-none").textContent = search
      ? `Aucune commande en cours ne correspond à « ${search} »${tableOnly ? " parmi celles à table" : ""}.`
      : tableOnly ? "Aucune commande à table en cours." : "Aucune commande en cours.";
  }

  row(order) {
    const ready = order.status === "ready";
    const minutes = this.minutesSince(ready ? order.updatedAt : order.createdAt);
    const late = !ready && minutes >= LATE_MINUTES;
    const notice = this.notices.get(order.uuid);

    return el("li", { class: ["track-row", order.status, late ? "late" : ""].filter(Boolean).join(" ") }, [
      el("span", { class: "track-number", text: order.displayNumber }),
      el("span", { class: `pill ${order.status}`, text: STATUS_LABELS[order.status] }),
      el("span", { class: "track-time num", text: ready ? `depuis ${minutes} min` : `${minutes} min` }),
      late ? el("span", { class: "track-late", text: "En retard" }) : null,
      order.tableNumber != null ? el("span", { class: "tag tag-table", text: `Table ${order.tableNumber}` }) : null,
      order.mine ? el("span", { class: "tag", text: "Cette caisse" }) : null,
      ready ? this.serveAction(order) : null,
      notice ? el("span", { class: "track-error", attrs: { role: "status" }, text: notice }) : null,
    ]);
  }

  serveAction(order) {
    const entry = this.pending.get(order.uuid);
    if (!entry) {
      return el("button", {
        class: "btn btn-small act-served",
        text: "Remise au client",
        attrs: { type: "button", "aria-label": `Remise au client : commande ${order.displayNumber}` },
        on: { click: () => this.press(order) },
      });
    }

    const message = entry.sending ? "Envoi…" : entry.error ?? this.countdownText(entry);
    return el("span", { class: "track-actions" }, [
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
  }
}
