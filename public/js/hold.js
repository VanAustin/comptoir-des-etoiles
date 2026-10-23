// Boutons à maintenir 2 secondes (« Tablette ») : quitter un écran de service par erreur
// ferait perdre la commande en cours. Au doigt, à la souris, ou au clavier (Entrée ou
// Espace maintenus). Une jauge se remplit pendant l'appui ; un appui court l'explique.

const HOLD_MS = 2000;
const HINT_MS = 2500;

for (const button of document.querySelectorAll("[data-hold-href]")) {
  const label = button.textContent;
  let timer = null;
  let hintTimer = null;

  const showHint = () => {
    button.textContent = "Maintenir 2 s";
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => { button.textContent = label; }, HINT_MS);
  };
  const start = () => {
    if (timer) {
      return;
    }
    clearTimeout(hintTimer);
    button.textContent = label;
    button.classList.add("holding");
    timer = setTimeout(() => { window.location.href = button.dataset.holdHref; }, HOLD_MS);
  };
  const stop = (tooShort) => {
    if (!timer) {
      return;
    }
    clearTimeout(timer);
    timer = null;
    button.classList.remove("holding");
    if (tooShort) {
      showHint();
    }
  };

  button.addEventListener("pointerdown", (event) => {
    if (event.button === 0) {
      start();
    }
  });
  button.addEventListener("pointerup", () => stop(true));
  button.addEventListener("pointerleave", () => stop(false));
  button.addEventListener("pointercancel", () => stop(false));
  // Pas de menu contextuel d'Android sur un appui long
  button.addEventListener("contextmenu", (event) => event.preventDefault());
  button.addEventListener("keydown", (event) => {
    if ((event.key === "Enter" || event.key === " ") && !event.repeat) {
      event.preventDefault();
      start();
    }
  });
  button.addEventListener("keyup", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      stop(true);
    }
  });
  button.addEventListener("blur", () => stop(false));
}
