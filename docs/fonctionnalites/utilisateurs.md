# Utilisateurs et paramètres

## Utilisateurs

**Écran :** « Utilisateurs », section « Administration » — chargé de production seulement.

### Ce qu'on y fait

- **La liste des comptes**, par pages de dix, avec une recherche sur le nom, le prénom ou
  l'identifiant, des onglets par rôle avec leur compte, et un choix de densité. Un compte tient
  sur une ligne ; sur tablette en portrait, la colonne « Créé le » s'efface pour laisser la place
  au nom, à l'identifiant et à l'état du mot de passe.
- **Créer un compte** — « Nouvel utilisateur » : prénom, nom, courriel facultatif, rôle. **Pas de
  mot de passe** : la personne choisit le sien à sa première connexion, avec un **code d'accès**
  que la création affiche une fois ; on lui transmet son identifiant et ce code
  ([le détail](#le-mot-de-passe)). Celui-ci se fabrique tout seul — initiale du nom, un point, le prénom, en
  minuscules : Marie Dupont devient `d.marie` —, et sa disponibilité se vérifie pendant la
  saisie.
- **Réinitialiser le mot de passe** d'un compte, depuis le menu de sa ligne, après confirmation —
  à la demande de la personne, ou de soi-même : l'ancien ne marche plus, un code d'accès
  s'affiche une fois, et avec lui elle en choisit un nouveau à sa prochaine connexion. La ligne
  passe à « Mot de passe à choisir ». Sur un compte qui attend déjà, l'entrée devient « Nouveau
  code d'accès » : un code perdu ou passé se remplace.
- **Réinitialiser la signature** d'un compte, depuis le même menu, après confirmation : la
  personne en trace une nouvelle et la valide ([le détail](#la-signature)).
- **Modifier** le prénom et le nom ; l'identifiant est recalculé, et refusé s'il est déjà pris.
- **Supprimer** un compte, après confirmation, ou **une sélection** de comptes : on les choisit
  comme dans l'explorateur de fichiers — un clic, `Ctrl` pour ajouter, `Maj` pour étendre, ou un
  lasso — et une barre « Supprimer » paraît en bas. Cent comptes au plus à la fois.

### Ce qu'on ne peut pas faire

| | Pourquoi |
| --- | --- |
| Changer le **rôle** d'un compte existant | Ce n'est pas prévu dans l'interface : supprimer et recréer, ou passer par la base. |
| Choisir le **mot de passe** de quelqu'un | Plus jamais : chacun choisit le sien. On le réinitialise, et l'on donne le code à la personne, qui en choisit un nouveau. |
| Tracer la **signature** de quelqu'un | Elle ne vaudrait pas visa : chacun trace la sienne. On la réinitialise, la personne en trace une nouvelle. |
| Supprimer **son propre** compte | Refusé ; dans une suppression en lot, il est simplement laissé de côté. |
| Supprimer un compte qui a **déposé une demande** | Refusé : la demande perdrait son demandeur. |
| Supprimer un compte qui a **travaillé en atelier** | Refusé par la base : affectations, pointages et validations le citent. |

Un compte se crée aussi depuis le terminal, avec un mot de passe tiré au sort : voir
[Les commandes run](/guide/commandes#admin).

### Le mot de passe

Il ne se tape qu'à l'écran de connexion : personne ne le change sur place, pas même le chargé de
production. Pour en changer, on en demande un nouveau ; le chargé de production réinitialise, et
l'on choisit le nouveau en se reconnectant, avec le code d'accès qu'il donne.

**Le code d'accès** — décidé le 6 novembre 2026. Un compte qui attendait son mot de passe se
prenait avec son seul identifiant : le premier qui le tapait choisissait le mot de passe, et
entrait. Choisir son mot de passe demande maintenant un code, que le chargé de production reçoit
en créant le compte ou en le réinitialisant :

| Le code | Détail |
| --- | --- |
| **Sa forme** | Huit caractères, lus par groupes de quatre — `K7P4-QX9M`. Ni I ni O, ni 0 ni 1 : on ne les confond pas en le dictant. À la connexion, majuscules, tiret et espaces sont indifférents. |
| **Montré une fois** | Dans une fenêtre, en grand, à la création ou à la réinitialisation — avec « Copier » quand le navigateur le permet (en HTTPS). Il n'est rangé que haché, à la place du mot de passe : personne ne peut le relire. |
| **Valable** | Une fois, et sept jours. Passé, la ligne du compte dit « Code expiré » ; « Nouveau code d'accès », dans son menu, en donne un autre, et l'ancien ne vaut plus. |
| **Un code faux** | Refusé, et noté au journal : une rafale sur le même compte s'y voit. |
| **Les comptes d'avant** | Ceux qui attendaient sans code portent « Code à donner » : ils ne se prennent plus avec leur seul identifiant. « Nouveau code d'accès » leur en donne un. |

| Moment | Ce qui se passe |
| --- | --- |
| **Première connexion** | L'écran de connexion ne demande d'abord que l'identifiant. « Continuer » : si le compte n'a pas encore de mot de passe, l'écran demande le **code reçu**, puis d'en choisir un — au moins 8 caractères, deux fois — et elle entre ; s'il en a un, son champ paraît (`auth/compte_a_creer.php`, qui répond pareil pour un compte inconnu). Une seule fois : un compte qui a déjà le sien ne se laisse plus choisir de mot de passe ainsi. |
| **Oublié** | « Mot de passe oublié ? », sur l'écran de connexion : elle donne son identifiant. L'écran répond la même chose que le compte existe ou non. |
| **À changer** | Connectée, dans Paramètres → Compte → Mot de passe : « Demander ». Rien ne change encore — l'ancien marche jusqu'à la réinitialisation —, et la ligne dit « Demandé le … ». |
| **La demande** | Dans les deux cas, chaque chargé de production est prévenu : une alerte s'il a l'application ouverte — « Mot de passe oublié » ou « Nouveau mot de passe demandé » —, une pastille sur « Utilisateurs », « Nouveau mot de passe demandé » sur la ligne du compte. Une seconde demande avant la réinitialisation ne le prévient pas une seconde fois. |
| **Réinitialisation** | Le chargé de production réinitialise depuis la ligne : le code s'affiche, il le donne à la personne, et le compte repasse à « Mot de passe à choisir ». Elle choisit le nouveau à sa connexion suivante, avec son identifiant et ce code, comme la première fois. Encore connectée, elle le lit dans ses paramètres, avec « Se reconnecter ». |
| **Le chargé de production** | Il réinitialise le sien dans Paramètres → Compte, après confirmation : son code s'affiche — à noter —, puis il est déconnecté, et choisit le nouveau en se reconnectant avec ce code. Sa propre ligne, dans « Utilisateurs », ne le propose pas. |

::: warning Le code vaut une clé
Qui a le code et l'identifiant choisit le mot de passe : donnez-le à la personne elle-même, de
vive voix ou par un canal à elle, pas sur un tableau d'affichage. Tant que la ligne du compte
porte « Mot de passe à choisir », personne n'est encore entré. Le journal note chaque code donné,
chaque code faux et chaque mot de passe choisi.
:::

### La signature

Elle vise les soudures qu'on valide ([le visa](/fonctionnalites/soudures#visa)) : sans elle,
pas de validation. Chacun la trace en grand, depuis Paramètres → Compte, et la valide **une
fois** ; qui valide des soudures et n'en a pas la trace dès son arrivée, avant tout le reste.
Ensuite, on ne la refait ni ne la supprime soi-même — une signature qu'on changerait à sa guise
ne vaudrait pas visa. Pour en changer, on le demande au chargé de production.

| Moment | Ce qui se passe |
| --- | --- |
| **Valider** | « Valider » demande confirmation : une fois validée, seul un administrateur pourra la changer. Le cadre vide le dit déjà avant qu'on trace. Validée, elle s'affiche « Validée le … », sans outils, avec « Pour la changer, demandez à un administrateur. » |
| **Réinitialisation** | Le chargé de production réinitialise depuis la ligne du compte, après confirmation. Un toast le confirme — la ligne n'en change pas d'aspect. La signature reste sur les soudures qu'elle a déjà visées. La personne ne valide plus de soudures jusqu'à la nouvelle : si elle en valide, la fenêtre de signature s'ouvre dès son arrivée suivante, et elle la trace et la valide à son tour. Le journal note chaque réinitialisation. |
| **Le menu de la ligne** | « Réinitialiser la signature » est éteint, avec sa raison, sur un compte qui n'en a pas et sur la sienne. |
| **Le chargé de production** | Il garde la main sur la sienne : il la refait ou la supprime dans Paramètres → Compte, sans confirmation de validation. |
| **Les signatures d'avant** | Celles tracées avant cette règle comptent comme validées. |

### Dans le code

| Fichier | Rôle |
| --- | --- |
| `src/dashboard/pages/lists_users.php`, `src/api/users/get.php` | La liste, rendue par htmx |
| `src/dashboard/includes/vue/user_row.php` | Une ligne, en lecture ou en saisie |
| `src/dashboard/actions/create_users.php`, `edit_user.php`, `delete_user.php`, `check_identifiant.php`, `reinitialiser_mot_de_passe.php`, `reinitialiser_signature.php` | Les écritures — chargé de production seulement |
| `signatureModifiable()`, `signatureColonneCompte()` dans `src/dashboard/includes/atelier/signature.php` | Qui peut changer sa signature ; la date de la signature en cours, que la ligne d'un compte lit pour proposer de la réinitialiser |
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
