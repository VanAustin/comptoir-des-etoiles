# Essai à la maison

À faire début décembre, seule, avant le stand de Noël. On vérifie la règle la plus importante de l'application : **aucune commande perdue**, même pendant une coupure réseau. Aucun test automatique ne couvre ce parcours de bout en bout : cet essai le remplace.

Imprimez la fiche et notez, pour chaque scénario, ce que vous observez, même si tout se passe bien. Prévoyez environ une heure.

## Préparation

- [ ] Le téléphone partage sa connexion. La tablette caisse et l'appareil cuisine y sont connectés.
- [ ] Dans l'administration, un événement **« Essai maison »** est créé et activé, **avec cuisine**, avec quelques articles (au moins un qui passe en cuisine, une boisson). Les ventes de l'essai restent dans cet événement, à part des vrais.
- [ ] Une tablette **caisse** et une tablette **cuisine** sont appairées à cet événement (fiche [Jour J](jour-j.md), étape 4).
- [ ] Sur la caisse, l'en-tête n'affiche rien « en attente d'envoi ».
- [ ] Noté avant de commencer : le nombre de commandes et le chiffre d'affaires du **Carnet de bord** pour « Essai maison » : ______ commandes, ______ €.

Pour **couper le réseau** de la caisse : mode avion sur la tablette (ou partage de connexion coupé sur le téléphone). Pour le **rétablir** : l'inverse.

## Scénarios

| Fait | Scénario | Résultat attendu | Ce que j'observe |
| --- | --- | --- | --- |
| [ ] | **1. Coupure pendant l'envoi.** Préparez une commande avec un plat, touchez « Encaisser », puis coupez le réseau **aussitôt**, avant que le numéro s'affiche. | Au plus tard 6 secondes après, la caisse donne quand même un numéro au client. L'en-tête affiche « Hors ligne · 1 en attente d'envoi ». Après le retour du réseau, la commande apparaît **une seule fois** en cuisine et dans le Carnet de bord. | |
| [ ] | **2. Trois commandes hors ligne, puis rechargement.** Réseau coupé, encaissez trois commandes (une en espèces, une par carte, une avec un plat). Rechargez la page de la caisse (ou fermez puis rouvrez l'icône « Comptoir »). | La caisse se rouvre sans réseau, avec le menu. L'en-tête indique toujours « 3 en attente d'envoi » (ou le total avec les commandes précédentes). Le panneau des commandes en attente montre leur détail. | |
| [ ] | **3. Chrome fermé, puis réseau rétabli.** Avec des commandes en attente, fermez complètement Chrome (bouton des applications récentes d'Android, puis balayer Chrome). Rouvrez l'icône « Comptoir », puis rétablissez le réseau. | Les commandes en attente sont toujours là à la réouverture. Moins d'une minute après le retour du réseau, l'en-tête n'affiche plus rien en attente. Les plats arrivent en cuisine. | |
| [ ] | **4. Vérification dans le Carnet de bord.** Dans l'administration, **Carnet de bord** de « Essai maison ». | Chaque commande encaissée pendant l'essai y est, **une seule fois**, avec le bon numéro (lettre de la caisse et numéro donné au client), le bon montant et le bon moyen de paiement. Le nombre de commandes a augmenté exactement du nombre encaissé. | |
| [ ] | **5. Désappairage refusé.** Coupez le réseau, encaissez une commande, puis ouvrez « Tablette » (appui maintenu 2 secondes) et essayez de désappairer. | La tablette **refuse** : un message indique qu'une commande attend d'être envoyée. Rétablissez le réseau, attendez l'envoi : le désappairage n'est plus bloqué (inutile d'aller au bout). | |

## Après l'essai

- [ ] Sur la caisse, l'en-tête n'affiche plus rien en attente ni « À vérifier ».
- [ ] Les commandes de l'essai sont passées en cuisine jusqu'à « Remise au client », ou annulées dans le Carnet de bord.
- [ ] Avant d'activer l'événement du stand de Noël, la règle de la [Clôture](cloture.md) est respectée : aucune commande en attente sur aucune caisse.
- [ ] Ce qui a coincé est noté ci-dessous, et signalé pour correction avant le stand de Noël.

Remarques :

&nbsp;

&nbsp;

&nbsp;
