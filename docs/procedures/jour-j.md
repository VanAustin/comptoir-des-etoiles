# Jour J

## La veille

1. **Sauvegarde** sur le serveur, puis copie sur votre ordinateur :

   ```
   php bin/console app:backup
   ```

2. Dans l'administration, **Événements** : l'événement du jour existe, avec la bonne date, le montant de la consigne (0 s'il n'y en a pas) et la case **Avec cuisine** (décochée pour un stand sans préparation, comme le stand de Noël : toutes les commandes sont servies au comptoir et seules les tablettes caisse fonctionnent). Cette case ne se modifie plus après la première commande. Activez-le avec **Activer** : un seul événement est actif à la fois, et les tablettes rattachées à un autre événement (la répétition, par exemple) cessent de fonctionner.
3. **Menu** : prix, stocks de départ (approximatifs, ils ne bloquent jamais une vente), articles qui passent en cuisine (colonne absente pour un événement sans cuisine), articles avec consigne, formules. Décochez « Vendu seul » pour un article vendu seulement dans les formules (saucisse d'un menu) : il disparaît des caisses, son stock reste décompté.
4. **Tablettes**, une fois l'événement du jour activé : créez une fiche par tablette (« Caisse buvette », « Cuisine », « Écran Arrivées »…) avec son rôle. Pour une caisse, cochez les catégories qu'elle vend (« Loto » seul pour la table de l'entrée, tout sauf « Loto » pour la buvette) et « Vente directe » si elle n'a pas de numéro à annoncer (cartons, jetons) ; modifiable ensuite avec « Réglages », même pendant l'événement. Les fiches sont rattachées à l'événement actif : celles de la répétition ne servent plus, il faut les recréer. L'appairage peut se faire la veille ou sur place : le jeton d'une tablette reste valable jusqu'au lendemain de l'événement, 6 h.
5. Vérifiez que vous pouvez vous connecter à l'administration. Mot de passe oublié : voir [Incidents](incidents.md).
6. Chargez toutes les tablettes et le ou les téléphones qui partageront la 4G. Prévoyez rallonges et chargeurs.
7. Imprimez la fiche [Coupure réseau](coupure-reseau.md) pour chaque poste, et prévoyez un carnet et un stylo par caisse.

## Installation sur place

1. Activez le partage de connexion sur le téléphone (ou connectez-vous au Wi-Fi de la salle). Vérifiez le nombre de barres 4G aux emplacements des caisses et de la cuisine.
2. Pour chaque tablette pas encore appairée : dans l'administration, **Tablettes**, puis **Nouveau code** sur sa ligne si le code a expiré (valable 10 minutes, affiché une seule fois). Sur la tablette, ouvrez l'adresse du site dans Chrome, touchez « Appairer cette tablette » et saisissez le code.
3. Installez l'application sur chaque tablette : menu ⋮ de Chrome, **« Ajouter au bureau »**. Ouvrez ensuite l'icône « Comptoir », jamais l'onglet Chrome.
4. Chaque caisse reçoit une lettre (A, B, C…) : les numéros de commande commencent par cette lettre. Notez quelle tablette porte quelle lettre.
5. **Cuisine** et **Arrivées** : touchez le bouton du son pour qu'il affiche « Son activé ». Branchez ces tablettes sur secteur ; l'écran reste allumé tant que l'application est ouverte.
6. **Commande d'essai** : sur une caisse, encaissez un article qui passe en cuisine. Il doit apparaître en cuisine en quelques secondes (moins de 10), ou presque aussitôt si le serveur a un hub Mercure. Faites-le avancer jusqu'à « Remise au client », puis annulez cette commande depuis l'administration, **Carnet de bord** (elle ne comptera pas dans les chiffres).
7. Comptez le **fond de caisse** de chaque caisse et notez-le : le Carnet de bord affiche les espèces attendues *hors* fond de caisse.

## Pendant le service

- Le bouton **Tablette** (retour à l'appairage) se maintient 2 secondes : un appui accidentel ne fait rien.
- Une bande « Nouvelle version de l'application » propose de recharger : touchez « Recharger » **entre deux commandes**, jamais pendant un encaissement.
- Le stock affiché est une information : une vente n'est jamais bloquée, même si le stock devient négatif.
- La remarque pour la cuisine ne contient **jamais** de nom ni de téléphone : la commande est un numéro.
- Erreur d'encaissement : ne réencaissez pas par-dessus. Notez le numéro ; la commande s'annule depuis l'administration, **Carnet de bord**, ligne de la commande, **Annuler** (l'annulation est tracée).
- Le réseau tombe : fiche [Coupure réseau](coupure-reseau.md).
- Une tablette tombe en panne : fiche [Incidents](incidents.md).

En fin de service : fiche [Clôture](cloture.md).
