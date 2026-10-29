# Architecture

Une application PHP classique, sans framework ni étape de compilation, servie par Apache dans un
conteneur Docker, à côté d'une base MySQL.

## Les conteneurs

```
                ┌─────────────────────── docker compose ───────────────────────┐
  navigateur ──▶│ php  (php:8.3-apache + Poppler + Tesseract)   :8080 → :80    │
                │   ./src  monté sur /var/www/html  (le code, servi en direct)   │
                │   ./tests monté sur /tests, en lecture seule                   │
                │        │                                                       │
                │        ▼                                                       │
                │ db   (mysql:8.4)   volume mysql_data     — non exposé          │
                │        ▲                                                       │
  navigateur ──▶│ phpmyadmin                                    :8081 → :80    │
  navigateur ──▶│ docs (node:22-alpine, VitePress, profil docs) :8082          │
                └────────────────────────────────────────────────────────────────┘
```

| Service | Image | Rôle |
| --- | --- | --- |
| `php` | construite par le `Dockerfile` | Apache et PHP 8.3, avec `mysqli`, `pdo_mysql`, Poppler et Tesseract |
| `db` | `mysql:8.4` | La base `myapp`, dans le volume `mysql_data` |
| `phpmyadmin` | `phpmyadmin:latest` | L'administration de la base |
| `docs` | `node:22-alpine` | Cette documentation — profil `docs`, postes de développement seulement |

Ce que le `Dockerfile` règle en plus de l'image de base :

| Réglage | Valeur | Pourquoi |
| --- | --- | --- |
| `upload_max_filesize`, `post_max_size`, `max_file_uploads` | 50M, 512M, 1000 | Les cahiers de plans |
| `session.gc_maxlifetime` | 28800 (8 h) | Une session tient la journée de travail |
| Accès à `uploads/` | refusé par Apache | Les fichiers ne sortent que par les actions qui vérifient les droits |
| `FallbackResource` sous `/api` | `/api/index.php` | Les adresses de l'API ne sont pas des fichiers |
| Journal | format propre, `log_errors=On`, `display_errors=Off`, `auto_prepend_file=journal.php` | Voir [Le journal](/technique/journal) |
| `TZ` | `Europe/Paris` | L'heure du journal ; PHP garde UTC pour la base |

## L'arborescence

```
.
├── src/                      la racine web (/var/www/html)
│   ├── index.php             l'écran de connexion
│   ├── auth/                 l'action de connexion, la déconnexion, la prise de place (dev)
│   ├── dashboard/
│   │   ├── dashboard.php     la coquille : barre du haut, menu, l'écran demandé
│   │   ├── pages/            les écrans, chargés dans la coquille
│   │   ├── partials/         des fragments partagés par plusieurs écrans
│   │   ├── actions/          les écritures et les téléchargements — répondent en JSON
│   │   ├── includes/         la logique : demande/, atelier/, materiel/, lecture/, vue/…
│   │   ├── outils/           des scripts en ligne de commande
│   │   └── uploads/          les fichiers déposés — jamais servis directement
│   ├── api/                  l'API JSON, et le flux en temps réel
│   ├── modeles/              le métier : Session, Utilisateur, Role, Demande, Affaire…
│   ├── js/                   un script par écran, et le socle
│   ├── css/                  une feuille par écran, et le socle
│   ├── assets/               logos et polices
│   ├── db.php                MyPDO, la connexion à la base
│   ├── my_setting.ini        les identifiants de connexion à la base
│   └── journal.php           le journal, chargé avant chaque script
├── db/schema.sql             le schéma complet d'une installation neuve
├── scripts/                  init/, migrations/, et les outils de poste
├── tests/                    les essais automatiques
├── docker/                   le format du journal d'Apache
├── docs/                     cette documentation
├── Dockerfile, docker-compose.yml
└── terminal.json             les commandes run
```

## Le chargement d'un écran

```
  GET /dashboard/dashboard.php?vue=planning
        │
        ▼
  dashboard.php ── la session ? non ──▶ redirection vers / (l'écran de connexion)
        │ oui
        ▼
  menu.php : dashboardVueDemandee() ── un écran inconnu ou interdit ──▶ l'accueil
        │
        ▼
  la coquille est rendue, l'écran inclus dans #content, avec seulement
  les feuilles et les scripts des écrans de ce rôle
        │
        ▼  un clic dans la barre latérale
  dashboard-navigation.js : $('#content').load(page) puis history.pushState(?vue=…)
```

1. **`includes/menu.php` déclare chaque entrée** : son identifiant, sa section, la page qu'elle
   ouvre — éventuellement avec un filtre —, les rôles qui la voient, ses feuilles et ses
   scripts, et laquelle est l'écran par défaut.
2. **`dashboard.php` rend l'écran demandé par l'adresse** (`?vue=`) dès le premier affichage : il
   n'y a pas d'accueil qui clignote avant l'écran voulu.
3. **Seules les ressources du rôle** sont envoyées : les feuilles portent `?v=` + la date du
   fichier, les scripts `?v=` + son condensat SHA-256.
4. **La navigation recharge `#content`**, marque l'entrée active et inscrit l'écran dans
   l'adresse ; Précédent et Suivant rejouent l'adresse sans empiler d'entrée. Une réponse `401`
   affiche « Votre session a expiré ».

## Les écritures : les actions

Chaque écriture passe par un script de `src/dashboard/actions/` : il inclut
`includes/prefa_context.php`, vérifie la méthode et le jeton (`prefaPost()`), vérifie le droit,
écrit, et répond en JSON — `{"success": true, "message": …}`, ou `{"success": false, "message":
…}` avec le code HTTP de l'échec. Les téléchargements sont des `GET` du même dossier. Le patron
complet est dans [Conventions de code](/technique/conventions#une-action).

Quelques actions de l'écran Utilisateurs répondent en HTML : la liste est rendue par htmx.

## Les modèles

| Classe | Rôle |
| --- | --- |
| `Session` | La seule à toucher `$_SESSION` : l'identifiant, le jeton CSRF, les comptes mémorisés |
| `Utilisateur` | L'identité, relue en base à chaque requête, et les droits nommés (`gereLAtelier()`…) |
| `Role` | L'énumération des quatre rôles, et leurs trois noms |
| `Demande` | La lecture, la décision, la suppression — et `clauseVisible()`, la règle de visibilité |
| `FiltreDemandes` | Les filtres et la pagination de la liste |
| `Affaire` | Ouvrir, modifier, fermer un chantier |
| `Evenement` | Les types d'événements, et `noter()` |
| `Environnement` | Le mode : `APP_ENV` |

Ils se chargent seuls (`src/modeles/autoload.php`).

## Le navigateur

| Bibliothèque | Version | D'où | Pour |
| --- | --- | --- | --- |
| htmx | 2.0.11 | `cdn.jsdelivr.net`, avec SRI | Les fragments de l'écran Utilisateurs, `hx-confirm` |
| axios | 1.20.0 | `cdnjs.cloudflare.com`, avec SRI | L'écran de connexion seulement |
| signature_pad | 5.1.4 | `cdn.jsdelivr.net`, avec SRI, chargé à l'ouverture du cadre | Le cadre où chacun trace sa signature (Paramètres → Compte) |

Tout le reste est écrit à la main, sans bibliothèque. Les polices — Inter et Source Serif 4,
sous licence OFL — sont servies localement, depuis `src/assets/fonts/`.

## Plus loin

- [Base de données](/technique/base-de-donnees)
- [L'API](/technique/api)
- [Sessions et sécurité](/technique/securite)
- [Le flux en temps réel](/technique/temps-reel)
