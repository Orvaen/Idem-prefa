# Mettre en service un serveur

Une installation **neuve**, sur une machine où l'application n'a jamais tourné, jusqu'au
premier compte. Pour un serveur qui tourne déjà, c'est une [mise à jour](/production/mise-a-jour) ;
pour rebâtir un serveur perdu à partir d'une sauvegarde, cette page puis
[la restauration](/production/sauvegarde#restaurer).

Lire d'abord [les règles](/production/) : les commandes suivent ses conventions d'écriture.

## Ce qu'il faut avant de commencer

**D'un humain :**

- l'accord pour cette mise en service, sur cette machine ;
- le **nom et le prénom du premier chargé de production** — le seul compte créé ici, qui créera
  les autres depuis l'application ;
- sa décision sur l'ouverture au réseau : qui doit atteindre le port 8080, et que faire de
  phpMyAdmin (étape 9).

**Sur la machine :**

| Quoi | Vérifier | Attendu |
| --- | --- | --- |
| Docker, avec Compose v2 ou plus | `docker compose version` | `Docker Compose version v2…` ou plus — éprouvé en v5.4 |
| Le moteur démarré | `docker info` | Pas d'erreur de connexion |
| Git | `git --version` | Une version |
| L'accès au dépôt | `git ls-remote https://github.com/rayan-studio/Idem-prefa.git main` | Une ligne `<empreinte> refs/heads/main` |
| Les ports | `docker ps` ne publie rien sur 8080 ni 8081, et aucun autre serveur ne les occupe | — |
| Le disque | Environ **3 Go** pour les images, plus la base et les fichiers | — |
| Internet, pour cette fois | La construction télécharge les images et les paquets de Poppler et Tesseract | — |

Si `git ls-remote` demande un identifiant, **s'arrêter** : les accès au dépôt se règlent avec
un humain, et aucun jeton ne s'écrit dans une adresse ou un fichier.

## 1. S'assurer que la machine est vierge

```sh
docker volume ls
```

**Attendu :** aucun volume dont le nom finit par `_mysql_data`.

Un tel volume, c'est une base qui existe déjà — peut-être la vraie. **S'arrêter** et le
signaler : il s'agit alors d'une mise à jour, ou d'une reprise, pas d'une mise en service.

## 2. Récupérer le code, dans un dossier nommé php-app

```sh
git clone https://github.com/rayan-studio/Idem-prefa.git php-app
cd php-app
git rev-parse --abbrev-ref HEAD
```

**Attendu :** `main`.

Le nom du dossier devient celui du projet Compose, donc celui du volume de la base
(`php-app_mysql_data`) et des conteneurs (`php-app-php-1`). `php-app` est le nom qu'emploie
toute cette documentation. Voir [la fiche](/production/reference#projet).

## 3. Ne pas créer de .env

```sh
git status --ignored --short .env
```

**Attendu :** rien. Sans `.env`, l'application démarre en production, et la documentation ne
démarre pas. Ne jamais copier `.env.examples` vers `.env` sur un serveur : il porte
`APP_ENV=dev`.

## 4. Construire et démarrer

```sh
docker compose up -d --build
```

**Attendu**, après quelques minutes la première fois : trois conteneurs démarrés —
`php-app-db-1`, `php-app-php-1`, `php-app-phpmyadmin-1`. Pas de `php-app-docs-1`.

```sh
docker compose ps
docker compose exec -T php printenv APP_ENV
```

**Attendu :** les trois services `Up`, puis `prod`. Toute autre valeur : **s'arrêter**.

## 5. Attendre la base

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'until mysqladmin -h127.0.0.1 -umyuser ping >/dev/null 2>&1; do sleep 1; done; echo prete'
```

**Attendu :** `prete`, en une quinzaine de secondes au premier démarrage.

::: details Pourquoi -h127.0.0.1
Au premier démarrage d'un volume, l'image MySQL lance un **serveur provisoire**, sans réseau,
crée la base et le compte, puis l'arrête et lance le vrai. Pendant environ deux secondes, une
connexion par la socket locale réussit déjà… juste avant que ce serveur ne s'éteigne : un
schéma chargé à ce moment-là est coupé en route. Le serveur provisoire n'écoute pas en TCP
(`port: 0` dans `docker compose logs db`) : passer par `127.0.0.1` attend le vrai.
:::

## 6. Vérifier que la base est vide

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'myapp'"
```

**Attendu :** `0`. Tout autre nombre : **s'arrêter** — `db/schema.sql` ne s'applique qu'à une
base vide.

## 7. Créer les tables

```sh
docker compose cp db/schema.sql db:/tmp/schema.sql
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'mysql --default-character-set=utf8mb4 -umyuser myapp < /tmp/schema.sql && rm /tmp/schema.sql'
```

**Attendu :** aucune sortie, code de sortie `0`. Puis vérifier :

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'myapp'"
docker compose exec -T -e MYSQL_PWD=mypassword db mysql -umyuser -N -e "SELECT id, name FROM role ORDER BY id" myapp
```

**Attendu :** le nombre de tables de `db/schema.sql` — 35 le 28 octobre 2026 ; le compter avec
`grep -c "^CREATE TABLE" db/schema.sql` sous bash, ou
`(Select-String -Path db/schema.sql -Pattern '^CREATE TABLE').Count` sous PowerShell. Puis les
quatre rôles :

```
1	Administrateur
2	Gestionnaire
3	Utilisateur
5	Demandeur
```

Aucune migration n'est à lancer : `db/schema.sql` en porte déjà le résultat.

## 8. Créer le premier compte

Avec le nom et le prénom donnés par l'humain :

```sh
docker compose exec -T php php /scripts/init/add_user.php --nom Dupont --prenom Jean --role charge-production
```

Sous Git Bash, `MSYS_NO_PATHCONV=1` doit être posé (règle 2). Un nom en deux mots se met entre
guillemets : `--nom "Le Gall"`. `--courriel` est facultatif.

**Attendu :**

```
Compte créé : d.jean (Chargé de production), sans courriel
Mot de passe : ****************
Notez-le maintenant : il n'est écrit nulle part, et il ne se relit pas.
```

::: warning Le mot de passe passe par l'agent
Il est tiré au sort et montré **une seule fois** — et l'application n'a pas d'écran pour le
changer. Le remettre à l'humain par le canal qu'il choisit, et ne le recopier dans aucun
fichier, commit, ticket ni compte rendu. Si l'humain préfère, il tape lui-même cette commande.
:::

Ce compte crée ensuite les autres depuis l'application. Voir
[Utilisateurs et paramètres](/fonctionnalites/utilisateurs).

## 9. Contrôler, puis ouvrir au réseau

Faire le [contrôle de santé](/production/surveillance#controle) : tous les points doivent
passer.

Restent des **décisions humaines**, à présenter dans le compte rendu sans les prendre :

| Décision | Voir |
| --- | --- |
| Le pare-feu : ouvrir le port **8080** aux postes de l'atelier, et à eux seuls | [Sécurité](/production/securite#reseau) |
| phpMyAdmin, publié sur **8081** avec les identifiants par défaut : le fermer au réseau | [Sécurité](/production/securite#phpmyadmin) |
| Le redémarrage automatique après un redémarrage de la machine | [Surveillance](/production/surveillance#redemarrage) |
| La sauvegarde : où, à quelle fréquence, qui vérifie | [Sauvegarde](/production/sauvegarde#planifier) |
| Les postes : Edge ou Chrome à jour, et l'accès aux trois CDN | [La fiche](/production/reference#ce-dont-les-postes-ont-besoin) |

Prendre enfin une première [sauvegarde](/production/sauvegarde#sauvegarder), et
[rendre compte](/production/#compte-rendu).
