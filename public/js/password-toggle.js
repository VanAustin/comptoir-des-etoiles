// Bouton en forme d'œil des champs mot de passe (attribut data-password-toggle) :
// œil ouvert pour afficher, œil barré pour masquer (feuille de style, selon aria-pressed).
// Le nom du bouton ne change pas ; aria-pressed dit s'il est enfoncé (lecteurs d'écran).
// Le bouton est dans le HTML, masqué : sans JavaScript, il ne s'affiche pas.
// À l'envoi du formulaire, le champ redevient masqué.

for (const input of document.querySelectorAll("input[data-password-toggle]")) {
  const button = document.querySelector(`button[aria-controls="${input.id}"]`);
  if (!button) {
    continue;
  }

  const show = (visible) => {
    input.type = visible ? "text" : "password";
    button.setAttribute("aria-pressed", visible ? "true" : "false");
    button.title = visible ? "Masquer le mot de passe" : "Afficher le mot de passe";
  };

  button.addEventListener("click", () => {
    show(input.type === "password");
    input.focus();
  });
  input.form?.addEventListener("submit", () => show(false));

  show(false);
  button.hidden = false;
}
