# Installation

De zéro à l'écran de connexion. Vérifier d'abord les [prérequis](/guide/prerequis).

Les commandes sont données pour le **terminal Orvaen** (`run …`). Chacune a son équivalent
`docker compose`, dans l'onglet voisin.

## 1. Récupérer le code

```sh
git clone https://github.com/rayan-studio/Idem-prefa.git php-app
cd php-app
```

Le nom du dossier compte : Docker Compose en fait le nom du projet, donc celui du volume de la
base (`php-app_mysql_data`) et des conteneurs (`php-app-php-1`), qu'emploie toute cette
documentation. Un autre dossier, c'est une autre base — vide.

Pour le serveur de l'atelier, suivre plutôt [Mettre en service un serveur](/production/mise-en-service).

## 2. Choisir le mode

**Sur un poste de développement**, poser un fichier `.env` à côté de `docker-compose.yml` :

```sh
echo APP_ENV=dev > .env
echo COMPOSE_PROFILES=docs >> .env
```

**Sur le serveur de l'atelier, ne rien faire** : sans `.env`, l'application démarre en
production. C'est voulu — `.gitignore` écarte le fichier `.env`, qui ne peut donc pas partir
en production par mégarde.

| Variable | Valeurs | Effet |
| --- | --- | --- |
| `APP_ENV` | `prod` (défaut) ou `dev` | Le mode. En `dev`, un repère **dev** paraît à côté du logo, et le chargé de production peut prendre la place de n'importe quel compte sans son mot de passe. |
| `JOURNAL_COMPLET` | vide (défaut) ou `1` | Note aussi dans le journal les fichiers statiques et le flux d'événements. |
| `COMPOSE_PROFILES` | vide (défaut) ou `docs` | Lu par Docker Compose lui-même : `docs` démarre aussi cette documentation, sur le port 8082. |

Le détail est dans [Développement et production](/guide/modes).

::: info Le fichier .env.examples
Il porte aussi `ADMIN_PASS` et `MYSQL_ROOT_PASSWORD`, mais rien ne lit ces deux variables :
seule `APP_ENV` compte.
:::

## 3. Monter la stack

::: code-group

```sh [Terminal Orvaen]
run up
```

```sh [docker compose]
docker compose up -d
```

:::

Au premier lancement, Docker construit l'image de l'application — Apache, PHP, Poppler et
Tesseract — et télécharge MySQL, phpMyAdmin et Node : **quelques minutes**. Les lancements
suivants prennent quelques secondes. `run up` attend que la base réponde, puis donne les trois
adresses.

## 4. Créer les tables

Sur une base **vide** seulement :

::: code-group

```sh [Terminal Orvaen]
run schema
```

```sh [docker compose]
docker compose exec -T -e MYSQL_PWD=mypassword db mysql --default-character-set=utf8mb4 -umyuser myapp < db/schema.sql
```

:::

`db/schema.sql` crée toutes les tables et leurs clés étrangères, avec les seules données sans
lesquelles l'application ne tourne pas : les rôles, les statuts de demande et le paramétrage
de la cadence. Aucun compte, aucune demande, aucun document. Il ne supprime aucune table :
appliqué sur une base déjà remplie, il **échoue** au lieu d'effacer quoi que ce soit.

## 5. Créer le premier compte

::: code-group

```sh [Terminal Orvaen]
run admin Dupont Jean
```

```sh [docker compose]
docker compose exec -T php php -- --nom Dupont --prenom Jean < scripts/init/add_user.php
```

:::

Le premier compte est **chargé de production** : c'est lui qui ouvre les affaires, valide les
demandes et crée les autres comptes. Son identifiant se fabrique comme dans l'application —
première lettre du nom, un point, le prénom : `d.jean`.

::: warning Le mot de passe ne s'affiche qu'une fois
Sans `--mdp`, le script tire un mot de passe au sort et le montre **une seule fois**. Il n'est
écrit nulle part ailleurs : le noter avant de fermer le terminal.
:::

Les autres options — rôle, courriel, mot de passe choisi — sont dans
[Les commandes run](/guide/commandes#admin).

## 6. Un jeu d'essai (facultatif)

Pour avoir de quoi travailler sur un poste de développement :

::: code-group

```sh [Terminal Orvaen]
run demo
```

```sh [docker compose]
docker compose exec -T php php < scripts/init/add_test_users.php
docker compose exec -T php php < scripts/init/add_demo_data.php
```

:::

Il pose un compte par profil — deux pour le personnel d'atelier, pour vérifier que chacun ne
voit que ses affectations — et un jeu de demandes et de plans.

| Identifiant | Profil |
| --- | --- |
| `charge-production` | Chargé de production |
| `chef-atelier` | Chef d'atelier |
| `personnel-atelier.1`, `personnel-atelier.2` | Personnel atelier |
| `charge-affaire` | Chargé d'affaire |

Leurs mots de passe sont tirés au hasard et **jamais affichés**. En développement, on n'en a
pas besoin : le chargé de production prend la place de n'importe quel compte depuis le menu des
comptes, section « Développement ».

## 7. Se connecter

Ouvrir **http://localhost:8080** : l'écran de connexion est à la racine du site. Saisir
l'identifiant et le mot de passe du compte créé à l'étape 5.

Sous Windows, `scripts/start-app.cmd` ouvre l'application dans une fenêtre à elle, sans barre
d'adresse, avec Edge ou Chrome. Il ne monte pas la stack : `run up` d'abord.

## 8. Vérifier

::: code-group

```sh [Terminal Orvaen]
run etat     # les conteneurs, et le mode : dev ou prod
run test     # les essais automatiques (run demo d'abord)
```

```sh [docker compose]
docker compose ps
docker compose exec -T php printenv APP_ENV
```

:::

| Adresse | Ce qu'on doit y voir |
| --- | --- |
| http://localhost:8080 | L'écran de connexion |
| http://localhost:8081 | phpMyAdmin, connecté à la base `myapp` |
| http://localhost:8082 | Cette documentation |

## Les identifiants de la base

Ils sont écrits dans `docker-compose.yml` :

| | Valeur |
| --- | --- |
| Base | `myapp` |
| Utilisateur | `myuser` / `mypassword` |
| Administrateur MySQL | `root` / `rootpassword` |

::: danger Sur un serveur accessible du réseau
La base n'est pas exposée hors de Docker, mais **phpMyAdmin l'est, sur le port 8081**. Sur le
serveur de l'atelier, quiconque atteint ce port avec ces identifiants voit toute la base :
changer les mots de passe, ou ne pas publier ce port.
:::

## Et après

- [Les commandes run](/guide/commandes), toutes, avec leur équivalent
- [Sauvegarde, mise à jour, remise à zéro](/guide/exploitation)
- [Dépannage](/guide/depannage)
