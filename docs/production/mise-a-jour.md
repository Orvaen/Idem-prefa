# Mettre à jour

Faire passer le serveur de l'atelier sur une version plus récente de `main`, **sans vider la
base** : récupérer le code, reconstruire l'image, appliquer les migrations nouvelles. Et, si
quelque chose casse, revenir en arrière.

Lire d'abord [les règles](/production/). Une mise à jour demande **l'accord d'un humain**, pour
cette mise à jour-là.

## Avant : les conditions {#conditions}

Toutes doivent être réunies ; une seule manque, on ne commence pas.

| Condition | Vérifier | Attendu |
| --- | --- | --- |
| Un humain a donné son accord, et un créneau | — | Hors des heures de travail : tout le monde sera déconnecté |
| C'est bien le serveur | [Où suis-je](/production/#ou-suis-je) | `APP_ENV=prod`, pas de `.env` |
| Personne ne travaille | `docker compose logs --since 15m php` | Aucune ligne portant un compte (`identifiant/rôle`) |
| Le dépôt est propre | `git status --porcelain` | Rien |
| On est sur `main` | `git rev-parse --abbrev-ref HEAD` | `main` |
| L'application va bien avant d'y toucher | [Contrôle de santé](/production/surveillance#controle) | Tous les points passent |

Un dépôt qui n'est pas propre, c'est une modification faite sur le serveur, à la main : elle
peut être une correction urgente que personne n'a reportée dans `main`. **S'arrêter** et la
montrer (`git diff`), sans la toucher.

## 1. Noter d'où l'on part

```sh
git rev-parse HEAD
```

Garder cette empreinte : c'est **`ANCIEN`**, le point de retour. Elle va dans le compte rendu.

## 2. Sauvegarder

La [sauvegarde complète](/production/sauvegarde#sauvegarder) — la base **et** les fichiers —,
avec sa vérification. Sans sauvegarde vérifiée, pas de mise à jour.

## 3. Lire ce qui arrive

```sh
git fetch origin
git log --oneline HEAD..origin/main
git diff --stat HEAD origin/main
git diff --name-only --diff-filter=A HEAD origin/main -- scripts/migrations/
```

La dernière commande donne **les migrations nouvelles**. Puis regarder de près ce qui touche
au serveur :

| Si le changement touche… | Alors |
| --- | --- |
| Rien | Déjà à jour : s'arrêter là, sans rien recréer |
| `scripts/migrations/` | Lire chaque migration nouvelle et sa ligne dans `scripts/README.md`. Une migration **qui touche aux données** demande un accord explicite, nommément |
| `db/schema.sql`, **sans** migration nouvelle | **S'arrêter** : le code attend une forme de base que rien n'amènera sur le serveur |
| `docker-compose.yml` | Lire le diff. Un changement de volume, de nom de projet, de port ou d'identifiants : **s'arrêter** et demander |
| `Dockerfile` | Normal : la reconstruction de l'étape 5 le prend en compte |
| `src/my_setting.ini` | **S'arrêter** : les identifiants de la base changeraient pour l'application |

Le résumé de ce qui arrive va dans le compte rendu.

## 4. Récupérer le code

```sh
git pull --ff-only origin main
```

**Attendu :** `Fast-forward`, puis la liste des fichiers. Un refus (`Not possible to
fast-forward`) veut dire que le serveur a des commits que `main` n'a pas : **s'arrêter**. Jamais
de fusion, de rebase ni de force sur le serveur.

## 5. Reconstruire et redémarrer

**À partir d'ici, tout le monde est déconnecté.**

```sh
docker compose up -d --build
```

Toujours reconstruire, même si le `Dockerfile` n'a pas bougé : `scripts/`, donc les
migrations, n'arrivent dans le conteneur que par l'image. Les couches lourdes (Poppler,
Tesseract) sont en cache : une à deux minutes.

```sh
docker compose ps
docker compose exec -T php printenv APP_ENV
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'until mysqladmin -h127.0.0.1 -umyuser ping >/dev/null 2>&1; do sleep 1; done; echo prete'
```

**Attendu :** les trois services `Up`, `prod`, `prete`.

Si la construction échoue, ou si un conteneur ne démarre pas : rien n'a encore touché la base.
Passer au [retour arrière du code seul](#retour-code).

## 6. Appliquer les migrations nouvelles {#migrations}

Aucune table ne retient les migrations déjà passées. **Ne passer que celles de l'étape 3**,
une par une, dans l'ordre du nom — la date en tête les range :

```sh
docker compose exec -T php ls /scripts/migrations
docker compose exec -T php php /scripts/migrations/2026-10-28_affaires_vues.php
```

La première commande confirme que l'image porte bien les nouveaux fichiers. Sous Git Bash,
`MSYS_NO_PATHCONV=1` doit être posé.

**Attendu**, pour chacune : un code de sortie `0` et quelques lignes qui disent ce qui a été
fait — « Colonne … ajoutée », ou « … déjà là » si elle était passée. Une migration qui échoue
écrit sur la sortie d'erreur (« Migration annulée : … ») et rend `1` ou `255`.

::: danger Une migration échoue : s'arrêter là
Ne pas lancer la suivante, ne pas relancer celle-ci autrement, ne rien corriger dans la base.
Recopier sa sortie exacte dans le compte rendu, et demander : c'est un humain qui choisit entre
corriger en avançant et [revenir en arrière avec la base](#retour-base).
:::

::: warning Ne jamais relancer toutes les migrations « pour être sûr »
Elles sont relançables, mais pas toutes sans effet : `2026-10-13_evenements` supprime à chaque
passage les événements de plus de sept jours, `2026-10-14_plan_numeros_de_ligne` relit des
plans pendant plusieurs secondes chacun. La liste de celles qui touchent aux données est dans
`scripts/README.md`.
:::

## 7. Vérifier

1. Le [contrôle de santé](/production/surveillance#controle), en entier.
2. [Comparer la base au schéma](/production/surveillance#schema) : seules les différences
   attendues doivent rester. Une colonne de `db/schema.sql` absente de la base, c'est une
   migration qui manque.
3. Le journal depuis le redémarrage :

   ```sh
   docker compose logs --since 10m php
   ```

   **Attendu :** la ligne `** … app  démarrage en mode prod`, et aucune ligne `!!`.

Puis [rendre compte](/production/#compte-rendu), avec `ANCIEN` et le nouveau commit
(`git rev-parse HEAD`).

## Revenir en arrière {#retour}

Deux cas, selon qu'une migration a tourné ou non. **Dans les deux, l'accord d'un humain est
nécessaire.**

### Aucune migration n'a tourné : le code seul {#retour-code}

La base n'a pas changé de forme : il suffit de remettre l'ancien code.

```sh
git switch --detach ANCIEN
docker compose up -d --build
```

Puis le contrôle de santé. Le serveur reste sur une **tête détachée** : c'est voulu. La
mise à jour suivante commencera par une décision humaine (`git switch main`), au lieu de
retenter sans le savoir la version qui a cassé.

### Une migration a tourné : le code et la base {#retour-base}

L'ancien code ne connaît pas forcément la nouvelle forme de la base, et une migration qui
réécrit des données ne se défait pas. Revenir, c'est **restaurer la sauvegarde de l'étape 2** :

1. Prévenir l'humain : tout ce qui a été saisi depuis cette sauvegarde est perdu. Dans un
   créneau hors des heures de travail, ce devrait être rien.
2. `git switch --detach ANCIEN`
3. `docker compose up -d --build`
4. [Restaurer](/production/sauvegarde#restaurer) la base **et** les fichiers de l'étape 2 —
   une migration comme `2026-10-27_pv_refonte` déplace aussi des fichiers.
5. Le contrôle de santé, puis le compte rendu.
