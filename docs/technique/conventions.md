# Conventions de code

Les règles qui gardent le code lisible et l'interface cohérente. Elles se vérifient presque
toutes d'un `grep`.

## La langue

Le code est écrit **en français** : noms de fonctions et de variables (`ouvrirParametres`,
`quitterFormulairePrefa`, `prefaEscape`), commentaires, messages, commits. Quelques fichiers
plus anciens gardent des noms anglais ; on ne les renomme pas pour le plaisir.

Les commentaires disent **pourquoi**, pas ce que la ligne fait déjà : la contrainte, l'erreur
qu'on a déjà commise, ce qui casserait si on changeait la chose.

## Pas d'étape de compilation

Le code est servi tel quel : PHP interprété, JavaScript et CSS chargés par des balises. Il n'y a
ni bundler, ni transpileur, ni préprocesseur, ni `package.json` à la racine — seule cette
documentation en a un, dans `docs/`. Une modification d'un fichier de `src/` se voit au
rechargement de la page.

Chaque feuille et chaque script porte une empreinte dans son adresse (`?v=…`, la date ou le
condensat du fichier) : le navigateur garde sa copie tant que le fichier ne bouge pas.

## PHP

### Une action

Les écritures du tableau de bord passent par `src/dashboard/actions/*.php`. Chacune suit le
même patron :

```php
<?php
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();                                     // POST seulement, et le jeton CSRF valide
if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');

// … lire, vérifier, écrire …

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'Enregistré.']);
```

| Outil | Rôle |
| --- | --- |
| `prefa_context.php` | La session, la base (`$db`), la personne connectée (`$utilisateur`) et ses droits (`$isAdmin`, `$canManageWorkshop`…) |
| `prefaPost()` | Refuse ce qui n'est pas un POST, et un jeton CSRF absent ou faux |
| `prefaError($statut, $message)` | Répond `{"success": false, "message": …}` avec le statut HTTP, et s'arrête |
| `prefaEscape($valeur)` | Échappe pour le HTML — **toute** valeur écrite dans une page passe par elle |
| `$utilisateur->est(Role::…)` | La seule façon de tester un rôle |

**Le droit se vérifie sur le serveur**, dans l'action, même quand l'interface cache déjà le
bouton : l'interface cache, le serveur refuse.

### L'API

Plus stricte : aucun SQL hors de `src/modeles/`, aucun `echo` hors d'une `Reponse`. Voir
[L'API](/technique/api).

### Le schéma

Toute évolution de la base s'accompagne d'une **migration** datée dans `scripts/migrations/`,
relançable sans dégât, et de la mise à jour de `db/schema.sql`. Voir
[Base de données](/technique/base-de-donnees).

### Les composants d'écran

Un balisage qui sert à plus d'un endroit s'écrit une fois, dans `src/dashboard/includes/vue/`,
en fonction qui rend du HTML : la page ne fait plus que ranger ses morceaux. Les gros morceaux
s'y écrivent tels quels entre `?>` et `<?php`, dans un `ob_start()` — du HTML qu'on lit et
qu'on corrige ligne à ligne —, les petits bout à bout. Le composant reçoit les classes que le
script de l'écran écoute ; il ne les invente pas.

| Fichier | Ce qu'il rend |
| --- | --- |
| `vue/liste.php` | Les pièces des écrans de liste : la densité (`listeDensite()`), « Actualiser » (`listeActualiser()`), la recherche (`listeRecherche()`), les onglets d'état et leur résumé (`listeOnglets()`) |
| `vue/liste_filtrable.php` | Le champ « liste filtrable » des fenêtres (`listeFiltrableRendu()`) |
| `vue/filtres.php` | Les pastilles de filtre de la barre (`filtreRendu()`, `filtresBarre()`) |
| `vue/soudures.php`, `vue/manques.php` | « Mes soudures » : un plan et ses feuilles, la fenêtre d'une soudure, celle d'un manque |

Avant de recopier un bloc d'un écran à l'autre, en faire un composant. La densité était écrite
à la main, à l'identique, dans huit écrans ; la sortir n'a pas changé un pixel — vérifié en
photographiant les huit avant et après.

## JavaScript

- **Pas de jQuery** : le DOM natif (`closest`, `classList`, `textContent`) et `fetch`. Un
  écran se charge dans `#content` par `window.chargerDans(cible, adresse)`
  (`src/js/composants/charger.js`). Les dossiers de `src/js/` : voir son `README.md`.
- **Un fichier par écran**, déclaré avec son entrée de menu dans `includes/menu.php`, plus un
  **socle** chargé pour tout le monde (navigation, palette, toasts, alertes, paramètres,
  listes filtrables, champ date…).
- **Délégation sur le document** : les écrans arrivent par AJAX et se remplacent à chaque
  navigation, un écouteur posé sur un bouton disparaîtrait avec lui.

  ```js
  document.addEventListener('click', async evenement => {
      const bouton = evenement.target.closest('.affaire-supprimer');
      if (!bouton) return;
      …
  });
  ```

- Un module s'enferme dans une fonction immédiate, en mode strict, et ne publie sur `window`
  que ce que d'autres scripts appellent :

  ```js
  (() => {
      'use strict';
      // …
      window.toast = function (texte, { erreur = false } = {}) { … };
  })();
  ```

- **Jamais `alert`, `confirm` ni `prompt`.** Les questions passent par
  `window.alerte`, `window.confirmer` et `window.choisir`, qui rendent une promesse :

  ```js
  if (!await window.confirmer('Supprimer l’affaire « ' + nom + ' » ?', {
      action: 'Supprimer',
      ton: 'danger',
  })) return;
  ```

  Un `hx-confirm` d'htmx passe par la même fenêtre ; l'élément dit le reste par
  `data-confirmer-texte` et `data-confirmer-action`.
- **Les réponses** passent par `window.toast(message, { erreur })` — sauf dans une fenêtre, où
  un refus s'écrit à côté du champ à corriger. Une action htmx qui répond par du HTML sans
  changement visible — une ligne remplacée par une ligne identique — l'annonce par son en-tête :
  `HX-Trigger: {"toast": "…"}` (`json_encode`, qui échappe les accents et les retours à la
  ligne).
- **Montrer, cacher : l'attribut `hidden`, et lui seul** — jamais `style.display` à côté.
- **L'anneau de focus ne paraît qu'au clavier.** Chrome l'allume dès qu'une touche est pressée,
  Échap compris : un clic dans un tableau puis Échap entourait le tableau entier.
  `js/composants/focus.js` marque l'élément atteint au pointeur (`data-focus-pointeur`), et
  `dashboard-base.css` éteint son anneau ; par Tab, il reste. Un composant n'a rien à refaire.

## CSS

- **Les tailles en pixels, jamais en `rem`** : `html` est à `font-size: 13px`, un `rem` y vaut
  donc 13 px, et une échelle pensée en `rem` se décale d'un quart.
- **Les jetons, jamais les valeurs** : couleurs (`--text`, `--accent`, `--danger-texte`…),
  hauteurs (`--h-xs` à `--h-2xl`), rayons (`--r-sm` à `--r-xl`). Ils sont définis dans
  `dashboard-base.css` pour le thème sombre et redéfinis dans `light-theme.css` pour le clair.
- **`hidden` suffit.** `dashboard-base.css` pose `[hidden] { display: none !important }`, qu'aucune
  règle d'écran ne bat : n'écrivez jamais `.x[hidden] { display: none }`.
- **`.sr-only`** (lu par les lecteurs d'écran, jamais affiché) vit dans le socle : s'en servir,
  ne pas la redéfinir.
- **Pas de style dans le PHP.** Une valeur calculée passe en **variable**, et la feuille dit
  comment la poser :

  ```php
  <span style="--part: <?= $partFaite ?>%"></span>
  ```

  ```css
  .soudures-barre > span { width: var(--part, 0%); }
  ```

- **Une fenêtre se construit sur `dialog.fenetre`** (`fenetre.css`) ; elle ne garde que sa
  largeur et la mise en page de son contenu.
- Le thème clair est encore, en partie, une feuille de surcharges en `!important` : une nouvelle
  règle du thème clair redéfinit un **jeton**, plutôt que de repeindre un élément.

Avant tout travail d'interface, lire la [direction artistique](/direction-artistique).

## Les commits

Un commit, une idée. Le titre dit l'écran et ce qui change, en français ; le corps dit pourquoi,
en quelques lignes :

```
Planning : la tranche du Gantt n'est plus écrite deux fois

planning.css portait deux fois les mêmes 290 lignes […]. La seconde copie
l'emportait partout où elles différaient ; la première part.
```

Un fichier qui mêle deux sujets se découpe entre deux commits, bloc par bloc.
