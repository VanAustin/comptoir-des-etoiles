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

Comptez dix minutes. Faites-le un jour sans événement, après avoir vérifié sur GitHub que la dernière pull request est fusionnée sur `main` avec la coche verte.

### 3.1 Vérifier que rien n'a été modifié à la main sur le serveur

```
git status
```

Résultat attendu : `nothing to commit, working tree clean` (ou « rien à valider, la copie de travail est propre »). Le fichier `.env.local` n'apparaît pas : c'est normal, Git l'ignore.

Si des fichiers sont listés : **arrêtez-vous**. Quelqu'un a modifié le code sur le serveur ; la mise à jour l'écraserait. Notez la liste et demandez de l'aide.

### 3.2 Noter la version actuelle (pour revenir en arrière)

```
git rev-parse --short HEAD
```

Résultat attendu : un code de 7 caractères, par exemple `0c34dd7`. **Notez-le** (papier ou fichier sur le Mac) : c'est la version qui fonctionne aujourd'hui.

### 3.3 Sauvegarder la base de données

```
php bin/console app:backup
```

Résultat attendu : `[OK] Base sauvegardée dans …/var/backups/comptoir-<date>.sql (… Ko).` Copiez-la dans votre dossier personnel du serveur, hors du projet :

```
cp var/backups/comptoir-*.sql ~/
ls -l ~/comptoir-*.sql
```

Résultat attendu : le fichier du jour apparaît, avec une taille supérieure à 0.

Si `app:backup` ou `cp` répond « Permission denied », relancez la commande en la précédant de `sudo ` (par exemple `sudo cp var/backups/comptoir-*.sql ~/`).

### 3.4 Récupérer la nouvelle version

```
git pull
```

Résultat attendu : une liste de fichiers modifiés, ou `Already up to date.` si rien n'a changé (dans ce cas, la suite est sans effet, vous pouvez vous arrêter).

### 3.5 Installer les dépendances

```
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

Si le serveur répond `composer: command not found`, remplacez `composer` par `php composer.phar`.

Résultat attendu : des lignes `Upgrading …` (ou `Nothing to install, update or remove`), puis `Generating optimized autoload files`, sans ligne contenant `Error` ni `Problem`.

### 3.6 Mettre à jour la structure de la base

```
php bin/console doctrine:migrations:migrate --no-interaction
```

Résultat attendu : `[OK] Already at the latest version (…)` (aucune modification de structure) ou `[OK] Successfully migrated to version …`.

### 3.7 Vider le cache et rendre ses droits au serveur web

```
php bin/console cache:clear
sudo chown -R www-data:www-data var
```

Résultat attendu : `[OK] Cache for the "prod" environment (debug=false) was successfully cleared.` La seconde commande n'affiche rien.

La seconde commande est indispensable : les fichiers créés par vos commandes vous appartiennent, et Apache (utilisateur `www-data`) doit pouvoir les lire et écrire dans `var/`.

### 3.8 Vérifier

1. Sur le serveur :

   ```
   curl -sI https://<adresse du site>/login | head -n 1
   ```

   Résultat attendu : `HTTP/1.1 200 OK` ou `HTTP/2 200`.

2. Dans Chrome, sur le Mac : ouvrez l'adresse du site, connectez-vous à l'administration, ouvrez **Menu** puis **Carnet de bord**. Les pages s'affichent normalement.

3. Si une page affiche une erreur, regardez les dernières erreurs d'Apache :

   ```
   sudo tail -n 30 /var/log/apache2/error.log
   ```

   Notez ce qui s'affiche, puis passez à « Revenir en arrière ».

Les tablettes affichent un bandeau « Nouvelle version de l'application » : touchez « Recharger ».

### 3.9 Revenir en arrière

À faire seulement si la vérification échoue. Remplacez `<version notée>` par le code noté à l'étape 3.2.

```
cd <dossier du projet>
git reset --hard <version notée>
composer install --no-dev --optimize-autoloader --classmap-authoritative
php bin/console cache:clear
sudo chown -R www-data:www-data var
```

`git reset --hard` remet le code exactement dans l'état noté. Il ne touche ni à `.env.local` ni à `var/` (ignorés par Git).

Si l'étape 3.6 avait affiché `Successfully migrated`, la structure de la base a changé : restaurez aussi la sauvegarde de l'étape 3.3 (voir « Sauvegarde » dans `README.md` : phpMyAdmin, onglet « Importer »). Les commandes enregistrées entre la sauvegarde et la restauration seraient perdues : c'est pourquoi on ne met jamais à jour pendant un événement.

Vérifiez à nouveau (étape 3.8). La prochaine mise à jour se fera normalement avec `git pull`.

## 4. Mise à jour de sécurité Symfony 7.4.23 (novembre 2026)

Symfony a publié le 4 novembre 2026 une série de correctifs de sécurité (version 7.4.23). La pull request `securite/symfony-7.4.23` les apporte. Elle ne change que des dépendances `symfony/*` : aucune modification de la base, aucun réglage à changer dans l'application.

### 4.1 Appliquer

Suivez la section 3 en entier. Points particuliers :

- Étape 3.5 : des lignes `Upgrading symfony/… (v7.4.21 => v7.4.23)` s'affichent.
- Étape 3.6 : le résultat attendu est `[OK] Already at the latest version`.

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

Section 3.9, sans restauration de la base (aucune migration dans cette mise à jour). Revenir en arrière remet les failles corrigées : ne le faites que si l'application ne fonctionne plus.
