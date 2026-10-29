# PV de préfabrication

Les procès-verbaux qui documentent la fabrication d'une demande validée.

**Écran :** « PV de préfabrication », section « Atelier » — chargé de production et chef
d'atelier (qui les remplissent), chargé d'affaire (qui consulte ceux de ses demandes). Le
personnel d'atelier n'y a pas accès. Une pastille compte les PV à faire.

## Les trois PV

| PV | Clé | Ce qu'il porte |
| --- | --- | --- |
| **Réception matière** | `reception_materiel` | Les commandes fournisseur et leur réception |
| **Fin de fabrication et conformité** | `fin_fabrication` | La fin de fabrication, avec ses **photos** |
| **Passivation** | `passivation` | Le traitement de surface |

L'ancien PV « Conformité » a été fusionné dans « Fin de fabrication » par la migration
`2026-10-27_pv_refonte` ; la valeur `conformite` reste dans l'énumération SQL.

## Les états

| État | Se pose |
| --- | --- |
| **À faire** | Par défaut |
| **Fait** | Avec une date — depuis le volet ; depuis la ligne, la date du jour est posée |
| **Sans objet** | À la main, ou d'office : sans passivation demandée, le PV passivation est « Sans objet » et toute écriture y est refusée (`409`) |

## L'écran

- **Les demandes validées**, en deux groupes : **À renseigner** et **Complètes**, avec le nombre
  de PV à faire. Le chargé d'affaire ne voit que les siennes.
- **Trois colonnes d'état** par ligne. L'encadrement change un état directement depuis la ligne,
  par un menu ; un trombone dit le nombre de fichiers.
- **Le volet d'une demande** :
  - la demande — affaire, demandeur, passivation, production (nombre de plans et de soudures) ;
  - pour chaque PV : son état, sa date, un commentaire, ses pièces jointes ;
  - la **réception** : le tableau des commandes fournisseur, avec « Lire le bon » et « Ajouter une
    commande » ;
  - la **fin de fabrication** : ses photos ;
  - l'historique — les dix derniers événements.

Le chargé d'affaire voit tout cela en lecture seule.

## Les fichiers

PDF, PNG ou JPEG, 50 Mo par fichier, sur chaque PV ; des photos sur la fin de fabrication
seulement. Ils sont rangés dans `src/dashboard/uploads/pv/<id>/` et servis par une action qui
vérifie le droit de les voir.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/pv.php`, `src/dashboard/partials/pv_*.php` | L'écran, le volet, les documents, les photos |
| `src/dashboard/includes/demande/pv.php` | Les types de PV, les états, ce qui reste à faire |
| `src/dashboard/actions/pv.php` | Enregistrer, changer un état, retirer un document — `$canFillPv` |
| `src/dashboard/actions/lire_bon_commande.php` | La lecture d'un bon dans le PV réception |
| `src/js/pv.js`, `src/js/commandes.js` | Le volet, et le tableau des commandes fournisseur |
