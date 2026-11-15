# Incidents

## Mot de passe administrateur oublié

Sur le serveur, dans le dossier du projet (`docs/hebergement.md`, sections 1 et 2) :

```
php8.4 bin/console app:admin:password
```

Toujours `php8.4`, jamais `php` seul : sur notre serveur, `php` lance une ancienne version avec laquelle la commande échoue.

La commande rappelle l'identifiant de l'administrateur et demande deux fois le nouveau mot de passe (12 caractères minimum). Les sessions ouvertes avec l'ancien sont fermées.

## Tablette de caisse en panne

1. **Si elle peut redémarrer**, redémarrez-la et rouvrez l'icône « Comptoir » : les commandes en attente sont toujours là et partiront au retour du réseau.
2. **Sinon**, ajoutez une nouvelle tablette dans l'administration (**Tablettes**, **Ajouter une tablette**, rôle caisse) et appairez-la. Elle reçoit une **nouvelle lettre** : une lettre n'est jamais réattribuée pendant un événement, pour éviter deux commandes avec le même numéro.
3. Les commandes encaissées hors ligne sur la tablette en panne et pas encore envoyées restent dans cette tablette. Elles partiront seulement si elle redémarre. Notez leurs numéros si vous les connaissez, et signalez-le au moment de la clôture.

## Tablette perdue ou volée

Dans l'administration, **Tablettes**, **Révoquer** sur sa ligne : elle ne peut plus rien lire ni envoyer, immédiatement. Elle disparaît de la liste ; « Afficher les tablettes retirées » la remontre.

## Tablette « n'est plus reconnue » ou « appairage expiré »

Elle a été révoquée, ou son jeton a expiré (lendemain de l'événement, 6 h), ou un autre événement a été activé. Une tablette déjà appairée ne peut pas recevoir de nouveau code : ajoutez-la à nouveau (**Tablettes**, **Ajouter une tablette**) et appairez-la. Une caisse reçoit alors une nouvelle lettre.

Avant de le faire sur une caisse, vérifiez qu'elle n'a plus de commande « en attente d'envoi » : ces commandes ne partent qu'avec l'appairage d'origine.

## Une commande n'apparaît pas en cuisine

1. Sur la caisse, l'en-tête indique-t-il des commandes « en attente d'envoi » ? C'est une coupure réseau : fiche [Coupure réseau](coupure-reseau.md).
2. La commande ne contenait-elle que des articles hors cuisine (boissons…) ? Elle est servie directement au comptoir et n'apparaît jamais en cuisine.
3. Sinon, cherchez le numéro (par exemple A12) dans **Carnet de bord**, historique des commandes.

## Erreur d'encaissement

Ne réencaissez pas par-dessus. **Carnet de bord**, ligne de la commande, **Annuler**, puis confirmez dans la page. La commande sort de la cuisine, des Arrivées et des chiffres ; l'annulation est tracée. Rendez l'argent au client si besoin, puis encaissez la bonne commande.

## Le site ne répond plus du tout

Si même la page d'accueil ne s'ouvre pas avec un bon réseau (essayez sur un téléphone en 4G), le serveur a un problème. Les caisses continuent d'encaisser hors ligne : appliquez la fiche [Coupure réseau](coupure-reseau.md) pendant que quelqu'un vérifie le serveur : `docs/hebergement.md`, étapes 7.4 (Apache et MySQL), 7.5 (certificat) et 7.8 (pannes dans le journal d'Apache).
