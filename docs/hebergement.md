# Hébergement : notre serveur de production

Ce document décrit, commande par commande, les opérations sur le serveur de production : un serveur privé virtuel (VPS) chez OVHcloud, géré avec le panneau **ISPConfig**, avec Apache, PHP 8.4 et MySQL 8.4. Il ne suppose aucune connaissance préalable en administration de serveur.

**Règle d'or : jamais de mise à jour pendant un événement**, ni dans les trois jours qui précèdent (voir 5, « Gel des modifications »). En cas de doute, ne faites rien : la version en place fonctionne.

## Conventions de ce document

- Les lignes dans un cadre gris sont des commandes : tapez-les (ou copiez-les) telles quelles, puis appuyez sur Entrée.
- Ce qui est entre `<` et `>` est à remplacer par votre valeur, **sans** les signes `<` et `>`. Par exemple `<adresse du site>` devient `caisse.exemple.fr`.
- Les lignes « Résultat attendu » indiquent ce qui doit s'afficher. Si ce n'est pas le cas, arrêtez-vous et passez à « Revenir en arrière » (ou ne faites rien de plus si vous n'avez encore rien modifié).

### Votre compte sur le serveur

Le serveur est géré par ISPConfig. Votre utilisateur SSH est rattaché au site : une fois connectée, **vous êtes déjà le site** (son utilisateur s'appelle `web54`). Conséquences :

- **Jamais de `sudo`** : il n'est pas nécessaire (les fichiers que vous créez appartiennent déjà au site) et votre compte n'y a pas droit.
- Pas de `chown`, pas de `systemctl reload` ni de `restart` : ISPConfig et l'administrateur du serveur s'en chargent.

### Toujours `php8.4`, jamais `php` seul

Plusieurs versions de PHP sont installées sur le serveur. La commande `php` seule lance une **ancienne version (7.3)**, avec laquelle l'application ne fonctionne pas. Toutes les commandes de ce document écrivent donc la version en toutes lettres :

- `php8.4 bin/console …` pour les commandes de l'application ;
- `php8.4 /usr/local/bin/composer …` pour Composer (jamais `composer` seul : il lancerait lui aussi PHP 7.3).

Si une commande répond `PHP Parse error`, `syntax error, unexpected` ou mentionne PHP 7.3 : vous avez tapé `php` au lieu de `php8.4`. Rien n'est cassé ; relancez la commande avec `php8.4`.

#### Facultatif : faire lancer PHP 8.4 à la commande `php`

Ce réglage ne concerne que **votre** compte, ne demande aucun droit d'administrateur et ne change rien pour le site. Il évite l'erreur ci-dessus si vous tapez `php` par habitude. Les commandes de ce document gardent `php8.4` en toutes lettres : elles fonctionnent avec ou sans ce réglage.

1. Trouver le fichier lu par le serveur à chaque connexion :

   ```
   for f in ~/.bash_profile ~/.bash_login ~/.profile; do [ -f "$f" ] && break; done; echo "$f"
   ```

   Résultat attendu : un chemin qui se termine par `.bash_profile`, `.bash_login` ou `.profile`. Notez-le : c'est `<fichier de connexion>` ci-dessous.

2. Ajouter le raccourci à la fin de ce fichier (les deux `>` sont importants : avec un seul, le fichier serait remplacé) :

   ```
   echo "alias php='php8.4'  # Comptoir des étoiles : php lance PHP 8.4" >> <fichier de connexion>
   ```

   Résultat attendu : rien ne s'affiche.

3. Vérifier : déconnectez-vous (`exit`), reconnectez-vous (section 1), puis :

   ```
   php -v
   ```

   Résultat attendu : une première ligne qui commence par `PHP 8.4`. Si elle commence encore par `PHP 7.3`, le réglage n'est pas lu : revenez en arrière (ci-dessous) et gardez simplement `php8.4`.

**Revenir en arrière** : supprimer la ligne ajoutée, puis se reconnecter.

```
sed -i '/# Comptoir des étoiles : php lance PHP 8.4/d' <fichier de connexion>
```

Résultat attendu : rien ne s'affiche ; après reconnexion, `php -v` affiche de nouveau `PHP 7.3`.

Le raccourci ne vaut que pour les commandes que vous tapez vous-même : les scripts et `composer` seul utilisent toujours PHP 7.3. C'est pourquoi on garde `php8.4` et `php8.4 /usr/local/bin/composer` partout.

## 1. Se connecter au serveur

Sur le Mac, ouvrez l'application **Terminal** (Applications, Utilitaires), puis :

```
ssh <utilisateur>@<adresse du serveur>
```

`<utilisateur>` est votre utilisateur SSH, créé dans ISPConfig pour le site ; si un mot de passe est demandé, c'est le sien (rien ne s'affiche pendant que vous le tapez, c'est normal).

Résultat attendu : une invite qui se termine par `$`. Vous tapez désormais les commandes **sur le serveur**.

Pour vous déconnecter à la fin : `exit`.

## 2. Aller dans le dossier du projet

```
cd /var/www/clients/client5/web54/web
ls
```

Résultat attendu : la liste contient notamment `bin`, `composer.json`, `public`, `src` et `var`.

Toutes les commandes des sections suivantes se tapent dans ce dossier.

## 3. Mettre à jour l'application

Procédure valable pour toute mise à jour. Comptez quinze minutes. Faites-le un jour sans événement, en dehors du gel des modifications (section 5), après avoir vérifié sur GitHub que la dernière pull request est fusionnée sur `main` avec la coche verte.

### 3.1 Vérifier que rien n'a été modifié à la main sur le serveur

```
git status
```

Résultat attendu : `nothing to commit, working tree clean` (ou « rien à valider, la copie de travail est propre »). Le fichier `.env.local` n'apparaît pas : c'est normal, Git l'ignore.

- Seul `stats/` est listé (« Untracked files ») : c'est le dossier des statistiques de fréquentation créé par ISPConfig, sans rapport avec l'application. Continuez. Il n'apparaît plus une fois le dépôt à jour (il est ignoré depuis novembre 2026).
- D'autres fichiers sont listés : **arrêtez-vous**. Quelqu'un a modifié le code sur le serveur ; la mise à jour l'écraserait. Notez la liste et demandez de l'aide.

### 3.2 Noter la version actuelle (pour revenir en arrière)

```
git rev-parse --short HEAD
php8.4 bin/console doctrine:migrations:current
```

Résultat attendu : un code de 7 caractères, par exemple `0c34dd7`, puis une ligne comme `DoctrineMigrations\Version20261110140502 - Révisions des commandes et du menu par événement`. **Notez le code, et la seconde ligne jusqu'au tiret** (ici `DoctrineMigrations\Version20261110140502`), sur papier ou dans un fichier sur le Mac : c'est la version du code et la version de la base qui fonctionnent aujourd'hui.

### 3.3 Sauvegarder la base de données

```
php8.4 bin/console app:backup
ls -l var/backups/ | tail -n 3
```

Résultat attendu : `[OK] Base sauvegardée dans …/var/backups/comptoir-<date>.sql (… Ko).`, puis le fichier du jour dans la liste (par exemple `comptoir-2026-11-20_143005.sql`), avec une taille supérieure à 0. Sans ce fichier, **ne continuez pas**.

**Comparez sa taille avec celle de la sauvegarde précédente**, juste au-dessus dans la liste : elle doit être au moins aussi grande (la base ne fait que grossir : commandes, journal). Un fichier nettement plus petit peut être une sauvegarde tronquée, par exemple sur un disque plein, même si la commande a affiché `[OK]` : vérifiez la place sur le disque (étape 5.7) et demandez de l'aide avant de continuer.

**Rapatrier une copie sur le Mac** : si le serveur tombait en panne, la sauvegarde restée sur le serveur serait perdue avec lui. Laissez la connexion au serveur ouverte. Dans Terminal, ouvrez **une nouvelle fenêtre** (menu Shell, Nouvelle fenêtre) : ses commandes s'exécutent sur le Mac. Tapez-y :

```
mkdir -p ~/Documents/comptoir-sauvegardes
scp '<utilisateur>@<adresse du serveur>:/var/www/clients/client5/web54/web/var/backups/comptoir-*.sql' ~/Documents/comptoir-sauvegardes/
ls -l ~/Documents/comptoir-sauvegardes/
```

- `<utilisateur>@<adresse du serveur>` : les mêmes valeurs que pour `ssh` (section 1).
- Gardez les apostrophes autour de la première partie : elles demandent au serveur de chercher lui-même les fichiers `comptoir-*.sql`.

Résultat attendu : une ligne par fichier copié, avec `100%`, puis la liste du dossier, où apparaît le fichier du jour avec la même taille que sur le serveur.

Le dossier `Documents/comptoir-sauvegardes` est **hors du dépôt Git** : ne rangez jamais une sauvegarde dans le dossier du projet sur le Mac. Elle contient les données réelles de l'association (commandes, empreintes des mots de passe et des jetons) et ne doit pas finir sur GitHub. Ne la partagez pas.

Revenez ensuite à la fenêtre connectée au serveur pour la suite.

### 3.4 Récupérer la nouvelle version

```
git pull
```

Résultat attendu : une liste de fichiers modifiés, ou `Already up to date.` si rien n'a changé (dans ce cas, la suite est sans effet, vous pouvez vous arrêter).

À partir d'ici et jusqu'à la fin de l'étape 3.6, le site peut afficher une erreur pendant une ou deux minutes : c'est normal, le nouveau code n'est pas encore prêt.

### 3.5 Installer les dépendances

```
php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
```

Résultat attendu : des lignes `Installing …`, `Upgrading …` ou `Removing …` (ou `Nothing to install, update or remove`), puis `Generating optimized autoload files`, sans ligne contenant `Error` ni `Problem`.

`--no-scripts` : le cache est reconstruit à l'étape suivante.

### 3.6 Reconstruire le cache : obligatoire après chaque `git pull`

> **Ne sautez jamais cette étape**, même pour une toute petite mise à jour. En production, Symfony réutilise son ancien cache **sans vérifier** qu'il correspond encore au code. Si on l'oublie, des pages tombent en erreur (erreur 500) et le journal (étape 3.8) montre une ligne `ArgumentCountError` ou `Too few arguments`. C'est arrivé sur la page Tablettes en novembre 2026 : ces deux commandes ont suffi à réparer.

```
rm -rf var/cache/prod
php8.4 bin/console cache:warmup
```

Résultat attendu : la première commande n'affiche rien, la seconde `[OK] Cache for the "prod" environment (debug=false) was successfully warmed.`

On efface l'ancien cache plutôt que de le « vider » : la commande de vidage elle-même peut échouer après une grosse mise à jour.

Pas de rechargement d'Apache à faire : PHP relit tout seul les fichiers modifiés. Si, cinq minutes après la mise à jour, le site montre encore clairement l'ancienne version, demandez à l'administrateur du serveur de recharger PHP 8.4 (PHP-FPM).

### 3.7 Mettre à jour la structure de la base

```
php8.4 bin/console doctrine:migrations:migrate --no-interaction
```

Résultat attendu : `[OK] Already at the latest version (…)` (aucune modification de structure) ou `[OK] Successfully migrated to version …`. Notez lequel des deux s'affiche.

### 3.8 Vérifier

1. Sur le serveur, la page de connexion et l'API des tablettes (remplacez `<adresse du site>`, par exemple `caisse.exemple.fr`, sans `https://`) :

   ```
   curl -sI https://<adresse du site>/login | head -n 1
   curl -s https://<adresse du site>/api/menu
   ```

   Résultat attendu : `HTTP/1.1 200 OK` (ou `HTTP/2 200`), puis `{"error":"Cette tablette n'est pas appairée. Appairez-la depuis l'administration."}` (les `'` et `é` sont l'apostrophe et le « é », écrits à la façon du JSON). Ce message prouve que l'API répond : une requête sans tablette est refusée, comme il se doit.

2. Dans Chrome, sur le Mac : ouvrez l'adresse du site, connectez-vous à l'administration, ouvrez **Menu**, **Tablettes** puis **Carnet de bord**. Les pages s'affichent normalement.

3. Sur chaque tablette : un bandeau « Nouvelle version de l'application » apparaît en haut de l'écran, touchez « Recharger ». S'il n'apparaît pas au bout d'une minute, fermez l'application et rouvrez-la.

4. Si une page affiche une erreur, regardez les dernières erreurs du site :

   ```
   tail -n 30 /var/www/clients/client5/web54/log/error.log
   ```

   Une ligne `ArgumentCountError` ou `Too few arguments` : l'étape 3.6 a été sautée ou a échoué ; refaites-la, puis vérifiez à nouveau. Sinon, notez ce qui s'affiche et passez à « Revenir en arrière ».

### 3.9 Revenir en arrière

À faire seulement si la vérification échoue. Dans l'ordre, **sans en sauter** :

1. **La base, d'abord**, seulement si l'étape 3.7 avait affiché `Successfully migrated`. C'est le nouveau code qui sait défaire sa migration : cette commande se lance donc **avant** de remettre l'ancien code. Remplacez `<version de la base notée>` par la version notée à l'étape 3.2 (sans la description après le tiret), en gardant les apostrophes, par exemple `'DoctrineMigrations\Version20261110140502'` :

   ```
   php8.4 bin/console doctrine:migrations:migrate '<version de la base notée>' --no-interaction
   ```

   Résultat attendu : `[OK] Successfully migrated to version: …` avec la version notée. Les commandes enregistrées depuis la mise à jour sont gardées.

   Si cette commande échoue, restaurez la sauvegarde (étape 3.10), puis continuez ci-dessous.

2. **Le code**, en remplaçant `<version du code notée>` par le code de 7 caractères noté à l'étape 3.2 :

   ```
   git reset --hard <version du code notée>
   php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
   rm -rf var/cache/prod
   php8.4 bin/console cache:warmup
   ```

   `git reset --hard` remet le code exactement dans l'état noté. Il ne touche ni à `.env.local` ni à `var/` (ignorés par Git). Les deux dernières commandes sont celles de l'étape 3.6 : obligatoires ici aussi.

3. Vérifiez à nouveau (étape 3.8). La prochaine mise à jour se fera normalement avec `git pull`.

### 3.10 Restaurer la sauvegarde (dernier recours)

Seulement si l'étape 1 de 3.9 a échoué. La base revient exactement à son état de l'étape 3.3 : **les commandes enregistrées depuis seraient perdues**, c'est pourquoi on ne met jamais à jour pendant un événement.

Retrouvez l'utilisateur, le mot de passe et le nom de la base (ne recopiez cette ligne nulle part) :

```
grep DATABASE_URL .env.local
```

La ligne a la forme `DATABASE_URL="mysql://<utilisateur de la base>:<mot de passe>@<hôte>:3306/<base>?…"`. Puis, avec le fichier de l'étape 3.3 (son nom exact est affiché par `ls -l var/backups/`) :

```
mysql --default-character-set=utf8mb4 -u <utilisateur de la base> -p <base> < var/backups/comptoir-<date>.sql
```

Tapez le mot de passe quand il est demandé (rien ne s'affiche, c'est normal). Résultat attendu : rien ne s'affiche, l'invite revient. Reprenez à l'étape 2 de 3.9.

## 4. Réglages d'Apache pour les tablettes (facultatif)

**Facultatif** : l'application fonctionne sans ces réglages ; chaque interrogation des tablettes coûte seulement un peu plus de réseau. À faire **avant le 4 décembre 2026, ou après le Loto**, jamais entre les deux, un jour sans événement.

- **Connexions gardées ouvertes 15 secondes** (`KeepAliveTimeout 15`, au lieu de 5 par défaut). Une tablette interroge le serveur toutes les 3 secondes (suivi de la caisse : 10 s) : elle garde la même connexion, sans refaire la négociation HTTPS, lente en 4G.
- **Jusqu'à 1000 requêtes par connexion** (`MaxKeepAliveRequests 1000`, au lieu de 100) : sinon, la connexion d'une tablette cuisine serait coupée toutes les 5 minutes.
- **Compression des réponses JSON** de l'API (`application/json`) et du manifeste de l'application.

Ce sont les réglages du fichier `docker/apache.conf` utilisé en développement.

### 4.1 Regarder les réglages actuels (ne modifie rien)

Ces deux commandes ne font que lire : elles peuvent se lancer à tout moment, même la veille d'un événement. Remplacez `<adresse du site>` comme à l'étape 3.8.

```
curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' https://<adresse du site>/manifest.webmanifest | grep -i '^content-encoding'
curl -sI --http1.1 https://<adresse du site>/login | grep -i '^keep-alive'
```

Résultats possibles :

- Première commande : `Content-Encoding: gzip` (la compression est déjà active) ou rien (pas de compression).
- Seconde commande : `Keep-Alive: timeout=15, max=1000` (réglages déjà en place), `Keep-Alive: timeout=5, max=100` (réglages par défaut d'Apache), ou rien.

Si vous obtenez déjà `gzip` et `timeout=15, max=1000`, il n'y a rien à faire.

### 4.2 Qui peut appliquer les réglages

Votre compte SSH ne peut pas modifier la configuration d'Apache. Deux cas :

- **Vous avez accès au panneau ISPConfig** (une adresse du type `https://<adresse du serveur>:8080`, avec un identifiant et un mot de passe) : suivez 4.3.
- **Vous n'y avez pas accès** : c'est à faire **par la personne qui administre le serveur, après le Loto**. Donnez-lui cette section 4 ; ne cherchez pas à contourner.

### 4.3 Dans ISPConfig : directives à coller

1. Notez d'abord les résultats de 4.1 : ils serviront de point de comparaison.
2. Dans ISPConfig : **Sites**, cliquez sur le site, onglet **Options**, champ **Apache Directives**. Si l'onglet ou le champ n'apparaît pas, votre compte ISPConfig n'y a pas droit : arrêtez-vous, c'est à faire par l'administrateur du serveur, après le Loto. S'il contient déjà du texte, **ne l'effacez pas** : ajoutez ce qui suit à la fin, après une ligne vide, et copiez le texte déjà présent dans un fichier sur le Mac pour pouvoir le remettre.
3. Collez exactement :

   ```
   # Le Comptoir des étoiles : connexions des tablettes (voir docs/hebergement.md, section 4)
   KeepAlive On
   MaxKeepAliveRequests 1000
   KeepAliveTimeout 15
   <IfModule mod_filter.c>
       <IfModule mod_deflate.c>
           AddOutputFilterByType DEFLATE application/json application/manifest+json image/svg+xml
       </IfModule>
   </IfModule>
   ```

   - La compression a besoin de deux modules d'Apache (`deflate` et `filter`) : elle est entourée de `<IfModule …>`, si bien qu'un module absent fait simplement ignorer la ligne, sans jamais empêcher Apache de démarrer.
   - Les trois lignes `KeepAlive…` sont des réglages de base d'Apache, présents dans toutes les installations : il n'existe pas de module dont elles dépendent, donc pas de `<IfModule>` possible ni nécessaire.

4. Cliquez sur **Enregistrer**. ISPConfig applique la modification en une à deux minutes (une icône de tâche en cours peut s'afficher en haut du panneau).

5. **Juste après**, vérifiez que le site répond toujours, depuis le serveur :

   ```
   curl -sI https://<adresse du site>/login | head -n 1
   ```

   Résultat attendu : `HTTP/1.1 200 OK` ou `HTTP/2 200`. Relancez la même commande deux minutes plus tard : même résultat. Si elle ne répond plus, ou affiche autre chose qu'un `200`, revenez en arrière **tout de suite** (4.5).

### 4.4 Vérifier les réglages

Deux minutes après l'enregistrement, relancez les deux commandes de 4.1.

Résultat attendu : `Content-Encoding: gzip`, puis `Keep-Alive: timeout=15, max=1000`.

- Pas de `gzip` : un des deux modules n'est pas activé sur le serveur ; la ligne est ignorée, sans danger. C'est à faire activer par l'administrateur du serveur, après le Loto.
- Toujours `timeout=5, max=100` : sur un serveur qui héberge plusieurs sites, Apache ne prend la durée des connexions que dans la configuration du premier site ; le réglage de notre site est alors ignoré, sans danger. Il devra être fait pour tout le serveur, par son administrateur, après le Loto.

Enfin, dans Chrome : ouvrez le site, connectez-vous à l'administration, puis ouvrez l'écran d'une tablette appairée. Tout s'affiche normalement.

### 4.5 Revenir en arrière

Dans ISPConfig, même champ **Apache Directives** : supprimez le bloc collé, de `# Le Comptoir des étoiles` à la dernière ligne `</IfModule>` comprise (ou remettez le texte d'origine copié à l'étape 2), puis **Enregistrer**. Deux minutes plus tard, vérifiez comme à l'étape 5 de 4.3 que le site répond `200`.

## 5. Vérifications avant un événement

Deux passages : **une semaine avant** (5.1 à 5.8), pour avoir le temps de corriger, et **la veille** (5.9 et 5.10), rapide, sans rien modifier. Les réglages de l'événement dans l'administration (date, menu, tablettes, case « Avec cuisine ») sont dans la fiche [Jour J](procedures/jour-j.md).

**Gel des modifications** : dans les **trois jours** qui précèdent un événement, ne lancez ni mise à jour de l'application (section 3), ni changement dans ISPConfig (section 4). Si une vérification échoue pendant ces trois jours, demandez de l'aide avant de toucher à quoi que ce soit.

Connectez-vous au serveur et allez dans le dossier du projet (sections 1 et 2).

### 5.1 Le code est à jour

```
git status
git fetch
git status
```

Résultat attendu : `nothing to commit, working tree clean` la première fois, puis, après `git fetch`, `Your branch is up to date with 'origin/main'.`

- `Your branch is behind 'origin/main'` : une version plus récente existe sur GitHub. À plus de trois jours de l'événement, et si elle doit servir à l'événement, mettez à jour (section 3). Sinon, gardez la version en place : elle fonctionne.
- Des fichiers listés la première fois (autres que `stats/`) : arrêtez-vous (voir 3.1).

### 5.2 La base est à jour

```
php8.4 bin/console doctrine:migrations:up-to-date
```

Résultat attendu : `[OK] Up-to-date! No migrations to execute.` Sinon, une mise à jour s'est arrêtée en route : demandez de l'aide.

### 5.3 L'application est en mode production

```
php8.4 bin/console about | grep -E "Environment|Debug"
```

Résultat attendu : `Environment   prod`, puis `Debug   false` (les espaces varient). Sinon, le fichier `.env.local` est à revoir : les erreurs afficheraient des détails techniques aux visiteurs. Demandez de l'aide.

### 5.4 Apache et MySQL tournent

```
systemctl is-active apache2 mysql
```

Résultat attendu : `active` deux fois. Cette commande ne fait que lire : elle fonctionne sans droit d'administrateur.

### 5.5 Le certificat HTTPS est valable

Remplacez `<adresse du site>` (sans `https://`) aux deux endroits :

```
echo | openssl s_client -connect <adresse du site>:443 -servername <adresse du site> 2>/dev/null | openssl x509 -noout -enddate
```

Résultat attendu : une ligne `notAfter=…` avec une date **au moins deux semaines après l'événement**. Let's Encrypt renouvelle le certificat tout seul un mois avant son expiration : une date plus proche signale un renouvellement qui échoue. Sans certificat valable, les tablettes ne peuvent plus joindre le site : demandez de l'aide sans attendre.

### 5.6 L'heure du serveur est juste

Les temps d'attente de la cuisine et la fin de validité des tablettes se calculent sur l'heure du serveur.

```
timedatectl
```

Résultat attendu : une ligne `System clock synchronized: yes`, et une heure (`Universal time`) qui correspond à l'heure actuelle en temps universel (une heure de moins que l'heure de Paris en hiver).

### 5.7 Il reste de la place sur le disque

```
df -h /
```

Résultat attendu : dans la colonne `Use%`, une valeur **inférieure à 80 %**. Au-delà, les sauvegardes et les commandes pourraient ne plus s'enregistrer : demandez de l'aide.

### 5.8 Pas de panne récente

L'application écrit ses erreurs dans le journal du site. Les pannes y sont marquées `[critical]` :

```
grep -E "\[critical\]|PHP Fatal" /var/www/clients/client5/web54/log/error.log | tail -n 5
```

Résultat attendu : **rien ne s'affiche**. Des lignes `[error]` « No route found » existent sans doute dans le journal : ce sont des robots qui cherchent des pages inexistantes (`robots.txt`, `.env.local`, `composer.json`…), c'est sans conséquence. Si des lignes `[critical]` s'affichent, notez-les (date et message) et demandez de l'aide.

### 5.9 La veille : le site répond

```
curl -sI https://<adresse du site>/login | head -n 1
curl -s https://<adresse du site>/api/menu
systemctl is-active apache2 mysql
```

Résultat attendu : comme à l'étape 3.8 (`HTTP/1.1 200 OK` ou `HTTP/2 200`, puis le message « Cette tablette n'est pas appairée… »), puis `active` deux fois.

Dans Chrome, connectez-vous à l'administration : en haut de la page, l'événement du jour est indiqué comme actif.

### 5.10 La veille : sauvegarde rapatriée sur le Mac

Suivez l'étape 3.3 en entier : `app:backup` sur le serveur, puis `scp` vers `~/Documents/comptoir-sauvegardes` sur le Mac. Refaites-le le lendemain de l'événement, après l'export du Carnet de bord (fiche [Clôture](procedures/cloture.md)).
