# Atelier : plans et affectations

Une demande validée est **ouverte à l'atelier** : le chef d'atelier y ajoute ses plans ISO, les
répartit entre le personnel, et valide l'avancement qu'on lui déclare.

Il n'y a plus d'étape de « prise en charge » : plusieurs chefs peuvent travailler sur la même
demande. L'ancienne opération répond `410`.

## Plans / ISO & Affectations

**Écran :** section « Atelier » — chargé de production et chef d'atelier.

Les demandes validées y sont rangées en deux groupes : **À préparer** (aucun plan encore) et
**En préparation**. Le volet d'une demande porte un onglet par plan.

### Ajouter un plan

« Ajouter un plan » : un nom (80 caractères), une description (180), et les documents de la
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

### Les feuilles ISO

Un plan porte ses feuilles PDF : le chef les dépose, les remplace ou les retire, une à une ou en
lot. Le numéro de ligne est lu dans le cartouche du dessin. La visionneuse plein écran les
feuillette — pages, zoom (`Ctrl` + molette), sommaire en vignettes — et porte les **cases de
coulée** : voir [Soudures](/fonctionnalites/soudures#la-visionneuse).

## L'avancement

Un pourcentage par affectation. **Il se calcule**, et seul le chef d'atelier — ou le chargé de
production — le fait bouger.

| Travail | Ce qui le mesure |
| --- | --- |
| **Suivi soudage** d'un plan qui porte une liste de soudures | Les cordons renseignés, sur les lignes de la liste reconnue sur le PDF |
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

**Écran :** « Mes plans », section « Affaires & demandes » — personnel d'atelier.

- **Une ligne par plan affecté**, sur une demande validée : le plan, la demande, l'affaire, une
  pastille par travail (« Montage · 40 % »), le nombre de feuilles.
- **Onglets** Tous, En cours, À commencer, Terminés — calculés sur l'avancement validé —, et le
  nombre de déclarations en attente.
- **« Mettre à jour »** ouvre le volet du plan : ses travaux et d'où vient leur chiffre, les
  cordons « x sur y » avec « Renseigner mes soudures », les sept étapes et « Enregistrer les
  étapes », les fichiers du plan et l'historique.
- **« Mes saisies sur des plans qui ne me sont plus confiés »** garde les cent dernières, après
  une réaffectation.

Le serveur n'accepte l'enregistrement des étapes que sur **sa propre** affectation.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/plans_iso.php`, `src/dashboard/partials/atelier_request.php` | Plans / ISO & Affectations |
| `src/dashboard/pages/atelier.php` | Mes plans |
| `src/dashboard/includes/atelier/avancement.php` | Le calcul de l'avancement |
| `src/dashboard/actions/save_atelier.php` | Plans et affectations : `element`, `update_element`, `delete_element`, `documents`, `assign`, `revoke` |
| `src/dashboard/actions/update_atelier.php` | Étapes, déclarations, validations, saisie directe |
| `src/dashboard/actions/upload_plan.php`, `delete_plan.php` | Les feuilles PDF |
| `src/js/atelier.js`, `src/js/plan-viewer.js` | Le volet et la visionneuse |
