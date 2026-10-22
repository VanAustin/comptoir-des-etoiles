// Interrogation régulière d'une route de l'API (cuisine, Arrivées) :
// pause quand l'écran est caché, essais espacés quand le réseau tombe.

import { apiPoll, ApiError } from "./api.js";

const MAX_DELAY_MS = 30000;

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
  constructor(path, { interval = 2500, onData, onSuccess = () => {}, onError = () => {}, onFatal = () => {} }) {
    this.path = path;
    this.interval = interval;
    this.handlers = { onData, onSuccess, onError, onFatal };
    this.etag = null;
    this.failures = 0;
    this.timer = null;
    this.running = false;
    this.busy = false;

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
  }

  /** Interroge tout de suite (après une action, au retour de l'écran). */
  async poll() {
    clearTimeout(this.timer);
    if (!this.running || this.busy) {
      return;
    }
    if (document.hidden) {
      return; // Reprise au retour de l'écran (visibilitychange)
    }

    this.busy = true;
    try {
      const result = await apiPoll(this.path, this.etag);
      this.failures = 0;
      if (result) {
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

    if (this.running) {
      // Après une erreur : 5 s, 10 s, 20 s, puis 30 s au plus
      const delay = this.failures === 0 ? this.interval : Math.min(MAX_DELAY_MS, this.interval * 2 ** this.failures);
      this.timer = setTimeout(() => this.poll(), delay);
    }
  }
}
