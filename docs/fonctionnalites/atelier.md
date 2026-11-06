# Atelier : plans et affectations

Une demande validée est **ouverte à l'atelier** : le chef d'atelier y ajoute ses plans ISO, les
répartit entre le personnel, et valide l'avancement qu'on lui déclare.

Il n'y a plus d'étape de « prise en charge » : plusieurs chefs peuvent travailler sur la même
demande. L'ancienne opération répond `410`.

## Plans & équipes

**Écran :** section « Atelier » — chargé de production et chef d'atelier.

Les demandes validées y sont rangées en deux états, chacun avec son onglet et son point de
couleur — le code de la liste des demandes : **À préparer** (aucun plan encore, point de
l'attente) puis **En préparation** (point de l'accent). Chaque ligne s'ouvre sur le point de
son état ; les onglets en sont la légende. Le volet d'une demande porte un onglet par plan.

**Toute la ligne** ouvre le volet « Plans & équipes », comme la liste des demandes ouvre la
fiche — le 6 novembre 2026 : « quand je clique sur un plan, rien ne se passe », seul le bouton de
la ligne l'ouvrait. Les boutons de la ligne gardent leur geste.

### Ajouter un plan

« Ajouter un plan » : un nom (80 caractères), une description facultative (180), et les documents de la
demande à lui rattacher — au moins un plan ou ISO. Le nom est unique dans la demande. Un plan se
renomme ou se supprime ; le supprimer efface ses affectations et leur suivi, mais garde les
fichiers joints à la demande.

### Affecter

Chaque plan a **trois postes** : **Plan / ISO**, **Montage**, **Suivi soudage**. Un menu y choisit
une personne parmi le personnel d'atelier, ou retire la personne en place.

| Règle | Détail |
| --- | --- |
| Une seule affectation active par poste | Réaffecter archive l'ancienne : son historique reste. Réaffecter la même personne ne change rien. |
| Le poste Plan / ISO | Exige qu'un fichier de plan soit rattaché. |
| Ce que l'affectation ouvre | Le plan et ses documents rattachés — pas le reste de la demande. |

### Les métiers

Sous les trois postes, chaque plan a **cinq métiers** : **Débiteur**, **Assembleur**,
**Pointeur**, **Soudeur**, **Passiveur**. Le chef les donne à n'importe qui du personnel
d'atelier, depuis le même menu, où chaque nom se coche ou se décoche.

| Règle | Détail |
| --- | --- |
| Plusieurs par personne | Une même personne peut tenir plusieurs métiers sur le même plan — débiteur et soudeur, par exemple. |
| Plusieurs par métier | Deux soudeurs sur un plan, c'est deux noms sur la même ligne. |
| Seulement par plan | Le métier ne se pose pas sur le compte : on peut être soudeur sur un plan et passiveur sur un autre. |
| En plus des postes | Les trois postes ne changent pas, et l'avancement reste à eux : un métier n'en porte pas. |
| Ce que le métier ouvre | Le plan, comme un poste : il paraît dans « Mes plans », ses fichiers s'ouvrent, ses soudures se renseignent, ses étapes s'enregistrent. |

Les métiers arrivent avec la migration `2026-11-02_metiers` ; tant qu'elle n'est pas passée sur
un serveur, la rangée ne s'affiche pas.

### Les feuilles ISO

Un plan porte ses feuilles PDF : le chef les dépose, les remplace ou les retire, une à une ou en
lot. Le numéro de ligne est lu dans le cartouche du dessin. La visionneuse plein écran les
feuillette — pages, zoom (`Ctrl` + molette), sommaire en vignettes — et porte les **cases de
coulée** : voir [Soudures](/fonctionnalites/soudures#la-visionneuse).

### Le résumé du plan

**« Résumé du plan »**, en tête du volet, dit ce qui manque encore au plan, feuille ISO par
feuille ISO — ce que le plan final écrit. Quatre chiffres d'abord : les soudures à renseigner,
celles qui attendent la validation, les lignes de nomenclature sans coulée, et les coulées hors
CCPU. Puis deux tableaux, où ne paraissent que les feuilles où il manque quelque chose :

| Tableau | Ce qu'il dit |
| --- | --- |
| Soudures | Les numéros de la liste soudure imprimée que personne n'a renseignés, et ceux qui attendent le visa du chef — « n° 1 à 6, 8 » |
| Coulées (CCPU) | Les certificats de la demande, leur numéro et leurs coulées lues ; puis, par feuille, les repères de la nomenclature sans coulée, et les coulées tapées qu'aucun certificat ne porte. L'article d'un repère paraît au survol |

Le nom d'une feuille l'ouvre dans la visionneuse, à sa page. Tout l'encadrement l'ouvre : il ne
fait que lire. Rouvert, le résumé revient tel qu'on l'a laissé et se relit sans bruit ; il ne
change que si le plan a changé. Rien n'y est relu des PDF — les listes soudure et les nomenclatures sont lues au
dépôt ([Lecture automatique](/fonctionnalites/lecture-automatique#tout-est-lu-au-depot-en-arriere-plan)) ;
une feuille pas encore lue le dit. Une case de coulée va à la ligne qu'elle couvre sur le
dessin, pas à son rang : les rangs se renumérotent quand le chef en ajoute une.

## L'avancement

Un pourcentage par affectation. **Il se calcule**, et seul le chef d'atelier — ou le chargé de
production — le fait bouger.

| Travail | Ce qui le mesure |
| --- | --- |
| **Suivi soudage** d'un plan qui porte une liste de soudures | Les soudures renseignées, sur les lignes de la liste reconnue sur le PDF |
| **Tout le reste** — plan / ISO, montage, suivi soudage sans liste | Les étapes « OK », sur les étapes qui s'appliquent |

Les **sept étapes** d'un plan : Débit, Découpage, Pliage, Pointage, Soudage, Passivation,
Autre. Chacune vaut **— / OK / Non OK / RAS**. « RAS » ne s'applique pas et sort du total ;
« Non OK » et une étape vide restent à faire ; « Autre » vaut RAS par défaut.

| Avancement | Se lit |
| --- | --- |
| 0 % | À commencer |
| 1 à 99 % | En cours |
| 100 % | Terminé |

### Déclarer, valider

1. Le personnel d'atelier enregistre ses étapes, ou ses soudures : le chiffre calculé part en
   **déclaration** — une seule en attente par affectation.
2. Le chef d'atelier la **valide** — le pourcentage s'écrit — ou la **refuse**.
3. Quand c'est le chef lui-même qui enregistre les étapes, ou qui « Saisit » un pourcentage, le
   chiffre est posé d'office.

Une déclaration retient le compte qui l'a produite — « 4 étapes sur 6 », « 7 soudures sur 12 » —,
figé au jour où elle est faite. Le modèle complet est dans
[Affectations de l'atelier](/workflow-atelier).

## Mes plans — le personnel d'atelier

**Écran :** « Mes plans », section « Affaires & demandes » — personnel d'atelier. Depuis le
6 novembre 2026, c'est aussi là qu'il **renseigne ses soudures** : l'écran « Mes soudures » y a
été réuni ([le détail](/fonctionnalites/soudures)).

- **Une ligne par plan affecté** — par un poste ou par un métier —, sur une demande validée :
  une flèche, le plan, la demande, l'affaire, une pastille par travail (« Montage · 40 % »),
  puis une par métier (« Soudeur »), le nombre de feuilles.
- **Toucher la ligne déplie le plan** sur ses feuilles, en vignettes — le menu qui se déplie —,
  et un autre toucher le replie ; un plan seul arrive déplié. Toucher une feuille ouvre son
  dessin, où l'on renseigne ses soudures.
- **La recherche** trouve un plan, une demande, une affaire — ou le numéro de ligne d'une
  feuille : le plan qui la porte se déplie, et seules les feuilles qui répondent restent.
- **Onglets** Tous, En cours, À commencer, Terminés — calculés sur l'avancement validé ; un plan
  où l'on ne tient qu'un métier se range selon ses étapes —, et le nombre de déclarations en
  attente.
- **« Étapes »** — il s'appelait « Mettre à jour » — ouvre le volet du plan : ses travaux et
  d'où vient leur chiffre, les soudures « x sur y » avec « Renseigner mes soudures » — qui
  referme le volet et déplie le plan —, les sept étapes et « Enregistrer les étapes », les
  fichiers du plan et l'historique.
- **« Signaler un manque »** en haut, et **« Matériel manquant »** sous les plans.
- **« Mes saisies sur des plans qui ne me sont plus confiés »** garde les cent dernières, après
  une réaffectation.

Le serveur n'accepte l'enregistrement des étapes que sur un plan où l'on est : par **sa propre**
affectation, ou, pour qui n'y tient qu'un métier, au titre du plan lui-même.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/plans_iso.php`, `src/dashboard/partials/atelier_request.php` | Plans & équipes |
| `src/dashboard/pages/atelier.php` | Mes plans |
| `src/dashboard/includes/atelier/avancement.php` | Le calcul de l'avancement |
| `src/dashboard/includes/atelier/plan_resume.php`, `src/dashboard/actions/plan_resume.php` | Le résumé du plan : ce qui manque, feuille par feuille (`planResume()`), et sa fenêtre (`iso.js`) |
| `src/dashboard/actions/save_atelier.php` | Plans et affectations : `element`, `update_element`, `delete_element`, `documents`, `assign`, `revoke`, et `metier` (donner ou retirer un métier) |
| `src/dashboard/includes/atelier/atelier.php` | Les métiers (`atelierMetiers()`), et la règle d'accès du personnel à un plan, par un poste ou un métier (`atelierPlansDeSql()`) |
| `src/dashboard/actions/update_atelier.php` | Étapes, déclarations, validations, saisie directe |
| `src/dashboard/actions/upload_plan.php`, `delete_plan.php` | Les feuilles PDF |
| `src/js/ecrans/atelier.js`, `src/js/ecrans/plan-viewer.js` | Le volet et la visionneuse |
