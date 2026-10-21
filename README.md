# Le Comptoir des étoiles

Application de caisse pour les événements d'une association de parents d'élèves : caisse, cuisine, écran d'appel client, suivi des stocks et bilan.

Application en cours de développement : l'installation complète sera décrite ici au fil des étapes.

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

## Tester l'API des tablettes avec Bruno

Le dossier `bruno/` contient une collection pour [Bruno](https://www.usebruno.com/), un logiciel gratuit pour envoyer des requêtes à l'API et vérifier les réponses.

1. Installez Bruno (`brew install --cask bruno`, ou depuis le site).
2. Dans Bruno : « Open Collection », puis choisissez le dossier `bruno/` du projet.
3. En haut à droite, sélectionnez l'environnement « Local ».
4. Suivez le déroulé décrit dans la collection (onglet « Docs ») : créez une tablette dans l'administration, recopiez son code dans la variable `pairingCode`, puis lancez les requêtes du dossier « Appairage ».

Chaque requête contient ses tests ; « Run » sur un dossier les lance tous. Le jeton reçu reste en mémoire dans Bruno et n'est jamais écrit dans les fichiers.

Si le port 8080 est déjà utilisé par un autre programme, arrêtez ce programme avant de lancer `docker compose up`.

## Licence

Voir le fichier [LICENSE](LICENSE).
