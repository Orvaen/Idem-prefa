# Consignes pour les agents IA

IDEM Préfabrication : PHP 8.3 + Apache, MySQL 8.4, htmx, servis par Docker Compose. Le même
dépôt tourne sur les postes de développement **et sur le serveur de l'atelier**, où les
données sont réelles et n'ont pas de sauvegarde automatique.

## Avant toute commande : où suis-je ?

| Signal | Poste de développement | Serveur de l'atelier |
| --- | --- | --- |
| `git status --ignored --short .env` | `!! .env` (il contient `APP_ENV=dev`) | rien |
| `docker compose exec -T php printenv APP_ENV` | `dev` | `prod` |

**Dans le doute, c'est la production.** Ce qu'on y fait, et comment : `docs/production/`,
à lire en entier avant d'y toucher — d'abord `docs/production/index.md`.

## Interdit sur le serveur, même sur demande

Expliquer ce que la commande détruit, et laisser l'humain la taper lui-même.

- `docker compose down -v`, `docker volume rm`/`prune`, `docker system prune --volumes` :
  la base entière.
- `run clean` : la base **et** les fichiers déposés.
- `run schema`, `run demo`, `run test`, `run dev`, un `.env` ou `APP_ENV=dev`.
- `git reset --hard`, `git clean`, `git checkout -- .`, `git stash`, `git push`.
- Cloner le dépôt dans un autre dossier : autre projet Compose, donc base vide.
- Supprimer quoi que ce soit dans `src/dashboard/uploads/` ou `src/uploads/`.
- `DROP`, `TRUNCATE`, `DELETE`, `UPDATE` sur la base hors d'une procédure de
  `docs/production/`.
- Recopier un mot de passe, un dump ou un document dans un commit, un ticket, un message.

## Sur l'accord d'un humain, pour cette opération-là

Mise à jour, migration, redémarrage d'un conteneur qui tourne, restauration, retour arrière,
modification de `docker-compose.yml`, du `Dockerfile` ou de `src/my_setting.ini`, création d'un
compte, suppression d'une sauvegarde, tout durcissement.

Libre : lire (`ps`, `logs`, `SELECT`, `git status`/`log`/`fetch`/`diff`), le contrôle de santé,
**prendre une sauvegarde** dans `../sauvegardes-idem/`.

## Écrire les commandes

- Depuis la racine du dépôt ; chemins de l'hôte en relatif.
- Git Bash : `export MSYS_NO_PATHCONV=1` d'abord, sinon `/scripts/…` est réécrit en chemin Windows.
- Aucune redirection `<` `>` entre l'hôte et un conteneur : `docker compose cp`, et les
  redirections **dans** le conteneur, par `sh -c '…'`.
- Pas de guillemets doubles dans un `sh -c '…'` : PowerShell 5.1 les perd.
- Terminal Orvaen : `docker.exe`, pas `docker`.

## S'arrêter et rendre compte

Dès qu'un code de sortie ou une sortie diffère de ce que la procédure annonce : ne pas réparer
en avançant. Le format du compte rendu est dans `docs/production/index.md`.

## Sur un poste de développement

`README.md` et la documentation (`docs/`, servie sur http://localhost:8082 par
`COMPOSE_PROFILES=docs`). Les conventions de code : `docs/technique/conventions.md` ; la
direction artistique : `docs/direction-artistique.md`. La lecture des documents (OCR) reste
locale — Poppler et Tesseract dans l'image —, jamais un service ou un modèle en ligne.
