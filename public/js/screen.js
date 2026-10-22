// Outils des écrans posés en continu (cuisine, Arrivées) : écran toujours allumé et signaux sonores.
// Les sons sont produits par le navigateur (Web Audio), sans fichier.

import { getPreference, savePreference } from "./device-store.js";

/** Empêche la mise en veille tant que la page est affichée (Chrome, en HTTPS ou sur localhost). */
export function keepScreenOn() {
  if (!("wakeLock" in navigator)) {
    return;
  }
  const request = () => navigator.wakeLock.request("screen").catch(() => {});
  request();
  // Le verrou est relâché quand la page est cachée : on le reprend au retour
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      request();
    }
  });
}

/** Notes jouées pour chaque signal : [fréquence en Hz, début en s, durée en s]. */
const SIGNALS = {
  // Nouvelle commande en cuisine : deux bips brefs
  bip: [[880, 0, 0.12], [880, 0.18, 0.12]],
  // Commande prête sur l'écran Arrivées : carillon montant
  carillon: [[659, 0, 0.35], [784, 0.2, 0.35], [988, 0.4, 0.6]],
};

export class Sound {
  /** @param {string} preference nom du réglage enregistré sur la tablette */
  constructor(preference) {
    this.preference = preference;
    this.enabled = true;
    this.context = null;
  }

  async load() {
    this.enabled = await getPreference(this.preference, true).catch(() => true);
    return this.enabled;
  }

  async toggle() {
    this.enabled = !this.enabled;
    await savePreference(this.preference, this.enabled).catch(() => {});
    if (this.enabled) {
      this.unlock();
    }
    return this.enabled;
  }

  /** Chrome n'autorise le son qu'après un premier toucher sur la page. */
  get unlocked() {
    return this.context?.state === "running";
  }

  unlock() {
    const AudioContext = window.AudioContext ?? window.webkitAudioContext;
    if (!AudioContext) {
      return;
    }
    this.context ??= new AudioContext();
    this.context.resume().catch(() => {});
  }

  play(name) {
    if (!this.enabled || !this.unlocked) {
      return;
    }
    const now = this.context.currentTime;
    for (const [frequency, start, duration] of SIGNALS[name]) {
      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      oscillator.type = "sine";
      oscillator.frequency.value = frequency;
      // Attaque et extinction douces : pas de claquement
      gain.gain.setValueAtTime(0.0001, now + start);
      gain.gain.exponentialRampToValueAtTime(0.3, now + start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + start + duration);
      oscillator.connect(gain).connect(this.context.destination);
      oscillator.start(now + start);
      oscillator.stop(now + start + duration + 0.05);
    }
  }
}
