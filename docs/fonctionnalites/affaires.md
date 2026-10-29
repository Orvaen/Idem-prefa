# Affaires

Une affaire est un **chantier**. Chaque demande lui est rattachée, et un chargé d'affaire la
suit. C'est le chargé de production qui ouvre les affaires et désigne qui les suit.

**Écran :** « Affaires », section « Affaires & demandes » — chargé de production. Le chargé
d'affaire a le même écran sous le nom **« Mes affaires »**, en lecture seule
([plus bas](#mes-affaires)).

## Ce qu'on y fait

- **Voir les affaires en cartes** : un monogramme, le nom, le chargé d'affaire, le nombre de
  demandes. Une recherche filtre les cartes.
- **Ouvrir une affaire** — « Nouvelle affaire » : un nom, et un chargé d'affaire facultatif.
  S'il n'existe encore aucun compte de chargé d'affaire, un bouton mène à « Utilisateurs », avec
  ce rôle déjà choisi.
- **Modifier sur place** le nom et le chargé d'affaire, directement sur la carte ; une barre
  « Enregistrer » apparaît après une modification.
- **Ouvrir les demandes d'une affaire** : le compteur « N demandes » ouvre la liste des demandes,
  filtrée sur elle.
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
| Renommer | Renomme aussi le nom d'affaire de ses demandes. |
| Supprimer | **Définitif** — il n'existe pas d'état « clôturée ». Refusé (`409`) dès qu'une demande y est rattachée. |
| Confier | Quand le chargé d'affaire change, le nouveau reçoit l'événement `affaire.confiee` : l'affaire s'annonce sur son accueil, avec « Déposer une demande ». |
| Déposer | Un chargé d'affaire ne peut déposer une demande que dans une affaire qui lui est confiée. Sans affaire, le formulaire le dit au lieu de s'afficher. |

## Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/affaires.php`, `src/js/ecrans/affaires.js` | L'écran et sa fenêtre ; « Mes affaires » quand `$enLecture` |
| `src/dashboard/actions/affaires.php` | `ouvrir`, `modifier`, `fermer` — réservé au chargé de production |
| `src/modeles/Affaire.php` | Le modèle : unicité du nom, renommage des demandes, suppression. `Affaire::pour()` donne les affaires d'un chargé d'affaire |
