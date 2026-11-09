# L'interface

Ce que tous les écrans partagent : la coquille, la navigation, les fenêtres et les messages.

## La coquille

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  IDEM dev                                                       🔍  (R) │  ← la barre du haut
├────┬─────────────────────────────────────────────────────────────────────┤
│ ⌂  │                                                                     │
│────│                                                                     │
│ ▤  │                       l'écran ouvert                                │
│ ✓² │                       (la seule surface qui flotte)                 │
│────│                                                                     │
│ 〰 │                                                                     │
│ ▦  │                                                                     │
│ ⚙  │                                                                     │
└────┴─────────────────────────────────────────────────────────────────────┘
```

Réduite le 6 novembre 2026 à la demande du client : une barre du haut d'une ligne, et le menu
replié en rail d'icônes.

| Zone | Ce qu'elle porte |
| --- | --- |
| **La barre du haut** | Le bouton du menu, le logo — et le repère **dev** en développement —, puis à droite la loupe de la recherche d'écran (`Ctrl K`) et l'initiale du compte. Le nom et le rôle sont dans l'infobulle de l'initiale ; le **thème**, les **paramètres** et la **déconnexion** sont dans le menu du compte. |
| **La barre latérale** | Les écrans que le rôle peut ouvrir. Repliée — par défaut —, un rail d'icônes de 56 px : le libellé paraît en bulle au survol, les compteurs en pastille sur l'icône, et un filet sépare les sections. Dépliée, les sections repliables : « Affaires & demandes », « Atelier », « Administration ». Au pied, **Paramètres**, pour tout le monde. |
| **La carte de travail** | L'écran ouvert. C'est la seule surface qui flotte ; tout le reste est posé sur le cadre. |

L'écran ouvert s'inscrit dans l'adresse, sous `?vue=` : un rechargement, un lien envoyé à un
collègue ou les boutons Précédent et Suivant du navigateur ramènent au bon écran. Une adresse
qui nomme un écran inconnu, ou interdit à ce rôle, ouvre l'accueil sans rien dire.

**Précédent** — le bouton du navigateur, ou celui d'Android dans l'application :

- rouvre l'écran d'avant **tel qu'on l'avait** : la liste des demandes d'une affaire revient
  avec sa recherche ;
- rouvrir l'écran où l'on est déjà n'ajoute pas d'entrée : Précédent ne revient jamais sur
  place ;
- avec une fenêtre, un volet ou le tiroir du menu ouvert, il **le ferme** et reste sur
  l'écran — par le même chemin qu'Échap, donc avec la confirmation d'une saisie en cours ;
- l'écran de connexion ne reste pas dans l'historique : depuis le premier écran, Précédent
  quitte l'application au lieu d'y ramener.

**Le clavier d'une tablette** prend la moitié de l'écran ; la page se réduit à l'autre moitié,
dans l'application Android comme dans Chrome (`interactive-widget=resizes-content`). Tant
qu'il est ouvert (`js/composants/clavier.js`, au doigt seulement) :

- la barre du haut se retire, et le cadre perd sa marge ;
- les fenêtres et les volets montent contre le haut et prennent toute la hauteur qui reste ;
- le champ qu'on remplit revient au milieu de ce qu'on voit ;
- sur l'écran de connexion, le logo se retire : les champs et le bouton restent au-dessus du
  clavier.

Dans Chrome, la page devine le clavier à la hauteur visible, comparée à celle de l'écran dans
la même orientation. Dans l'application Android, c'est l'application qui le lui dit
(`clavier:natif`, envoyé par `MainActivity`) : la barre de navigation qui revient avec le
clavier faussait le calcul, et une fenêtre comme « Ajouter un plan » passait dessous.

### La barre latérale

- **Repliable** avec le bouton du menu : sur grand écran, entre le rail d'icônes et le menu
  complet — le choix se retient sur le poste ; sous 1 080 px de large, elle passe par-dessus
  le contenu et se referme après un choix.
- **Redimensionnable** à la souris : la poignée est le bord gauche de la carte, entre 150 et
  600 px de large. Elle est masquée sous 1 100 px.
- **Au clavier** : un seul arrêt de tabulation, puis les flèches haut et bas pour aller d'une
  entrée à l'autre.
- Des **pastilles** comptent ce qui attend : les demandes urgentes en attente, les PV à
  renseigner, les manques de matériel signalés, les nouvelles affaires confiées. Les deux
  dernières s'allument en direct, par le [flux d'événements](/technique/temps-reel).

## La palette — Ctrl K {#palette}

`Ctrl K`, ou un clic sur « Rechercher un écran… », ouvre la palette : on tape quelques lettres
d'un écran, sans accents ni majuscules, et Entrée l'ouvre. Elle lit les entrées de la barre
latérale ; elle ne propose donc que ce que le rôle peut ouvrir.

## Le menu des comptes

Le bouton du compte, en haut à droite, ouvre le menu des comptes :

- **Plusieurs comptes sur le même appareil.** « Ajouter un compte » en connecte un second, puis
  on passe de l'un à l'autre d'un clic, sans ressaisir de mot de passe — un poste d'atelier
  partagé entre un chef et ses soudeurs.
- **Gérer les comptes** révèle le retrait d'un compte de cet appareil ; son mot de passe sera
  redemandé pour y revenir.
- **Paramètres**, et **Se déconnecter**, qui ferme la session de tous les comptes de l'appareil.
- En développement seulement, une section **Développement** permet de prendre la place de
  n'importe quel compte sans son mot de passe. Elle ne liste que ceux qui ne sont pas encore
  dans « Comptes » : chacun ne paraît qu'une fois, et quand tous y sont, la section disparaît.

## Les paramètres — Ctrl , {#parametres}

Une fenêtre, sur le modèle de celle de Claude, que chacun ouvre du pied de la barre latérale, du
menu du compte ou par `Ctrl ,` :

| Rubrique | Pour qui | Ce qu'on y règle |
| --- | --- | --- |
| **Général** | tout le monde | Le thème — système, clair ou sombre —, et la liste des raccourcis clavier |
| **Compte** | tout le monde | Son profil, en lecture : nom, identifiant, rôle, courriel. Sa **signature**, qui vise les soudures qu'on valide ([le visa](/fonctionnalites/soudures#visa)) : on la trace et on la valide, une fois — pour la changer, on demande au chargé de production, qui la réinitialise ([le détail](/fonctionnalites/utilisateurs#la-signature)) ; lui refait ou supprime la sienne. Son **mot de passe**, qui ne se change pas sur place : on en demande un nouveau au chargé de production, et on le choisit en se reconnectant, avec le code d’accès qu’il donne — lui réinitialise le sien ([le détail](/fonctionnalites/utilisateurs#le-mot-de-passe)). La déconnexion. |
| **Fabrication** | chargé de production | Le **temps par pouce ISO**, qui sert à estimer le délai d'une demande |
| **Passivation** | chargé de production | Les **types de passivation** proposés dans le formulaire de demande |
| **Matériel** | chargé de production | Les **unités** proposées quand on signale un manque de matériel |

Un réglage s'applique **dès qu'on le touche** : il n'y a pas de bouton « Enregistrer » — sauf
pour la signature, qu'on trace en grand dans sa fenêtre à elle et qu'on valide une fois tracée. Le temps par pouce part quand on
quitte le champ ; un refus du serveur s'écrit sous la ligne. La recherche, en haut de la colonne,
traverse toutes les rubriques.

Sur un téléphone, la fenêtre prend tout l'écran et se lit comme les réglages du téléphone : la
liste des rubriques d'abord, puis la rubrique touchée, avec une flèche en haut à gauche — ou le
bouton retour d'Android — pour revenir à la liste.

## Les fenêtres

Toutes les boîtes de dialogue suivent une seule recette (`dialog.fenetre`) : un en-tête avec son
titre et sa croix, un corps, un pied avec « Annuler » encadré et l'action en bouton plein. Échap
les ferme ; le focus y reste enfermé tant qu'elles sont ouvertes. Sur tablette, elles
s'élargissent : champs de 54 px, intitulés au-dessus, cases à cocher sur des lignes de 52 px,
boutons de 50 px.

## Le lecteur PDF

Tout PDF de l'application s'ouvre dans **son lecteur à elle**, jamais dans celui du navigateur :
le serveur rend chaque page en image, à l'adresse même du document — qui vérifie le droit de le
lire (`?pages`, `?page=N`, `?vignette=N`, `includes/lecture/pdf_pages.php`). Le lecteur du
navigateur, dans une iframe, est banni : d'autres outils que les nôtres, et rien du tout dans
l'application Android. Un document que le serveur ne sait pas rendre le dit, et propose de le
télécharger.

- Les pages l'une sous l'autre, un zoom « − », « + », « Ajuster » (Ctrl + molette au poste) ;
- le sommaire, une vignette par page — une colonne au poste ; sur tablette, un volet posé sur
  les pages, replié au départ, que « Pages » ouvre et qu'une vignette touchée referme ;
- sur tablette, la page à la largeur de l'écran, une pastille « 3 / 15 » en bas, et des
  commandes de 36 px, comme celles des écrans — sans libellés en portrait.

## Les questions et les alertes

L'application ne se sert jamais des boîtes du navigateur (`alert`, `confirm`, `prompt`). Ses
questions s'ouvrent dans sa propre fenêtre :

- **une pastille d'intention** au-dessus du titre : **rouge** quand on ne pourra pas revenir en
  arrière — une suppression —, **ambre** pour une confirmation qui engage, **azur** pour une
  information ;
- **« Annuler » vient d'abord et prend le focus** : Entrée ne supprime rien par mégarde ;
- **Échap annule**, et un clic à côté ne ferme rien ;
- le bouton dit ce qu'il fait — « Supprimer », « Retirer », « Valider » —, jamais « OK » pour
  une question ;
- sur un téléphone, la fenêtre se pose en bas de l'écran, boutons empilés.

Quand il faut choisir parmi plusieurs documents — « Quel bon lire ? » —, la fenêtre montre la
liste, et un clic en prend un.

## Les messages — les toasts

Ce que l'application répond après une action — « Affaire modifiée. », « Demande supprimée. » —
paraît en bas à droite, quelques secondes, puis s'efface. Un refus reste plus longtemps et se lit
en rouge. Un clic le chasse. Dans une fenêtre, en revanche, un refus s'écrit **à côté du champ
à corriger**, pas dans un toast.

## Les listes et les dates

- **Les listes de choix** — affaire, matière, chargé d'affaire, motif… — sont celles de
  l'application, pas les menus du système : on tape pour chercher, on choisit au clavier ou à la
  souris, et « Autre… » ouvre un champ libre quand la liste le permet.
- **Les champs de date** gardent la saisie au clavier `jj/mm/aaaa` du navigateur, mais leur
  calendrier est celui de l'application. `Alt ↓` l'ouvre, les flèches s'y déplacent, `Échap` le
  referme.

## Le thème

Clair, sombre, ou celui du système — qui suit alors le système quand il change, à la tombée de
la nuit par exemple. Le choix se fait dans les paramètres ou dans le menu du thème de la barre
du haut ; il est **retenu par le navigateur** : chacun choisit le sien, sur chaque poste.

## Les raccourcis

| Touche | Effet |
| --- | --- |
| `Ctrl K` | Rechercher un écran |
| `Ctrl ,` | Ouvrir les paramètres |
| `Alt ↓` | Ouvrir le calendrier d'un champ date |
| `Échap` | Fermer la fenêtre, le menu ou le volet ouvert |
| `↑` `↓` | Parcourir la barre latérale, une liste, les rubriques des paramètres |

## Sur une tablette d'atelier

L'interface s'adapte au doigt (`pointer: coarse`) : les commandes qui n'apparaissent qu'au survol
sur un poste restent visibles, et la barre latérale passe par-dessus le contenu.

Les commandes d'un écran ont toutes la même hauteur, **36 px** (`--h-doigt`,
`dashboard-base.css`) : les boutons de l'en-tête (« Actualiser », « Signaler un manque »…), la
recherche et les filtres, les boutons d'une ligne (« Étapes »), la barre du lecteur de plan et
celle du lecteur PDF. Elles faisaient 44 px, jugés « trop gros » le 9 novembre 2026. Ce qu'on
touche comme une rangée — un onglet, une entrée de menu, une entrée de la barre latérale — garde
44 px ; les fenêtres gardent leurs propres mesures, plus grandes ([les fenêtres](#les-fenetres)).

Le haut des listes tient sur une ligne en paysage — le titre, la recherche, les outils — et sur
deux en portrait : le titre à gauche et les outils à droite, puis la recherche. La phrase sous
le titre se retire dans les deux cas.

La fenêtre du plan — « Mes plans » du soudeur, Plans / ISO — laisse la place au dessin : sa barre
tient sur une ligne, sans la consigne ni la légende. En portrait, « Sommaire », « Liste des
soudures », « Télécharger le PDF », « Imprimer » et « Signaler un manque » passent en icônes,
« Page » se retire devant « 3 / 15 », et le nom du plan prend la place qui reste ;
« Enregistrer » garde son mot. Le sommaire du cahier part replié ; « Sommaire » l'ouvre en
volet sur le bord gauche du dessin, et une feuille touchée le referme. Le plan se manie comme
une carte : un doigt le déplace dans tous les sens, deux doigts le zooment en pinçant, un
toucher simple reste un clic (une soudure, une case).
