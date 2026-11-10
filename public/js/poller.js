// Interrogation régulière d'une route de l'API : cuisine, Arrivées, suivi et menu de la caisse.
// Le rythme et la reprise après erreur sont décidés par poll-rules.js (fonctions pures, testées).
//
// - Une seule requête à la fois par écran (Lane), abandonnée au bout de 8 s.
// - Écran masqué : aucune requête ; une tout de suite à son retour.
// - Réseau revenu (événement online) : une requête tout de suite.
// - L'empreinte (ETag) est renvoyée : tant que rien n'a changé, le serveur répond 304, presque rien.

import { apiPoll, ApiError } from "./api.js";
import { clockOffset, isFatal, pollDecision, scheduleDelay } from "./poll-rules.js";

/** File des requêtes d'un même écran : la caisse a deux interrogations (suivi et menu), jamais en même temps. */
export class Lane {
  constructor() {
    this.tail = Promise.resolve();
  }

  /** @template T @param {() => Promise<T>} task @returns {Promise<T>} */
  run(task) {
    const result = this.tail.then(task, task);
    this.tail = result.catch(() => {});
    return result;
  }
}

export class Poller {
  /**
   * @param {string} path
   * @param {{
   *   kind: "kitchen"|"board"|"tracking"|"menu",
   *   lane?: Lane,
   *   blocked?: () => boolean,
   *   onData: (data: object, info: {forced: boolean}) => boolean|void,
   *   onSuccess?: (info: {clockOffset: number|null}) => void,
   *   onError?: (error: ApiError, failures: number) => void,
   *   onFatal?: (error: ApiError) => void,
   * }} options
   *   blocked : vrai quand il ne faut pas interroger maintenant (menu pendant un paiement) ;
   *   onData : renvoie false pour refuser le contenu reçu (redemandé dès que possible) ;
   *   onFatal : tablette refusée (révoquée, expirée), l'interrogation s'arrête
   */
  constructor(path, { kind, lane = new Lane(), blocked = () => false, onData, onSuccess = () => {}, onError = () => {}, onFatal = () => {} }) {
    this.path = path;
    this.kind = kind;
    this.lane = lane;
    this.blocked = blocked;
    this.handlers = { onData, onSuccess, onError, onFatal };
    this.etag = null;
    this.failures = 0;
    this.timer = null;
    this.running = false;
    this.busy = false;
    /** Relecture demandée pendant une autre, ou pendant un blocage : faite dès que possible. */
    this.again = false;
    this.lastStartAt = 0;
    this.lastChangeAt = -Infinity;

    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        clearTimeout(this.timer); // Rien tant que l'écran est masqué
      } else {
        this.poll();
      }
    });
    window.addEventListener("online", () => this.poll());
  }

  /**
   * @param {{immediate?: boolean, etag?: string|null}} options
   *   etag : empreinte d'un contenu déjà reçu (menu chargé au démarrage de la caisse)
   */
  start({ immediate = true, etag = null } = {}) {
    this.running = true;
    if (etag) {
      this.etag = etag;
    }
    if (immediate) {
      this.poll();
    } else {
      this.lastStartAt = Date.now();
      this.schedule();
    }
  }

  stop() {
    this.running = false;
    clearTimeout(this.timer);
  }

  /** Changement fait par cette tablette (cuisine) : rythme accéléré, comme pour un changement reçu. */
  noteChange() {
    this.lastChangeAt = Date.now();
  }

  /**
   * Interroge tout de suite (après une action, au retour de l'écran, à la fin d'un paiement…).
   * forced : même quand c'est bloqué (menu à relire après une vente ou un refus du serveur).
   */
  async poll({ forced = false } = {}) {
    const decision = pollDecision({ running: this.running, hidden: document.hidden, busy: this.busy, blocked: !forced && this.blocked() });
    if (decision === "busy" || decision === "blocked") {
      this.again = true; // Refaite juste après, pour ne rien manquer
      return;
    }
    if (decision !== "go") {
      return; // Arrêtée, ou écran masqué : reprise au retour de l'écran
    }
    clearTimeout(this.timer);

    this.busy = true;
    // Relecture forcée pendant un blocage : celle retenue reste à faire après
    this.again = forced && this.blocked() ? this.again : false;
    try {
      const result = await this.lane.run(() => {
        this.lastStartAt = Date.now();
        return apiPoll(this.path, this.etag);
      });
      this.failures = 0;
      if (result.data) {
        if (this.handlers.onData(result.data, { forced }) === false) {
          this.again = true; // Refusé (paiement commencé entre-temps) : relu après
        } else {
          if (this.etag !== null && result.etag !== this.etag) {
            this.lastChangeAt = Date.now();
          }
          this.etag = result.etag;
        }
      }
      this.handlers.onSuccess({ clockOffset: clockOffset(result.date, Date.now()) });
    } catch (error) {
      if (error instanceof ApiError && isFatal(error.status)) {
        this.busy = false;
        this.stop();
        this.handlers.onFatal(error);
        return;
      }
      this.failures += 1;
      this.handlers.onError(error, this.failures);
    } finally {
      this.busy = false;
    }

    if (this.again && !this.blocked()) {
      this.poll();
    } else {
      this.schedule();
    }
  }

  /** Relit si une interrogation a été retenue (fin d'un paiement). */
  pollIfDue() {
    if (this.again) {
      this.poll();
    }
  }

  schedule() {
    clearTimeout(this.timer);
    if (!this.running || document.hidden) {
      return;
    }
    const now = Date.now();
    const delay = scheduleDelay({
      kind: this.kind,
      failures: this.failures,
      sinceChangeMs: now - this.lastChangeAt,
      elapsedSinceStartMs: now - this.lastStartAt,
    });
    this.timer = setTimeout(() => this.poll(), delay);
  }
}
