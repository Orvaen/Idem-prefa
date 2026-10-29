# L'accueil

L'écran d'arrivée, pour tout le monde. Il dit ce qui attend, et ce qui a bougé aujourd'hui. Il
n'écrit rien.

## Les tuiles

Des chiffres propres au métier de chacun. Une tuile s'ouvre d'un clic sur l'écran où l'on agit
sur ce qu'elle compte ; elle se teinte d'azur quand son compte dépasse zéro — c'est le travail du
jour, pas une anomalie.

| Rôle | Tuiles |
| --- | --- |
| **Chargé de production** | À valider · Urgentes · PV à renseigner · Affaires |
| **Chef d'atelier** | Avancements à valider · Sans plan · En atelier · PV à renseigner |
| **Personnel atelier** | Mes plans · À terminer · Mes soudures |
| **Chargé d'affaire** | Mes affaires · En attente · Validées · Refusées |

## Les nouvelles affaires

Quand le chargé de production confie une affaire à un chargé d'affaire, celle-ci s'annonce sur
son accueil, avec un bouton **« Déposer une demande »**. La barre latérale porte une pastille
« +N » sur l'entrée Accueil tant qu'il ne les a pas vues ; les afficher vaut lecture.

## Aujourd'hui

Le journal du jour : les huit derniers dépôts, validations, refus et PV renseignés. Il suit la
même règle de visibilité que la liste des demandes — chacun n'y voit que les demandes qu'il a le
droit de voir.

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/accueil.php` | L'écran |
| `src/dashboard/includes/demande/accueil.php` | Les tuiles et le journal du jour |
| `src/dashboard/includes/demande/affaires_nouvelles.php` | Les affaires confiées pas encore vues |

L'accueil est la seule entrée marquée `defaut` dans `menu.php` : une adresse qui nomme un écran
inconnu y retombe.
