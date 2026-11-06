# Affaires

Une affaire est un **chantier**. Chaque demande lui est rattachée, et un chargé d'affaire la
suit. C'est le chargé de production qui ouvre les affaires et désigne qui les suit.

**Écran :** « Affaires », section « Affaires & demandes » — chargé de production. Le chargé
d'affaire a le même écran sous le nom **« Mes affaires »**, en lecture seule
([plus bas](#mes-affaires)).

## Ce qu'on y fait

- **Voir les affaires en cartes** : un monogramme, le nom, l'état, le chargé d'affaire, le
  nombre de demandes. Une recherche filtre les cartes.
- **L'état** se lit au point de couleur des demandes. Une affaire n'a pas de statut à elle :
  elle prend celui de ses demandes, dans cet ordre — **Validée** dès qu'une demande l'est (elle
  ne se renomme plus et ne se supprime plus), sinon **En attente** s'il en reste une à décider,
  sinon **Refusée**, et **Sans demande** tant qu'aucune n'est déposée.
- **Ouvrir une affaire** — « Nouvelle affaire » : un nom, et un chargé d'affaire facultatif.
  S'il n'existe encore aucun compte de chargé d'affaire, un bouton mène à « Utilisateurs », avec
  ce rôle déjà choisi.
- **Ouvrir la fiche d'une affaire** : un clic n'importe où sur la carte — ou sur son compteur,
  au clavier — ouvre sa fiche : le chargé d'affaire, les demandes comptées par statut, puis
  chaque demande. Un clic sur une demande ouvre la liste des demandes sur sa référence, sa
  fiche déjà dépliée ; « Voir dans la liste des demandes » ouvre la liste filtrée sur
  l'affaire. Une affaire sans demande le dit.
- **Renommer sur place** : le crayon, au survol de la carte, rend le nom modifiable ; une barre
  « Annuler / Enregistrer » apparaît après une modification (Entrée enregistre, Échap
  annule). Le chargé d'affaire ne se choisit sur la carte que tant qu'aucun n'est désigné :
  une fois enregistré, son nom s'affiche avec un cadenas.
- **Supprimer une affaire** — seulement si aucune demande n'y est rattachée ; la corbeille est
  grisée sinon.

## Mes affaires

Le chargé d'affaire y retrouve **toutes les affaires qui lui sont confiées**. Sans cet écran, il
ne les voyait que dans la liste du formulaire de dépôt et, une seule fois, sur l'accueil. La
tuile « Mes affaires » de l'accueil y mène.

- Les mêmes cartes, **sans rien de modifiable** : ni « Nouvelle affaire », ni renommage, ni
  corbeille, ni ligne « Chargé d'affaire » (sur toutes ses cartes, ce serait lui).
- Le compteur ne compte que **ses** demandes : une affaire reprise d'un collègue garde les
  demandes de celui-ci, et il ne les voit pas dans « Mes demandes ».
- **« Déposer une demande »**, au pied de chaque carte, ouvre le formulaire sur cette affaire.
  Une fois la demande enregistrée, la carte se met à jour.

## Les règles

| Règle | Détail |
| --- | --- |
| Nom unique | Sans tenir compte de la casse ni des accents : « BATIMENT A » et « Bâtiment a » sont le même nom. |
| Renommer | Renomme aussi le nom d'affaire de ses demandes. **Refusé (`409`) dès qu'une de ses demandes est validée** : son nom est sur ses plans et ses PV. La carte ne propose alors plus ni crayon ni corbeille. |
| Supprimer | **Définitif** — il n'existe pas d'état « clôturée ». Refusé (`409`) dès qu'une demande y est rattachée. |
| Confier | Quand une affaire reçoit son chargé d'affaire, il reçoit l'événement `affaire.confiee` : l'affaire s'annonce sur son accueil, avec « Déposer une demande ». |
| Chargé désigné | **Définitif** : une fois enregistré, il ne se change plus, ni pour un autre ni pour personne — refusé (`409`). Seule la suppression de son compte rend l'affaire de nouveau à confier. |
| Déposer | Un chargé d'affaire ne peut déposer une demande que dans une affaire qui lui est confiée. Sans affaire, le formulaire le dit au lieu de s'afficher. |

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/affaires.php`, `src/js/ecrans/affaires.js` | L'écran et sa fenêtre ; « Mes affaires » quand `$enLecture` |
| `src/dashboard/actions/affaires.php` | `ouvrir`, `modifier`, `fermer` — réservé au chargé de production |
| `src/modeles/Affaire.php` | Le modèle : unicité du nom, renommage des demandes, chargé désigné verrouillé, suppression. `Affaire::pour()` donne les affaires d'un chargé d'affaire |
| `tests/affaires.php` | Le verrou du chargé d'affaire désigné, dans une transaction annulée — inscrit dans `run test` |
