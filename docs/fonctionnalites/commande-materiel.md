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
2. **Relire et corriger** — chaque case s'ouvre au clic. « Ajouter un article » complète ce que
   la lecture a manqué. « Enregistrer » garde les corrections ; « Relire le bon » repart de zéro
   et remplace ce qui est affiché, après confirmation.
3. **Valider la commande** — après confirmation, elle est **figée** : ses articles ne se
   modifient plus, et elle ne se relit plus.
4. **Recevoir** — une liste à cocher, rangée par date de livraison : « 8 / 13 reçus », « En
   retard » sur une livraison dont l'échéance est passée, des filtres Tous / À recevoir / Reçus,
   une recherche par référence pour pointer un bon de livraison, « Marquer reçue » par
   livraison et « Tout marquer reçu ». Chaque pointage retient qui et quand.

::: warning La lecture est une proposition
Les bons arrivent **scannés** : la lecture passe par la reconnaissance de caractères, qui se
trompe — « 3,00 » lu « 300 », un `L` lu `1`. Le contrôle qui rattrape les quantités est
l'arithmétique du bon lui-même — quantité × prix unitaire = total. **La relecture humaine reste
obligatoire** avant de valider.
:::

## Les manques {#les-manques}

Le personnel d'atelier **signale** un manque depuis « Mes soudures », sur un plan qui lui est
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
| `src/dashboard/pages/commande_materiel.php`, `src/js/commande-materiel.js` | L'écran |
| `src/dashboard/actions/lire_bon_materiel.php` | La lecture d'un bon |
| `src/dashboard/actions/save_bon_materiel.php` | Les corrections, et la validation |
| `src/dashboard/actions/receptionner_bon_materiel.php` | Les pointages de réception |
| `src/dashboard/actions/signaler_manque.php`, `traiter_manque.php` | Les manques |
| `src/dashboard/includes/lecture/commandes_lignes.php` | L'interprétation des lignes lues, et le contrôle arithmétique |
| `src/dashboard/includes/materiel/` | Les bons, les manques |
