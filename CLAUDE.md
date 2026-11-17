# Le Comptoir des étoiles

Application de caisse pour l'APE (Association des Parents d'Élèves) de l'école Antoine de St Exupéry, à Uzein. Elle relie caisse, cuisine et écran d'appel client pendant les événements (Halloween, Loto, Carnaval, Vide-grenier, Kermesse), suit les stocks et produit un bilan pour ajuster les courses suivantes.

Premiers événements réels : **stand de Noël vers le 11 décembre 2026** (sans cuisine), qui sert de répétition générale, puis **Loto du 18 décembre 2026** (avec cuisine).

## Contraintes du projet (non négociables)

- **Budget : 0 €.** Aucun service payant, aucun matériel à acheter.
- **Léger et maintenable, avec le moins de dépendances possible.** Le projet sera repris par des parents bénévoles. Ne jamais ajouter de dépendance Composer ou JavaScript sans le demander d'abord.
- **Tablettes Android, navigateur Chrome.** L'application est installable (PWA) depuis le navigateur.
- **Une seule installation, en ligne : la production** (voir Production). Les tablettes s'y connectent par le partage de connexion d'un téléphone, avec si possible un second téléphone en secours : c'est le même serveur, passer de l'un à l'autre ne change rien pour l'application. Pas de serveur sur place cette année, ni portable, ni Raspberry (voir « Plus tard »). Une coupure est couverte par le mode hors ligne.
- **Aucune commande ne doit être perdue**, même pendant une coupure réseau.

## Stack

Dépendances de production autorisées, et rien d'autre :

- `symfony/skeleton` (Symfony 7.4 LTS, PHP 8.4+). On reste sur Symfony 7.4 LTS ; le passage en 8.x sera une décision à part, après la saison (Dependabot ignore les versions majeures de `symfony/*`).
- `symfony/twig-bundle`
- `doctrine/doctrine-bundle`, `doctrine/orm`, `doctrine/doctrine-migrations-bundle`
- `symfony/security-bundle` et `symfony/rate-limiter` : seule exception à la règle du minimum, réservée à l'authentification de l'administration (hachage du mot de passe, protection CSRF, limitation des tentatives, protection de session). Pour un projet qui pourrait devenir public, un composant audité vaut mieux qu'une authentification écrite à la main.
- Base de données : **MySQL 8.4** en développement, en CI et en production (`utf8mb4`, interclassement `utf8mb4_unicode_ci` fixé dans `config/packages/doctrine.yaml`). SQLite a été abandonné le 26 octobre 2026.

Développement uniquement : `symfony/maker-bundle`, PHPUnit.

Seule bibliothèque JavaScript, exception accordée pour le QR code d'appairage : **qrcode-generator 2.0.4** de Kazuhiko Arase (licence MIT, un seul fichier, sans dépendance), copiée sans modification dans `public/js/vendor/qrcode-generator.js` (fichier `dist/qrcode.mjs` du paquet npm, renommé en `.js` pour qu'Apache l'envoie comme du JavaScript), avec sa licence (`qrcode-generator.LICENSE.txt`). L'en-tête du fichier donne la version et l'empreinte SHA-256 de l'original. Pour la mettre à jour : télécharger la nouvelle version fixée, vérifier son empreinte, relire les changements, relancer les tests. Pas de Dependabot pour ce fichier.

Explicitement exclus : EasyAdmin, Symfony Form, Stimulus, AssetMapper, Webpack Encore, tout framework CSS ou JS.

Node est exclu de l'application et de toute construction. Seule exception : le lanceur de tests intégré (node --test), sans dépendance, dans Docker et en CI, pour tester la logique JavaScript critique.

## Environnement de développement : Docker

PHP, Composer et Symfony ne sont **pas** installés sur le Mac : tout tourne dans Docker.

- Une seule image, basée sur l'image officielle **`php:8.4.x-apache`** (version fixée), avec les extensions `pdo_mysql`, `intl` et `opcache`, `php.ini-production`, et Composer copié depuis l'image officielle `composer:2`. Même pile que la production (Apache, PHP 8.4), sans HTTPS. Développement uniquement : la production n'utilise pas Docker (voir Production).
- `docker/apache.conf` : racine `/app/public` (le projet est monté dans `/app`), `AllowOverride All` pour le même `public/.htaccess` qu'en production, modules `rewrite`, `headers` et `deflate` (compression du JSON), `KeepAlive` (15 s). `Define COMPTOIR_DEV` désactive la redirection vers le HTTPS de `.htaccess`. **Pas** de `symfony/apache-pack`.
- Apache ne transmet pas les variables d'environnement du conteneur à PHP : la configuration passe par les fichiers `.env`, comme en production.
- Le site est servi sur **`http://localhost:8080`**. Pas de HTTPS en développement : Chrome autorise le service worker sur `localhost`, ce qui suffit pour développer et tester le mode hors ligne sur le Mac.
- Fichiers attendus, et rien de plus : `Dockerfile`, `compose.yaml`, `docker/apache.conf` et `docker/mysql/init.sql` (plus le script `bin/bruno`). Pas de Redis.
- Services en plus de `php` :
  - **`database`** : image officielle `mysql` (version fixée), base `comptoir`, utilisateur et mot de passe `comptoir` (développement uniquement). Données dans le volume nommé `database_data` : elles survivent au redémarrage des conteneurs (`docker compose down -v` les efface). `docker/mysql/init.sql` crée la base des tests `comptoir_test`.
  - **phpMyAdmin** (image officielle `phpmyadmin`, version fixée), connexion automatique, sur `http://localhost:8082`. Développement uniquement, lié à `127.0.0.1`. Rien dans l'application n'en dépend.
  - **`jstest`** (image officielle `node`, LTS, version fixée, la même qu'en CI), profil `tests` : ne démarre pas avec `docker compose up`. `docker compose run --rm jstest` lance `node --test` sur `tests/js/`.
  - **`bruno`** (même image `node`, profil `tests`) : Bruno CLI, lancé par `bin/bruno` uniquement.
- Tablettes d'essai : `bin/console app:dev:test-devices` (refuse hors `APP_ENV=dev`) affiche l'événement actif, demande confirmation (« oui » ; `--yes` pour un script), crée pour cet événement une caisse, une cuisine et un écran Arrivées (la caisse seule sans cuisine), et affiche pour chacune l'adresse `/appairer#code=…` à ouvrir dans Chrome. Aucun jeton créé ni affiché. Audit au nom de « commande app:dev:test-devices ».
- Essais de l'API : `bin/bruno` lance toute la collection Bruno sur une base à part, `comptoir_bruno` (créée par `docker/mysql/init.sql`, et par le script sur un volume plus ancien), servie par un second site Apache sur `http://localhost:8081` (`docker/apache.conf` : `SetEnv DATABASE_URL` et `APP_CACHE_DIR=/app/var/bruno/cache`, que Symfony garde plutôt que les fichiers `.env`). Base vidée et remise à jour à chaque lancement, données préparées par `app:dev:bruno-fixtures` (refuse hors `APP_ENV=dev` et sur toute autre base), tablettes appairées par le script, jetons gardés dans ses variables seulement. Bruno CLI (`@usebruno/cli`, version fixée) tourne dans le service **`bruno`** (même image `node` que `jstest`, profil `tests`, cache npm dans le volume `bruno_npm`) : aucun Node à installer. La base de développement n'est jamais touchée par les essais.
- Tests : `tests/bootstrap.php` recrée le schéma de `comptoir_test` une fois par lancement ; `ResetDatabaseTrait` vide les tables avant chaque test.
- **Toutes** les commandes PHP passent par le conteneur, jamais par le PHP du Mac :
  - `docker compose exec php composer …`
  - `docker compose exec php bin/console …`
  - `docker compose exec php bin/phpunit`
- Le nom du service dans `compose.yaml` est `php`.
- En production (sans Docker), le même code tourne avec le PHP du serveur : ne rien faire qui dépende de Docker dans le code de l'application.
- Apache ne transmet pas l'en-tête `Authorization` à PHP par défaut. Sur un serveur Apache (production et Docker), `public/.htaccess` le fait avec `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` (avec la redirection vers le HTTPS ; `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` seulement si `mod_rewrite` est absent), sinon aucune tablette ne peut s'authentifier. Exemple Nginx : `docs/deploiement/nginx.conf`. Installation décrite dans `README.md`.

## Production

Une seule installation, déjà en ligne. Détail des opérations : `docs/hebergement.md`.

- Serveur privé virtuel (VPS) chez OVHcloud, géré avec **ISPConfig** : Apache, PHP-FPM 8.4, HTTPS (Let's Encrypt), MySQL 8.4. Pas de Docker. Dossier du projet : `/var/www/clients/client5/web54/web` ; journal du site : `/var/www/clients/client5/web54/log/error.log`.
- L'utilisateur SSH est celui du site (`web54`) : **jamais de `sudo`**, ni `chown`, ni `systemctl reload` (pas de droit d'administrateur). La commande `php` du serveur est PHP 7.3 : toujours `php8.4 bin/console …` et `php8.4 /usr/local/bin/composer …`, en toutes lettres dans toute procédure.
- Le site pointe sur `public/` ; `public/.htaccess` redirige vers le HTTPS et transmet l'en-tête `Authorization`.
- L'adresse du site n'est écrite ni dans le dépôt ni dans le code : elle est dans `DEFAULT_URI`, dans le `.env.local` du serveur.
- Réglages d'Apache pour les tablettes (`docs/hebergement.md`, section 4, comme `docker/apache.conf`) : `KeepAliveTimeout 15`, `MaxKeepAliveRequests 1000`, compression `DEFLATE` de `application/json`. **Facultatifs** : dans le champ « Apache Directives » d'ISPConfig (compression entourée de `<IfModule>`), avant le 4 décembre ou après le Loto, sinon par l'administrateur du serveur après le Loto. Vérification sans rien modifier par `curl` (section 4.1).
- Mise à jour en SSH, dans le dossier du projet (`docs/hebergement.md`, section 3) : noter le commit et la version de migration, `app:backup` (puis `scp` sur le Mac), `git pull`, `php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts`, **cache effacé puis reconstruit, obligatoire après chaque `git pull`** (`rm -rf var/cache/prod`, `php8.4 bin/console cache:warmup` : en production, Symfony réutilise l'ancien cache sans vérifier ; oublié, il donne une 500 `ArgumentCountError`), `doctrine:migrations:migrate`. Pas de rechargement d'Apache. Retour arrière : migration défaite avec le nouveau code, **puis** `git reset --hard`. Jamais pendant un événement ni pendant le gel des trois jours qui le précèdent.
- `/stats/` (statistiques d'ISPConfig dans le dossier du site) est ignoré par Git.
- Avant d'exiger une version plus récente de PHP ou de MySQL (`composer.json`, CI, Docker), vérifier celle du serveur.

## Architecture

- **Pages** : Twig, HTML simple.
- **JavaScript** : fichiers vanilla dans `public/js/`, sans build. Modules ES natifs.
- **CSS** : fichiers simples dans `public/css/`, avec `tokens.css` chargé en premier (voir Charte).
- **Polices** : fichiers locaux dans `public/fonts/`, jamais depuis Google Fonts (doit marcher hors ligne).
- **Administration** (événements, menu, prix, stocks, tablettes) : pages Twig avec formulaires HTML traités dans les contrôleurs. L'en-tête de chaque page affiche l'événement actif (« Aucun événement actif » sinon) : les tablettes et le carnet de bord dépendent de lui. Le Menu travaille sur un événement choisi (`?evenement=…`, l'actif par défaut, sinon le plus récent) : un événement pas encore actif se prépare à l'avance, sans effet sur les tablettes (seule la révision de son propre menu augmente). « Reprendre le menu d'un autre événement » (`App\Service\MenuCopier`) copie les articles et formules des catégories cochées, après confirmation dans la page : formules reliées aux copies, composants d'une catégorie non cochée copiés aussi, article déjà présent sous le même nom réutilisé, jamais les articles retirés ni les commandes ; tracé (`product.create` par article, `menu.copy`). L'activation reste une action séparée, confirmée dans la page. Protégée par Symfony Security (voir Sécurité). Pas de comptes utilisateurs pour les bénévoles.
- **API JSON** pour les tablettes, sous `/api`, accessible uniquement aux tablettes appairées (voir Sécurité).
- **Hors ligne** : service worker qui met en cache l'application ; file d'attente des commandes dans IndexedDB, envoyée au retour du réseau.
  - `/sw.js` est servi par `PwaController` : `App\Pwa\AppShell` y inscrit la liste des fichiers à garder (écrans de service et tout `public/css`, `js`, `fonts`, `img`) et une version calculée sur leur contenu, celui des gabarits et du service worker (`src/Pwa/service-worker.js`). Pas d'outil de build : toute modification change la version, et les tablettes rechargent tout.
  - Pages : réseau d'abord (4 s), copie locale sinon. Fichiers statiques : copie de la version en cours. Jamais l'API ni l'administration.
  - Nouvelle version : bandeau « Recharger » sur la tablette, jamais de rechargement automatique (commande en cours de saisie).
  - File d'attente (`public/js/till-outbox.js`) : sans réponse du serveur en 6 s, la commande est gardée dans IndexedDB (magasin `outbox`) avec son UUID et son numéro, et le client reçoit son numéro. Envoi dans l'ordre, une à la fois, au retour du réseau, avec `"offline": true` : mêmes reprises que les interrogations (3, 6, 12 puis 30 s, 8 s au plus par envoi, envoi immédiat à l'événement `online`). Décisions dans `public/js/outbox-rules.js` (fonctions pures, `tests/js/outbox-rules.test.js`). Une commande gardée n'est jamais effacée sans accord : un refus du serveur la met « À vérifier » avec tout son détail (articles, montant, paiement), retirée seulement après ressaisie, en deux appuis. Elle n'est envoyée qu'avec l'appairage d'origine (la lettre du numéro ne doit pas changer), et le désappairage est bloqué tant qu'il en reste.
  - Le dernier menu reçu est gardé dans la tablette : une caisse rechargée sans réseau reste utilisable.
  - Application installable : `/manifest.webmanifest`, icônes dans `public/img/app/` (générées depuis le logo générique), démarrage sur `/demarrer`, qui ouvre l'écran du rôle de la tablette.
- **Idempotence** : chaque commande reçoit un UUID créé sur la tablette. `POST /api/orders` avec un UUID déjà connu ne crée pas de doublon et renvoie la commande existante.
- **Événement sans cuisine** (`Event.withKitchen` décoché) : toute commande passe directement à `served`, quel que soit le réglage cuisine de ses articles, et la remarque pour la cuisine n'est ni proposée ni gardée. Les écrans Cuisine et Arrivées ne sont pas proposés, ni le suivi des commandes de la caisse ; seul le rôle `caisse` est proposé à l'appairage, et l'API refuse les rôles `cuisine` et `affichage` (403). Le Carnet de bord fonctionne normalement.
- **Service à table** (Loto, `Event.tableService`, décoché par défaut, cochable seulement avec cuisine) : le client commande et paie à la caisse comme d'habitude, donne son numéro de table, un bénévole lui apporte son plat. Aucun paiement hors des caisses.
  - Caisse : bouton « Table » dans le panier, pavé numérique (1 à 999 ; numérotation par rangée 101 à 808). Facultatif : sans numéro, commande au comptoir. Règles dans `public/js/table-rules.js` (fonctions pures, `tests/js/table-rules.test.js`).
  - Les boissons sont toujours remises au comptoir : une commande sans article cuisine reste une commande au comptoir, servie tout de suite, **sans** numéro de table (le serveur ne le garde pas). Avec au moins un article cuisine, elle part en cuisine avec son numéro de table. Écran du numéro : « Servi à la table 304 », ou « Plats servis à la table 304 · boissons à remettre maintenant » si la commande contient aussi des boissons.
  - `Order.tableNumber` validé par le serveur (entier de 1 à 999, sinon 422 ; hors ligne, un numéro invalide est écarté plutôt que de perdre la commande, et tracé dans `AuditLog` : `order.table_rejected`, valeur reçue en texte court dans `before`, au nom de la tablette). Le réglage ne fait qu'afficher ou masquer le bouton : un numéro valide est toujours gardé. Fixé à l'encaissement, jamais modifié (révision et ETag inchangés).
  - Fiche [Livreur](docs/procedures/livreur.md) (demi-page) pour les bénévoles qui apportent les plats ; la fiche Bénévoles n'y consacre qu'une ligne.
  - Cuisine : « Table 304 » en grand à côté du numéro ; « Déjà remis au comptoir : … » au lieu de « Au comptoir : … », pour que le passe ne mette pas les boissons sur le plateau. Arrivées : les commandes à table n'y apparaissent pas. Suivi de la caisse : numéro de table, recherche par table, filtre « À table ». Carnet de bord : nombre et chiffre d'affaires (hors consignes) des commandes à table ; export CSV des commandes : colonne « Table ».
- **Temps réel** : par interrogation seule (`public/js/poller.js`). L'écran cuisine (`GET /api/orders`) et l'écran Arrivées (`GET /api/board`) interrogent le serveur toutes les 3 secondes, 2 secondes pendant les 30 secondes qui suivent un changement, jamais plus de 3 secondes. Mercure retiré le 10 novembre 2026, disponible dans l'historique Git au commit `0c34dd7`.
  - Rythme décidé par `public/js/poll-rules.js` (fonctions pures, `tests/js/poll-rules.test.js`) : délai compté depuis le début de la requête précédente, décalage aléatoire vers le bas seulement (2,7 à 3 s) ; une seule requête à la fois par écran (`Lane` : suivi et menu de la caisse), abandonnée au bout de 8 s ; écran masqué (Page Visibility) : aucune requête, une tout de suite à son retour ; après une erreur : 3, 6, 12 puis 30 s, et une requête tout de suite à l'événement `online`.
  - Le serveur renvoie à chaque fois la **liste complète** des commandes en cours (pas de `since`) : une tablette qui a manqué une réponse se recale à la suivante.
  - Révisions : `Event.ordersRevision` et `Event.menuRevision`, augmentées de 1 par `App\Service\Revisions` (`UPDATE … + 1` en SQL, dans la transaction de l'écriture), jamais en PHP. **Toute écriture qui change ce que reçoivent les tablettes passe par `Revisions::flushOrders()` ou `flushMenu()`** au lieu de `flush()` : commandes (création, cuisine, comptoir, annulation) ; menu (articles, prix, stocks de départ, ordre, retrait, formules, menu d'exemple, réglages de l'événement et des caisses). Sinon les tablettes reçoivent 304 et ne voient rien. Les tests le vérifient (`RevisionsTrait`).
  - Empreinte (`ETag`) = événement, révisions, et date de construction du conteneur (change à chaque `cache:clear`, donc à chaque mise à jour du serveur). La tablette la renvoie dans `If-None-Match` ; tant qu'elle n'a pas changé, le serveur répond 304 sans contenu (`App\Controller\Api\PollResponse`) après **une seule lecture SQL** : la tablette et son événement, lus ensemble à l'authentification (`PollRevisionTest` compte les requêtes). Les commandes ne sont relues que si la révision a changé. `Cache-Control: no-cache, private`. Le pare-feu `/api` reste sans session.
  - Dépendances : Arrivées et suivi de la caisse dépendent des commandes ; cuisine (noms des articles) et menu de la caisse (stocks restants, dernier numéro) des commandes et du menu.
  - Les temps d'attente se calculent sur l'heure du serveur (en-tête `Date`, présent aussi sur une 304), jamais sur l'horloge de la tablette.
  - La cuisine fait avancer une commande avec `POST /api/orders/{uuid}/status` ; redemander l'état actuel ne change rien (deux tablettes cuisine), un autre état non autorisé répond 409 avec l'état actuel.
  - L'écran Arrivées montre les 12 dernières commandes prêtes et toutes celles en préparation, sauf les commandes servies à table.
  - La caisse suit les commandes en cours (`GET /api/till/orders`, toutes les 10 secondes) : résumé dans l'en-tête, panneau « Suivi » avec recherche par numéro, et nombre de commandes avant la nouvelle en cuisine.
  - Chaque caisse relit le menu toutes les 30 secondes (`GET /api/menu`, avec empreinte), seulement sur la grille (jamais pendant un paiement ; relu au retour sur la grille si l'heure est passée), et après chaque vente : stocks des autres caisses, prix modifiés, articles retirés.
- **Stocks** : le stock restant est calculé à partir des ventes (stock de départ moins quantités vendues des commandes non annulées), jamais stocké comme un compteur décrémenté. Le stock est une **information, jamais une limite** : ni le serveur ni la caisse ne bloquent une vente (stocks de départ approximatifs, ventes hors ligne), et il peut devenir négatif. Une formule décompte les composants figés sur la ligne au moment de la vente ; son stock restant est le nombre de formules encore possibles avec ses composants comptés (stock de chaque composant divisé par sa quantité, le plus petit l'emporte).

## Modèle de données (point de départ)

- `Event` : nom, date, actif ou non, avec cuisine (`withKitchen`, bool, coché par défaut ; modifiable seulement tant que l'événement n'a aucune commande, encadré bien visible dans la fiche sinon, avec le nombre de commandes), montant de la consigne (centimes, 0 = pas de consigne), service à table (`tableService`, bool, décoché par défaut, seulement avec cuisine), révisions des commandes et du menu (`ordersRevision`, `menuRevision`, voir Temps réel).
- `Product` : événement, nom, icône (clé du catalogue d'icônes, voir Charte), catégorie, prix (centimes, entier), stock de départ (nullable = non compté), passe en cuisine (bool), avec consigne (bool), ordre d'affichage, composants si formule, retiré le (`archivedAt`). Un article retiré du menu est archivé, jamais supprimé : il disparaît des caisses mais reste accepté dans une commande encaissée hors ligne avant le retrait.
- `ProductComponent` : article inclus dans une formule, avec sa quantité (« 2 crêpes »). Une formule ne contient que des articles simples, jamais une autre formule.
- `Order` : UUID (identifiant venu de la tablette), événement, lettre de caisse et numéro (voir Numérotation), moyen de paiement (espèces ou carte), montant reçu, total recalculé par le serveur, état, créé le, modifié le, tablette d'origine, remarque pour la cuisine (facultative, 140 caractères, texte brut sur une ligne, gardée seulement si la commande passe en cuisine ; l'aide « Pas de nom ni de téléphone » rappelle la règle des données personnelles), numéro de table (`tableNumber`, facultatif, 1 à 999, seulement si la commande passe en cuisine).
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
| Caisse | Caisse | Grille d'articles, panier, paiement (billets et pièces reçus additionnés, monnaie à rendre et sa décomposition en billets et pièces, `Money::changeBreakdown` côté serveur faisant référence), numéro de commande en grand |
| Cuisine | Cuisine | Tickets à préparer, boutons « Je prépare », « Prête », « Remise au client », alerte au-delà de 10 min |
| Appel client | Arrivées | Numéros prêts et en préparation, très grands, façon tableau d'aéroport (sans les commandes servies à table) |
| Bilan | Carnet de bord | `/admin/carnet` (`App\Service\Logbook`) : CA hors consignes, commandes, panier moyen, espèces et carte, consignes (encaissées, rendues, gobelets non rendus), espèces attendues par caisse (hors fond de caisse) et écarts des commandes hors ligne, ventes et stock restant par article (articles retirés compris), chiffre d'affaires par catégorie (catégorie actuelle de l'article, une formule dans la sienne ; se somme au CA, pour séparer cartons et restauration dans les comptes), historique filtrable, annulation confirmée dans la page et tracée (`order.cancel`), événements passés ; export CSV pour Excel (`App\Service\LogbookExport` : commandes, une ligne par commande avec CA, consignes encaissées et rendues, total, reçu, rendu, écart ; détail des lignes sans aucun montant de commande répété ; bilan par article, avec sa catégorie ; bilan par catégorie, sans ligne de total. Commandes annulées exclues : chaque colonne de montant doit se sommer sans double compte. UTF-8 avec BOM, point-virgule, virgule décimale ; toute cellule de texte commençant par `=`, `+`, `-`, `@` est précédée d'une apostrophe contre l'injection de formules) |
| Administration | Chevalets de table | `/admin/chevalets` (`App\Service\TableTents`) : rangées (1 à 9) et tables par rangée (1 à 99), numéros rangée × 100 + table (101 à 808), rien d'enregistré. Deux chevalets par feuille A4 (`public/css/chevalets.css`, `@page` sans marge), chaque moitié pliée en deux, face du haut à l'envers : le numéro se lit des deux côtés. Noir sur blanc dans les deux thèmes (`--impression-encre`, `--impression-fond`, `--impression-trait`) |
| Administration | Menu | Deux parties, Articles et Formules : tableau de modification rapide (prix, stock, cuisine, consigne, ordre ↑ ↓) et une page par article ou formule (nom, icône, catégorie, composition avec quantités). Colonne cuisine masquée si l'événement est sans cuisine |

Le prototype validé par l'APE sert de référence pour l'ergonomie : `docs/prototype.html`.

## Sécurité

Usage prévu : une APE, petite échelle. Mais le code est écrit **comme s'il était publié et installé par d'autres associations**, sur un serveur exposé à internet. Aucune règle ci-dessous n'est optionnelle.

### Principe : le serveur ne fait jamais confiance à la tablette

- Le serveur **recalcule** prix, totaux et monnaie à partir de la base. Le total envoyé par la tablette est ignoré.
- Seule exception, pour ne jamais perdre une commande déjà payée : une commande envoyée avec `"offline": true` (encaissée sans réseau) est acceptée même si le montant reçu est inférieur au total recalculé ; l'écart apparaît comme une monnaie négative, à signaler dans le Carnet de bord.
- Toute entrée est validée côté serveur : UUID au bon format, quantités entières entre 1 et 99, produit appartenant à l'événement actif, état demandé autorisé depuis l'état actuel, taille du corps JSON limitée (64 Ko).
- Les transitions d'état sont vérifiées par le serveur : `new` → `prep` → `ready` → `served`. L'annulation est réservée à l'administration.

### Administration

- Symfony Security avec formulaire de connexion, mot de passe haché (`auto`, donc bcrypt ou argon2), protection CSRF sur la connexion et sur **chaque** formulaire qui modifie des données.
- `login_throttling` : 5 tentatives par tranche de 15 minutes, par IP et par identifiant.
- Session régénérée à la connexion (comportement par défaut de Symfony, à ne pas désactiver), expiration après 2 heures d'inactivité.
- « Rester connectée sur cet appareil » (case décochée par défaut) : `remember_me` de Symfony Security, cookie `REMEMBERME` de 30 jours (`secure: auto`, donc sécurisé en HTTPS ; `httponly`, `samesite: lax`), signé avec l'empreinte du mot de passe (`signature_properties: ['password']`) : `app:admin:password` déconnecte tous les appareils. Sans la case, l'expiration après 2 heures d'inactivité est inchangée ; avec, la session est rouverte par le cookie, sans message d'expiration (`SessionIdleListener`). « Se déconnecter » efface le cookie.
- Champ mot de passe : bouton en forme d'œil dans le champ, ouvert pour afficher, barré pour masquer (`public/js/password-toggle.js`, attribut `data-password-toggle` ; nom fixe « Afficher le mot de passe » et `aria-pressed`), masqué sans JavaScript ; le champ redevient masqué à l'envoi du formulaire.
- **Aucun identifiant par défaut livré.** Le premier administrateur est créé par une commande : `php bin/console app:install`, qui demande le mot de passe (12 caractères minimum) et génère `APP_SECRET`. Mot de passe oublié : `php bin/console app:admin:password` (mêmes règles de saisie, `App\Command\NewPasswordPrompt` ; ferme les sessions ouvertes, tracé `admin.password`).

### Tablettes : appairage, sans comptes

- L'administration crée une tablette (nom + rôle) et affiche un **code d'appairage** à usage unique (8 caractères, valable 10 minutes), avec à côté un **QR code** du lien d'appairage (`/appairer#code=…`), le temps restant (calculé par le serveur, l'horloge de l'ordinateur peut être fausse) et un bouton « Nouveau code ». Code expiré : le QR code et le code disparaissent. Le QR code se scanne avec l'appareil photo de la tablette, qui ouvre le lien dans le navigateur par défaut : Chrome doit l'être (fiche Jour J). L'application n'utilise pas la caméra : `Permissions-Policy` inchangée. Dessin dans un `<canvas>` (`public/js/pairing-qr.js`, règles dans `public/js/pairing-qr-rules.js`, `tests/js/pairing-qr-rules.test.js`), noir sur blanc dans les deux thèmes (`--qr-encre`, `--qr-fond`).
- Appairage par lien : `/appairer#code=K7QM-3XRP`. Le code est après le `#`, jamais envoyé au serveur par le navigateur ; `pair.js` le retire de l'adresse dès qu'il l'a lu (`history.replaceState`). Tablette déjà appairée : « Cette tablette est déjà appairée comme <nom>. La remplacer ? », confirmé en deux appuis ; refusé tant que des commandes attendent d'être envoyées (comme le désappairage). Code expiré ou déjà utilisé : message clair, champ vide. Décisions dans `public/js/pair-link.js` (fonctions pures, `tests/js/pair-link.test.js`). Seul le code passe par une adresse, jamais le jeton.
- La tablette échange ce code contre un **jeton** aléatoire (32 octets, `random_bytes`). Le serveur n'en garde que l'empreinte SHA-256. La tablette le conserve et l'envoie dans l'en-tête `Authorization: Bearer`.
- Le jeton expire à la fin de l'événement (par défaut le lendemain à 6 h) et peut être révoqué à tout moment depuis l'administration.
- Page Tablettes : seulement les tablettes de l'événement actif ; les révoquées sont masquées (« Afficher les tablettes retirées », `?retirees=1`). Suppression confirmée dans la page et tracée (`device.delete`), seulement pour une tablette sans commande, jamais appairée, ou cuisine ou Arrivées. Une caisse appairée se révoque, jamais ne se supprime : elle peut garder des commandes hors ligne pas encore envoyées, et sa lettre ne doit jamais être réattribuée pendant l'événement.
- Chaque rôle n'accède qu'à ce dont il a besoin :
  - `caisse` : lire le menu et les stocks, créer des commandes, suivre les commandes en cours (numéros et états seulement) et remettre au client une commande prête (`POST /api/till/orders/{uuid}/served`, au comptoir de la buvette).
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
  - `X-Robots-Tag: noindex` (le site n'apparaît pas dans les moteurs de recherche)
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()` (le QR code d'appairage se scanne avec l'appareil photo de la tablette, hors de l'application : rien à assouplir)
  - `Strict-Transport-Security: max-age=31536000` quand le site est servi en HTTPS
- Cookies de session : `secure: auto`, `httponly: true`, `samesite: lax`.
- Sessions rangées dans `var/sessions/` (pas dans le dossier de l'hébergeur, vidé selon ses propres règles sur un mutualisé : l'administrateur était déconnecté).
- Le jeton de tablette est stocké dans IndexedDB et n'est jamais mis dans une URL.

### HTTPS (point technique important)

Le service worker, donc le mode hors ligne, **ne fonctionne qu'en HTTPS** (ou sur `localhost`).

- En ligne : HTTPS obligatoire (Let's Encrypt, gratuit chez la plupart des hébergeurs), HTTP redirigé vers HTTPS par `public/.htaccess`. Derrière un proxy qui assure le HTTPS, `SYMFONY_TRUSTED_PROXIES` dans `.env.local`, sinon ni HSTS ni cookies sécurisés.
- Développement sur le Mac : `http://localhost:8080` suffit, `localhost` étant considéré comme sécurisé par Chrome.
- Pas de serveur sur place cette année (voir Contraintes). Ne jamais désactiver la vérification des certificats ni recourir aux options de Chrome qui traitent un site HTTP comme sécurisé.

### Données et serveur

- Racine web = `public/` uniquement. `.env.local` (adresse et mot de passe de la base) et `var/` ne sont jamais accessibles par URL.
- `.env.local`, la base et les sauvegardes ne sont jamais commités (`.gitignore`).
- `APP_ENV=prod` et `APP_DEBUG=0` en production ; aucune page d'erreur détaillée en production.
- **Aucune donnée personnelle des clients** : pas de nom, pas de téléphone, pas d'e-mail. Une commande est un numéro. Garder ce principe, il évite l'essentiel des obligations RGPD.
- Commande de sauvegarde : `php bin/console app:backup` écrit, en PHP (sans `mysqldump`, absent de nombreux mutualisés), un fichier SQL horodaté dans `var/backups/`, lu dans un instantané cohérent et importable avec phpMyAdmin.
- Les montants, annulations et changements de prix sont tracés dans `AuditLog`.

### Dépendances et dépôt

- `composer.lock` commité. `composer audit` lancé par une GitHub Action à chaque pull request et chaque semaine (gratuit pour un dépôt public).
- Dependabot activé pour Composer et GitHub Actions.
- `.github/workflows/tests.yml` à chaque pull request et fusion sur `main` : PHP 8.4 comme la production (`shivammathur/setup-php`), MySQL 8.4 en service, `composer validate`, `lint:yaml`, `lint:twig`, `lint:container`, migrations sur une base neuve (aller, `schema:validate`, retour, aller), puis PHPUnit ; en parallèle, `node --test` sur `tests/js/` dans la même image `node` que `compose.yaml`. Ne jamais fusionner une PR sans la coche verte. `composer.json` exige PHP 8.4 (`config.platform.php` à 8.4).
- Tests obligatoires pour : authentification et CSRF de l'administration, appairage et expiration des jetons, droits de chaque rôle sur l'API (une tablette `affichage` ne peut pas créer de commande, etc.), recalcul des totaux par le serveur, idempotence, événement sans cuisine (état `served`, rôles refusés).

## Si le projet devient public

- Le nom, le logo, les couleurs et le nom de l'association sont **configurables** (fichier de configuration ou écran d'administration), sans toucher au code.
- Le logo de l'APE (qui reprend le Petit Prince, protégé en France) **n'est pas commité** dans le dépôt : il est déposé par chaque installation dans `var/uploads/`. Le dépôt ne contient qu'un logo générique.
- Les droits d'auteur appartiennent à la personne qui porte le projet (pas à l'APE). Le fichier `LICENSE` porte son nom ; l'APE utilise l'application librement, comme tout le monde, au titre de la licence.
- Projet développé par une seule personne : pas de contributions extérieures acceptées pour l'instant.
- Ajouter un fichier `SECURITY.md` qui explique comment signaler une faille en privé (fait).
- Activer **Private vulnerability reporting** dans les réglages du dépôt GitHub au moment de le rendre public (option indisponible sur un dépôt privé) : c'est le canal de signalement indiqué dans `SECURITY.md`.
- Documenter l'installation pas à pas dans `README.md`, y compris `app:install` (fait : « Installer sur un serveur »).

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
  --sauge-encre: #1A1612;   /* texte sur Sauge (5,24:1 ; l'encre n'atteint que 3,53:1) */
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
    --renard-encre: #1A1612;  --or-encre: #1A1612;  --sauge-encre: #1A1612;
    --or-texte: #E2B83A;  --or-fond: #3A2F12;
    --sauge-fond: #2A3319;  --papier: #241F1A;
    color-scheme: dark;
  }
}
/* Mêmes valeurs sombres répétées sous :root[data-theme="dark"] pour forcer le thème. */
```

La référence est `public/css/tokens.css`. Renard est passé de #A66927 à #A56726 : le texte blanc sur l'ancien Renard n'atteignait que 4,4955:1. Toutes les paires texte / fond de la charte et des couleurs dérivées ont été vérifiées à au moins 4,5:1, en clair et en sombre ; refaire ce calcul avant d'ajouter une couleur.

Icônes des articles : pictogrammes au trait de la bibliothèque **Lucide** (licence ISC), choisis dans une grille de l'administration. Pas d'émojis.

- Une sélection de fichiers SVG est copiée dans `public/img/icons/` avec la licence (`LICENSE.txt`) ; aucune dépendance, rien de chargé depuis internet. Les icônes de l'interface (œil du mot de passe) sont à part, dans `public/img/ui/`, avec la même licence : `public/img/icons/` ne contient que le catalogue du menu.
- La liste fait foi dans `src/Menu/IconCatalog.php` ; chaque icône a sa règle dans `public/css/icons.css` (masque CSS, couleur du texte : compatible avec le thème sombre). `IconCatalogTest` vérifie que les trois concordent.
- Pour ajouter une icône : copier le SVG depuis le paquet `lucide-static`, l'ajouter au catalogue et à `icons.css`.
- Là où Lucide n'a rien (frites, crêpe, saucisse, agneau, cochon), l'icône est dessinée pour le projet dans le même style (grille 24 px, trait de 2, extrémités arrondies, `currentColor`), sous la licence du projet ; c'est indiqué dans le fichier et dans `public/img/icons/LICENSE.txt`.
- Le serveur n'accepte et ne renvoie que des clés du catalogue ; une icône absente ou inconnue s'affiche avec l'icône par défaut.

Règles :

- Aucune couleur en dur dans les composants : uniquement ces variables.
- Un seul bouton principal (fond Renard) par écran.
- Chaque état a une couleur **et** un libellé écrit.
- Zones tactiles d'au moins 48 px, boutons d'au moins 52 px de haut.
- Sur les écrans de service, « Tablette » (vers l'appairage) se maintient 2 secondes, au doigt ou au clavier : un appui accidentel ferait perdre la commande en cours.
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
- Migrations : `doctrine:migrations:diff` après chaque modification d'entité, puis relire la migration générée avant de l'appliquer. Elle doit être écrite pour MySQL 8. Les migrations SQLite d'origine ont été remplacées par un schéma initial unique (`Version20261026131450`).
- MySQL : ne jamais nommer une colonne avec un mot réservé (`before`, `order`, `key`…) ; Doctrine ne les met pas entre guillemets à l'enregistrement. Ne pas compter sur l'ordre des lignes sans `ORDER BY`, ni sur l'ordre des clés d'une colonne JSON (MySQL les réordonne).
- Avant de terminer une étape : lancer les tests et vérifier l'écran concerné.
- Chaque route de l'API (`/api/…`) a sa requête dans la collection Bruno (`bruno/`), avec des tests sur le code de réponse et le contenu, y compris les cas de refus. La collection ne contient jamais de jeton ni de secret.
- Une requête Bruno ne dépend jamais de l'état de la base : ce dont elle a besoin est préparé par `app:dev:bruno-fixtures`, et sa place dans l'ordre de `bin/bruno`. Une nouvelle requête s'ajoute à la liste de `bin/bruno`. Avant de terminer une étape qui touche l'API : `bin/bruno`, tout vert.

## Planning

Fait au 3 novembre : Caisse, Menu, Cuisine, Arrivées, hors ligne (service worker, file IndexedDB), Carnet de bord et export CSV, CI, production en ligne.

Fait au 10 novembre : réglage « Avec cuisine », en-tête `X-Robots-Tag`, Symfony 7.4.23 (sécurité), Mercure retiré, développement sous Docker identique à la production (Apache, PHP 8.4, MySQL 8.4), interrogations par révisions (304 sans relire les commandes), `docs/hebergement.md` (mise à jour de novembre, réglages d'Apache).

Fait au 10 novembre, documentation : fiche bénévoles (`docs/procedures/benevoles.md`), vérifications avant un événement (`docs/hebergement.md`, section 5).

Fait au 15 novembre : PR A à D, E (tablettes et événements, chiffre d'affaires par catégorie) et F (caisse sur téléphone) ; production mise à jour ; `docs/hebergement.md` réécrit pour ISPConfig (sans `sudo`, `php8.4`), sections terminées de novembre retirées.

Nouvelles évolutions, à mettre en production avant le 4 décembre, une PR chacune, dans cet ordre : A connexion à l'administration (« Rester connectée », « Afficher » le mot de passe), B QR code d'appairage, C service à table (Loto), D chevalets de table (facultative, seulement si prête avant le 27 novembre).

Suites de l'audit du 16 novembre, avant le 4 décembre, une PR chacune : documentation (règle « aucune commande en attente avant d'activer un autre événement » en tête de la Clôture, page d'erreur dans Coupure réseau, taille de la sauvegarde, fiche [Essai maison](docs/procedures/essai-maison.md)), puis confirmation dans la page avant d'activer un événement.

1. Semaine du 9 novembre : mettre la production à jour (fait). Réglages d'Apache (`docs/hebergement.md`, section 4) : facultatifs, avant le 4 décembre ou après le Loto.
2. D'ici le 20 novembre : vérifier que la production est à jour et fonctionne avec le réglage « Avec cuisine » (`docs/hebergement.md`, section 5).
3. Début décembre : essai seule à la maison, en suivant la fiche [Essai maison](docs/procedures/essai-maison.md) (une tablette en caisse, un appareil en cuisine, partage de connexion du téléphone, coupure du réseau), puis corrections. Pas de répétition avec des bénévoles : le stand de Noël sert de répétition générale.
4. **Stand de Noël vers le 11 décembre** (sans cuisine).
5. Semaine du 14 décembre : retours du stand, corrections, marge de sécurité. **Loto le 18 décembre** (avec cuisine).

## Plus tard (après le Loto)

Pistes, rien à faire avant le 18 décembre :

- Journal (`AuditLog`) visible dans l'administration : annulations, changements de prix, appairages, tables invalides écartées (`order.table_rejected`)…
- Raspberry Pi (point d'accès Wi-Fi et serveur local), pour les lieux sans réseau.
- Export et import du menu d'un événement en JSON.
- Heure du serveur : avertissement dans l'administration si l'heure du serveur s'écarte de celle de l'appareil.
- Prise de commande à table sur téléphone : changerait la règle « aucun paiement hors des caisses » (qui tient l'argent, comptage par téléphone, impayés). À discuter avec l'APE après le Loto.
- « Reprendre les tablettes de l'événement précédent » : mêmes noms, rôles et réglages, nouveaux codes à scanner.
- Rôle « Suivi » sur téléphone (suivre les commandes sans encaisser, sans lettre), si le besoin se confirme.
- Passe de style et responsive de toute l'application, administration comprise.
- Production : `public/robots.txt` qui interdit tout ; pages introuvables (404) journalisées à un niveau plus bas que `error`, pour repérer les vraies pannes dans le journal.

Suites de l'audit du 16 novembre 2026 (rapport gardé hors du dépôt), après le Loto, une PR chacune, dans cet ordre :

1. Service worker : pour une page, servir la copie gardée quand le serveur répond par une erreur 500 ou plus (aujourd'hui, la page d'erreur s'affiche et la caisse rechargée ne peut plus encaisser).
2. `app:backup` : vérifier chaque `fopen` et `fwrite`, écrire une ligne de fin et la contrôler, échouer sinon (disque plein : sauvegarde tronquée annoncée comme réussie).
3. Écran d'appairage : relire le code à l'événement `hashchange`, masquer la section « Remplacer » quand le code n'est plus en mémoire (message trompeur « n'a pas pu être enregistré sur la tablette »).
4. Passe de style et d'accessibilité : contour de focus et bordures des champs à 3:1 au moins en thème clair (Or sur Nuage : 1,75:1 ; Kraft : 1,58:1), zones tactiles de l'administration à 48 px (champs du Menu, historique du Carnet), `X-Content-Type-Options: nosniff` sur les fichiers statiques (`public/.htaccess`, `mod_headers` dans `<IfModule>`).
5. `composer.json` : licence AGPL réelle (au lieu de `proprietary`), `name` et `description`.
6. Dépendances : correctifs (`doctrine/orm` 3.8, PHPUnit, branche Dependabot `doctrine-bundle`) ; versions majeures et Symfony 8 en décision à part.
7. Selon le temps : index `orders (event_id, status)` à la place de `(event_id, updated_at)`, inutilisé ; `denyUnlessCsrfValid` et motif d'UUID communs ; `public/js/till.js` découpé ; effacement des remarques pour la cuisine des événements passés (seule donnée personnelle possible).
