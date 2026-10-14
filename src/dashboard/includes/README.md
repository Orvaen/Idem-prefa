# Les includes du tableau de bord

Trente-quatre fichiers à plat, c'est beaucoup à lire avant de savoir lequel ouvrir. Ils se
rangent en réalité en six familles. Le dossier reste plat — déplacer un fichier casse tous
les `require_once` qui le désignent, pour un gain qui ne vaut pas ce risque — mais voici la
carte.

## Le socle — tout le monde en dépend

| Fichier | Ce qu'il porte |
|---|---|
| `prefa_context.php` | **Le point d'entrée de toute page et de toute action.** La connexion à la base, la personne connectée, ses droits sous leurs anciens noms (`$isAdmin`, `$canFillPv`…), et les fonctions que tout le monde appelle : `prefaEscape`, `prefaFormatDate`, `prefaError`, `prefaPost`. |
| `prefa_references.php` | La référence d'une demande — `26-DP-0006` — et le compteur par année. |
| `prefa_visibilite.php` | Quelles demandes une personne a le droit de voir. Une seule règle, partagée avec l'API. |
| `menu.php` | Le menu déclaré une fois : qui voit quoi, quelle page chaque entrée ouvre, et les feuilles de style et scripts dont elle a besoin. |
| `user_display.php` | Le métier accolé au rôle : « Gestionnaire » devient « Chef d'atelier ». |
| `comptes.php` | La bascule entre les comptes connectés sur un même poste. |

## Les composants d'interface — à réutiliser, pas à recopier

| Fichier | Ce qu'il rend |
|---|---|
| `liste_filtrable.php` | **Le champ « chercher et choisir »** : on tape, on filtre, l'identifiant part dans un champ caché. Matière, passivation, affaire, rôle. Avant de réécrire un sélecteur, c'est ici qu'il faut passer. |
| `filtres.php` | La barre de filtres à pastilles des listes. |
| `prefa_documents.php` | Les cinq familles de documents d'une demande, et le rendu d'une ligne de document. |
| `passivation_row.php` | Une ligne du tableau des passivations, en lecture ou en saisie. |

## Les demandes

| Fichier | Ce qu'il porte |
|---|---|
| `accueil.php` · `accueil_vue.php` | Les chiffres de l'accueil et ce qui a bougé aujourd'hui. |
| `prefa_attachments.php` | Les plans et pièces jointes, rangés sur le disque. |
| `prefa_hours.php` | Du pouce ISO aux heures, puis aux jours. |
| `prefa_pdf.php` | Un PDF déposé par un formulaire, vérifié avant d'entrer en base. |
| `prefa_workshop.php` | Le personnel d'atelier : qui peut recevoir une affectation. |
| `pv.php` | Les quatre procès-verbaux d'une demande. |

## L'atelier et les plans — la plus grosse famille

| Fichier | Ce qu'il porte |
|---|---|
| `atelier.php` | Les éléments d'atelier d'une demande, leurs étapes, et le droit d'ouvrir leurs fichiers. |
| `avancement.php` | L'avancement d'une affectation : déclaré par l'atelier, validé par le chef, et lui seul l'écrit. Le dénominateur vient des soudures imprimées sur le plan, pas de ce qui est saisi. |
| `soudure_atelier.php` | La liste soudure vue par le soudeur : la feuille qu'il travaille, et ce qu'il y renseigne. |
| `planning.php` | D'une durée en heures à des bandes sur un calendrier. |
| `plan_document.php` · `plan_viewer.php` | Les fichiers ISO d'un élément, et le visualiseur où l'on pose les coulées. |
| `plan_table.php` · `plan_lignes.php` · `plan_numeros.php` | La lecture d'un plan : son tableau de nomenclature, ses lignes, ses numéros de feuille. |
| `nomenclature.php` · `soudure.php` | La nomenclature de matériel et la liste des soudures : lecture, écriture, rendu. |
| `plan_final.php` · `plan_iso_pdf.php` · `pdf.php` | Le plan final et les feuilles à imprimer. `pdf.php` est l'écriture de PDF réduite au strict nécessaire. |

## Le matériel et les bons

| Fichier | Ce qu'il porte |
|---|---|
| `materiel_bon.php` | Les bons de commande déposés sur une demande, leur lecture, leur validation et le pointage des réceptions. |
| `commandes.php` · `commandes_lignes.php` | Les commandes fournisseur du PV « Réception matériel », et la lecture des articles dans le PDF. |
| `ccpu.php` · `ccpu_demande.php` | Les certificats matière : la lecture pure d'un côté, la jonction avec la base et l'écran de l'autre. |

## Deux habitudes qui expliquent la forme du dossier

**Un fichier qui lit ne connaît ni la base ni le HTML.** `ccpu.php` sait reconnaître des
numéros de coulée dans un PDF, rien de plus ; `ccpu_demande.php` fait la jonction. Même
partage entre `commandes_lignes.php` et `materiel_bon.php`. C'est ce qui permet d'essayer
une lecture en ligne de commande, sans base ni session — voir `scripts/ccpu_lire.php`.

**Une page chargée en AJAX demande elle-même ce dont elle a besoin.** Les écrans arrivent
dans `#content` sans passer par `dashboard.php` : un `require_once` manquant ne se voit
qu'à l'ouverture de cet écran-là, jamais au démarrage.
