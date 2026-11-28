// Page Menu : onglets de catégories. Une seule catégorie affichée à la fois (ou « Tout »).
// Les lignes cachées restent dans le formulaire : changer d'onglet ne perd aucune saisie,
// et « Enregistrer le menu » envoie toujours tout le tableau.
// Sans JavaScript, toutes les catégories s'affichent et les onglets sont de simples liens.

const tabs = [...document.querySelectorAll('[data-menu-tab]')];
const panels = [...document.querySelectorAll('[data-menu-panel]')];

function show(key) {
    for (const panel of panels) {
        panel.hidden = key !== 'all' && panel.id !== key;
    }
    for (const tab of tabs) {
        tab.setAttribute('aria-current', String(tab.dataset.menuTab === key));
    }
}

// Onglet à ouvrir : celui de l'adresse (#categorie-2), celui de la ligne en erreur ou
// de l'article qu'on vient de déplacer (#produit-12), sinon la première catégorie.
function initialKey() {
    const invalid = document.querySelector('[data-menu-panel] .row-invalid');
    if (invalid) {
        return invalid.closest('[data-menu-panel]').id;
    }
    const hash = decodeURIComponent(location.hash.slice(1));
    if (hash === 'categories') {
        return 'all';
    }
    const target = hash ? document.getElementById(hash) : null;
    const panel = target?.closest('[data-menu-panel]');
    if (panel) {
        return panel.id;
    }

    return panels[0].id;
}

if (tabs.length > 0 && panels.length > 0) {
    for (const tab of tabs) {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            show(tab.dataset.menuTab);
            // Retrouvé au rechargement, sans faire défiler la page
            history.replaceState(null, '', tab.getAttribute('href'));
        });
    }

    show(initialKey());
    // La ligne visée (article déplacé, erreur) n'était peut-être pas visible au chargement
    const row = document.querySelector('[data-menu-panel] .row-invalid') ?? (location.hash.startsWith('#produit-') ? document.getElementById(location.hash.slice(1)) : null);
    row?.scrollIntoView({ block: 'center' });
}
