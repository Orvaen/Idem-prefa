# Sauvegarder et restaurer

Une sauvegarde complète, c'est **deux fichiers pris ensemble** : le dump de la base, et
l'archive des fichiers déposés. L'un sans l'autre ne restaure rien d'utilisable — des plans
sans demande, ou des demandes dont les plans manquent.

Les commandes de cette page ont été éprouvées le 28 octobre 2026 : sauvegarde, sortie vers
l'hôte, restauration dans un projet à part ; les 35 tables restaurées avaient le même
`CHECKSUM TABLE` que l'original. Elles suivent [les conventions d'écriture](/production/#commandes).

::: danger Ce que contient une sauvegarde
Les mots de passe hachés de tous les comptes, les certificats matière, les QMOS, les DMOS, les
bons de commande, les plans, les PV. Elle ne va **jamais** dans le dépôt, dans un ticket, dans
un message, ni vers un service en ligne sans l'accord d'un humain.
:::

## Où vont les sauvegardes

Dans **`../sauvegardes-idem/`** : le dossier voisin du dépôt, hors de la copie de travail. Hors
du dépôt, un `git add` ne peut pas l'emporter ; hors de `src/`, Apache ne peut pas le servir.

| Fichier | Contenu |
| --- | --- |
| `myapp-AAAAMMJJ-HHMM.sql` | La base entière, documents compris |
| `uploads-AAAAMMJJ-HHMM.tar.gz` | `dashboard/uploads/` et `uploads/`, chemins relatifs à `src/` |

Les deux portent **le même horodatage**, à l'heure de la machine. Un dossier sur la même
machine ne protège pas d'une panne de disque : les copier ailleurs est une
[décision à planifier](#planifier).

::: tip Pourquoi pas run dump
`run dump` écrit toujours dans le même `db/dump.sql` : la deuxième sauvegarde écrase la
première. Et il passe par une redirection de l'hôte, que PowerShell réencode.
:::

## Sauvegarder {#sauvegarder}

Libre, sans demander. **Dans cet ordre : la base d'abord.** Un fichier déposé l'est avant la
ligne qui le cite : une archive prise après le dump contient tout ce que le dump référence.

### 1. Préparer le dossier et l'horodatage

::: code-group

```sh [bash, Git Bash]
mkdir -p ../sauvegardes-idem
STAMP=$(date +%Y%m%d-%H%M)
```

```powershell [PowerShell]
New-Item -ItemType Directory -Force ../sauvegardes-idem | Out-Null
$STAMP = Get-Date -Format yyyyMMdd-HHmm
```

:::

### 2. La base

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'mysqldump --default-character-set=utf8mb4 --single-transaction --no-tablespaces --hex-blob -umyuser --result-file=/tmp/sauvegarde.sql myapp'
docker compose exec -T db sh -c 'ls -l /tmp/sauvegarde.sql && tail -n 1 /tmp/sauvegarde.sql'
```

**Attendu :** aucune sortie pour la première ; pour la seconde, la taille du fichier, puis
`-- Dump completed on 2026-10-28 15:01:14` — l'heure UTC. Sans cette dernière ligne, le dump
est tronqué : **s'arrêter**.

| Option | Pourquoi |
| --- | --- |
| `--single-transaction` | Une image cohérente, sans bloquer l'application — toutes les tables sont en InnoDB |
| `--no-tablespaces` | `myuser` n'a pas le privilège `PROCESS` ; sans elle, `mysqldump` échoue |
| `--hex-blob` | Les documents rangés en base deviennent du texte hexadécimal : rien ne peut les abîmer en route |
| `--result-file` | Le fichier s'écrit dans le conteneur, sans traverser le shell de l'hôte |

Il n'y a ni procédure stockée, ni déclencheur, ni événement planifié dans la base : les options
par défaut suffisent.

```sh
docker compose cp db:/tmp/sauvegarde.sql "../sauvegardes-idem/myapp-$STAMP.sql"
docker compose exec -T db rm /tmp/sauvegarde.sql
```

### 3. Les fichiers déposés

```sh
docker compose exec -T php sh -c 'cd /var/www/html && tar -czf /tmp/uploads.tar.gz $(ls -d uploads dashboard/uploads 2>/dev/null) && tar -tzf /tmp/uploads.tar.gz | wc -l'
docker compose cp php:/tmp/uploads.tar.gz "../sauvegardes-idem/uploads-$STAMP.tar.gz"
docker compose exec -T php rm /tmp/uploads.tar.gz
```

**Attendu :** le nombre d'entrées de l'archive. Il ne fait que croître d'une sauvegarde à
l'autre, sauf suppression de demandes.

### 4. Vérifier {#verifier}

::: code-group

```sh [bash, Git Bash]
ls -l ../sauvegardes-idem
tail -n 1 "../sauvegardes-idem/myapp-$STAMP.sql"
```

```powershell [PowerShell]
Get-ChildItem ../sauvegardes-idem
Get-Content "../sauvegardes-idem/myapp-$STAMP.sql" -Tail 1
```

:::

**Attendu :** les deux fichiers, de taille non nulle et comparable à la sauvegarde précédente ;
puis la ligne `-- Dump completed on …`. Noter les deux noms et leurs tailles dans le compte
rendu.

Sous Git Bash, ne pas lire l'archive par un chemin absolu `C:/…` : le `tar` de Git y voit un
nom de machine distante (`Cannot connect to C: resolve failed`). Un chemin relatif convient.

## Éprouver une sauvegarde {#eprouver}

Une sauvegarde qu'on n'a jamais restaurée n'est qu'un espoir. Celle-ci se vérifie dans un
**projet Compose à part**, `verif-restauration` : son propre conteneur, son propre volume, sans
port publié. La base en service n'est pas touchée. Libre, sans demander.

```sh
docker compose -p verif-restauration up -d db
docker compose -p verif-restauration exec -T -e MYSQL_PWD=mypassword db sh -c 'until mysqladmin -h127.0.0.1 -umyuser ping >/dev/null 2>&1; do sleep 1; done; echo prete'
docker compose -p verif-restauration cp "../sauvegardes-idem/myapp-$STAMP.sql" db:/tmp/restauration.sql
docker compose -p verif-restauration exec -T -e MYSQL_PWD=mypassword db sh -c 'mysql --default-character-set=utf8mb4 -umyuser myapp < /tmp/restauration.sql'
```

**Attendu :** `prete`, puis aucune sortie et un code `0` — 2,4 secondes pour une petite base.
Puis comparer avec la base en service :

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "CHECKSUM TABLE Utilisateur, demande_prefabrication, demande_ccpu_documents" myapp
docker compose -p verif-restauration exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "CHECKSUM TABLE Utilisateur, demande_prefabrication, demande_ccpu_documents" myapp
```

**Attendu :** les mêmes valeurs, si personne n'a rien saisi depuis la sauvegarde ; sinon des
valeurs proches, et le même nombre de tables. Puis retirer le projet à part, **par ses noms
exacts** :

```sh
docker rm -f verif-restauration-db-1
docker volume rm verif-restauration_mysql_data
docker network rm verif-restauration_default
docker volume ls
```

**Attendu :** chaque commande répète le nom qu'elle retire ; la dernière montre encore le volume
de la base en service.

::: danger Jamais docker compose down -v, même avec -p
Un `-p` oublié, et c'est le volume de la production qui part. Les trois noms exacts ne peuvent
désigner que le projet à part.
:::

## Restaurer {#restaurer}

**Sur la base en service : accord explicite d'un humain**, qui désigne la sauvegarde à
restaurer. Tout ce qui a été saisi depuis est perdu.

### Avant

1. Un créneau **hors des heures de travail** : l'application sera arrêtée.
2. Une **sauvegarde de sûreté** de l'état actuel, [complète](#sauvegarder) — si la restauration
   tourne mal, ou si l'on s'est trompé de fichier, c'est elle qui sauve.
3. La sauvegarde à restaurer est entière : sa dernière ligne est `-- Dump completed on …`, et
   son archive a le même horodatage.

Dans la suite, `X` est l'horodatage de la sauvegarde **à restaurer**.

### 1. Les fichiers

Le conteneur web tourne encore : l'archive s'y extrait, en `root`, ce qui évite tout souci de
droits sur un hôte Linux.

```sh
docker compose cp "../sauvegardes-idem/uploads-X.tar.gz" php:/tmp/restauration-uploads.tar.gz
docker compose exec -T php sh -c 'cd /var/www/html && tar -xzf /tmp/restauration-uploads.tar.gz && rm /tmp/restauration-uploads.tar.gz && chown -R www-data:www-data dashboard/uploads'
```

L'archive est étendue **par-dessus** les dossiers, sans les vider : un fichier déposé après la
sauvegarde reste là, orphelin et sans effet — aucune ligne de la base ne le cite, personne ne
peut l'ouvrir. Ne rien supprimer pour « faire propre ».

### 2. Arrêter l'application

```sh
docker compose stop php
```

Plus personne n'écrit. phpMyAdmin et la base tournent toujours.

### 3. La base

```sh
docker compose cp "../sauvegardes-idem/myapp-X.sql" db:/tmp/restauration.sql
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -e "DROP DATABASE myapp; CREATE DATABASE myapp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'mysql --default-character-set=utf8mb4 -umyuser myapp < /tmp/restauration.sql && rm /tmp/restauration.sql'
```

**Attendu :** aucune sortie, trois codes `0`. Vider puis recréer la base, au lieu de charger le
dump par-dessus, retire aussi les tables qu'une migration aurait ajoutées depuis.

::: warning Si le chargement échoue
La base est alors vide ou à moitié remplie. **Ne pas redémarrer l'application.** Rendre compte
avec la sortie exacte : la sauvegarde de sûreté permet de reprendre.
:::

### 4. Redémarrer et contrôler

```sh
docker compose start php
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "SELECT (SELECT COUNT(*) FROM Utilisateur), (SELECT COUNT(*) FROM demande_prefabrication)" myapp
```

**Attendu :** les nombres de comptes et de demandes de la sauvegarde restaurée. Puis le
[contrôle de santé](/production/surveillance#controle), et le compte rendu — avec les noms des
deux sauvegardes : celle qu'on a restaurée, et celle de sûreté.

## Planifier {#planifier}

**Aujourd'hui, rien n'est automatique** : une sauvegarde n'existe que si quelqu'un la prend. À
décider par un humain, et à écrire ici une fois décidé :

| Décision | Proposition |
| --- | --- |
| Quand | Chaque soir, hors des heures de travail, et avant toute mise à jour |
| Comment | Les étapes 1 à 4 ci-dessus, dans une tâche planifiée — Planificateur de tâches sous Windows, `cron` sous Linux |
| Combien en garder | Quatorze jours, plus celles qui précèdent une mise à jour |
| Où les copier | Hors de la machine, sur un support que l'humain choisit — jamais un service en ligne sans son accord |
| Qui vérifie | Une [épreuve](#eprouver) au moins par mois, notée dans un compte rendu |

Un agent ne supprime **jamais** une sauvegarde de lui-même, même ancienne : c'est peut-être la
seule bonne.
