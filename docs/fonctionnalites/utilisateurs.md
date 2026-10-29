# Utilisateurs et paramètres

## Utilisateurs

**Écran :** « Utilisateurs », section « Administration » — chargé de production seulement.

### Ce qu'on y fait

- **La liste des comptes**, par pages de dix, avec une recherche sur le nom, le prénom ou
  l'identifiant, des onglets par rôle avec leur compte, et un choix de densité.
- **Créer un compte** — « Nouvel utilisateur » : prénom, nom, courriel facultatif, rôle et mot de
  passe. L'identifiant se fabrique tout seul — initiale du nom, un point, le prénom, en
  minuscules : Marie Dupont devient `d.marie` —, et sa disponibilité se vérifie pendant la
  saisie.
- **Modifier** le prénom et le nom ; l'identifiant est recalculé, et refusé s'il est déjà pris.
- **Supprimer** un compte, après confirmation, ou **une sélection** de comptes : on les choisit
  comme dans l'explorateur de fichiers — un clic, `Ctrl` pour ajouter, `Maj` pour étendre, ou un
  lasso — et une barre « Supprimer » paraît en bas. Cent comptes au plus à la fois.

### Ce qu'on ne peut pas faire

| | Pourquoi |
| --- | --- |
| Changer le **rôle** ou le **mot de passe** d'un compte existant | Ce n'est pas prévu dans l'interface : supprimer et recréer, ou passer par la base. |
| Supprimer **son propre** compte | Refusé ; dans une suppression en lot, il est simplement laissé de côté. |
| Supprimer un compte qui a **déposé une demande** | Refusé : la demande perdrait son demandeur. |
| Supprimer un compte qui a **travaillé en atelier** | Refusé par la base : affectations, pointages et validations le citent. |

Un compte se crée aussi depuis le terminal, avec un mot de passe tiré au sort : voir
[Les commandes run](/guide/commandes#admin).

### Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/lists_users.php`, `src/api/users/get.php` | La liste, rendue par htmx |
| `src/dashboard/includes/vue/user_row.php` | Une ligne, en lecture ou en saisie |
| `src/dashboard/actions/create_users.php`, `edit_user.php`, `delete_user.php`, `check_identifiant.php` | Les écritures — chargé de production seulement |
| `src/api/User.php` | La fabrication de l'identifiant, le hachage du mot de passe (bcrypt) |
| `src/js/composants/liste-selection.js` | La sélection de lignes et la suppression en lot |

## Paramètres

La fenêtre des paramètres est décrite avec l'[interface](/fonctionnalites/interface#parametres).
Trois de ses rubriques règlent l'application pour tout le monde, et sont réservées au chargé de
production :

| Rubrique | Réglage | Effet |
| --- | --- | --- |
| **Fabrication** | **Temps par pouce ISO**, en jours | La durée estimée d'une demande = ses pouces × ce temps. Le serveur refuse une cadence qui ferait dépasser au planning 3 650 jours sur la plus grosse demande en attente. |
| **Passivation** | **Types de passivation** | La liste proposée dans le formulaire de demande. On en ajoute, on en renomme. |
| **Matériel** | **Unités** | La liste où le personnel d'atelier choisit l'unité d'un manque. On en ajoute, on en renomme sur la ligne (Entrée enregistre, Échap abandonne), on en supprime après confirmation. Deux unités ne peuvent pas porter le même nom, majuscules comprises. Les manques déjà signalés gardent leur unité. |

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/includes/vue/parametres.php` | La fenêtre, rendue avec le tableau de bord |
| `src/js/parametres.js`, `src/css/dashboard/settings.css` | Son comportement, son dessin |
| `src/dashboard/includes/materiel/unites.php`, `src/dashboard/actions/unite_materiel.php` | La liste des unités, et ses trois opérations |
| `src/dashboard/actions/save_prefa_settings.php` | Le temps par pouce ISO |
| `src/dashboard/actions/create_passivation.php`, `edit_passivation.php` | Les types de passivation |
