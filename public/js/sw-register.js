// Enregistre le service worker (application utilisable hors ligne) et prévient
// quand une nouvelle version est installée.

import { el } from "./dom.js";

if ("serviceWorker" in navigator) {
  const base = document.documentElement.dataset.basePath ?? "";
  // Premier passage : pas de bandeau, la page vient d'être chargée depuis le serveur
  const hadController = Boolean(navigator.serviceWorker.controller);

  navigator.serviceWorker.register(`${base}/sw.js`, { scope: `${base}/` }).catch(() => {});

  navigator.serviceWorker.addEventListener("controllerchange", () => {
    if (!hadController || document.getElementById("update-banner")) {
      return;
    }
    // Pas de rechargement automatique : il ferait perdre une commande en cours de saisie
    document.body.append(el("div", { class: "update-banner", attrs: { id: "update-banner", role: "status" } }, [
      el("span", { text: "Nouvelle version de l'application : rechargez entre deux commandes." }),
      el("button", { class: "btn btn-small", text: "Recharger", attrs: { type: "button" }, on: { click: () => window.location.reload() } }),
    ]));
  });
}

// Appairage et commandes en attente : Chrome ne doit pas les effacer pour libérer de la place
navigator.storage?.persist?.().catch(() => {});
