# Le Comptoir des étoiles

Application de caisse pour les événements d'une association de parents d'élèves : caisse, cuisine, écran d'appel client, suivi des stocks et bilan.

Application en cours de développement. Pour l'installer sur un serveur, voir « Installer sur un serveur ».

## Démarrer en développement

PHP et Composer n'ont pas besoin d'être installés sur votre ordinateur : tout tourne dans Docker.

Prérequis : [Docker Desktop](https://www.docker.com/products/docker-desktop/).

1. Construisez et démarrez le conteneur :

   ```
   docker compose up -d --build
   ```

2. Installez les dépendances PHP :

   ```
   docker compose exec php composer install
   ```

3. Créez la base de données (un fichier SQLite dans `var/`) :

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

Pour lancer les tests :

```
docker compose exec php bin/phpunit
```

Pour arrêter le conteneur : `docker compose down`.

## Consulter la base de données (Adminer)

Adminer est un outil web proche de phpMyAdmin, qui sait lire la base SQLite du projet. Il démarre avec `docker compose up -d`.

1. Ouvrez <http://localhost:8082> : le type de base (SQLite) et le fichier (`/var/db/data_dev.db`) sont déjà remplis.
2. Mot de passe : `comptoir` (laissez « Utilisateur » vide).
3. Cliquez sur une table à gauche, puis sur « Afficher les données ».

Adminer n'est accessible que depuis votre ordinateur et n'existe qu'en développement. Préférez la consultation : une modification faite ici contourne les vérifications de l'application et l'historique des changements. Pour modifier un prix, un stock ou une icône, passez par l'administration.

## Tester l'API des tablettes avec Bruno

Le dossier `bruno/` contient une collection pour [Bruno](https://www.usebruno.com/), un logiciel gratuit pour envoyer des requêtes à l'API et vérifier les réponses.

1. Installez Bruno (`brew install --cask bruno`, ou depuis le site).
2. Dans Bruno : « Open Collection », puis choisissez le dossier `bruno/` du projet.
3. En haut à droite, sélectionnez l'environnement « Local ».
4. Suivez le déroulé décrit dans la collection (onglet « Docs ») : créez une tablette dans l'administration, recopiez son code dans la variable `pairingCode`, puis lancez les requêtes du dossier « Appairage ».

Chaque requête contient ses tests ; « Run » sur un dossier les lance tous. Le jeton reçu reste en mémoire dans Bruno et n'est jamais écrit dans les fichiers.

Si le port 8080 ou 8082 est déjà utilisé par un autre programme, arrêtez ce programme avant de lancer `docker compose up`.

## Installer sur un serveur

Le jour de l'événement, les tablettes se connectent à un serveur en ligne, en HTTPS, par le partage 4G d'un téléphone ou par le Wi-Fi de la salle. Le HTTPS est indispensable : sans lui, Chrome refuse le mode hors ligne et l'installation de l'application sur les tablettes. Rien n'est à installer sur les tablettes elles-mêmes.

### Ce qu'il faut

- Un serveur web **Apache** ou **Nginx**, joignable depuis internet avec un nom de domaine et un certificat HTTPS (Let's Encrypt, gratuit).
- **PHP 8.2 ou plus récent**, avec les extensions `pdo_sqlite`, `intl`, `ctype` et `iconv` (`php -m` les liste).
- Un accès **SSH** au serveur.
- **Composer**. S'il n'est pas installé sur le serveur : `curl -sS https://getcomposer.org/installer | php`, puis remplacez `composer` par `php composer.phar` dans les commandes ci-dessous.

Pas de MySQL : la base est un simple fichier SQLite dans `var/`.

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

3. Installez les dépendances, sans les outils de développement :

   ```
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   ```

4. Créez la base de données, puis le premier administrateur (la commande génère aussi `APP_SECRET` dans `.env.local`) :

   ```
   php bin/console doctrine:migrations:migrate
   php bin/console app:install
   ```

5. Donnez au serveur web le droit d'écrire dans `var/` (la base et ses fichiers temporaires y sont créés). Par exemple, si le serveur web tourne sous l'utilisateur `www-data` :

   ```
   sudo chown -R www-data:www-data var
   ```

6. Faites pointer le site sur le dossier **`public/`**, jamais sur la racine du projet : la base de données et `.env.local` ne doivent pas être accessibles par une adresse web.
   - **Apache** : le fichier `public/.htaccess` est fourni. Il redirige le HTTP vers le HTTPS et transmet le jeton des tablettes à l'application. Le site doit autoriser ce fichier (`AllowOverride All`) et le module `rewrite` doit être actif (`sudo a2enmod rewrite`).
   - **Nginx** : partez de l'exemple `docs/deploiement/nginx.conf`.

7. Ouvrez l'adresse du site : l'accueil s'affiche. Connectez-vous à l'administration, créez l'événement, le menu et les tablettes.

### Serveur derrière un proxy

En entreprise ou chez certains hébergeurs, le HTTPS est assuré par un autre serveur placé devant le vôtre (proxy). L'application croit alors recevoir du HTTP simple : les cookies sécurisés et l'en-tête HSTS ne partent pas. Indiquez-lui de faire confiance au proxy, dans `.env.local` :

```
SYMFONY_TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
```

`REMOTE_ADDR` fait confiance à la machine qui contacte directement le serveur : n'utilisez cette valeur que si le serveur n'est joignable que par le proxy. Sinon, indiquez l'adresse IP du proxy. Après chaque modification de `.env.local` : `php bin/console cache:clear`.

### Sauvegarde

```
php bin/console app:backup
```

La commande copie la base, horodatée, dans `var/backups/` (lisible par le seul propriétaire du fichier). Lancez-la avant et après chaque événement, et récupérez la copie sur votre ordinateur, par exemple avec `scp`. Elle peut tourner pendant que les tablettes enregistrent des commandes.

### Mettre à jour

```
php bin/console app:backup
git pull
composer install --no-dev --optimize-autoloader --classmap-authoritative
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

Évitez de mettre à jour pendant un événement.

## Installer l'application sur une tablette

Une fois la tablette appairée, dans Chrome : menu ⋮, puis « Ajouter à l'écran d'accueil » (ou « Installer l'application »). L'icône « Comptoir » ouvre directement l'écran de la tablette (caisse, cuisine ou Arrivées), même sans réseau.

Après une mise à jour du serveur, un bandeau « Nouvelle version de l'application » apparaît sur les tablettes : touchez « Recharger » entre deux commandes.

## Tester sur le terrain

À faire une fois dans la salle, avant la répétition générale, à l'emplacement exact des caisses et de la cuisine. Comptez dix minutes.

1. Sur un téléphone, activez le partage de connexion 4G (ou connectez-vous au Wi-Fi de la salle s'il existe).
2. Connectez une tablette à ce partage et ouvrez l'adresse du site dans Chrome : la page d'accueil doit s'afficher en quelques secondes, avec le cadenas du HTTPS.
3. Appairez la tablette en caisse (code créé dans l'administration), puis encaissez une commande d'essai.
4. Si possible, une deuxième tablette en cuisine : la commande doit y apparaître en moins de 5 secondes.
5. Notez la qualité du réseau à chaque emplacement (nombre de barres 4G, temps d'affichage), et essayez un autre opérateur si le premier est faible.
6. Faites ce test avec un événement « Essai » : activez ensuite le vrai événement, ses ventes partent de zéro.

En cas de coupure le jour J, les caisses continuent d'encaisser : les commandes sont envoyées au retour du réseau (mode hors ligne, étape 3).

## Licence

Voir le fichier [LICENSE](LICENSE).
