# La fiche de la production

Tout ce qu'il faut savoir du serveur pour agir sans deviner. Les valeurs viennent de
`docker-compose.yml`, du `Dockerfile` et de mesures faites le 28 octobre 2026 ; en cas de doute,
ces deux fichiers font foi.

## Le projet Compose {#projet}

Docker Compose nomme le projet d'après **le dossier du dépôt**, et tout en découle :

| Dossier du dépôt | Projet | Volume de la base | Conteneur web |
| --- | --- | --- | --- |
| `php-app` | `php-app` | `php-app_mysql_data` | `php-app-php-1` |
| `Idem-prefa` | `idem-prefa` | `idem-prefa_mysql_data` | `idem-prefa-php-1` |

::: danger Un autre dossier, c'est une autre base
Cloner le dépôt ailleurs, ou renommer le dossier, puis lancer `docker compose up` crée un
**nouveau volume, vide**. L'application démarre sans aucune table, et les vraies données dorment
dans l'ancien volume. Rien n'est perdu, mais tout semble l'être. Toujours travailler dans le
dossier existant ; `docker compose ls` et `docker volume ls` disent lequel c'est.
:::

Ajouter `name:` en tête de `docker-compose.yml` figerait le nom — mais sur un serveur dont le
dossier ne s'appelle pas `php-app`, ce serait justement basculer sur un volume vide. C'est une
décision humaine, prise en connaissant le nom en service.

## Les services

| Service | Image | Port publié | Rôle | Démarré en production |
| --- | --- | --- | --- | --- |
| `php` | `<projet>-php`, construite par le `Dockerfile` — PHP 8.3, Apache 2.4, Poppler 25.03, Tesseract 5.5 | **8080** → 80 | L'application | oui |
| `db` | `mysql:8.4` (8.4.11 mesurée) | aucun | La base `myapp` | oui |
| `phpmyadmin` | `phpmyadmin:latest` | **8081** → 80 | Une interface sur la base | oui — voir [Sécurité](/production/securite#phpmyadmin) |
| `docs` | `node:22-alpine` | 8082 | Cette documentation | **non** : profil `docs`, activé seulement par un `.env` |

Les ports sont publiés sur **toutes les interfaces** de la machine (`0.0.0.0` et `[::]`) : ce
que le pare-feu laisse passer est joignable depuis le réseau. La base, elle, n'est joignable
que depuis les autres conteneurs.

Aucun service n'a de **politique de redémarrage** ni de contrôle de santé Docker. Après un
redémarrage de la machine, rien ne repart seul — et sous Windows, Docker Desktop ne démarre
qu'à l'ouverture de la session. Voir [Surveillance](/production/surveillance#redemarrage).

## Où sont les données {#donnees}

| Quoi | Où | Sauvegardé par |
| --- | --- | --- |
| Les tables, et les documents rangés **en base** : QMOS, DMOS, bons de commande (`MEDIUMBLOB`, 16 Mio au plus), certificats matière (`LONGBLOB`) | Volume `<projet>_mysql_data`, monté sur `/var/lib/mysql` | Le dump |
| Les plans, les pièces jointes, les caches de lecture (`lignes-*.json`) | `src/dashboard/uploads/prefa/<id de la demande>/` | L'archive des fichiers |
| Les documents et photos des PV | `src/dashboard/uploads/pv/<id de la demande>/` | L'archive des fichiers |
| Un ancien dossier, plus écrit | `src/uploads/` | L'archive des fichiers |
| Les sessions | `/tmp` **dans** le conteneur web | Rien : elles tombent quand il est recréé |
| Le journal | La sortie du conteneur web, `docker compose logs php` | Rien : il disparaît avec le conteneur |
| Les sauvegardes | `../sauvegardes-idem/`, à côté du dépôt | — à copier hors de la machine |

`src/` est monté tel quel dans le conteneur (`./src:/var/www/html`) : les fichiers déposés
sont sur le disque de la machine, et `.gitignore` les écarte du dépôt (motif `uploads`).
Apache refuse de les servir (`Require all denied`) : ils ne sortent que par les actions
`download_*.php`, derrière la session.

## Les fichiers qui comptent

| Fichier | Rôle en production |
| --- | --- |
| `docker-compose.yml` | Les services, les ports, les identifiants de **création** de la base |
| `Dockerfile` | L'image web : extensions PHP, limites d'envoi, durée de session, refus d'Apache sur les dossiers d'envoi, Poppler et Tesseract |
| `src/my_setting.ini` | Les identifiants que l'application emploie **à chaque requête** pour joindre la base |
| `db/schema.sql` | Le schéma complet d'une base neuve, avec les rôles, les statuts et le paramétrage |
| `scripts/migrations/*.php` | Les évolutions du schéma pour une base en service, datées |
| `scripts/README.md` | La liste des migrations qui **touchent aux données** — à lire avant d'en lancer une |
| `scripts/init/add_user.php` | Créer un compte de l'application |
| `.env` | **Absent du serveur.** Sa seule présence est un signal de poste de développement |

`scripts/` est copié dans l'image au moment de la construction (`/scripts`), il n'est pas
monté : après un `git pull`, ce que voit le conteneur ne change qu'à la reconstruction. Les
essais, `tests/`, sont montés en lecture seule sur `/tests` — inutiles en production.

## Les variables d'environnement

| Variable | Défaut | En production |
| --- | --- | --- |
| `APP_ENV` | `prod` | `prod`, toujours. `dev` ouvre la prise de place sans mot de passe |
| `JOURNAL_COMPLET` | vide | Vide. `1` note aussi les fichiers statiques et le flux, le temps d'une recherche |
| `COMPOSE_PROFILES` | vide | Vide : la documentation ne démarre pas |

Elles ne sont lues qu'au démarrage du conteneur web : en changer une le recrée, et déconnecte
tout le monde.

## Les identifiants {#identifiants}

| Compte | Valeur | Défini dans | Lu par |
| --- | --- | --- | --- |
| MySQL, application | `myuser` / `mypassword` | `docker-compose.yml`, `src/my_setting.ini`, `terminal.json` | L'application, phpMyAdmin, les procédures |
| MySQL, administrateur | `root` / `rootpassword` | `docker-compose.yml` | phpMyAdmin, un humain |
| Les comptes de l'application | Hachés en bcrypt, colonne `Utilisateur.password` | — | La connexion |

::: warning Les valeurs de docker-compose.yml ne servent qu'une fois
`MYSQL_USER`, `MYSQL_PASSWORD` et `MYSQL_ROOT_PASSWORD` ne sont lus qu'à la **création** du
volume. Les changer ensuite dans le fichier ne change aucun mot de passe : la base garde les
anciens, et l'application garde ceux de `src/my_setting.ini`. Voir
[Sécurité](/production/securite#mots-de-passe).
:::

`myuser` a tous les droits sur la base `myapp`, y compris la supprimer et la recréer — c'est
ce qui permet la [restauration](/production/sauvegarde#restaurer) sans le compte `root`.

## Les limites

| Limite | Valeur | Où |
| --- | --- | --- |
| Un fichier envoyé | 50 Mo | `upload_max_filesize`, `Dockerfile` |
| Un envoi entier | 512 Mo, 1000 fichiers | `post_max_size`, `max_file_uploads` |
| Un document rangé en base (QMOS, DMOS, bon de commande) | 16 Mio | Le type `MEDIUMBLOB` |
| Une requête vers MySQL | 64 Mio | `max_allowed_packet`, défaut de MySQL 8.4 |
| Une session sans activité | 8 heures | `session.gc_maxlifetime=28800` |
| Les journaux binaires de MySQL | Gardés 30 jours | `binlog_expire_logs_seconds`, défaut de MySQL 8.4 |

Les journaux binaires sont actifs par défaut dans MySQL 8.4 : chaque document rangé en base y
est écrit une seconde fois, pendant 30 jours. Le volume grossit donc plus vite que la base.

## L'heure

| Où | Fuseau |
| --- | --- |
| Les écrans, le journal | Europe/Paris (`TZ` de l'image web) |
| PHP (`date.timezone`), MySQL | UTC |
| L'en-tête d'un dump (`Dump completed on …`) | UTC : il a une ou deux heures d'écart avec le nom du fichier |

## Ce qui n'existe pas

Pour ne pas le chercher :

- **aucune sauvegarde automatique** — voir [Sauvegarde](/production/sauvegarde#planifier) ;
- aucune table qui retienne **les migrations déjà passées** — voir
  [Mise à jour](/production/mise-a-jour#migrations) ;
- aucun **HTTPS** : l'application est servie en HTTP clair sur le port 8080 ;
- aucun **mode maintenance** : arrêter le conteneur web est la seule façon d'empêcher les
  écritures ;
- aucun **contrôle de santé** Docker, aucune politique de redémarrage ;
- aucune limite de taille sur le journal du conteneur.

## Ce dont les postes ont besoin

Le serveur tourne sans internet une fois l'image construite. Les **navigateurs**, non : chaque
page charge htmx (`cdn.jsdelivr.net`) et, à la connexion, axios
(`cdnjs.cloudflare.com`) ; le cadre où l'on trace sa signature charge signature_pad
(`cdn.jsdelivr.net`). Un poste qui n'atteint pas ces adresses affiche des écrans inertes.
Il faut aussi **Edge ou Chrome à jour** — voir [Prérequis](/guide/prerequis#le-navigateur).
