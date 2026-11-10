# Hébergement : notre serveur de production

Ce document décrit, commande par commande, les opérations sur le serveur de production : un serveur privé virtuel (VPS) chez OVHcloud, avec Apache, PHP 8.4 et MySQL 8.4. Il ne suppose aucune connaissance préalable en administration de serveur.

**Règle d'or : jamais de mise à jour pendant un événement**, ni dans les heures qui précèdent. En cas de doute, ne faites rien : la version en place fonctionne.

## Conventions de ce document

- Les lignes dans un cadre gris sont des commandes : tapez-les (ou copiez-les) telles quelles, puis appuyez sur Entrée.
- Ce qui est entre `<` et `>` est à remplacer par votre valeur, **sans** les signes `<` et `>`. Par exemple `<dossier du projet>` devient `/var/www/comptoir-des-etoiles`.
- Les lignes « Résultat attendu » indiquent ce qui doit s'afficher. Si ce n'est pas le cas, arrêtez-vous et passez à « Revenir en arrière » (ou ne faites rien de plus si vous n'avez encore rien modifié).
- Le mot de passe demandé par `sudo` est celui de votre compte sur le serveur. Rien ne s'affiche pendant que vous le tapez, c'est normal.

## 1. Se connecter au serveur

Sur le Mac, ouvrez l'application **Terminal** (Applications, Utilitaires), puis :

```
ssh <utilisateur>@<adresse du serveur>
```

L'utilisateur et l'adresse du serveur figurent dans l'e-mail de livraison du VPS envoyé par OVHcloud (ou dans votre espace client, rubrique « Bare Metal Cloud », « Serveurs privés virtuels »).

Résultat attendu : une invite qui se termine par `$`, par exemple `ubuntu@vps-1234:~$`. Vous tapez désormais les commandes **sur le serveur**.

Pour vous déconnecter à la fin : `exit`.

## 2. Aller dans le dossier du projet

Si vous ne connaissez pas le dossier, Apache le connaît :

```
grep -ri documentroot /etc/apache2/sites-enabled/
```

Résultat attendu : une ligne qui se termine par `…/public`, par exemple `DocumentRoot /var/www/comptoir-des-etoiles/public`. Le dossier du projet est ce chemin **sans** `/public` à la fin.

```
cd <dossier du projet>
ls
```

Résultat attendu : la liste contient notamment `bin`, `composer.json`, `public`, `src` et `var`.

## 3. Mettre à jour l'application

Procédure générale, valable pour toute mise à jour. Pour la grande mise à jour de novembre 2026 (sécurité Symfony, retrait de Mercure, Apache et PHP 8.4, interrogations des tablettes), suivez plutôt la **section 5**, qui reprend ces étapes une par une avec les résultats attendus.

Comptez quinze minutes. Faites-le un jour sans événement, après avoir vérifié sur GitHub que la dernière pull request est fusionnée sur `main` avec la coche verte.

Les commandes qui écrivent dans `var/` (sauvegarde, cache, migrations) sont lancées **au nom du serveur web** (`sudo -u www-data …`) : les fichiers créés appartiennent ainsi à Apache, qui doit pouvoir les lire et les modifier.

### 3.1 Vérifier que rien n'a été modifié à la main sur le serveur

```
git status
```

Résultat attendu : `nothing to commit, working tree clean` (ou « rien à valider, la copie de travail est propre »). Le fichier `.env.local` n'apparaît pas : c'est normal, Git l'ignore.

Si des fichiers sont listés : **arrêtez-vous**. Quelqu'un a modifié le code sur le serveur ; la mise à jour l'écraserait. Notez la liste et demandez de l'aide.

### 3.2 Noter la version actuelle (pour revenir en arrière)

```
git rev-parse --short HEAD
sudo -u www-data php bin/console doctrine:migrations:current
```

Résultat attendu : un code de 7 caractères, par exemple `0c34dd7`, puis une ligne comme `DoctrineMigrations\Version20261103152436 - Catégories vendues et vente directe des caisses`. **Notez le code, et la seconde ligne jusqu'au tiret** (ici `DoctrineMigrations\Version20261103152436`), sur papier ou dans un fichier sur le Mac : c'est la version du code et la version de la base qui fonctionnent aujourd'hui.

### 3.3 Sauvegarder la base de données

```
sudo -u www-data php bin/console app:backup
```

Résultat attendu : `[OK] Base sauvegardée dans …/var/backups/comptoir-<date>.sql (… Ko).` Copiez-la dans votre dossier personnel du serveur, hors du projet :

```
cp var/backups/comptoir-*.sql ~/
ls -l ~/comptoir-*.sql
```

Résultat attendu : le fichier du jour apparaît, avec une taille supérieure à 0. Si `cp` répond « Permission denied », relancez-la précédée de `sudo ` : `sudo cp var/backups/comptoir-*.sql ~/`.

### 3.4 Récupérer la nouvelle version

```
git pull
```

Résultat attendu : une liste de fichiers modifiés, ou `Already up to date.` si rien n'a changé (dans ce cas, la suite est sans effet, vous pouvez vous arrêter).

À partir d'ici et jusqu'à l'étape 3.6, le site peut afficher une erreur pendant une ou deux minutes : c'est normal, le nouveau code n'est pas encore prêt.

### 3.5 Installer les dépendances

```
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
```

Si le serveur répond `composer: command not found`, remplacez `composer` par `php composer.phar`.

Résultat attendu : des lignes `Installing …`, `Upgrading …` ou `Removing …` (ou `Nothing to install, update or remove`), puis `Generating optimized autoload files`, sans ligne contenant `Error` ni `Problem`.

`--no-scripts` : le cache est reconstruit à l'étape suivante, au nom du serveur web.

### 3.6 Reconstruire le cache

```
sudo rm -rf var/cache/prod
sudo -u www-data php bin/console cache:warmup
sudo chown -R www-data:www-data var
sudo systemctl reload apache2
```

Résultat attendu : `[OK] Cache for the "prod" environment (debug=false) was successfully warmed.` Les autres commandes n'affichent rien.

- On efface l'ancien cache plutôt que de le « vider » : en production, Symfony réutilise l'ancien cache sans vérifier s'il correspond encore au code, et la commande de vidage elle-même peut échouer après une grosse mise à jour.
- `reload` relit le code sans couper les connexions en cours : il garantit qu'Apache ne garde pas en mémoire d'ancienne version des fichiers PHP.

### 3.7 Mettre à jour la structure de la base

```
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
```

Résultat attendu : `[OK] Already at the latest version (…)` (aucune modification de structure) ou `[OK] Successfully migrated to version …`. Notez lequel des deux s'affiche.

### 3.8 Vérifier

1. Sur le serveur, la page de connexion et l'API des tablettes (remplacez `<adresse du site>`, par exemple `caisse.exemple.fr`, sans `https://`) :

   ```
   curl -sI https://<adresse du site>/login | head -n 1
   curl -s https://<adresse du site>/api/menu
   ```

   Résultat attendu : `HTTP/1.1 200 OK` (ou `HTTP/2 200`), puis `{"error":"Cette tablette n\u0027est pas appair\u00e9e. Appairez-la depuis l\u0027administration."}` (les `\u0027` et `\u00e9` sont l'apostrophe et le « é », écrits à la façon du JSON). Ce message prouve que l'API répond : une requête sans tablette est refusée, comme il se doit.

2. Dans Chrome, sur le Mac : ouvrez l'adresse du site, connectez-vous à l'administration, ouvrez **Menu** puis **Carnet de bord**. Les pages s'affichent normalement.

3. Sur chaque tablette : un bandeau « Nouvelle version de l'application » apparaît, touchez « Recharger ». S'il n'apparaît pas au bout d'une minute, fermez l'application et rouvrez-la.

4. Si une page affiche une erreur, regardez les dernières erreurs d'Apache :

   ```
   sudo tail -n 30 /var/log/apache2/error.log
   ```

   Notez ce qui s'affiche, puis passez à « Revenir en arrière ».

### 3.9 Revenir en arrière

À faire seulement si la vérification échoue. Dans l'ordre, **sans en sauter** :

1. **La base, d'abord**, seulement si l'étape 3.7 avait affiché `Successfully migrated`. C'est le nouveau code qui sait défaire sa migration : cette commande se lance donc **avant** de remettre l'ancien code. Remplacez `<version de la base notée>` par la version notée à l'étape 3.2 (sans la description après le tiret), en gardant les apostrophes, par exemple `'DoctrineMigrations\Version20261103152436'` :

   ```
   sudo -u www-data php bin/console doctrine:migrations:migrate '<version de la base notée>' --no-interaction
   ```

   Résultat attendu : `[OK] Successfully migrated to version: …` avec la version notée. Les commandes enregistrées depuis la mise à jour sont gardées.

   Si cette commande échoue, restaurez la sauvegarde (étape 3.10), puis continuez ci-dessous.

2. **Le code**, en remplaçant `<version du code notée>` par le code de 7 caractères noté à l'étape 3.2 :

   ```
   git reset --hard <version du code notée>
   composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
   sudo rm -rf var/cache/prod
   sudo -u www-data php bin/console cache:warmup
   sudo chown -R www-data:www-data var
   sudo systemctl reload apache2
   ```

   `git reset --hard` remet le code exactement dans l'état noté. Il ne touche ni à `.env.local` ni à `var/` (ignorés par Git).

3. Vérifiez à nouveau (étape 3.8). La prochaine mise à jour se fera normalement avec `git pull`.

### 3.10 Restaurer la sauvegarde (dernier recours)

Seulement si l'étape 1 de 3.9 a échoué. La base revient exactement à son état de l'étape 3.3 : **les commandes enregistrées depuis seraient perdues**, c'est pourquoi on ne met jamais à jour pendant un événement.

Retrouvez l'utilisateur, le mot de passe et le nom de la base :

```
grep DATABASE_URL .env.local
```

La ligne a la forme `DATABASE_URL="mysql://<utilisateur>:<mot de passe>@127.0.0.1:3306/<base>?…"`. Puis, avec le fichier copié à l'étape 3.3 :

```
mysql --default-character-set=utf8mb4 -u <utilisateur> -p <base> < ~/comptoir-<date>.sql
```

Tapez le mot de passe quand il est demandé (rien ne s'affiche, c'est normal). Résultat attendu : rien ne s'affiche, l'invite revient. Reprenez à l'étape 2 de 3.9.

## 4. Mise à jour de sécurité Symfony 7.4.23 (novembre 2026)

Symfony a publié le 4 novembre 2026 une série de correctifs de sécurité (version 7.4.23). La pull request `securite/symfony-7.4.23` les apporte. Elle ne change que des dépendances `symfony/*` : aucune modification de la base, aucun réglage à changer dans l'application.

### 4.1 Appliquer

Cette mise à jour fait partie de la grande mise à jour de novembre 2026 : suivez la **section 5**. Si vous l'appliquez seule, suivez la section 3 en entier. Points particuliers :

- Étape 3.5 : des lignes `Upgrading symfony/… (v7.4.21 => v7.4.23)` s'affichent.
- Étape 3.7 : le résultat attendu est `[OK] Already at the latest version`.

### 4.2 Vérifier la version installée

```
composer show symfony/http-foundation | grep versions
```

Résultat attendu : `versions : * v7.4.23`.

```
composer audit --no-dev
```

Résultat attendu : `No security vulnerability advisories found.`

### 4.3 Vérifier le réglage des proxies de confiance

Une des alertes concerne le raccourci `private_ranges` (ou `PRIVATE_SUBNETS`) dans les proxies de confiance. Le dépôt ne l'utilise pas ; reste à vérifier le `.env.local` du serveur :

```
grep -n TRUSTED .env.local
```

Résultat attendu : **rien ne s'affiche**. Notre serveur assure lui-même le HTTPS (Let's Encrypt, sans proxy devant Apache) : la variable `SYMFONY_TRUSTED_PROXIES` n'a pas lieu d'être.

Si une ligne `SYMFONY_TRUSTED_PROXIES=…` s'affiche, ne la modifiez pas vous-même : notez-la et demandez de l'aide. Elle n'est utile que si un autre serveur (proxy) est placé devant le nôtre.

### 4.4 Revenir en arrière

Section 3.9, sans l'étape 1 (aucune migration dans cette mise à jour). Revenir en arrière remet les failles corrigées : ne le faites que si l'application ne fonctionne plus.

## 5. Grande mise à jour de novembre 2026

Une seule mise à jour apporte quatre changements fusionnés sur `main` :

- le correctif de sécurité Symfony 7.4.23 (section 4) ;
- le retrait de Mercure : l'application n'en dépend plus, rien à installer ni à régler ;
- Apache et PHP 8.4 en développement, comme ici : le code exige désormais **PHP 8.4** ;
- des interrogations plus économes et plus rapides : les tablettes voient une commande en 3 secondes au plus. Cette partie ajoute **une migration** (deux colonnes dans la table `event`).

Comptez trente minutes, un jour sans événement, avec le Mac et une tablette (ou un téléphone avec Chrome) à portée de main. Vérifiez d'abord sur GitHub que la pull request « Interrogations économes et précises » est fusionnée sur `main`, avec la coche verte.

Connectez-vous au serveur et allez dans le dossier du projet (sections 1 et 2). Toutes les commandes ci-dessous se tapent dans ce dossier.

### 5.1 Vérifier que le serveur est prêt

```
git status
```

Résultat attendu : `nothing to commit, working tree clean`. Sinon, **arrêtez-vous** (voir 3.1).

```
php -v
```

Résultat attendu : la première ligne commence par `PHP 8.4`. Si elle affiche `PHP 8.3` ou moins, **arrêtez-vous** : rien n'a été modifié, la version en place continue de fonctionner. Demandez de l'aide pour installer PHP 8.4.

```
ls /etc/apache2/mods-enabled/ /etc/apache2/conf-enabled/ | grep php
```

Résultat attendu : des lignes contenant `php8.4` (par exemple `php8.4.load` ou `php8.4-fpm.conf`), et aucun autre numéro de version. Sinon, **arrêtez-vous** et demandez de l'aide : Apache utiliserait une autre version de PHP que la ligne de commande.

### 5.2 Noter les versions actuelles

```
git rev-parse --short HEAD
sudo -u www-data php bin/console doctrine:migrations:current
```

Résultat attendu : un code de 7 caractères, puis très probablement `DoctrineMigrations\Version20261103152436 - Catégories vendues et vente directe des caisses`. **Notez le code, et la seconde ligne jusqu'au tiret** (`DoctrineMigrations\Version20261103152436`) : ils servent à revenir en arrière.

### 5.3 Sauvegarder la base, avant toute modification

```
sudo -u www-data php bin/console app:backup
cp var/backups/comptoir-*.sql ~/
ls -l ~/comptoir-*.sql
```

Résultat attendu : `[OK] Base sauvegardée dans …`, puis le fichier du jour dans la liste, avec une taille supérieure à 0. Sans ce fichier, **ne continuez pas**.

Rapatriez ensuite une copie sur le Mac : si le serveur tombait en panne, la sauvegarde restée sur le serveur serait perdue avec lui. Laissez la connexion au serveur ouverte. Dans Terminal, ouvrez **une nouvelle fenêtre** (menu Shell, Nouvelle fenêtre) : ses commandes s'exécutent sur le Mac. Tapez-y :

```
mkdir -p ~/Documents/comptoir-sauvegardes
scp '<utilisateur>@<adresse du serveur>:comptoir-*.sql' ~/Documents/comptoir-sauvegardes/
ls -l ~/Documents/comptoir-sauvegardes/
```

- `<utilisateur>@<adresse du serveur>` : les mêmes valeurs que pour `ssh` (section 1). Si un mot de passe est demandé, c'est celui de la connexion `ssh`.
- Gardez les apostrophes autour de la première partie : elles demandent au serveur de chercher lui-même les fichiers `comptoir-*.sql`, rangés dans votre dossier personnel du serveur.

Résultat attendu : une ligne par fichier copié, avec `100%`, puis la liste du dossier, où apparaît le fichier du jour (par exemple `comptoir-2026-11-20_143005.sql`) avec la même taille que sur le serveur.

Le dossier `Documents/comptoir-sauvegardes` est **hors du dépôt Git** : ne rangez jamais une sauvegarde dans le dossier du projet sur le Mac. Elle contient les données réelles de l'association (commandes, empreintes des mots de passe et des jetons) et ne doit pas finir sur GitHub. Ne la partagez pas.

Revenez ensuite à la fenêtre connectée au serveur pour la suite.

### 5.4 Récupérer la nouvelle version

```
git pull
```

Résultat attendu : une longue liste de fichiers, dont `migrations/Version20261110140502.php` (créé) et `src/Realtime/…` (supprimés). Le site peut afficher une erreur jusqu'à la fin de l'étape 5.6 : c'est normal.

### 5.5 Installer les dépendances

```
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
```

Résultat attendu :

- des lignes `Removing symfony/mercure-bundle`, `Removing symfony/mercure`, `Removing lcobucci/jwt`… (Mercure s'en va) ;
- des lignes `Upgrading symfony/… (v7.4.… => v7.4.23)` (correctif de sécurité) ;
- puis `Generating optimized autoload files`, sans ligne contenant `Error` ni `Problem`.

Si un message parle de `php` et de `8.4` (`Your requirements could not be resolved…`), PHP est trop ancien : passez à 5.9, étape 2 seulement (la base n'a pas encore changé).

### 5.6 Reconstruire le cache

```
sudo rm -rf var/cache/prod
sudo -u www-data php bin/console cache:warmup
sudo chown -R www-data:www-data var
sudo systemctl reload apache2
```

Résultat attendu : `[OK] Cache for the "prod" environment (debug=false) was successfully warmed.` Les autres commandes n'affichent rien.

Indispensable cette fois : l'ancien cache mentionne encore Mercure, et Symfony le réutiliserait tel quel.

### 5.7 Mettre à jour la structure de la base

```
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:migrations:current
```

Résultat attendu : `[OK] Successfully migrated to version: DoctrineMigrations\Version20261110140502`, puis `DoctrineMigrations\Version20261110140502 - Révisions des commandes et du menu par événement`. L'opération est instantanée et ne touche à aucune donnée existante.

### 5.8 Vérifier

1. **Versions et sécurité** :

   ```
   composer show symfony/http-foundation | grep versions
   composer audit --no-dev
   composer show | grep -i mercure
   ```

   Résultat attendu : `versions : * v7.4.23`, puis `No security vulnerability advisories found.`, puis rien du tout (plus aucun paquet Mercure).

2. **Proxies de confiance** : faites la vérification 4.3.

3. **Le site et l'API**, depuis le serveur (`<adresse du site>` sans `https://`) :

   ```
   curl -sI https://<adresse du site>/ | head -n 1
   curl -sI https://<adresse du site>/login | head -n 1
   curl -s https://<adresse du site>/api/menu
   ```

   Résultat attendu : `HTTP/1.1 200 OK` (ou `HTTP/2 200`) deux fois, puis `{"error":"Cette tablette n\u0027est pas appair\u00e9e. Appairez-la depuis l\u0027administration."}` (voir 3.8).

4. **L'administration**, dans Chrome sur le Mac : ouvrez le site, connectez-vous. En haut de chaque page, l'événement actif s'affiche (« Événement actif : … », nouveauté de cette mise à jour). Ouvrez **Menu**, **Tablettes**, **Carnet de bord** : les pages s'affichent normalement.

5. **Une tablette** :

   - **Une tablette déjà appairée** : allumez-la, ouvrez l'application. Un bandeau « Nouvelle version de l'application » apparaît : touchez « Recharger ». À faire sur **chaque** tablette : tant qu'elle n'a pas rechargé, une tablette cuisine afficherait des temps d'attente faux. L'écran s'affiche normalement (menu sur une caisse, liste des commandes sur la cuisine, numéros sur l'écran Arrivées).
   - **Aucune tablette appairée** : dans l'administration, **Tablettes**, ajoutez une tablette « Essai mise à jour », rôle **Arrivées** si l'événement actif a une cuisine, **Caisse** sinon. Sur un téléphone ou une tablette, ouvrez `https://<adresse du site>/appairer` dans Chrome et tapez le code affiché. L'écran de la tablette s'affiche. Sur une caisse, **n'encaissez rien** : ce serait une vraie vente de l'événement. Révoquez ensuite cette tablette dans l'administration.
   - **Si une caisse et une cuisine sont appairées** sur un événement d'essai : encaissez une commande, elle doit apparaître en cuisine en 3 secondes au plus, avec « 0 min » d'attente. Annulez-la ensuite depuis le Carnet de bord.

6. Si quelque chose ne va pas, regardez les dernières erreurs d'Apache, notez-les, puis passez à 5.9 :

   ```
   sudo tail -n 30 /var/log/apache2/error.log
   ```

Le fichier `.env.local` contient peut-être encore des lignes `MERCURE_…` : elles ne servent plus et ne gênent pas. Laissez-les : on ne modifie pas `.env.local` le jour d'une mise à jour.

### 5.9 Revenir en arrière

Seulement si la vérification échoue. Dans l'ordre, sans en sauter :

1. **La base**, avec le **nouveau** code encore en place (c'est lui qui sait défaire sa migration). Remplacez `<version de la base notée>` par la version notée à l'étape 5.2 (sans la description après le tiret), en gardant les apostrophes, par exemple `'DoctrineMigrations\Version20261103152436'` :

   ```
   sudo -u www-data php bin/console doctrine:migrations:migrate '<version de la base notée>' --no-interaction
   sudo -u www-data php bin/console doctrine:migrations:current
   ```

   Résultat attendu : `[OK] Successfully migrated to version: …` avec la version notée, puis la même version suivie de sa description. Les deux colonnes ajoutées sont retirées ; les commandes enregistrées depuis la mise à jour sont gardées.

   Si la première commande échoue (par exemple parce que l'étape 5.6 n'a pas abouti), restaurez la sauvegarde avec l'étape 3.10, puis continuez.

2. **Le code**, avec le code de 7 caractères noté à l'étape 5.2 :

   ```
   git reset --hard <version du code notée>
   composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
   sudo rm -rf var/cache/prod
   sudo -u www-data php bin/console cache:warmup
   sudo chown -R www-data:www-data var
   sudo systemctl reload apache2
   ```

   Résultat attendu : `composer install` remet les anciennes versions (`Downgrading …`, `Installing symfony/mercure…`), puis `[OK] Cache … successfully warmed.`

3. **Vérifiez** comme à l'étape 5.8, points 3 et 4 (sans la mention de l'événement actif en haut des pages, qui n'existait pas encore). Sur les tablettes, touchez « Recharger » si le bandeau apparaît.

Revenir en arrière remet aussi les failles corrigées par Symfony 7.4.23 : ne le faites que si l'application ne fonctionne plus, et demandez de l'aide pour retenter la mise à jour.
