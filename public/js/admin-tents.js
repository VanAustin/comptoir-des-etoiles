// Chevalets de table : bouton « Imprimer les chevalets » (fenêtre d'impression du navigateur).
// Sans JavaScript, le bouton reste masqué : Ctrl+P ou Cmd+P fait la même chose.

const button = document.getElementById("tents-print");

if (button) {
  button.addEventListener("click", () => window.print());
  button.hidden = false;
}
