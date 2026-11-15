# Clôture de l'événement

À faire dans l'ordre, avant de ranger les tablettes.

## 1. Plus aucune commande en attente

Sur **chaque caisse**, l'en-tête ne doit plus afficher « en attente d'envoi » ni de commande « À vérifier ». Sinon, gardez la tablette allumée et connectée jusqu'à l'envoi, ou traitez les commandes « À vérifier » (voir [Coupure réseau](coupure-reseau.md)).

Une caisse éteinte avec des commandes en attente les garde : elles partiront à la prochaine ouverture, avec le même appairage. Elles manqueraient d'ici là dans les chiffres.

## 2. Compter les caisses

1. Dans l'administration, **Carnet de bord** : bloc **Par caisse**, colonne **Espèces attendues**. Ce montant est *hors fond de caisse*, consignes comprises, monnaie rendue déduite.
2. Comptez les espèces de chaque caisse, retirez le fond de caisse noté à l'installation, et comparez.
3. La colonne **Écart** signale les commandes encaissées hors ligne avec un montant reçu inférieur au total (prix modifié pendant la coupure, ou erreur de saisie). Un écart inexpliqué se note dans le compte rendu.
4. Les paiements par carte se rapprochent avec le relevé de chaque terminal de paiement : colonne **Carte** de la caisse qui l'utilise (au Loto, le terminal des cartons avec la caisse Cartons, celui de la buvette avec les caisses de la buvette). Le Carnet de bord donne aussi le total carte.
5. Consignes : le Carnet de bord donne les consignes encaissées, rendues, et le solde (gobelets non rendus). Ce solde n'est pas du chiffre d'affaires.

## 3. Exporter le bilan

Dans **Carnet de bord**, « Exporter pour Excel » : téléchargez les quatre fichiers (commandes, détail des lignes, bilan par article, bilan par catégorie). Les commandes annulées en sont exclues. Le bilan par article sert à ajuster les courses du prochain événement ; le bilan par catégorie sépare les activités dans les comptes (cartons, restauration…), hors consignes, et se somme au chiffre d'affaires.

## 4. Sauvegarder

Sur le serveur, dans le dossier du projet (`docs/hebergement.md`, sections 1 et 2), sauvegarde puis copie sur votre ordinateur, comme à l'étape 3.3 de `docs/hebergement.md` :

```
php8.4 bin/console app:backup
ls -l var/backups/ | tail -n 3
```

Résultat attendu : `[OK] Base sauvegardée dans …`, puis le fichier du jour dans la liste. Toujours `php8.4`, jamais `php` seul (ancienne version de PHP sur notre serveur).

Puis, sur le Mac, dans une autre fenêtre de Terminal :

```
scp '<utilisateur>@<adresse du serveur>:/var/www/clients/client5/web54/web/var/backups/comptoir-*.sql' ~/Documents/comptoir-sauvegardes/
```

Gardez cette copie avec les fichiers Excel, jamais dans le dossier du projet.

## 5. Tablettes

Les jetons des tablettes expirent tout seuls le lendemain de l'événement à 6 h : rien d'obligatoire à faire. Si une tablette personnelle rentre chez un parent avant, ou si l'une d'elles a disparu, **Révoquer** sur sa ligne, dans l'administration, **Tablettes**.

## 6. Avant le prochain événement

- Créez le prochain événement et activez-le le moment venu : les numéros repartent à 1 et les lettres de caisse à A.
- Notez ce qui a manqué (articles en rupture, attente en cuisine, réseau…) pendant que c'est frais.
