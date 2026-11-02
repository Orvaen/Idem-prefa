# Utilisateurs et paramètres

## Utilisateurs

**Écran :** « Utilisateurs », section « Administration » — chargé de production seulement.

### Ce qu'on y fait

- **La liste des comptes**, par pages de dix, avec une recherche sur le nom, le prénom ou
  l'identifiant, des onglets par rôle avec leur compte, et un choix de densité.
- **Créer un compte** — « Nouvel utilisateur » : prénom, nom, courriel facultatif, rôle. **Pas de
  mot de passe** : la personne choisit le sien à sa première connexion ; on ne lui transmet que
  son identifiant. Celui-ci se fabrique tout seul — initiale du nom, un point, le prénom, en
  minuscules : Marie Dupont devient `d.marie` —, et sa disponibilité se vérifie pendant la
  saisie.
- **Réinitialiser le mot de passe** d'un compte, depuis le menu de sa ligne, après confirmation —
  à la demande de la personne, ou de soi-même : l'ancien ne marche plus, et elle en choisit un
  nouveau à sa prochaine connexion.
- **Modifier** le prénom et le nom ; l'identifiant est recalculé, et refusé s'il est déjà pris.
- **Supprimer** un compte, après confirmation, ou **une sélection** de comptes : on les choisit
  comme dans l'explorateur de fichiers — un clic, `Ctrl` pour ajouter, `Maj` pour étendre, ou un
  lasso — et une barre « Supprimer » paraît en bas. Cent comptes au plus à la fois.

### Ce qu'on ne peut pas faire

| | Pourquoi |
| --- | --- |
| Changer le **rôle** d'un compte existant | Ce n'est pas prévu dans l'interface : supprimer et recréer, ou passer par la base. |
| Choisir le **mot de passe** de quelqu'un | Plus jamais : chacun choisit le sien. On le réinitialise, la personne en choisit un nouveau. |
| Supprimer **son propre** compte | Refusé ; dans une suppression en lot, il est simplement laissé de côté. |
| Supprimer un compte qui a **déposé une demande** | Refusé : la demande perdrait son demandeur. |
| Supprimer un compte qui a **travaillé en atelier** | Refusé par la base : affectations, pointages et validations le citent. |

Un compte se crée aussi depuis le terminal, avec un mot de passe tiré au sort : voir
[Les commandes run](/guide/commandes#admin).

### Le mot de passe

Il ne se tape qu'à l'écran de connexion : personne ne le change sur place, pas même le chargé de
production. Pour en changer, on en demande un nouveau ; le chargé de production réinitialise, et
l'on choisit le nouveau en se reconnectant.

| Moment | Ce qui se passe |
| --- | --- |
| **Première connexion** | La personne tape son identifiant, sans mot de passe : l'écran lui demande d'en choisir un — au moins 8 caractères, deux fois — puis elle entre. Une seule fois : un compte qui a déjà le sien ne se laisse plus choisir de mot de passe ainsi. |
| **Oublié** | « Mot de passe oublié ? », sur l'écran de connexion : elle donne son identifiant. L'écran répond la même chose que le compte existe ou non. |
| **À changer** | Connectée, dans Paramètres → Compte → Mot de passe : « Demander ». Rien ne change encore — l'ancien marche jusqu'à la réinitialisation —, et la ligne dit « Demandé le … ». |
| **La demande** | Dans les deux cas, chaque chargé de production est prévenu : une alerte s'il a l'application ouverte — « Mot de passe oublié » ou « Nouveau mot de passe demandé » —, une pastille sur « Utilisateurs », « Nouveau mot de passe demandé » sur la ligne du compte. Une seconde demande avant la réinitialisation ne le prévient pas une seconde fois. |
| **Réinitialisation** | Le chargé de production réinitialise depuis la ligne : le compte repasse à « Mot de passe à choisir », et la personne choisit le nouveau à sa connexion suivante, avec son seul identifiant, comme la première fois. Encore connectée, elle le lit dans ses paramètres, avec « Se reconnecter ». |
| **Le chargé de production** | Il réinitialise le sien dans Paramètres → Compte, après confirmation : il est déconnecté aussitôt, et choisit le nouveau en se reconnectant. Sa propre ligne, dans « Utilisateurs », ne le propose pas. |

::: warning Entre la création — ou la réinitialisation — et la première connexion
Le compte n'a pas de mot de passe : qui connaît l'identifiant peut choisir le sien à la place de
son titulaire. Prévenez la personne tout de suite, et regardez la ligne du compte : tant qu'elle
porte « Mot de passe à choisir », personne n'est encore entré. Le journal note chaque mot de
passe choisi.
:::

### Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/lists_users.php`, `src/api/users/get.php` | La liste, rendue par htmx |
| `src/dashboard/includes/vue/user_row.php` | Une ligne, en lecture ou en saisie |
| `src/dashboard/actions/create_users.php`, `edit_user.php`, `delete_user.php`, `check_identifiant.php`, `reinitialiser_mot_de_passe.php` | Les écritures — chargé de production seulement |
| `src/api/User.php` | La fabrication de l'identifiant, le mot de passe : le hacher (bcrypt), le choisir, le réinitialiser, en demander un nouveau — et prévenir les chargés de production |
| `src/auth/creer_mot_de_passe.php`, `mot_de_passe_oublie.php` | Le choix du mot de passe et l'oubli, depuis l'écran de connexion |
| `src/dashboard/actions/mon_mot_de_passe.php`, `includes/vue/mot_de_passe.php` | Le sien, depuis les paramètres : en demander un nouveau — ou, pour le chargé de production, le réinitialiser — et la ligne qui dit où en est la demande |
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
| `src/js/coquille/parametres.js`, `src/css/dashboard/settings.css` | Son comportement, son dessin |
| `src/dashboard/includes/materiel/unites.php`, `src/dashboard/actions/unite_materiel.php` | La liste des unités, et ses trois opérations |
| `src/dashboard/actions/save_prefa_settings.php` | Le temps par pouce ISO |
| `src/dashboard/actions/create_passivation.php`, `edit_passivation.php` | Les types de passivation |
