# IDEM- Préfabrication

Ce site web gére les demandes de préfabrication, réaliser en php et docker.

## Installation

Démarrer les conteneurs :

```bash
docker compose up -d --build
```

Créer les tables dans la base vide. `db/schema.sql` contient la structure complète
(30 tables et leurs clés étrangères) et les seules données sans lesquelles
l'application ne tourne pas : les rôles, les statuts de demande et le paramétrage
du rendement. Il ne contient aucun compte, aucune demande et aucun document.

```bash
docker compose exec -T db mysql -umyuser -pmypassword myapp < db/schema.sql
```

```powershell
Get-Content db\schema.sql -Raw | docker compose exec -T db mysql -umyuser -pmypassword myapp
```

Le fichier ne supprime aucune table : l'appliquer sur une base déjà remplie échoue
au lieu d'effacer quoi que ce soit.

Les sauvegardes complètes de la base ne sont pas suivies par git : elles contiennent
les mots de passe hachés et les documents déposés.

## Développement ou production

L'application tourne dans l'un des deux modes, selon la variable `APP_ENV` lue dans
l'environnement du serveur. **Le défaut est la production** : `docker-compose.yml` est
livré avec `APP_ENV: ${APP_ENV:-prod}`, donc une machine qui démarre sans rien de plus
tourne en production.

### Monter en production

Rien à faire, c'est le défaut. Le serveur de l'atelier n'a pas de fichier `.env` :

```bash
docker compose up -d --build
```

### Monter en développement

Poser un fichier `.env` à côté de `docker-compose.yml`. Il est ignoré par git : il ne
peut donc pas partir en production par mégarde.

```bash
printf 'APP_ENV=dev\n' > .env
docker compose up -d --build
```

### Basculer d'un mode à l'autre

La variable du shell l'emporte sur le fichier `.env`, ce qui permet d'essayer l'autre mode
sans rien modifier :

```bash
APP_ENV=prod docker compose up -d php    # ce poste, en production, le temps d'un essai
docker compose up -d php                 # retour au mode du fichier .env
```

```powershell
$env:APP_ENV = 'prod'; docker compose up -d php
Remove-Item Env:APP_ENV; docker compose up -d php
```

La variable n'est lue qu'au démarrage du conteneur : changer de mode demande donc de le
recréer, et les sessions ouvertes tombent.

### Savoir où l'on est

Dans l'application, un repère **DEV** est posé à côté du logo, dans la barre latérale. Il
n'apparaît qu'en développement. Depuis le terminal :

```bash
docker compose config | grep APP_ENV
```

### Ce que le développement ouvre

Le chargé de production peut prendre la place de n'importe quel compte sans son mot de
passe, depuis la section « Développement » du menu des comptes, pour essayer les quatre
profils sans se déconnecter. Son propre compte reste dans la liste des comptes connectés :
il y revient d'un clic.

En production, l'action qui le permet répond `404` à tout le monde, y compris à un
visiteur non connecté — rien n'indique qu'elle existe.

## Les scripts

`scripts/README.md` dit ce que contient chaque dossier et quand s'''en servir, en trois
familles : `init/` pour les comptes et les jeux d'''essai, `migrations/` pour mettre à jour
une base déjà en service, et la racine pour les outils de poste.

Sur une base neuve, aucune migration n'''est à lancer : `db/schema.sql` en porte déjà le
résultat.

## Créer le premier administrateur

```bash
docker exec -it php-app-php-1 php /scripts/init/add_user.php
```

```powershell
Get-Content scripts\init\add_user.php -Raw | docker exec -i php-app-php-1 php
```

## Références des demandes

Les références des demandes suivent le format `26-DP-0001`, avec un compteur par année
de création. Sur une base créée avec `db/schema.sql`, il n'y a rien à faire. Sur une base
plus ancienne, appliquer une fois cette migration (elle peut être relancée sans
renuméroter les demandes) :

```powershell
Get-Content scripts\init\prefa_references.php -Raw | docker compose exec -T php php
```

Les identifiants techniques restent inchangés. Les références supprimées restent
réservées et ne sont pas réutilisées.
