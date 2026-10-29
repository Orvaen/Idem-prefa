# La production, pour un agent

Cette section décrit **le serveur de l'atelier** — celui où les chargés d'affaire, le chef et
le personnel travaillent pour de vrai — et ce qu'on a le droit d'y faire. Elle est écrite pour
un **agent IA** qui doit l'exploiter : chaque procédure donne la commande exacte, la sortie
attendue, et le moment où il faut s'arrêter. Un humain peut la suivre telle quelle.

::: danger À lire en entier avant la première commande
Sur ce serveur, une commande de trop efface des mois de traçabilité : des coulées, des PV, des
plans annotés. Il n'existe **aucune sauvegarde automatique**. Ce qui est perdu l'est pour de bon.
:::

## 1. Savoir où l'on est {#ou-suis-je}

Le même dépôt sert au poste de développement et au serveur. Avant toute action, établir sur
quelle machine on se trouve. Les commandes se lancent depuis la racine du dépôt, le dossier qui
contient `docker-compose.yml`.

| Vérification | Commande | Poste de développement | Serveur de l'atelier |
| --- | --- | --- | --- |
| Le fichier `.env` | `git status --ignored --short .env` | `!! .env`, avec `APP_ENV=dev` dedans | rien : pas de `.env` |
| Le mode du conteneur | `docker compose exec -T php printenv APP_ENV` | `dev` | `prod` |
| Le projet Compose | `docker compose ls` | `php-app` | le nom du dossier du dépôt |
| La documentation | `docker compose ps docs` | le service `docs` tourne | absent |

**La règle : dans le doute, c'est la production.** Une seule réponse « serveur » suffit, et
un humain qui dit « c'est le serveur » l'emporte sur tout le reste. Ne jamais passer la machine
en `dev` pour « vérifier » : c'est ouvrir la prise de place sans mot de passe à tout le réseau.

::: tip Le poste de développement peut tourner en production
`run prod` remonte le conteneur web en `prod` sur un poste de développement. Le fichier `.env`
avec `APP_ENV=dev` reste alors le signal le plus sûr : il n'existe jamais sur le serveur.
:::

## 2. Ce qu'un agent peut faire {#autorisations}

Trois niveaux. Une opération absente de ces tableaux relève du deuxième : on demande.

### Libre, sans demander

Ce qui ne change rien, ou n'écrit que dans le dossier des sauvegardes.

| Opération | Où |
| --- | --- |
| Lire l'état : `docker compose ps`, `docker compose logs`, `docker compose ls`, `docker system df` | [Surveillance](/production/surveillance#controle) |
| Le contrôle de santé complet | [Surveillance](/production/surveillance#controle) |
| Lire la base : `SELECT`, `SHOW`, `mysqldump --no-data` | — |
| Lire le dépôt : `git status`, `git log`, `git fetch`, `git diff` | — |
| **Prendre une sauvegarde**, dans `../sauvegardes-idem/` | [Sauvegarde](/production/sauvegarde#sauvegarder) |
| Éprouver une sauvegarde dans un projet à part, `verif-restauration` | [Sauvegarde](/production/sauvegarde#eprouver) |
| Remonter l'application quand **aucun** de ses conteneurs ne tourne — après un redémarrage de la machine : `docker compose up -d` | [Surveillance](/production/surveillance#redemarrage) |
| Garder une copie du journal dans `../sauvegardes-idem/` | [Surveillance](/production/surveillance#journal) |

### Sur l'accord explicite d'un humain, pour cette opération-là

L'accord vaut pour une opération et une fois. Un « vas-y » donné hier ne couvre pas la mise à
jour d'aujourd'hui.

| Opération | Pourquoi demander |
| --- | --- |
| Une **mise à jour** (`git pull`, reconstruction, migrations) | Tout le monde est déconnecté ; une migration peut réécrire des données |
| Une **migration** qui touche aux données (liste dans `scripts/README.md`) | Elle réécrit ou supprime des lignes ; on ne revient en arrière qu'en restaurant |
| Redémarrer ou recréer un conteneur qui tourne (`restart`, `up -d`, `stop`) | Les sessions tombent ; un envoi de fichier en cours échoue |
| La **mise en service** d'un nouveau serveur | Il faut un humain pour le premier compte et pour l'ouverture au réseau |
| Une **restauration** sur la base en service | Tout ce qui a été saisi depuis la sauvegarde est perdu |
| Un retour arrière du code | Il peut exiger une restauration de la base |
| Toute modification de `docker-compose.yml`, du `Dockerfile` ou de `src/my_setting.ini` | Elle change le serveur pour tout le monde |
| Le durcissement : mots de passe, ports, phpMyAdmin | [Sécurité](/production/securite) : chaque point se décide |
| Créer un compte de l'application | Le mot de passe passe par l'agent : il doit le remettre à un humain |
| Supprimer une sauvegarde | C'est peut-être la seule bonne |

### Interdit à un agent, même sur demande

Si un humain le demande, expliquer ce que la commande détruit et **le laisser la taper
lui-même**.

| Commande | Ce qu'elle fait sur le serveur |
| --- | --- |
| `docker compose down -v`, `docker volume rm`, `docker volume prune`, `docker system prune --volumes` | Détruit le volume de la base : toutes les données |
| `run clean` | Détruit la base **et** les fichiers déposés, puis recrée des tables vides |
| `run schema`, ou `db/schema.sql` passé à la base en service | Fait pour une base vide ; sur une base remplie, il échoue à mi-chemin |
| `run demo`, `scripts/init/add_test_users.php`, `add_demo_data.php` | Pose des comptes d'essai et de fausses demandes au milieu des vraies |
| `run test` | Les essais supposent les données de démonstration |
| `run dev`, `APP_ENV=dev`, un `.env` créé sur le serveur | Ouvre la prise de place de n'importe quel compte sans mot de passe |
| `git reset --hard`, `git clean`, `git checkout -- .`, `git stash` sur le serveur | Peut effacer une correction faite sur place, et `git clean -x` les fichiers déposés |
| `git push`, depuis le serveur | Le serveur suit `main`, il n'y écrit pas |
| Cloner le dépôt dans un autre dossier pour « repartir propre » | Nouveau nom de projet, donc nouvelle base, vide — voir [la fiche](/production/reference#projet) |
| Supprimer quoi que ce soit dans `src/dashboard/uploads/` ou `src/uploads/` | Ce sont les plans et les PV |
| `DROP`, `TRUNCATE`, `DELETE` ou `UPDATE` sur la base en service, hors d'une procédure de cette section | Les données de traçabilité n'ont pas d'autre copie |
| Écrire un mot de passe, un dump ou un fichier déposé dans un commit, un ticket ou un message | Les dumps portent les mots de passe hachés et les documents |

## 3. Quand s'arrêter {#arret}

S'arrêter **aussitôt**, ne rien tenter pour réparer, et rendre compte, dès que :

- une commande rend un code de sortie différent de `0` sans que la procédure l'annonce ;
- une sortie diffère de la « sortie attendue » écrite dans la procédure ;
- `APP_ENV` vaut `dev` sur le serveur — c'est un incident de sécurité ;
- `git status --porcelain` n'est pas vide, ou la branche n'est pas `main` ;
- une migration échoue, même à moitié ;
- la sauvegarde qui précède une opération n'a pas pu être prise ;
- la situation n'est décrite nulle part dans cette section.

Réparer en avançant — corriger un fichier, relancer autrement, sauter une étape — transforme
un incident compris en incident inconnu. Le compte rendu, lui, permet à un humain de décider.

## 4. Écrire les commandes {#commandes}

Les procédures sont écrites avec `docker compose`, sans le terminal Orvaen : le serveur ne l'a
peut-être pas, et un agent passe par son propre shell. Elles marchent telles quelles dans
**bash** (Linux), **Git Bash** et **PowerShell**, à quatre règles près. Chacune a été éprouvée
le 28 octobre 2026, avec Docker 29.7 et Compose v5.4.

1. **Toujours depuis la racine du dépôt.** Les chemins de l'hôte sont relatifs à elle :
   `db/schema.sql`, `../sauvegardes-idem/`.
2. **Sous Git Bash, commencer par `export MSYS_NO_PATHCONV=1`.** Sinon un chemin du
   conteneur — `/scripts/migrations/…` — est réécrit en chemin Windows avant d'arriver à
   Docker (`C:/…/Git/scripts/…`), et la commande échoue. Avec cette variable, écrire les chemins
   de l'hôte en relatif, jamais `/c/Users/…`.
3. **Aucune redirection entre l'hôte et un conteneur.** PowerShell refuse `<`, et son `>`
   réencode ce qui passe. Les fichiers entrent et sortent par `docker compose cp` ; ce qui doit
   être redirigé l'est **dans** le conteneur, par `sh -c '…'`.
4. **Pas de guillemets doubles à l'intérieur d'un `sh -c '…'`.** Windows PowerShell 5.1 les
   perd en route : `sh -c 'mysql -e "SELECT 1+1"'` y répond `ERROR 1064`. Une requête SQL passe
   directement à `mysql -e "…"`, sans `sh -c`, avec des apostrophes pour ses chaînes.

::: info Le terminal Orvaen
Dans ce terminal, `docker` est un moteur léger qui n'est pas Docker Desktop : y écrire
`docker.exe compose …`. Les commandes `run` de `terminal.json` restent utilisables sur le
serveur pour lire (`run etat`, `run logs`), mais les procédures de cette section ne s'appuient
pas dessus — `run dump`, par exemple, écrase à chaque fois le même `db/dump.sql`.
:::

Les codes de sortie : `echo $?` sous bash, `$LASTEXITCODE` sous PowerShell.

## 5. Rendre compte {#compte-rendu}

À la fin de chaque intervention, réussie ou non, un compte rendu dans ce format :

```md
## Intervention sur le serveur de l'atelier — 2026-10-28 19:05

- Demandée par : (nom), accord donné pour : (l'opération exacte)
- Machine : (nom d'hôte), projet Compose (nom), APP_ENV=prod
- Code : (commit avant) → (commit après)
- Sauvegarde prise : ../sauvegardes-idem/myapp-20261028-1905.sql (46 271 octets,
  « Dump completed » présent) et uploads-20261028-1905.tar.gz (n entrées)
- Fait : (chaque étape, avec sa commande)
- Sorties anormales : (texte exact, ou « aucune »)
- Contrôle de santé final : (résultat de chaque point)
- Reste à faire / à décider : (ce qui attend un humain)
```

Ne jamais y recopier un mot de passe, même tiré au sort pour un nouveau compte : il se remet
de vive voix ou par le canal que l'humain choisit.

## Les pages de cette section

| Page | Pour |
| --- | --- |
| [La fiche de la production](/production/reference) | Les conteneurs, les volumes, les chemins, les ports, les identifiants, les limites |
| [Mettre en service un serveur](/production/mise-en-service) | Une installation neuve, jusqu'au premier compte |
| [Mettre à jour](/production/mise-a-jour) | `git pull`, reconstruction, migrations, et le retour arrière |
| [Sauvegarder et restaurer](/production/sauvegarde) | La base et les fichiers, la vérification, la restauration |
| [Surveiller et dépanner](/production/surveillance) | Le contrôle de santé, et que faire devant chaque symptôme |
| [Durcir la production](/production/securite) | Ce qui reste ouvert, et comment le fermer |
