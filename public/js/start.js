// Page /demarrer, ouverte par l'application installée : chaque tablette va vers l'écran de son rôle.

import { getPairing } from "./device-store.js";

const links = document.getElementById("start").dataset;
const screens = { caisse: links.tillUrl, cuisine: links.kitchenUrl, affichage: links.arrivalsUrl };

let target = links.pairUrl;
try {
  const role = (await getPairing())?.device?.role;
  target = screens[role] ?? target;
} catch {
  // Stockage indisponible : la page d'appairage l'explique
}
window.location.replace(target);
