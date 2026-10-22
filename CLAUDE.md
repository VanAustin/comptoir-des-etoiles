# Le Comptoir des étoiles

Application de caisse pour l'APE (Association des Parents d'Élèves) de l'école Antoine de St Exupéry, à Uzein. Elle relie caisse, cuisine et écran d'appel client pendant les événements (Halloween, Loto, Carnaval, Vide-grenier, Kermesse), suit les stocks et produit un bilan pour ajuster les courses suivantes.

Premier événement réel : **Loto du 18 décembre 2026**. Répétition générale avec des bénévoles début décembre.

## Contraintes du projet (non négociables)

- **Budget : 0 €.** Aucun service payant, aucun matériel à acheter.
- **Léger et maintenable, avec le moins de dépendances possible.** Le projet sera repris par des parents bénévoles. Ne jamais ajouter de dépendance Composer ou JavaScript sans le demander d'abord.
- **Tablettes Android, navigateur Chrome.** L'application est installable (PWA) depuis le navigateur.
- **Réseau incertain.** Le jour J, les tablettes se connectent soit à un hébergement en ligne via le partage 4G d'un téléphone, soit à un portable qui fait tourner Symfony sur le réseau local du partage de connexion. Le même code doit fonctionner dans les deux cas.
- **Aucune commande ne doit être perdue**, même pendant une coupure réseau.

## Stack

Dépendances de production autorisées, et rien d'autre :

- `symfony/skeleton` (Symfony 7.4 LTS, PHP 8.2+)
- `symfony/twig-bundle`
- `doctrine/doctrine-bundle`, `doctrine/orm`, `doctrine/doctrine-migrations-bundle`
- `symfony/security-bundle` et `symfony/rate-limiter` : seule exception à la règle du minimum, réservée à l'authentification de l'administration (hachage du mot de passe, protection CSRF, limitation des tentatives, protection de session). Pour un projet qui pourrait devenir public, un composant audité vaut mieux qu'une authentification écrite à la main.
- Base de données : **SQLite** (un seul fichier, facile à sauvegarder et à déplacer)

Développement uniquement : `symfony/maker-bundle`, PHPUnit.

Explicitement exclus : EasyAdmin, Symfony Form, Stimulus, AssetMapper, Webpack Encore, Node, tout framework CSS ou JS.

## Environnement de développement : Docker

PHP, Composer et Symfony ne sont **pas** installés sur le Mac : tout tourne dans Docker.

- Une seule image, basée sur l'image officielle **`php:8.3-apache`**, avec les extensions `pdo_sqlite` (déjà incluse), `intl` et `opcache`, et Composer copié depuis l'image officielle `composer:2`. FrankenPHP a été écarté : trop de soucis à la mise en place.
- Apache sert `public/` comme racine, avec `FallbackResource /index.php` dans la configuration du site (fichier copié par le `Dockerfile`). **Pas** de `symfony/apache-pack`.
- Le site est servi sur **`http://localhost:8080`**. Pas de HTTPS en développement : Chrome autorise le service worker sur `localhost`, ce qui suffit pour développer et tester le mode hors ligne sur le Mac.
- Fichiers attendus, et rien de plus : `Dockerfile`, `compose.yaml`, et le fichier de configuration Apache du site (par exemple `docker/apache.conf`). Pas de MySQL, pas de Redis, pas de Node.
- Seul service en plus de `php` : **Adminer** (image officielle `adminer`, version fixée), pour consulter la base SQLite comme dans phpMyAdmin, sur `http://localhost:8082`. Développement uniquement, lié à `127.0.0.1`, mot de passe `comptoir` (exigé par Adminer pour SQLite) ; sa configuration tient dans `docker/adminer/login.php`. Rien dans l'application n'en dépend.
- La base SQLite reste dans `var/`, monté en volume : elle survit au redémarrage des conteneurs.
- **Toutes** les commandes PHP passent par le conteneur, jamais par le PHP du Mac :
  - `docker compose exec php composer …`
  - `docker compose exec php bin/console …`
  - `docker compose exec php bin/phpunit`
- Le nom du service dans `compose.yaml` est `php`.
- Le HTTPS pour les tablettes (répétition générale, mode portable) sera traité à l'étape 2, une fois l'application en place. Ne rien prévoir de plus pour l'instant.
- En production sur un hébergement mutualisé (sans Docker), le même code tourne avec le PHP de l'hébergeur : ne rien faire qui dépende de Docker dans le code de l'application.
- Apache ne transmet pas l'en-tête `Authorization` à PHP par défaut. `docker/apache.conf` le fait avec `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` ; sur un hébergement Apache, prévoir la même règle (fichier `.htaccess` dans `public/`), sinon aucune tablette ne peut s'authentifier. À documenter dans `README.md` avec l'installation.

## Architecture

- **Pages** : Twig, HTML simple.
- **JavaScript** : fichiers vanilla dans `public/js/`, sans build. Modules ES natifs.
- **CSS** : fichiers simples dans `public/css/`, avec `tokens.css` chargé en premier (voir Charte).
- **Polices** : fichiers locaux dans `public/fonts/`, jamais depuis Google Fonts (doit marcher hors ligne).
- **Administration** (événements, menu, prix, stocks, tablettes) : pages Twig avec formulaires HTML traités dans les contrôleurs. Protégée par Symfony Security (voir Sécurité). Pas de comptes utilisateurs pour les bénévoles.
- **API JSON** pour les tablettes, sous `/api`, accessible uniquement aux tablettes appairées (voir Sécurité).
- **Hors ligne** : service worker qui met en cache l'application ; file d'attente des commandes dans IndexedDB, envoyée au retour du réseau.
- **Idempotence** : chaque commande reçoit un UUID créé sur la tablette. `POST /api/orders` avec un UUID déjà connu ne crée pas de doublon et renvoie la commande existante.
- **Temps réel** : l'écran cuisine et l'écran Arrivées interrogent le serveur toutes les 2 à 3 secondes (`GET /api/orders?since=<horodatage>`). Pas de Mercure pour l'instant.
- **Stocks** : le stock restant est calculé à partir des ventes (stock de départ moins quantités vendues des commandes non annulées), jamais stocké comme un compteur décrémenté. Le stock est une **information, jamais une limite** : ni le serveur ni la caisse ne bloquent une vente (stocks de départ approximatifs, ventes hors ligne), et il peut devenir négatif. Une formule décompte les composants figés sur la ligne au moment de la vente ; son stock restant est le nombre de formules encore possibles avec ses composants comptés (stock de chaque composant divisé par sa quantité, le plus petit l'emporte).

## Modèle de données (point de départ)

- `Event` : nom, date, actif ou non, montant de la consigne (centimes, 0 = pas de consigne).
- `Product` : événement, nom, icône (clé du catalogue d'icônes, voir Charte), catégorie, prix (centimes, entier), stock de départ (nullable = non compté), passe en cuisine (bool), avec consigne (bool), ordre d'affichage, composants si formule.
- `ProductComponent` : article inclus dans une formule, avec sa quantité (« 2 crêpes »). Une formule ne contient que des articles simples, jamais une autre formule.
- `Order` : UUID (identifiant venu de la tablette), événement, lettre de caisse et numéro (voir Numérotation), moyen de paiement (espèces ou carte), montant reçu, total recalculé par le serveur, état, créé le, modifié le, tablette d'origine.
- `OrderLine` : commande, produit, type (article, consigne, retour de consigne), libellé, prix et passage en cuisine figés au moment de la vente, quantité, composants figés si formule (un identifiant d'article par unité : « 2 crêpes » donne deux fois l'identifiant de la crêpe).
- `Device` : tablette appairée. Nom (« Caisse buvette »), rôle (`caisse`, `cuisine`, `affichage`), lettre de caisse (rôle `caisse` uniquement), empreinte SHA-256 du jeton, empreinte et expiration du code d'appairage, événement, créée le, vue pour la dernière fois le, expire le, révoquée le.
- `AuditLog` : qui (administrateur ou tablette), quoi (annulation, changement de prix ou de stock, appairage, révocation), quand, valeurs avant et après.
- `AdminUser` : identifiant, mot de passe haché. Créé uniquement par la commande d'installation.

Montants toujours en centimes (entiers) côté serveur.

### Consigne (écocups, gobelets)

- **Un seul montant par événement**, réglé dans la fiche de l'événement (0 = pas de consigne).
- Les articles cochés « avec consigne » ajoutent **automatiquement** une ligne « Consigne » par unité vendue. Pour une formule, c'est la case de la formule elle-même qui compte.
- La caisse a un bouton **« Retour de consigne »** (nombre de gobelets rendus), qui ajoute une ligne négative. Il peut se combiner avec un achat, payé en espèces ou par carte ; si le total est nul ou négatif, l'argent est rendu **en espèces uniquement**.
- Chaque ligne de commande a un **type** : article, consigne ou retour de consigne. Le serveur recalcule tout (montant de la consigne lu sur l'événement, nombre de consignes déduit des articles). Pas de prix négatif saisi ni d'article « −1 € ».
- La consigne n'est **pas du chiffre d'affaires** : le Carnet de bord affiche le CA hors consignes, puis les consignes encaissées, rendues et le solde (gobelets non rendus), et les espèces attendues en caisse. Pas de comptage des gobelets.

États d'une commande : `new` → `prep` → `ready` → `served`, ou `cancelled`. Une commande sans article cuisine passe directement à `served`.

### Numérotation des commandes

Le numéro donné au client est la **lettre de la caisse suivie d'un numéro** : A12, B07. Chaque caisse peut ainsi numéroter seule, même hors ligne, sans risque de doublon avec une autre caisse.

- La lettre est attribuée par le **serveur** lors de l'appairage d'une tablette `caisse`, unique par événement. Les lettres I et O ne sont pas utilisées (confusion avec 1 et 0). Une lettre n'est jamais réattribuée au cours d'un même événement, même après révocation de la tablette.
- La numérotation de chaque lettre **repart à 1 à chaque événement**.
- La tablette attribue le numéro suivant au moment de l'encaissement. Le serveur prend la lettre dans le jeton de la tablette (jamais dans la requête) et vérifie que le couple (lettre, numéro) n'existe pas déjà pour l'événement (contrainte d'unicité en base). En cas de conflit, il répond 409 avec le prochain numéro libre (`nextNumber`), que la caisse reprend. `GET /api/menu` indique le dernier numéro utilisé (`lastNumber`), pour qu'une caisse réinstallée reprenne au bon endroit.

## Écrans

| Écran | Nom affiché | Rôle |
| --- | --- | --- |
| Caisse | Caisse | Grille d'articles, panier, paiement (calcul de la monnaie), numéro de commande en grand |
| Cuisine | Cuisine | Tickets à préparer, boutons « Je prépare », « Prête », « Remise au client », alerte au-delà de 10 min |
| Appel client | Arrivées | Numéros prêts et en préparation, très grands, façon tableau d'aéroport |
| Bilan | Carnet de bord | CA, nombre de commandes, panier moyen, ventes et stock restant par article, historique, annulation, export CSV pour Excel |
| Administration | Menu | Deux parties, Articles et Formules : tableau de modification rapide (prix, stock, cuisine, consigne, ordre ↑ ↓) et une page par article ou formule (nom, icône, catégorie, composition avec quantités) |

Le prototype validé par l'APE sert de référence pour l'ergonomie : `docs/prototype.html`.

## Sécurité

Usage prévu : une APE, petite échelle. Mais le code est écrit **comme s'il était publié et installé par d'autres associations**, sur un serveur exposé à internet. Aucune règle ci-dessous n'est optionnelle.

### Principe : le serveur ne fait jamais confiance à la tablette

- Le serveur **recalcule** prix, totaux et monnaie à partir de la base. Le total envoyé par la tablette est ignoré.
- Toute entrée est validée côté serveur : UUID au bon format, quantités entières entre 1 et 99, produit appartenant à l'événement actif, état demandé autorisé depuis l'état actuel, taille du corps JSON limitée (64 Ko).
- Les transitions d'état sont vérifiées par le serveur : `new` → `prep` → `ready` → `served`. L'annulation est réservée à l'administration.

### Administration

- Symfony Security avec formulaire de connexion, mot de passe haché (`auto`, donc bcrypt ou argon2), protection CSRF sur la connexion et sur **chaque** formulaire qui modifie des données.
- `login_throttling` : 5 tentatives par tranche de 15 minutes, par IP et par identifiant.
- Session régénérée à la connexion (comportement par défaut de Symfony, à ne pas désactiver), expiration après 2 heures d'inactivité.
- **Aucun identifiant par défaut livré.** Le premier administrateur est créé par une commande : `php bin/console app:install`, qui demande le mot de passe (12 caractères minimum) et génère `APP_SECRET`.

### Tablettes : appairage, sans comptes

- L'administration crée une tablette (nom + rôle) et affiche un **code d'appairage** à usage unique (8 caractères, valable 10 minutes) et son QR code.
- La tablette échange ce code contre un **jeton** aléatoire (32 octets, `random_bytes`). Le serveur n'en garde que l'empreinte SHA-256. La tablette le conserve et l'envoie dans l'en-tête `Authorization: Bearer`.
- Le jeton expire à la fin de l'événement (par défaut le lendemain à 6 h) et peut être révoqué à tout moment depuis l'administration.
- Chaque rôle n'accède qu'à ce dont il a besoin :
  - `caisse` : lire le menu et les stocks, créer des commandes.
  - `cuisine` : lire les commandes en cours, faire avancer leur état.
  - `affichage` : lire uniquement les numéros et leur état, rien d'autre (pas de montants, pas d'articles).
- Limitation de débit par tablette sur l'API (par exemple 120 requêtes par minute), pour encaisser l'interrogation toutes les 2 à 3 secondes sans permettre d'abus.
- Comparaisons de jetons et de codes avec `hash_equals`.

### Navigateur

- Twig échappe tout par défaut ; ne jamais utiliser `|raw` sur une donnée saisie.
- En JavaScript, insérer les données (noms d'articles, catégories) avec `textContent` ou `createElement`, **jamais** `innerHTML` avec une donnée.
- Aucun script ni style en ligne : tout dans `public/js/` et `public/css/`, pour permettre une politique de sécurité stricte.
- En-têtes envoyés sur toutes les réponses (un écouteur `kernel.response`, sans dépendance) :
  - `Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()` (à assouplir pour la caméra si le scan du QR code d'appairage se fait dans l'app)
  - `Strict-Transport-Security: max-age=31536000` quand le site est servi en HTTPS
- Cookies de session : `secure: auto`, `httponly: true`, `samesite: lax`.
- Le jeton de tablette est stocké dans IndexedDB et n'est jamais mis dans une URL.

### HTTPS (point technique important)

Le service worker, donc le mode hors ligne, **ne fonctionne qu'en HTTPS** (ou sur `localhost`).

- Mode hébergé : HTTPS obligatoire (Let's Encrypt, gratuit chez la plupart des hébergeurs).
- Développement sur le Mac : `http://localhost:8080` suffit, `localhost` étant considéré comme sécurisé par Chrome.
- Mode portable en réseau local : solution à définir à l'étape 2 (certificat local installé une fois sur chaque tablette), et à documenter dans `README.md`. Ne jamais désactiver la vérification des certificats ni recourir aux options de Chrome qui traitent un site HTTP comme sécurisé.

### Données et serveur

- Racine web = `public/` uniquement. La base SQLite vit dans `var/`, jamais accessible par URL.
- `.env.local`, la base et les sauvegardes ne sont jamais commités (`.gitignore`).
- `APP_ENV=prod` et `APP_DEBUG=0` en production ; aucune page d'erreur détaillée en production.
- **Aucune donnée personnelle des clients** : pas de nom, pas de téléphone, pas d'e-mail. Une commande est un numéro. Garder ce principe, il évite l'essentiel des obligations RGPD.
- Commande de sauvegarde : `php bin/console app:backup` copie la base horodatée dans `var/backups/`.
- Les montants, annulations et changements de prix sont tracés dans `AuditLog`.

### Dépendances et dépôt

- `composer.lock` commité. `composer audit` lancé par une GitHub Action à chaque pull request et chaque semaine (gratuit pour un dépôt public).
- Dependabot activé pour Composer et GitHub Actions.
- Tests obligatoires pour : authentification et CSRF de l'administration, appairage et expiration des jetons, droits de chaque rôle sur l'API (une tablette `affichage` ne peut pas créer de commande, etc.), recalcul des totaux par le serveur, idempotence.

## Si le projet devient public

- Le nom, le logo, les couleurs et le nom de l'association sont **configurables** (fichier de configuration ou écran d'administration), sans toucher au code.
- Le logo de l'APE (qui reprend le Petit Prince, protégé en France) **n'est pas commité** dans le dépôt : il est déposé par chaque installation dans `var/uploads/`. Le dépôt ne contient qu'un logo générique.
- Les droits d'auteur appartiennent à la personne qui porte le projet (pas à l'APE). Le fichier `LICENSE` porte son nom ; l'APE utilise l'application librement, comme tout le monde, au titre de la licence.
- Projet développé par une seule personne : pas de contributions extérieures acceptées pour l'instant.
- Ajouter un fichier `SECURITY.md` qui explique comment signaler une faille en privé (fait).
- Activer **Private vulnerability reporting** dans les réglages du dépôt GitHub au moment de le rendre public (option indisponible sur un dépôt privé) : c'est le canal de signalement indiqué dans `SECURITY.md`.
- Documenter l'installation pas à pas dans `README.md`, y compris `app:install` et le certificat local.

## Charte graphique

Référence complète : document « Charte graphique — Le Comptoir des étoiles ». L'essentiel :

```css
:root {
  --renard: #A56726;  --renard-texte: #8A5418;   /* action principale */
  --or: #E2B83A;                                  /* en préparation, numéros */
  --sauge: #7A9438;   --sauge-texte: #4F6B22;    /* prête */
  --kraft: #E1C385;                               /* bordures, tickets */
  --encre: #3B352E;   --encre-douce: #6B6258;    /* texte */
  --nuage: #FBF6EC;   --surface: #FFFFFF;        /* fonds */
  --erreur: #B3261E;  --erreur-fond: #FBE1DE;
  /* Couleurs dérivées */
  --renard-encre: #FFFFFF;  /* texte sur Renard */
  --or-encre: #3B352E;      /* texte sur Or */
  --or-texte: #7A5A0F;  --or-fond: #FBF0CF;      /* en préparation */
  --sauge-fond: #EEF3DF;                          /* prête */
  --papier: #FFFDF8;                              /* tickets, panier */
  --f-titre: "Baloo 2", "Trebuchet MS", sans-serif;
  --f-texte: "Atkinson Hyperlegible", "Segoe UI", Roboto, sans-serif;
  --f-chiffres: "Atkinson Hyperlegible Mono", ui-monospace, monospace;
  --rayon: 12px;  --rayon-bouton: 10px;  --tactile: 52px;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --renard: #D08A45;  --renard-texte: #D08A45;
    --sauge: #A9C26A;   --sauge-texte: #A9C26A;
    --kraft: #4A3F33;
    --encre: #F0E6D6;   --encre-douce: #B4A897;
    --nuage: #1A1612;   --surface: #241F1A;
    --erreur: #FF8F86;  --erreur-fond: #43201D;
    --renard-encre: #1A1612;  --or-encre: #1A1612;
    --or-texte: #E2B83A;  --or-fond: #3A2F12;
    --sauge-fond: #2A3319;  --papier: #241F1A;
    color-scheme: dark;
  }
}
/* Mêmes valeurs sombres répétées sous :root[data-theme="dark"] pour forcer le thème. */
```

La référence est `public/css/tokens.css`. Renard est passé de #A66927 à #A56726 : le texte blanc sur l'ancien Renard n'atteignait que 4,4955:1. Toutes les paires texte / fond de la charte et des couleurs dérivées ont été vérifiées à au moins 4,5:1, en clair et en sombre ; refaire ce calcul avant d'ajouter une couleur.

Icônes des articles : pictogrammes au trait de la bibliothèque **Lucide** (licence ISC), choisis dans une grille de l'administration. Pas d'émojis.

- Une sélection de fichiers SVG est copiée dans `public/img/icons/` avec la licence (`LICENSE.txt`) ; aucune dépendance, rien de chargé depuis internet.
- La liste fait foi dans `src/Menu/IconCatalog.php` ; chaque icône a sa règle dans `public/css/icons.css` (masque CSS, couleur du texte : compatible avec le thème sombre). `IconCatalogTest` vérifie que les trois concordent.
- Pour ajouter une icône : copier le SVG depuis le paquet `lucide-static`, l'ajouter au catalogue et à `icons.css`.
- Là où Lucide n'a rien (frites, crêpe, saucisse, agneau, cochon), l'icône est dessinée pour le projet dans le même style (grille 24 px, trait de 2, extrémités arrondies, `currentColor`), sous la licence du projet ; c'est indiqué dans le fichier et dans `public/img/icons/LICENSE.txt`.
- Le serveur n'accepte et ne renvoie que des clés du catalogue ; une icône absente ou inconnue s'affiche avec l'icône par défaut.

Règles :

- Aucune couleur en dur dans les composants : uniquement ces variables.
- Un seul bouton principal (fond Renard) par écran.
- Chaque état a une couleur **et** un libellé écrit.
- Zones tactiles d'au moins 48 px, boutons d'au moins 52 px de haut.
- Contraste d'au moins 4,5:1 pour tout texte.
- Aucun texte sous 13 px ; prix et totaux en police Mono.
- Pas de dégradés, d'ombres marquées, de fenêtres système (`alert`, `confirm`) : les confirmations se font dans la page.
- Le logo de l'APE s'affiche seulement sur l'accueil, l'administration et le Carnet de bord. Les personnages du Petit Prince ne sont jamais utilisés dans le nom, l'icône ou les illustrations de l'application.

## Langue et ton

- Interface, messages et documentation en **français**, vouvoiement.
- Boutons : verbe d'action d'abord (« Encaisser », « Enregistrer le menu »).
- Erreurs : ce qui se passe et quoi faire, sans jargon technique.
- Code (classes, méthodes, variables) en anglais ; commentaires en français si nécessaire.

## Façon de travailler

- Avancer par petites étapes, une branche et une pull request par étape.
- Proposer le plan avant de coder une étape importante.
- Écrire un test pour la logique sensible : idempotence des commandes, calcul du stock, calcul de la monnaie, numérotation, consigne.
- Relire chaque migration générée. Sur SQLite, Doctrine recrée parfois une table entière (copie, suppression, recréation) : avec les clés étrangères actives, supprimer `event` ou `product` efface en cascade les données liées. Préférer `ALTER TABLE … ADD COLUMN` / `DROP COLUMN`, y compris dans `down()`.
- Avant de terminer une étape : lancer les tests et vérifier l'écran concerné.
- Chaque route de l'API (`/api/…`) a sa requête dans la collection Bruno (`bruno/`), avec des tests sur le code de réponse et le contenu, y compris les cas de refus. La collection ne contient jamais de jeton ni de secret.

## Planning

1. Fin octobre – début novembre : squelette Symfony, entités, migrations, `app:install`, connexion de l'administration, en-têtes de sécurité, appairage des tablettes, puis Caisse et Menu.
2. Mi-novembre : Cuisine, Arrivées, synchronisation.
3. Fin novembre : hors ligne (service worker, file IndexedDB), Carnet de bord et export.
4. Début décembre : répétition générale avec des bénévoles, corrections.
5. Semaine du 14 décembre : installation, prise en main, marge de sécurité.