// File d'attente de la caisse : les commandes encaissées sans réseau sont gardées dans la
// tablette (IndexedDB) et envoyées dans l'ordre dès que le serveur répond. Même UUID à chaque
// envoi : le serveur ne crée jamais de doublon. Une commande n'est jamais effacée sans accord :
// un refus du serveur la met « À vérifier », avec tout son détail pour la ressaisir.

// Décisions (quand renvoyer, quoi mettre « À vérifier ») : outbox-rules.js.

import { apiFetch, ApiError } from "./api.js";
import { deleteOutboxEntry, getOutbox, saveOutboxEntry } from "./device-store.js";
import { isOffline, otherOwnerMessage, refusalMessage, retryDelay, sameOwner, sendFailure, SEND_TIMEOUT_MS } from "./outbox-rules.js";

export class TillOutbox {
  /** @param {{onChange: () => void}} handlers appelé à chaque changement de la file ou de la connexion */
  constructor({ onChange }) {
    this.entries = [];
    this.onChange = onChange;
    this.flushing = false;
    this.failures = 0;
    this.lastFailureAt = 0;
    /** Le serveur a répondu depuis l'ouverture de la caisse. */
    this.reachedServer = false;
    this.timer = null;
    /** Caisse qui a encaissé : une commande n'est envoyée qu'avec le même appairage. */
    this.owner = null;

    // Réseau revenu : envoi tout de suite, sans attendre le prochain essai
    window.addEventListener("online", () => this.flush());
  }

  async start(owner) {
    this.owner = owner;
    try {
      this.entries = await getOutbox();
    } catch {
      this.entries = [];
    }
    this.onChange();
    this.flush();
  }

  get pending() {
    return this.entries.filter((entry) => entry.status === "pending");
  }

  get blocked() {
    return this.entries.filter((entry) => entry.status === "blocked");
  }

  /** Le dernier envoi a échoué il y a peu : inutile de faire attendre le caissier. */
  get offline() {
    return isOffline({ failures: this.failures, lastFailureAt: this.lastFailureAt, now: Date.now() });
  }

  /** Envoi direct réussi : le réseau est revenu. */
  noteSuccess() {
    this.failures = 0;
    this.reachedServer = true;
    this.onChange();
    this.flush();
  }

  /** Envoi direct d'une commande sans réponse : les suivantes iront directement dans la file. */
  noteFailure() {
    this.failures += 1;
    this.lastFailureAt = Date.now();
    this.onChange();
  }

  /**
   * Garde une commande dans la tablette. Rejette si le stockage échoue : la caisse
   * doit alors le dire, la commande n'est pas enregistrée.
   */
  async add(entry) {
    const saved = { ...entry, owner: this.owner, status: "pending", error: null, savedAt: new Date().toISOString() };
    await saveOutboxEntry(saved);
    this.entries.push(saved);
    this.onChange();
    this.schedule(retryDelay(this.failures));
  }

  schedule(delay) {
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.flush(), delay);
  }

  /** Envoie les commandes en attente, de la plus ancienne à la plus récente, une à la fois. */
  async flush() {
    if (this.flushing || this.pending.length === 0) {
      return;
    }
    clearTimeout(this.timer);
    this.flushing = true;
    try {
      for (const entry of this.pending) {
        if (!(await this.send(entry))) {
          break;
        }
      }
    } finally {
      this.flushing = false;
      this.onChange();
      if (this.pending.length > 0) {
        // Réseau toujours absent : 3 s, 6 s, 12 s, puis toutes les 30 s
        this.schedule(retryDelay(this.failures));
      }
    }
  }

  /** @returns {Promise<boolean>} true si l'on peut passer à la commande suivante */
  async send(entry) {
    if (!sameOwner(entry.owner, this.owner)) {
      await this.block(entry, otherOwnerMessage(entry));
      return true;
    }
    try {
      await apiFetch("/api/orders", { method: "POST", body: entry.body, timeout: SEND_TIMEOUT_MS });
    } catch (error) {
      const decision = sendFailure(error instanceof ApiError ? error.status : 0);
      if (decision === "retry") {
        // Pas de réponse utile : on réessaiera, rien n'est perdu
        this.failures += 1;
        this.lastFailureAt = Date.now();
        this.onChange();
        return false;
      }
      this.reachedServer = true;
      // Refus du serveur : la commande reste dans la tablette, à vérifier
      await this.block(entry, refusalMessage(entry, error));
      return decision === "block";
    }
    this.failures = 0;
    this.reachedServer = true;
    await deleteOutboxEntry(entry.uuid);
    this.entries = this.entries.filter((e) => e.uuid !== entry.uuid);
    return true;
  }

  async block(entry, message) {
    entry.status = "blocked";
    entry.error = message;
    await saveOutboxEntry(entry);
  }

  /** Nouvel essai d'une commande « À vérifier » (après un nouvel appairage refait, par exemple). */
  async retry(uuid) {
    const entry = this.entries.find((e) => e.uuid === uuid);
    if (entry) {
      entry.status = "pending";
      entry.error = null;
      // L'appairage d'origine reste exigé : la lettre du numéro donné au client ne doit pas changer
      await saveOutboxEntry(entry);
      this.onChange();
      this.flush();
    }
  }

  /** Retire une commande « À vérifier » après sa ressaisie : seulement sur demande explicite. */
  async discard(uuid) {
    await deleteOutboxEntry(uuid);
    this.entries = this.entries.filter((e) => e.uuid !== uuid);
    this.onChange();
  }
}
