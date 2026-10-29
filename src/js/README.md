# Le JavaScript du site

Trente et un fichiers, rangés en quatre dossiers. Ils étaient à plat, et le nom ne disait pas
toujours à quoi un fichier servait : `palette.js` habille toute la barre, `pv.js` un seul
écran, `toast.js` n'appartient à aucun. Le dossier le dit maintenant.

```
js/
  composants/   des briques sans écran : on les pose sur un balisage, elles s'habillent seules
  coquille/     le tableau de bord autour des écrans : barre, navigation, comptes, flux
  ecrans/       un fichier par écran, déclaré avec son entrée de menu
  connexion/    la page de connexion
```

Le tri suit une question : **qu'est-ce qui disparaît si on retire ce fichier ?** Un écran
entier → `ecrans/`. Un morceau de la barre ou du cadre → `coquille/`. Un comportement que
plusieurs écrans partagent, sans qu'aucun ne le possède → `composants/`.

Les noms n'ont pas changé : le dossier suffit à dire le rôle, et un nom anglais ne se
renomme pas pour le plaisir (`docs/technique/conventions.md`).

## Qui charge quoi

Rien n'est chargé par un `<script>` écrit à la main dans une page du tableau de bord : tout
passe par `src/dashboard/includes/menu.php`.

- Le **socle** — composants et coquille, plus `ecrans/prefa-interactions.js`, que tout le
  monde ouvre — est la liste de `dashboardRessources()`. **L'ordre compte** : un fichier qui
  appelle `window.toast` à son chargement doit venir après `toast.js`.
- Un **écran** déclare ses scripts dans son entrée de menu ; un rôle qui ne voit pas
  l'entrée ne reçoit pas le script.

Deux exceptions, chargées dans l'en-tête et non par le menu : `composants/theme.js`, qui doit
poser le thème avant le premier rendu, et `composants/floating-fields.js`.

## `composants/`

| Fichier | Ce qu'il porte |
|---|---|
| `charger.js` | `window.chargerDans(cible, adresse)` : un écran dans `#content`, à la place de `$.load`. Un chargement plus récent sur la même cible remplace le précédent. |
| `theme.js` | Le thème clair ou sombre, décidé à un seul endroit (`window.Theme`). |
| `floating-fields.js` | Les étiquettes flottantes des champs. |
| `mot-de-passe.js` | L'œil qui montre le mot de passe. |
| `toast.js` | `window.toast` : ce que l'application répond. |
| `alerte.js` | `window.alerte`, `confirmer`, `choisir` : ce que l'application demande. |
| `champ-date.js` | Le calendrier de tous les champs date. |
| `liste-filtrable.js` | La liste déroulante qu'on filtre à la frappe. |
| `liste-selection.js` | La sélection de lignes d'un tableau `data-selection`. |
| `filtres.js` | La barre de filtres à pastilles. |
| `suggestions.js` | Les suggestions sous un champ libre. |

## `coquille/`

| Fichier | Ce qu'il porte |
|---|---|
| `dashboard-navigation.js` | Ouvrir un écran, l'inscrire dans l'adresse, Précédent et Suivant. |
| `dashboard-sidebar.js` | La barre : ses rayons repliés, son ouverture sur petit écran. |
| `dashboard-accounts.js` | Le menu du compte et la bascule entre comptes. |
| `dashboard-forms.js` | Les formulaires de comptes et de passivations. |
| `palette.js` | La palette Ctrl+K. |
| `flux.js` | Ce que le serveur annonce en direct. |

## `ecrans/`

| Fichier | L'écran |
|---|---|
| `prefa-interactions.js` | Les demandes : la liste, la fiche, le formulaire. Dans le socle, puisque tout le monde l'ouvre. |
| `affaires.js` | Les affaires. |
| `atelier.js` | Le volet d'atelier d'une demande, partagé par les écrans du plan ISO. |
| `tableaux-plan.js` | Les tableaux de saisie d'un plan : nomenclature et soudures. |
| `iso.js` | Plans / ISO & affectations. |
| `soudures.js`, `suivi-soudures.js` | Mes soudures ; le suivi de l'encadrement. |
| `planning.js` | Le planning. |
| `commande-materiel.js` | La commande de matériel. |
| `pv.js`, `commandes.js` | Les PV, et le tableau des commandes fournisseur. |

`parametres.js` (coquille) et `plan-viewer.js` (écrans) sont encore à la racine : un travail
en cours les touche, ils rejoindront leur dossier ensuite.
