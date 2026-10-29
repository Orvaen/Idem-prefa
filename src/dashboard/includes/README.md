# Les includes du tableau de bord

Quarante-cinq fichiers, rangés en cinq dossiers. Ils ont longtemps été à plat, et ce document
disait alors qu'il valait mieux les y laisser — « déplacer un fichier casse tous les
`require_once` qui le désignent, pour un gain qui ne vaut pas ce risque ». Le risque s'est
avéré mesurable : les chemins ont été repris par un balayage qui vérifie que chacun pointe
sur un fichier qui existe, et les pages ont été rendues avant et après pour comparer leur
balisage octet par octet.

```
includes/
  prefa_context.php   le point d'entrée : qui est connecté, sur quelle base
  menu.php            la navigation
  comptes.php         la bascule entre comptes d'un même poste
  demande/            ce qui appartient à une demande de préfabrication
  atelier/            ce que l'atelier fabrique : plans, soudures, avancement
  materiel/           les bons de commande et les certificats matière
  lecture/            lire un document : ne connaît ni la base ni le HTML
  vue/                mettre en forme pour l'écran
```

Le tri suit la première phrase que chaque fichier écrit sur lui-même. « Les plans et
pièces jointes **d'une demande** » va dans `demande/` ; « la liste des soudures **d'un plan
ISO** » dans `atelier/` ; « les bons de commande » dans `materiel/`.

## La racine — tout le monde en dépend

| Fichier | Ce qu'il porte |
|---|---|
| `prefa_context.php` | **Le point d'entrée de toute page et de toute action.** La connexion à la base, la personne connectée, ses droits sous leurs anciens noms (`$isAdmin`, `$canFillPv`…), et les fonctions que tout le monde appelle : `prefaEscape`, `prefaFormatDate`, `prefaError`, `prefaPost`. |
| `menu.php` | Le menu déclaré une fois : qui voit quoi, quelle page chaque entrée ouvre, et les feuilles de style et scripts dont elle a besoin. |
| `comptes.php` | La bascule entre les comptes connectés sur un même poste. |

## `demande/` — la demande de préfabrication

| Fichier | Ce qu'il porte |
|---|---|
| `prefa_references.php` | La référence d'une demande — `26-DP-0006` — et le compteur par année. |
| `prefa_visibilite.php` | Quelles demandes une personne a le droit de voir. Une seule règle, partagée avec l'API. |
| `prefa_documents.php` | Les cinq familles de documents d'une demande, et le rendu d'une ligne de document. |
| `prefa_attachments.php` | Les plans et pièces jointes, rangés sur le disque — les seuls à ne pas être en base. |
| `prefa_pdf.php` | Un PDF déposé par un formulaire, vérifié avant d'entrer en base. |
| `prefa_hours.php` | Du pouce ISO aux heures, puis aux jours. |
| `pv.php` | Les procès-verbaux d'une demande : libellés, documents, avancement. |
| `accueil.php` | Les chiffres de l'accueil et ce qui a bougé aujourd'hui. Son balisage est dans `vue/accueil_vue.php`. |

## `atelier/` — ce que l'atelier fabrique

| Fichier | Ce qu'il porte |
|---|---|
| `atelier.php` | Les éléments d'atelier d'une demande, leurs étapes, et le droit d'ouvrir leurs fichiers. |
| `avancement.php` | L'avancement d'une affectation : déclaré par l'atelier, validé par le chef, et lui seul l'écrit. Le dénominateur vient des soudures imprimées sur le plan, pas de ce qui est saisi. |
| `soudure.php` | La liste des soudures d'un plan ISO : lecture, écriture, rendu. |
| `soudure_atelier.php` | La même liste vue par le soudeur : la feuille qu'il travaille, et ce qu'il y renseigne. |
| `signature.php` | Le visa des cordons : la signature que chacun trace dans ses paramètres, rangée en traits, et posée sur les cordons qu'il valide. |
| `nomenclature.php` | La nomenclature de matériel d'un plan, et le numéro de coulée qu'on y pose. |
| `plan_document.php` | Les fichiers ISO d'un élément, et celui sur lequel on travaille. |
| `planning.php` | D'une durée en heures à des bandes sur un calendrier. |
| `prefa_workshop.php` | Le personnel d'atelier : qui peut recevoir une affectation. |

## `materiel/` — l'approvisionnement

| Fichier | Ce qu'il porte |
|---|---|
| `materiel_bon.php` | Les bons de commande déposés sur une demande, leur lecture, leur validation et le pointage des réceptions. |
| `commandes.php` | Les commandes fournisseur du PV « Réception matériel ». |
| `ccpu_demande.php` | Les certificats matière d'une demande : la jonction entre la lecture pure et la base. |
| `unites.php` | Les unités proposées en signalant un manque, tenues dans les paramètres. |

## `lecture/` — lire un document

Aucun de ces fichiers ne contient de requête SQL ni de balisage. C'est ce qui permet
d'essayer une lecture en ligne de commande, sans base ni session — voir
`scripts/ccpu_lire.php`.

| Fichier | Ce qu'il porte |
|---|---|
| `ccpu.php` | Reconnaître des numéros de coulée, des dimensions et des nuances dans un certificat. |
| `commandes_lignes.php` | Reconnaître les articles d'un bon de commande. |
| `plan_table.php` · `plan_lignes.php` · `plan_numeros.php` | La lecture d'un plan : son tableau de nomenclature, ses lignes, ses numéros de feuille. |
| `plan_final.php` · `plan_iso_pdf.php` · `pdf.php` | Le plan final et les feuilles à imprimer. `pdf.php` est l'écriture de PDF réduite au strict nécessaire. |
| `ecriture_visa.php` | L'écriture manuscrite du visa au nom : ses chasses et son encre, lues dans la police. |

## `vue/` — mettre en forme pour l'écran

| Fichier | Ce qu'il rend |
|---|---|
| `liste_filtrable.php` | **Le champ « chercher et choisir »** : on tape, on filtre, l'identifiant part dans un champ caché. Matière, passivation, affaire, rôle. Avant de réécrire un sélecteur, c'est ici qu'il faut passer. |
| `filtres.php` | La barre de filtres à pastilles des listes. |
| `plan_viewer.php` | Le visualiseur de plan, où l'on pose les coulées. |
| `passivation_row.php` | Une ligne du tableau des passivations, en lecture ou en saisie. |
| `accueil_vue.php` | Le balisage de l'accueil. |
| `user_display.php` | Le métier accolé au rôle : « Gestionnaire » devient « Chef d'atelier ». |

## Deux habitudes qui expliquent la forme du dossier

**Un fichier qui lit ne connaît ni la base ni le HTML.** `lecture/ccpu.php` sait reconnaître
des numéros de coulée dans un PDF, rien de plus ; `materiel/ccpu_demande.php` fait la
jonction. Même partage entre `lecture/commandes_lignes.php` et `materiel/materiel_bon.php`.

**Une page chargée en AJAX demande elle-même ce dont elle a besoin.** Les écrans arrivent
dans `#content` sans passer par `dashboard.php` : un `require_once` manquant ne se voit
qu'à l'ouverture de cet écran-là, jamais au démarrage.

## Deux coutures qui traversent les dossiers

Elles sont connues et laissées telles quelles — les signaler vaut mieux que les masquer :

- `lecture/plan_lignes.php` appelle `soudureLignes()`, donc une requête, par `atelier/`.
- `vue/plan_viewer.php` lit la nomenclature, donc `atelier/` également.

Elles se rangeront le jour où les gros fichiers seront coupés en deux : ce qui touche la
base d'un côté, ce qui met en forme de l'autre.
