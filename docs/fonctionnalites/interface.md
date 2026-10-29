# L'interface

Ce que tous les écrans partagent : la coquille, la navigation, les fenêtres et les messages.

## La coquille

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰ Menu  IDEM dev        [ 🔍 Rechercher un écran…   Ctrl K ]   Compte ⌄ 🎨 ⏻ │  ← la barre du haut
├───────────────┬──────────────────────────────────────────────────────────┤
│ Accueil       │                                                          │
│               │                                                          │
│ Affaires &    │                  l'écran ouvert                          │
│ demandes  ⌄   │                  (la seule surface qui flotte)           │
│ Atelier   ⌄   │                                                          │
│ Admin.    ⌄   │                                                          │
│               │                                                          │
│ ⚙ Paramètres  │                                                          │
└───────────────┴──────────────────────────────────────────────────────────┘
```

| Zone | Ce qu'elle porte |
| --- | --- |
| **La barre du haut** | Le bouton qui replie le menu, le logo — et le repère **dev** en développement —, la recherche d'écran, le compte, le thème et la déconnexion. Rien de tout cela ne dépend de l'écran ouvert. |
| **La barre latérale** | Les écrans que le rôle peut ouvrir, rangés en sections repliables : « Affaires & demandes », « Atelier », « Administration ». Au pied, **Paramètres**, pour tout le monde. |
| **La carte de travail** | L'écran ouvert. C'est la seule surface qui flotte ; tout le reste est posé sur le cadre. |

L'écran ouvert s'inscrit dans l'adresse, sous `?vue=` : un rechargement, un lien envoyé à un
collègue ou les boutons Précédent et Suivant du navigateur ramènent au bon écran. Une adresse
qui nomme un écran inconnu, ou interdit à ce rôle, ouvre l'accueil sans rien dire.

### La barre latérale

- **Repliable** avec le bouton « Menu » ; sous 1 080 px de large, elle passe par-dessus le
  contenu et se referme après un choix.
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
  n'importe quel compte sans son mot de passe.

## Les paramètres — Ctrl , {#parametres}

Une fenêtre, sur le modèle de celle de Claude, que chacun ouvre du pied de la barre latérale, du
menu du compte ou par `Ctrl ,` :

| Rubrique | Pour qui | Ce qu'on y règle |
| --- | --- | --- |
| **Général** | tout le monde | Le thème — système, clair ou sombre —, et la liste des raccourcis clavier |
| **Compte** | tout le monde | Son profil, en lecture : nom, identifiant, rôle, courriel. La déconnexion. |
| **Fabrication** | chargé de production | Le **temps par pouce ISO**, qui sert à estimer le délai d'une demande |
| **Passivation** | chargé de production | Les **types de passivation** proposés dans le formulaire de demande |

Un réglage s'applique **dès qu'on le touche** : il n'y a pas de bouton « Enregistrer ». Le temps
par pouce part quand on quitte le champ ; un refus du serveur s'écrit sous la ligne. La recherche,
en haut de la colonne, traverse toutes les rubriques.

## Les fenêtres

Toutes les boîtes de dialogue suivent une seule recette (`dialog.fenetre`) : un en-tête avec son
titre et sa croix, un corps, un pied avec « Annuler » encadré et l'action en bouton plein. Échap
les ferme ; le focus y reste enfermé tant qu'elles sont ouvertes.

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

L'interface s'adapte au doigt (`pointer: coarse`) : les cibles passent à 44 px, les commandes
qui n'apparaissent qu'au survol sur un poste restent visibles, et la barre latérale passe
par-dessus le contenu.
