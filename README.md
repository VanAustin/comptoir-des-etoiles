# Le Comptoir des étoiles

Application de caisse pour les événements d'une association de parents d'élèves : caisse, cuisine, écran d'appel client, suivi des stocks et bilan.

Application en cours de développement. Pour l'installer sur un serveur, voir « Installer sur un serveur ».

## Démarrer en développement

PHP et Composer n'ont pas besoin d'être installés sur votre ordinateur : tout tourne dans Docker.

Prérequis : [Docker Desktop](https://www.docker.com/products/docker-desktop/).

1. Construisez et démarrez les conteneurs (application avec Apache et PHP 8.4, comme le serveur de production ; base MySQL ; phpMyAdmin) :

   ```
   docker compose up -d --build
   ```

2. Installez les dépendances PHP :

   ```
   docker compose exec php composer install
   ```

3. Créez les tables dans la base MySQL :

   ```
   docker compose exec php bin/console doctrine:migrations:migrate
   ```

4. Créez le premier administrateur :

   ```
   docker compose exec php bin/console app:install
   ```

   La commande demande un identifiant et un mot de passe (12 caractères minimum, la saisie est masquée). Elle génère aussi la clé secrète de l'application (`APP_SECRET`) dans le fichier `.env.local`, qui ne doit jamais être partagé ni commité. Aucun compte n'est livré par défaut, et la commande refuse de créer un second administrateur.

5. Ouvrez <http://localhost:8080> dans Chrome.

Toutes les commandes PHP passent par le conteneur :

```
docker compose exec php composer …
docker compose exec php bin/console …
```

Pour lancer les tests (ils utilisent une base à part, `comptoir_test`, vidée à chaque test) :

```
docker compose exec php bin/phpunit
```

Pour lancer les tests de la logique JavaScript (appairage par lien…), sans rien installer sur l'ordinateur : ils tournent dans un conteneur Node, avec le lanceur de tests intégré, sans aucune dépendance.

```
docker compose run --rm jstest
```

Pour essayer les écrans avec des tablettes sans passer par l'administration (développement uniquement) :

```
docker compose exec php bin/console app:dev:test-devices
```

La commande affiche l'événement actif et demande confirmation (tapez « oui ») ; elle crée alors pour cet événement une caisse, une cuisine et un écran Arrivées (la caisse seule pour un événement sans cuisine) et affiche pour chacune une adresse `http://localhost:8080/appairer#code=…`. Ouvrez-la dans Chrome : la tablette s'appaire toute seule. Chrome ne garde qu'un appairage par adresse de site : pour avoir les trois sur le même ordinateur, utilisez un profil Chrome par tablette, ou une fenêtre normale, une fenêtre de navigation privée, et `127.0.0.1` à la place de `localhost` pour la troisième. Les codes sont valables 10 minutes.

Si vous aviez construit les conteneurs avant le passage à Apache (novembre 2026), reconstruisez-les une fois : `docker compose up -d --build`.

Pour arrêter les conteneurs : `docker compose down`. Les données restent dans le volume Docker `database_data` ; `docker compose down -v` efface ce volume, donc toute la base.

Pour faire évoluer la base, après avoir modifié une entité :

```
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate
```

Relisez toujours la migration générée dans `migrations/` avant de l'appliquer et de la commiter.

Pour voir dans le Carnet de bord des ventes reconstituées (bons papier d'un événement passé), importez-les dans la base de développement :

```
docker compose exec php bin/console app:dev:import-sales var/import/ventes.json
```

Format du fichier : `comptoir-import-ventes`, version 1 (exemple : `tests/fixtures/import-ventes-exemple.json`). Chaque commande passe par le même code que l'API ; l'import est annulé en entier si le résultat diffère du bloc `expected`. L'événement créé est inactif ; `--replace` remplace un import précédent. La commande refuse de tourner hors développement. Le dossier `var/` n'est jamais commité : gardez-y les fichiers qui contiennent des données réelles.

Sur GitHub, chaque pull request lance automatiquement les tests, la vérification des migrations et de la configuration (onglet « Actions », fichier `.github/workflows/tests.yml`). Attendez la coche verte avant de fusionner.

## Consulter la base de données (phpMyAdmin)

phpMyAdmin démarre avec `docker compose up -d`.

1. Ouvrez <http://localhost:8082> : la connexion est automatique.
2. Choisissez la base `comptoir` à gauche, puis une table.

En développement, l'utilisateur et le mot de passe MySQL sont `comptoir` / `comptoir` (voir `compose.yaml`). phpMyAdmin n'est accessible que depuis votre ordinateur et n'existe qu'en développement. Préférez la consultation : une modification faite ici contourne les vérifications de l'application et l'historique des changements. Pour modifier un prix, un stock ou une icône, passez par l'administration.

## Tester l'API des tablettes avec Bruno

Le dossier `bruno/` contient une collection pour [Bruno](https://www.usebruno.com/), un logiciel gratuit pour envoyer des requêtes à l'API et vérifier les réponses.

### Toute la collection, en une commande

```sh
docker compose up -d --build   # la première fois, ou après une modification de docker/
bin/bruno
```

`bin/bruno` vide puis remplit une base à part, `comptoir_bruno`, servie sur `http://localhost:8081`, appaire les tablettes, puis lance toutes les requêtes dans le bon ordre. Votre base de développement (`http://localhost:8080`) n'est jamais touchée, et chaque lancement donne le même résultat. Tout tourne dans Docker : rien à installer, pas même Node. Le résultat se termine par « Tout est passé » ; sinon, le nom de la requête en échec et le test qui a échoué sont affichés.

### Requête par requête, dans le logiciel Bruno

1. Installez Bruno (`brew install --cask bruno`, ou depuis le site).
2. Dans Bruno : « Open Collection », puis choisissez le dossier `bruno/` du projet.
3. En haut à droite, sélectionnez l'environnement « Local ».
4. Suivez le déroulé décrit dans la collection (onglet « Docs ») : créez une tablette dans l'administration (ou avec `app:dev:test-devices`), recopiez son code dans la variable `pairingCode`, puis lancez les requêtes du dossier « Appairage ».

Chaque requête contient ses tests ; « Run » sur un dossier les lance tous. Le jeton reçu reste en mémoire dans Bruno et n'est jamais écrit dans les fichiers.

Si le port 8080, 8081 ou 8082 est déjà utilisé par un autre programme, arrêtez ce programme avant de lancer `docker compose up`.

## Installer sur un serveur

Le jour de l'événement, les tablettes se connectent à un serveur en ligne, en HTTPS, par le partage 4G d'un téléphone ou par le Wi-Fi de la salle. Le HTTPS est indispensable : sans lui, Chrome refuse le mode hors ligne et l'installation de l'application sur les tablettes. Rien n'est à installer sur les tablettes elles-mêmes.

Notre installation : un serveur privé virtuel (VPS) géré avec le panneau ISPConfig, avec Apache, PHP 8.4 et MySQL 8.4, sans Docker. L'environnement de développement (Docker, voir plus haut) reproduit la même pile. Les opérations sur ce serveur (mise à jour, sauvegarde, retour arrière, réglages d'Apache) sont décrites commande par commande dans [docs/hebergement.md](docs/hebergement.md).

Les commandes ci-dessous sont écrites pour un serveur où vous avez les droits d'administrateur (`sudo`) et où le serveur web tourne sous l'utilisateur `www-data`. **Hébergement avec panneau (ISPConfig, Plesk, cPanel, mutualisé)** : votre utilisateur SSH est en général déjà celui du site. Lancez alors les commandes **sans** `sudo -u www-data` et sans `chown`. Si plusieurs versions de PHP sont installées, `php -v` peut afficher une version trop ancienne : utilisez la commande de la bonne version (`php8.4 bin/console …`, `php8.4 <chemin de composer> …`), comme dans [docs/hebergement.md](docs/hebergement.md).

### Ce qu'il faut

- Un serveur web **Apache** ou **Nginx**, joignable depuis internet avec un nom de domaine et un certificat HTTPS (Let's Encrypt, gratuit).
- **PHP 8.4 ou plus récent**, avec les extensions `pdo_mysql`, `intl`, `ctype` et `iconv` (`php -m` les liste).
- Une base **MySQL 8.4** vide, créée chez votre hébergeur (souvent depuis son panneau, avec phpMyAdmin), en `utf8mb4`. Notez son nom, son utilisateur, son mot de passe et l'adresse du serveur MySQL.
- Un accès **SSH** au serveur.
- **Composer**. S'il n'est pas installé sur le serveur : `curl -sS https://getcomposer.org/installer | php`, puis remplacez `composer` par `php composer.phar` dans les commandes ci-dessous.

### Installation

1. Récupérez le code sur le serveur, en dehors du dossier publié par le serveur web :

   ```
   git clone https://github.com/VanAustin/comptoir-des-etoiles.git
   cd comptoir-des-etoiles
   ```

   Tant que le dépôt est privé, ajoutez d'abord sur GitHub une clé de déploiement en lecture seule (« Settings », « Deploy keys ») avec la clé SSH du serveur, et clonez avec l'adresse `git@github.com:VanAustin/comptoir-des-etoiles.git`.

2. Créez le fichier `.env.local`, qui règle l'application en production (remplacez l'adresse par la vôtre) :

   ```
   printf 'APP_ENV=prod\nAPP_DEBUG=0\nDEFAULT_URI=https://comptoir.example.org\n' > .env.local
   ```

   Ajoutez-y l'adresse de la base MySQL (remplacez utilisateur, mot de passe, serveur et nom de la base) :

   ```
   DATABASE_URL="mysql://utilisateur:mot-de-passe@serveur-mysql:3306/nom-de-la-base?serverVersion=8.4&charset=utf8mb4"
   ```

   Si le mot de passe contient des caractères spéciaux (`@`, `:`, `/`, `#`…), encodez-les (`@` devient `%40`, par exemple). Pour `serverVersion`, indiquez la version donnée par votre hébergeur (phpMyAdmin l'affiche : « Version du serveur »).

3. Installez les dépendances, sans les outils de développement :

   ```
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   ```

4. Créez les tables, puis le premier administrateur (la commande génère aussi `APP_SECRET` dans `.env.local`) :

   ```
   php bin/console doctrine:migrations:migrate
   php bin/console app:install
   ```

5. Donnez au serveur web le droit d'écrire dans `var/` (cache, journaux, sessions de l'administration et sauvegardes y sont créés). Par exemple, si le serveur web tourne sous l'utilisateur `www-data` :

   ```
   sudo chown -R www-data:www-data var
   ```

6. Faites pointer le site sur le dossier **`public/`**, jamais sur la racine du projet : `.env.local` (qui contient le mot de passe de la base) et `var/` ne doivent pas être accessibles par une adresse web.
   - **Apache** : le fichier `public/.htaccess` est fourni. Il redirige le HTTP vers le HTTPS et transmet le jeton des tablettes à l'application. Le site doit autoriser ce fichier (`AllowOverride All`) et le module `rewrite` doit être actif (`sudo a2enmod rewrite`). Réglages recommandés pour les tablettes (connexions gardées ouvertes, compression du JSON) : [docs/hebergement.md](docs/hebergement.md), section 4.
   - **Nginx** : partez de l'exemple `docs/deploiement/nginx.conf`.

7. Ouvrez l'adresse du site : l'accueil s'affiche. Connectez-vous à l'administration, créez l'événement, le menu et les tablettes.

### Serveur derrière un proxy

En entreprise ou chez certains hébergeurs, le HTTPS est assuré par un autre serveur placé devant le vôtre (proxy). L'application croit alors recevoir du HTTP simple : les cookies sécurisés et l'en-tête HSTS ne partent pas. Indiquez-lui de faire confiance au proxy, dans `.env.local` :

```
SYMFONY_TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
```

`REMOTE_ADDR` fait confiance à la machine qui contacte directement le serveur : n'utilisez cette valeur que si le serveur n'est joignable que par le proxy. Sinon, indiquez l'adresse IP du proxy. Après chaque modification de `.env.local` : `php bin/console cache:clear`.

### Mot de passe administrateur oublié

L'administration n'a pas de lien « mot de passe oublié ». Sur le serveur, choisissez un nouveau mot de passe :

```
php bin/console app:admin:password
```

La commande affiche l'identifiant de l'administrateur (utile s'il est oublié lui aussi) et demande deux fois le nouveau mot de passe. Les sessions ouvertes avec l'ancien sont fermées. En développement : `docker compose exec php bin/console app:admin:password`.

### Sauvegarde

```
php bin/console app:backup
```

La commande écrit un fichier SQL horodaté (structure et données) dans `var/backups/`, lisible par le seul propriétaire du fichier. Lancez-la avant et après chaque événement, et récupérez la copie sur votre ordinateur, par exemple avec `scp`. Elle peut tourner pendant que les tablettes enregistrent des commandes : elle lit un instantané cohérent de la base.

Pour restaurer : dans phpMyAdmin, sélectionnez la base, onglet « Importer », choisissez le fichier `.sql`. Sans phpMyAdmin, en SSH : `mysql --default-character-set=utf8mb4 -u <utilisateur> -p <base> < comptoir-<date>.sql`. Les tables sont remplacées par leur contenu au moment de la sauvegarde : les commandes enregistrées depuis sont perdues. La sauvegarde de votre hébergeur, si elle existe, est un filet de plus.

### Mettre à jour

> **Serveur de l'APE** : suivez [docs/hebergement.md](docs/hebergement.md), section 3, et non les commandes ci-dessous. Sur ce serveur, toujours `php8.4` (jamais `php` seul, qui lance PHP 7.3) et jamais `sudo`.

Notez d'abord la version du code (`git rev-parse --short HEAD`) et celle de la base (`php bin/console doctrine:migrations:current`), pour pouvoir revenir en arrière. Puis, si le serveur web tourne sous l'utilisateur `www-data` :

```
sudo -u www-data php bin/console app:backup
git pull
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
sudo rm -rf var/cache/prod
sudo -u www-data php bin/console cache:warmup
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
```

Sur un hébergement avec panneau, les mêmes commandes sans `sudo` ni `sudo -u www-data` (voir plus haut).

**Effacer puis reconstruire le cache est obligatoire après chaque `git pull`** : en production, Symfony réutilise l'ancien cache sans vérifier qu'il correspond encore au code, et des pages tombent en erreur 500 (`ArgumentCountError` dans le journal). Ne mettez jamais à jour pendant un événement. Procédure détaillée, avec les vérifications et le retour arrière (migration défaite **avant** de remettre l'ancien code) : [docs/hebergement.md](docs/hebergement.md), section 3.

## Installer l'application sur une tablette

Une fois la tablette appairée, dans Chrome : menu ⋮, puis « Ajouter au bureau ». Selon la version d'Android et de Chrome, l'option s'appelle aussi « Installer l'application » ou « Ajouter à l'écran d'accueil ». L'icône « Comptoir » ouvre directement l'écran de la tablette (caisse, cuisine ou Arrivées), sans barre d'adresse, même sans réseau.

Si l'icône ouvre un onglet Chrome avec la barre d'adresse, c'est un simple raccourci : supprimez-la (appui long) et refaites l'installation.

Après une mise à jour du serveur, un bandeau « Nouvelle version de l'application » apparaît sur les tablettes : touchez « Recharger » entre deux commandes.

## Procédures pour l'événement

Fiches à imprimer pour l'équipe, dans `docs/procedures/` : [bénévoles](docs/procedures/benevoles.md) (une page, pour tous), [jour J](docs/procedures/jour-j.md), [coupure réseau](docs/procedures/coupure-reseau.md), [clôture](docs/procedures/cloture.md) et [incidents](docs/procedures/incidents.md).

## Tester sur le terrain

À faire une fois dans la salle, avant la répétition générale, à l'emplacement exact des caisses et de la cuisine. Comptez dix minutes.

1. Sur un téléphone, activez le partage de connexion 4G (ou connectez-vous au Wi-Fi de la salle s'il existe).
2. Connectez une tablette à ce partage et ouvrez l'adresse du site dans Chrome : la page d'accueil doit s'afficher en quelques secondes, avec le cadenas du HTTPS.
3. Appairez la tablette en caisse (code créé dans l'administration), puis encaissez une commande d'essai.
4. Si possible, une deuxième tablette en cuisine : la commande doit y apparaître en 3 secondes environ. Au-delà de 10 secondes, le réseau est trop faible à cet emplacement.
5. Notez la qualité du réseau à chaque emplacement (nombre de barres 4G, temps d'affichage), et essayez un autre opérateur si le premier est faible.
6. Faites ce test avec un événement « Essai » : activez ensuite le vrai événement, ses ventes partent de zéro.

En cas de coupure le jour J, les caisses continuent d'encaisser : les commandes sont envoyées au retour du réseau (mode hors ligne, étape 3).

## Licence

Voir le fichier [LICENSE](LICENSE).
