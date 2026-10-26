# Direction artistique

> **État : validée le 26 octobre 2026.** Les jetons sont appliqués (écarts 1 à 5) ; les
> écrans suivent, un par un.

## Pourquoi ce fichier

Jusqu'ici la DA vivait dans des commentaires CSS, écran par écran. Le résultat était
prévisible : l'accueil a reçu des cartes à coins arrondis quand la liste des demandes est
restée un tableau à plat, et les deux écrans ne se ressemblent plus. Une règle qu'on ne
peut pas relire est une règle qu'on ne respecte pas.

Les valeurs ci-dessous viennent de **[Propel](https://propel.plane.so/)**, la bibliothèque
de composants de Plane. Ce n'est pas un goût mais une source : chaque chiffre est relevé
sur ses pages de fondations, converti d'OKLCH en hexadécimal, et non inventé.

- [Couleurs](https://propel.plane.so/foundations/color.md)
- [Bordures et rayons](https://propel.plane.so/foundations/borders.md)
- [Typographie](https://propel.plane.so/foundations/typography.md)
- [Échelle des contrôles](https://propel.plane.so/foundations/sizing.md)

---

## 1. Les surfaces

Propel empile trois niveaux, et c'est l'empilement qui donne la profondeur — pas les
ombres.

| Rôle Propel | Ce que c'est ici | Clair | Sombre |
|---|---|---|---|
| `bg-canvas` | `#contenaire` : le cadre, qui porte la barre latérale | `#F2F2F3` | `#0F0F10` |
| `bg-surface-1` | `#right-panel` : la grande vue arrondie | `#FFFFFF` | `#141415` |
| `bg-layer-1` | une carte posée dessus : tuile, section, volet | `#F9F9F9` | `#181A1B` |
| `bg-layer-3` | une carte dans une carte, un en-tête de groupe | `#F2F2F3` | `#222425` |

**Règle.** Un écran ne peint rien lui-même : il laisse voir `bg-surface-1`. Ce sont ses
cartes qui portent `bg-layer-1`. Un tableau n'est **pas** une carte — il vit à même la
surface, et ce sont ses filets qui le structurent.

> **Écart avec la palette actuelle.** Le cadre est aujourd'hui à `#FAFAFA`, soit 1,04:1
> contre le panneau blanc : la vue arrondie ne se détache plus. Propel le pose à `#F2F2F3`,
> et c'est son `border-strong` qui porte le bord du panneau. C'est la correction du défaut
> relevé sur l'écran de présentation.

## 2. Les filets

| Rôle Propel | Emploi | Clair | Sombre |
|---|---|---|---|
| `border-subtle` | séparateur entre deux lignes d'un tableau | `#EAEBEC` | `#222425` |
| `border-subtle-1` | cadre d'une carte, d'un champ | `#E1E2E3` | `#2C2E30` |
| `border-strong` | bord du panneau, séparation de deux zones | `#D5D7D9` | `#36393A` |

**Règle.** Trois filets, pas quatre. Le plus discret pour ce qui se répète quarante fois,
le plus franc pour ce qui délimite une zone. Sur quarante lignes, un filet franc fait une
grille — et c'est la grille qu'on lit au lieu des données.

## 3. Les rayons

`2px` · `4px` · `6px` · `8px` · `12px` · `16px` · `20px` · plein

| Rayon | Pour |
|---|---|
| `4px` | une pastille, une case à cocher, un bouton d'icône |
| `6px` | un bouton, un champ, une entrée de menu |
| `8px` | une carte, un volet, un menu déroulant |
| `12px` | la grande vue (`#right-panel`), une boîte de dialogue |
| plein | une pastille de statut, un compteur |

**Règle.** Le rayon croît avec la surface. Un bouton dans une carte dans la vue : 6, 8, 12.
Jamais l'inverse.

## 4. L'échelle des contrôles

Six barreaux, hauteur et padding latéral liés :

| Barreau | Hauteur | Padding | Pour |
|---|---|---|---|
| `xs` | 20px | 6px | une pastille dans une cellule |
| `sm` | 24px | 8px | un contrôle dans une ligne de tableau |
| `md` | 28px | 10px | une entrée de menu, un filtre |
| `lg` | 32px | 12px | un bouton, une barre d'outils |
| `xl` | 36px | 14px | un champ de formulaire |
| `2xl` | 40px | 16px | un champ principal, une recherche en tête d'écran |

**Règle.** Un libellé, une hauteur, partout. C'est ce qui manquait : les boutons se
mesuraient à 30, 32, 33, 35, 36, 38 et 39 pixels selon l'écran.

> **Écart.** Les jetons actuels sont `--ctrl-xs: 26px`, `--ctrl-sm: 30px`, `--ctrl-md: 38px`
> en clair, et `28 / 32 / 44` en sombre — les deux thèmes ont divergé. L'échelle Propel les
> remplace, **dans les deux thèmes**, et met fin à cette divergence.

## 5. La typographie

Propel utilise **Inter**, avec des graisses à 350 / 450 / 500 / 600 / 700.

| Rôle | Taille | Interligne | Graisse |
|---|---|---|---|
| Titre d'écran | 20px | 1,2 | 600 |
| Titre de carte | 16px | 1,3 | 600 |
| Texte courant | 13px | 1,54 | 450 |
| Texte secondaire | 12px | 1,54 | 450 |
| Étiquette, en-tête de colonne | 12px | 1,2 | 500 |

**Décidé :** on garde la pile système (`-apple-system, "Segoe UI", system-ui…`). Sous
Windows, « Segoe UI » tient le rôle, et un poste d'atelier sans réseau affiche la bonne
police. **Les tailles s'écrivent en pixels**, jamais en `rem` : `html` est à 13px, donc
`1rem` y vaut 13px et toute l'échelle de Propel se décalerait d'un quart.

## 6. Les couleurs de sens

| Rôle | Clair | Sombre |
|---|---|---|
| Accent (liens, états actifs, anneau de focus) | `#006399` | `#2893CC` |
| Accent, fond | `#EBF8FF` | `#0A2533` |
| Succès | `#2F934B` / fond `#E4F6E9` | `#36AB57` / fond `#11361C` |
| Avertissement | `#BB4D00` / fond `#FEF3C6` | `#FE9A00` / fond `#501D01` |
| Danger | `#BF2C22` / fond `#FBEAE9` | `#DB3E33` / fond `#3C0E0B` |

**Règle.** Le plein ne sert qu'à un fond qui porte du texte blanc. Le fond sourd (`subtle`)
sert à teinter une zone. Jamais le plein pour du texte sur la page.

**Le bouton plein reste noir** (`#000000` en clair, `#F5F5F5` en sombre) : c'est le choix
déjà fait, et Propel ne le contredit pas — son accent sert aux liens et aux états, pas au
bouton d'action principal.

## 7. Le focus

Un anneau de **2px** à la couleur de l'accent, sur tout ce qui prend le focus. Ne jamais
retirer l'`outline` sans le remplacer : c'est un manquement direct au WCAG 2.4.7, et sur un
poste d'atelier on navigue au clavier plus souvent qu'on ne le croit.

## 7 bis. La barre latérale suit le composant List

Propel est une bibliothèque **React** : `@makeplane/propel`, imports ESM par sous-chemin,
Tailwind v4 via `@import "@makeplane/propel/styles"`. Elle ne publie **ni distribution CSS
ni bundle navigateur** — la charger ici supposerait React, ReactDOM, Tailwind v4 et un
bundler dans une application PHP/htmx. On reprend donc sa **spécification**, pas son code.

Ce que [List](https://propel.plane.so/components/list.md) impose, et qui est appliqué :

| Règle | Valeur |
|---|---|
| Hauteur de ligne | **32px**, fixe — la densité joue sur le corps du texte, pas sur la hauteur |
| Densité | « compacte » : libellé à 13px (« confortable » serait 14px) |
| Retrait par niveau | 8px — inutilisé ici, la barre n'a qu'un niveau |
| Titre de section | sourd, en casse normale — **jamais en capitales** |
| Compteur | une pastille en bout de ligne (`ListItemCounter`) |
| Clavier | **un seul arrêt de tabulation** pour toute la liste, les flèches déplacent |
| Écran ouvert | marqué par `aria-current="page"` |

La correspondance avec le balisage existant était déjà là : `.nav-rayon` est un
`ListSection`, `.nav-rayon-titre` son déclencheur de repli, `.nav-btn` un `ListItemButton`
avec `startIcon`, `label` et `count`. Il n'a manqué que la spec visuelle et le clavier,
ajouté dans `dashboard-sidebar.js`.

## 7 ter. Les tableaux suivent le composant Table

[Table](https://propel.plane.so/components/table.md) a trois variantes. La nôtre est
**`plain`** : « elle supprime le cadre et les fonds, et laisse les filets de ligne sur la
surface hôte ». C'est exactement la règle de la section 8 — un tableau n'est pas une carte.

Ce qui en est appliqué :

| Règle | Application |
|---|---|
| `align` : `start` par défaut, `end` pour les nombres, **l'en-tête suit sa colonne** | classe `.prefa-nombre` sur « Pouces » et « Temps atelier », en-tête comprise |
| L'en-tête collant peint `surface-1` | déjà le cas |
| `TableActionCell` : une cellule d'actions à déclencheur unique | approché — les commandes s'effacent au repos et reviennent au survol |
| Les lignes de section ne prennent **aucune teinte de survol** | la ligne d'état vide est exclue du survol |

**Le piège du survol.** La règle `tbody tr:hover > td` s'appliquait à la ligne « Aucune
demande trouvée » : survoler un tableau vide peignait un bandeau gris sur toute sa largeur.
Elle est exclue par `:not(:has(> .prefa-table-empty))`, dans les deux thèmes.

**Les commandes de ligne.** Trois libellés répétés à chaque ligne pèsent plus lourd que les
données. Elles s'effacent au repos sous `@media (hover: hover)` — la media query est
indispensable : sur une tablette d'atelier, il n'y a pas de survol, et les cacher les
rendrait inatteignables. `:focus-within` les rend au clavier.

## 7 quater. Aucune couleur en dur qu'un jeton nomme déjà

C'est la règle qui a manqué le plus longtemps, et elle se mesure.

Le thème clair portait **30 occurrences de `color: #6b7280 !important`**. Le jeton
`--text-muted` valait `#494D50`, soit **8,4:1** sur blanc ; ces trente règles le
court-circuitaient avec `#6B7280`, soit **4,83:1**. D'où l'impression, légitime, qu'« on a
du mal à voir les couleurs en clair » : un tiers du texte sourd de l'application était un
cran plus faible que l'autre, sans raison. Le chapeau d'un écran et l'en-tête de sa
première colonne, à vingt pixels l'un de l'autre, ne sortaient pas de la même valeur.

**Règle.** Une couleur qu'un jeton nomme ne s'écrit pas en dur. Corollaire : quand on
change un jeton, on vérifie qu'aucune règle ne le double — sinon le changement ne se voit
qu'à moitié, et c'est pire que de n'avoir rien changé.

Même mécanique pour les formes : le rayon des boutons était posé à `--r-sm` dans la règle
partagée de `prefa-form.css`, et aucune feuille d'écran ne pouvait le rattraper sans un
sélecteur plus fort. Corrigé à la source, donc partout.

## 8. Ce qui est une carte, et ce qui n'en est pas

C'est la règle qui manquait le plus, et celle qui a produit l'incohérence actuelle.

**Est une carte** — fond `bg-layer-1`, filet `border-subtle-1`, rayon 8 : une tuile de
l'accueil, une section de fiche, un volet de détail, un bloc de paramètres.

**N'est pas une carte** : un tableau, une barre d'outils, une barre de filtres, un en-tête
d'écran. Ces éléments vivent à même la surface et se structurent par leurs filets. Un
tableau posé dans une carte donne deux cadres concentriques et mange 32px de largeur pour
rien.

---

## Les écarts à combler

Par ordre de visibilité. Rien n'est fait tant que ce document n'est pas validé.

| # | Écart | État |
|---|---|---|
| 1 | Le cadre à `#FAFAFA` au lieu de `#F2F2F3` | **fait** |
| 3 | Les filets : trois niveaux, plus `--border-fort` pour le bord de la vue | **fait** |
| 4 | L'échelle des contrôles divergeait entre les thèmes | **fait** — six barreaux `--h-*`, communs |
| 5 | Les rayons : le 10px n'appartenait à aucune échelle | **fait** — `--r-lg` 8, `--r-xl` 12 |
| 2 | La liste des demandes | en cours — la pastille « Urgente » est unifiée |
| 6 | Les autres cartes (`.settings-card`, `.prefa-section`) en blanc codé en dur | à faire — mais les 30 gris sourds en dur sont repris |
| 7 | Les 14 autres écrans, un par un | à faire |

**Au passage :** la grande vue arrondie est descendue dans `dashboard-base.css` — elle
valait pour le seul thème clair, ce qui n'avait aucune raison d'être. Le thème sombre l'a
donc reçue aussi, avec le filet `--border-fort` et le rayon `--r-xl`.

## Les deux décisions, tranchées

**La police :** pile système, pas Inter. Rien à charger, rien à héberger, et aucune
dépendance réseau pour le serveur de l'atelier.

**L'échelle :** les tailles s'écrivent en pixels. `html` reste à 13px, et les `rem` de
Propel sont convertis à la main au moment de les écrire. Remettre `html` à 16px serait plus
propre sur le fond, mais toucherait d'un coup tout ce qui est déjà dimensionné en `rem`
dans les 14 286 lignes de CSS — c'est un chantier à part, pas un effet de bord.
