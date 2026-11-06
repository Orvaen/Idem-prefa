# Commande matériel

Les bons de commande fournisseur d'une demande : les lire, les corriger, les valider, puis
pointer ce qui arrive. Et, sous les bons, les manques que l'atelier signale.

**Écran :** « Commande matériel », section « Atelier » — chargé de production et chef d'atelier.
Une pastille compte les manques nouveaux.

## Les bons

Le bon est déposé **sur la demande**, dans la famille « Commande matériel » de ses documents, par
le chargé d'affaire ou le chargé de production. L'écran suit la grammaire des autres listes
(PV, demandes) : titre et « Actualiser », recherche, onglets comptés — **À lire**, **À
vérifier**, **Validée**, **Lecture impossible** —, puis les bons **rangés en groupes par état**,
chacun sous son signe. Une ligne dit la demande, l'affaire, le bon déposé, le numéro de
commande, la ou les livraisons et les articles reçus « x / y » ; « Détails » ouvre le volet du
bon.

Le bon déposé s'ouvre **dans le lecteur de l'application**, pas dans celui du navigateur : le
serveur rend chaque page en image, et la fenêtre les empile, avec son zoom (−, +, « Ajuster »,
Ctrl + molette) et, au-delà d'une page, son sommaire. « Télécharger » reste dans sa barre. Il
en va de même depuis le volet et depuis la fiche de la demande.

### Le cycle d'un bon

```
 déposé ──▶ lu (automatiquement) ──▶ relu et corrigé ──▶ validé ──▶ reçu, livraison par livraison
```

1. **La lecture, sans clic** — le bon se lit **de lui-même dès son dépôt**, depuis la fiche
   comme depuis le formulaire de la demande, en tâche de fond : le dépôt répond tout de suite, et
   la lecture (quelques secondes par page scannée) se fait derrière (`outils/lire_bons.php`).
   Elle en tire les articles : référence, désignation, date de livraison, quantité, unité. Un bon
   resté sans lecture — déposé avant, ou dont la tâche n'a pas pu tourner — se lit à
   l'**ouverture** de son volet. Un verrou par bon empêche deux lectures à la fois : l'écran
   attend celle qui tourne au lieu de recommencer. Voir
   [Lecture automatique](/fonctionnalites/lecture-automatique).
2. **Vérifier, et corriger au besoin** — le volet s'ouvre sur la **liste à cocher** des
   articles, rangée par date de livraison, à toutes les étapes. En tête, **trois étapes** disent
   où en est la commande : « Bon lu » ; « Commande à valider », puis « Commande validée » ;
   « Matériel à recevoir », puis « Matériel reçu ». Une erreur de lecture se corrige par
   **« Corriger les articles »** : le tableau du bon, dont chaque case s'ouvre au clic.
   « Ajouter un article » complète ce que la lecture a manqué, « Relire le bon » repart de zéro
   après confirmation, et « Enregistrer les corrections » ramène à la liste. Un bon sans article
   s'ouvre directement sur la correction.
3. **Valider la commande** — après confirmation, elle est **figée** : ses articles ne se
   corrigent plus, et elle ne se relit plus. Ce qui est **déjà arrivé se coche avant** : les
   coches partent avec la validation, jamais avant. Avant le 6 novembre 2026, rien ne se cochait
   sur une commande pas encore validée — « pourquoi j'ai le bouton Valider la commande, alors
   que je ne peux pas cocher puis valider ? ». Une commande validée se rouvre par « Corriger les
   articles », dans le volet, ou par **« Modifier »**, sur sa ligne, après confirmation : elle
   repasse « À vérifier », et il faut la valider de nouveau. Les réceptions déjà pointées sont
   gardées.
4. **Recevoir** — la même liste : « 8 / 13 reçus », « En retard » sur une livraison dont
   l'échéance est passée, des filtres Tous / À recevoir / Reçus, une recherche par référence pour
   pointer un bon de livraison, et « Tout marquer reçu » (« Marquer reçue », au bout de chaque
   livraison, est retiré le 6 novembre 2026). Toucher une ligne la **coche** sans rien
   enregistrer : elle dit « à valider », en orange, et le pied du volet compte les articles qui
   attendent. **« Valider la réception »** les enregistre d'un coup ; « Annuler » les défait.
   Remettre « à recevoir » un article reçu efface sa date : la validation le demande avant.
   Fermer le volet avec des coches pas validées, ou des corrections pas enregistrées, le demande
   aussi. Chaque pointage retient qui et quand.

### L'export Excel

**« Exporter en Excel »**, en haut du volet, télécharge la commande en classeur `.xlsx` aux
couleurs de la maison : le logo, la commande en titre souligné de l'accent, une carte —
numéro, date de commande, bon déposé, livraisons, validation, réception —, puis le tableau des
articles : référence, désignation, quantité, unité, livraison prévue, état (« Reçu » en vert,
« En retard » en orange, « À recevoir »), reçu le, reçu par. L'en-tête du tableau reste à
l'écran et porte les filtres du tableur ; les dates sont de vraies dates, qui se trient. À
l'impression : A4 en paysage, toute la largeur sur une page, l'en-tête répété. Le fichier
s'appelle `Commande_<numéro>_<référence de la demande>.xlsx`.

::: warning La lecture est une proposition
Les bons arrivent **scannés** : la lecture passe par la reconnaissance de caractères, qui se
trompe — « 3,00 » lu « 300 », un `L` lu `1`. Le contrôle qui rattrape les quantités est
l'arithmétique du bon lui-même — quantité × prix unitaire = total. **La relecture humaine reste
obligatoire** avant de valider.
:::

## Les manques {#les-manques}

Le personnel d'atelier **signale** un manque depuis « Mes plans », sur un plan qui lui est
affecté : l'article, la quantité, l'unité, le motif (oubli, erreur de livraison, casse ou rebut,
autre) et une précision.

| État | Qui le pose | Ce qui se passe |
| --- | --- | --- |
| **Nouveau** | Le soudeur, en signalant | Chaque chargé de production et chaque chef d'atelier est prévenu nommément |
| **Vu** | L'encadrement — « Marquer vu » | — |
| **Traité** | L'encadrement — « Traité » | Le soudeur qui l'a signalé est prévenu à son tour |

Un manque avance sans retour possible. Les manques en attente forment une section **sous les
bons** ; à droite des onglets, « N manques à traiter », en couleur d'alerte, les annonce et y
mène d'un clic.

## À ne pas confondre

Le PV « Réception matériel » porte, lui, un tableau de **commandes fournisseur** — numéro,
fournisseur, reçu ou non, dates, lieu, articles — rempli par le chef d'atelier. C'est un autre
circuit, enregistré dans `bon_livraison`. Voir [PV de préfabrication](/fonctionnalites/pv).

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/commande_materiel.php`, `src/js/ecrans/commande-materiel.js` | L'écran |
| `src/dashboard/actions/lire_bon_materiel.php` | La lecture d'un bon |
| `src/dashboard/actions/save_bon_materiel.php` | Les corrections (« Corriger les articles ») |
| `src/dashboard/actions/valider_bon_materiel.php` | « Valider la commande », avec les articles déjà cochés |
| `src/dashboard/actions/rouvrir_bon_materiel.php` | « Modifier », « Corriger les articles » : rouvre une commande validée |
| `src/dashboard/actions/export_bon_materiel.php`, `src/dashboard/includes/materiel/materiel_export.php` | L'export Excel d'une commande |
| `src/dashboard/includes/vue/classeur.php` | Un classeur `.xlsx` écrit à la main : le paquet ZIP, et les styles de la maison |
| `src/dashboard/actions/receptionner_bon_materiel.php` | Les pointages de réception |
| `src/dashboard/actions/signaler_manque.php`, `traiter_manque.php` | Les manques |
| `src/dashboard/includes/lecture/commandes_lignes.php` | L'interprétation des lignes lues, et le contrôle arithmétique |
| `src/dashboard/actions/download_materiel.php`, `src/dashboard/includes/lecture/pdf_pages.php` | Le bon, et ses pages en images pour le lecteur (`?pages`, `?page=N`, `?vignette=N`) |
| `src/dashboard/includes/materiel/` | Les bons, les manques |
