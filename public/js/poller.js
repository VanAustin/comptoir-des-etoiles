// Interrogation régulière d'une route de l'API (cuisine, Arrivées, suivi de la caisse) :
// pause quand l'écran est caché, essais espacés quand le réseau tombe.
//
// Si le serveur a un hub Mercure (en-tête Link), la tablette s'y abonne : chaque message
// (« une commande a changé », sans données) et chaque reconnexion déclenchent une relecture
// de la liste complète. L'interrogation ne sert plus alors que de contrôle, toutes les 30 s.

import { apiPoll, ApiError } from "./api.js";

const MAX_DELAY_MS = 30000;
const REALTIME_CHECK_MS = 30000;

export class Poller {
  /**
   * @param {string} path
   * @param {{
   *   interval?: number,
   *   onData: (data: object) => void,
   *   onSuccess?: () => void,
   *   onError?: (error: ApiError, failures: number) => void,
   *   onFatal?: (error: ApiError) => void,
   * }} handlers onFatal : tablette refusée (révoquée, expirée), l'interrogation s'arrête
   */
  constructor(path, { interval = 5000, onData, onSuccess = () => {}, onError = () => {}, onFatal = () => {} }) {
    this.path = path;
    this.interval = interval;
    this.handlers = { onData, onSuccess, onError, onFatal };
    this.etag = null;
    this.failures = 0;
    this.timer = null;
    this.running = false;
    this.busy = false;
    /** Relecture demandée pendant une autre : refaite juste après, pour ne rien manquer. */
    this.again = false;
    /** Abonnement Mercure en cours, et son adresse. */
    this.source = null;
    this.sourceUrl = null;

    document.addEventListener("visibilitychange", () => {
      if (this.running && !document.hidden) {
        this.poll();
      }
    });
  }

  start() {
    this.running = true;
    this.poll();
  }

  stop() {
    this.running = false;
    clearTimeout(this.timer);
    this.subscribe(null);
  }

  /** Interroge tout de suite (après une action, au retour de l'écran, message du hub). */
  async poll() {
    clearTimeout(this.timer);
    if (!this.running) {
      return;
    }
    if (this.busy) {
      this.again = true;
      return;
    }
    if (document.hidden) {
      return; // Reprise au retour de l'écran (visibilitychange)
    }

    this.busy = true;
    this.again = false;
    try {
      const result = await apiPoll(this.path, this.etag);
      this.failures = 0;
      this.subscribe(result.realtime);
      if (result.data) {
        this.etag = result.etag;
        this.handlers.onData(result.data);
      }
      this.handlers.onSuccess();
    } catch (error) {
      if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
        this.stop();
        this.handlers.onFatal(error);
        return;
      }
      this.failures += 1;
      this.handlers.onError(error, this.failures);
    } finally {
      this.busy = false;
    }

    if (this.again) {
      this.poll();
    } else if (this.running) {
      this.timer = setTimeout(() => this.poll(), this.nextDelay());
    }
  }

  nextDelay() {
    // Hub connu : simple contrôle, même s'il est injoignable (il se reconnecte seul)
    if (this.sourceUrl) {
      return REALTIME_CHECK_MS;
    }
    // Sans hub : toutes les 5 s (suivi de la caisse : 10 s) ; après une erreur, 10 s, 20 s, puis 30 s au plus
    return this.failures === 0 ? this.interval : Math.min(MAX_DELAY_MS, this.interval * 2 ** this.failures);
  }

  /** @param {{hub: string, topic: string}|null} realtime */
  subscribe(realtime) {
    let url = null;
    if (realtime && typeof EventSource !== "undefined") {
      const hub = new URL(realtime.hub, window.location.href);
      hub.searchParams.append("match", realtime.topic);
      url = hub.toString();
    }
    // Abonnement déjà ouvert, ou en reconnexion automatique : rien à faire
    if (url === this.sourceUrl && (this.source || !url)) {
      return;
    }

    this.source?.close();
    this.source = null;
    this.sourceUrl = url;
    if (!url) {
      return;
    }

    const source = new EventSource(url);
    // Ouverture et chaque reconnexion après une coupure : relecture complète, rien n'est perdu
    source.addEventListener("open", () => this.poll());
    source.addEventListener("message", () => this.poll());
    source.addEventListener("error", () => {
      // Fermé pour de bon (hub en erreur) : nouvel essai au prochain contrôle
      if (source.readyState === EventSource.CLOSED && this.source === source) {
        this.source = null;
      }
    });
    this.source = source;
  }
}
