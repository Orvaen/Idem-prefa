# Rôles et droits

Quatre rôles, chacun avec son métier. Un compte a **un seul** rôle, choisi à sa création.

## Les trois noms d'un rôle

Les libellés de la base ne disent pas le métier : c'est la source de confusion la plus
fréquente. Le code, lui, parle toujours par l'énumération `Role` (`src/modeles/Role.php`).

| Métier | En base (`role.name`) | Dans le journal | `Role::` | id |
| --- | --- | --- | --- | --- |
| **Chargé de production** | Administrateur | `charge-production` | `ChargeDeProduction` | 1 |
| **Chef d'atelier** | Gestionnaire | `chef-atelier` | `ChefDAtelier` | 2 |
| **Personnel atelier** | Utilisateur | `personnel-atelier` | `PersonnelAtelier` | 3 |
| **Chargé d'affaire** | Demandeur | `charge-affaire` | `ChargeDAffaire` | 5 |

Il n'y a pas de rôle 4 : il a été supprimé par la migration `2026-10-07_roles`.

## Ce que chacun voit dans la barre latérale

| Rôle | Accueil | Affaires & demandes | Atelier | Administration |
| --- | --- | --- | --- | --- |
| **Chargé de production** | ✓ | Affaires · Valider les demandes · Demandes urgentes | Soudures · Plans / ISO & Affectations · Planning · Commande matériel · PV de préfabrication | Utilisateurs |
| **Chef d'atelier** | ✓ | Suivi des demandes | Soudures · Plans / ISO & Affectations · Planning · Commande matériel · PV de préfabrication | — |
| **Personnel atelier** | ✓ | Mes plans | Mes soudures & Suivi matériel | — |
| **Chargé d'affaire** | ✓ | Mes affaires · Mes demandes | PV de préfabrication | — |

Tout le monde a, en plus, les [paramètres](/fonctionnalites/interface#parametres) au
pied de la barre. La même entrée change de nom selon le rôle : l'écran des demandes s'appelle
« Valider les demandes » pour le chargé de production, « Suivi des demandes » pour le chef
d'atelier, « Mes demandes » pour le chargé d'affaire — et pour le personnel d'atelier, il
devient « Mes plans », un autre écran. De même, « Affaires » devient « Mes affaires » pour le
chargé d'affaire, en lecture seule.

Le menu est déclaré à un seul endroit, `src/dashboard/includes/menu.php` : pour chaque entrée,
la page qu'elle ouvre, les rôles qui la voient (`pour`), et les feuilles et scripts dont elle a
besoin.

## Qui peut faire quoi

Ce tableau décrit ce que le **serveur** accepte : l'interface cache les boutons, mais c'est
l'action qui refuse.

| Action | Chargé de production | Chef d'atelier | Personnel atelier | Chargé d'affaire |
| --- | :---: | :---: | :---: | :---: |
| Voir les affaires | ✓ toutes | | | ✓ les siennes |
| Ouvrir, renommer, supprimer une affaire | ✓ | | | |
| **Déposer** une demande | ✗ refusé | | | ✓ dans ses affaires |
| Modifier une demande | ✓ toutes | | | ✓ les siennes |
| Supprimer une demande | ✓ toutes | | | ✓ les siennes, en attente |
| **Valider ou refuser** une demande | ✓ | | | |
| Modifier le planning | ✓ | lecture | | |
| Ajouter des plans, les affecter | ✓ | ✓ | | |
| Déclarer l'avancement de ses plans | | | ✓ ses affectations | |
| Poser ou **valider** un avancement | ✓ | ✓ | | |
| Renseigner ses soudures | | | ✓ ses plans | |
| Suivre et valider les soudures | ✓ | ✓ | | |
| **Remplir** les PV | ✓ | ✓ | | |
| Consulter les PV | ✓ | ✓ | | ✓ les siens |
| Lire, valider, réceptionner une commande matériel | ✓ | ✓ | | |
| Signaler un manque de matériel | | | ✓ ses plans | |
| Traiter un manque | ✓ | ✓ | | |
| Gérer les comptes | ✓ | | | |
| Régler la cadence et les passivations | ✓ | | | |

## Ce que chacun voit des demandes

La règle tient dans `Demande::clauseVisible()` (`src/modeles/Demande.php`), et vaut pour la
liste, la fiche, les fichiers, l'accueil, l'API et le flux en temps réel :

| Rôle | Demandes visibles |
| --- | --- |
| Chargé de production | Toutes |
| Chef d'atelier | Les demandes validées — plus celles qu'il aurait déposées lui-même, un reste d'une version où il le pouvait |
| Chargé d'affaire | Les siennes — celles qu'il a déposées |
| Personnel atelier | Aucune par cette règle : elle ne lui ouvre que les demandes validées **qu'il a déposées**, et il n'en dépose pas |

Le personnel d'atelier n'entre donc pas par les demandes : **ses plans lui arrivent par ses
affectations** (« Mes plans », « Mes soudures »), qui ne lui ouvrent que les documents rattachés
à ces plans — pas le reste de la demande. Son journal « Aujourd'hui », sur l'accueil, reste vide
pour la même raison.

Une demande qu'on n'a pas le droit de voir répond « introuvable », et non « interdit » : un
refus confirmerait qu'elle existe.

## Dans le code

Les droits sont des méthodes de `Utilisateur` (`src/modeles/Utilisateur.php`), recopiées sous
leurs anciens noms par `src/dashboard/includes/prefa_context.php` pour les pages et les actions :

| Variable | Méthode | Qui |
| --- | --- | --- |
| `$isAdmin` | `est(Role::ChargeDeProduction)` | Chargé de production |
| `$canViewAllPrefa` | `voitToutesLesDemandes()` | Chargé de production, chef d'atelier |
| `$canManageWorkshop` | `gereLAtelier()` | Chargé de production, chef d'atelier |
| `$canFillPv` | `remplitLesPv()` | Chargé de production, chef d'atelier |
| `$canEditPrefa` | `deposeDesDemandes()` | Chargé de production, chargé d'affaire |
| `$canViewPv` | `consulteLesPv()` | Chargé de production, chef d'atelier, chargé d'affaire |

`$canEditPrefa` couvre le chargé de production pour **modifier** ; le dépôt d'une nouvelle
demande lui est refusé à part, dans `actions/create_prefa.php`.

## En développement

Avec `APP_ENV=dev`, le chargé de production peut prendre la place de n'importe quel compte,
sans son mot de passe, depuis le menu des comptes : c'est ainsi qu'on essaie les quatre profils.
Voir [Développement et production](/guide/modes).
