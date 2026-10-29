# Demandes de préfabrication

Le cœur de l'application : ce qu'un chargé d'affaire commande à l'atelier.

**Écran :** l'entrée des demandes, section « Affaires & demandes ». Son nom change selon le
rôle : « Valider les demandes » (chargé de production), « Suivi des demandes » (chef
d'atelier), « Mes demandes » (chargé d'affaire). « Demandes urgentes » est la même liste,
filtrée sur les urgentes, pour le chargé de production.

## Le cycle de vie

```
                 ┌──────────── modification (repasse en attente) ◀───────────┐
                 ▼                                                            │
  dépôt ──▶  1 En attente  ──▶  validation  ──▶  2 Validée  ──▶  atelier, planning, PV
                 │
                 └──────────▶  refus (motif obligatoire)  ──▶  3 Refusée
```

| Statut | id | Ce qu'il veut dire |
| --- | --- | --- |
| **En attente** | 1 | Déposée, ou modifiée depuis sa dernière décision |
| **Validée** | 2 | Acceptée par le chargé de production : planifiée, ouverte à l'atelier |
| **Refusée** | 3 | Refusée, avec un motif |

Les statuts vivent dans la table `statut_demande`.

## Déposer une demande

Seul le **chargé d'affaire** dépose, et seulement dans une affaire qui lui est confiée. Le
chargé de production ne dépose pas : le serveur le refuse explicitement. Le formulaire s'ouvre
dans une fenêtre, au-dessus de la liste.

| Champ | Obligatoire | Détail |
| --- | :---: | --- |
| Affaire | ✓ | Parmi celles qui lui sont confiées |
| Plans BPE / ISO | ✓ | PDF, images, Word, Excel — 50 Mo par fichier |
| QMOS, DMOS | ✓ | En PDF |
| Fin de fabrication | ✓ | Antérieure ou égale à la date de livraison |
| Pouces ISO | ✓ | La durée estimée s'en déduit : pouces × temps par pouce réglé dans les paramètres |
| Matière | | Une liste, avec « Autre » qui crée le type. Si une matière est choisie, sa disponibilité — en stock ou à commander — devient obligatoire. |
| Passivation | | Une liste, avec « Autre » |
| Revêtement | | Aucun, extérieur, intérieur ou les deux, avec une précision libre |
| Contrôles CND, RT, PT | | Chacun avec un pourcentage, de 1 à 100 |
| Demande urgente | | Une case : la demande ouvre alors sa ligne par un éclair |
| Fiche matériel, CCPU | | En PDF |

Une modification non enregistrée ne se perd pas sans un mot : quitter le formulaire — par
« Annuler », la croix ou Échap — demande confirmation.

## La référence

Chaque demande reçoit une référence au format **`AA-DP-NNNN`** — l'année de création, puis un
compteur annuel : `26-DP-0001`. C'est elle que l'atelier lit et recopie, et elle qui circule
dans les liens et dans l'API ; l'identifiant interne ne sort jamais de la base. Une référence
supprimée reste réservée : elle n'est jamais réattribuée.

## Valider ou refuser

Le **chargé de production seul** décide, et seulement sur une demande en attente — une demande
déjà tranchée répond `409`.

- **Valider** planifie d'office : le début est posé aujourd'hui — ou au jour ouvré suivant —, et
  la fin est calculée en jours ouvrés à partir de la durée estimée. Une demande qui occuperait
  plus de 3 650 jours est refusée.
- **Refuser** exige un **motif** — 1 000 caractères au plus : le demandeur doit savoir quoi
  corriger.

Les boutons Valider et Refuser sont sur la ligne même de la demande. Chaque décision émet un
événement (`demande.validee`, `demande.refusee`), comme le dépôt (`demande.deposee`).

## Modifier, supprimer

| | Chargé de production | Chargé d'affaire |
| --- | --- | --- |
| **Modifier** | Toutes les demandes | Les siennes, **quel que soit leur statut** |
| **Supprimer** | Toutes les demandes | Les siennes, **en attente seulement** |

::: warning Modifier une demande validée la repasse en attente
La validation et le planning sont effacés : la demande sort du circuit de l'atelier jusqu'à une
nouvelle décision. En revanche, **déposer un document** depuis la fiche (« + Déposer ») ne la
repasse pas en attente.
:::

Supprimer une demande efface ses fichiers, et les lignes liées suivent en cascade.

## La liste

- **Recherche** dans la référence, l'affaire, le statut, la passivation, les dates et le
  demandeur.
- **Onglets** Toutes, En attente, Validées, Refusées, avec leur compte.
- **Filtres** par demandeur (chargé de production, chef d'atelier) et par priorité.
- **En retard** : une demande dont la fin prévue est passée, qui n'est pas refusée, et dont le
  PV « Fin de fabrication » n'est pas encore fait. L'échéance s'y lit en rouge.
- **Rangement** : par statut pour l'encadrement (référence, affaire, fin prévue, pouces, temps
  atelier, demandeur) ; par affaire pour le chargé d'affaire.

## La fiche

Le menu d'une ligne ouvre sa fiche, dans un volet :

- **Le parcours** : déposée par qui et quand, validée par qui et quand ;
- **trois chiffres** : pouces ISO, durée estimée, livraison prévue ;
- **Fabrication, Contrôles, Suivi** — avec les dates planifiées une fois la demande validée —, et
  le motif de la décision ;
- **les documents**, en cinq familles — plans BPE / ISO, QMOS, DMOS, commande matériel, CCPU —,
  avec un aperçu des PDF ;
- **les coulées lues** sur les certificats CCPU (voir [Lecture automatique](/fonctionnalites/lecture-automatique)) ;
- pour l'encadrement, sur une demande validée : les liens vers ses affectations et vers sa
  commande matériel, et les **déclarations d'avancement** à valider ou refuser.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/lists_prefa.php` | La liste ; ses morceaux dans `src/dashboard/partials/prefa_*.php` |
| `src/dashboard/pages/create_prefa.php` | Le formulaire, pour un dépôt comme pour une modification |
| `src/dashboard/actions/create_prefa.php` | L'enregistrement |
| `src/dashboard/actions/review_prefa.php` | Valider, refuser |
| `src/dashboard/actions/delete_prefa.php` | Supprimer |
| `src/dashboard/actions/deposer_document.php` | Déposer un document depuis la fiche |
| `src/modeles/Demande.php` | Le modèle : visibilité, décision, planification, suppression |
| `src/dashboard/includes/demande/prefa_references.php` | Le compteur des références |
| `src/js/ecrans/prefa-interactions.js` | La liste, la fiche et le formulaire côté navigateur |
