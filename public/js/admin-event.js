// Fiche d'un événement : « Service à table » n'est cochable qu'avec « Avec cuisine ».
// Le serveur applique la même règle ; sans JavaScript, la case suit l'état enregistré.

const kitchen = document.getElementById("with-kitchen");
const table = document.getElementById("table-service");

if (kitchen && table) {
  const sync = () => {
    // « Avec cuisine » verrouillée (l'événement a des commandes) : son état enregistré fait foi
    table.disabled = !kitchen.checked;
    if (!kitchen.checked) {
      table.checked = false;
    }
  };
  kitchen.addEventListener("change", sync);
  sync();
}
