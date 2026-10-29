# Prérequis

Ce qu'il faut sur le poste avant d'installer l'application. Tout le reste — PHP, MySQL,
Tesseract, Node pour cette documentation — tourne dans des conteneurs : **rien d'autre n'est
à installer sur la machine.**

## En bref

| | Obligatoire | Pourquoi |
| --- | --- | --- |
| **Docker Desktop** (ou un moteur Docker avec Compose v2) | oui | Toute l'application tourne dans des conteneurs |
| **Git** | oui | Récupérer le dépôt et le mettre à jour |
| **Un navigateur récent** — Edge ou Chrome | oui | L'interface emploie du CSS et du HTML récents |
| **Un accès à internet** | oui | Images Docker, paquets à la première construction, et scripts chargés par les pages |
| **Le terminal Orvaen** | recommandé | Les commandes `run`, lues dans `terminal.json` |
| **Les ports 8080, 8081 et 8082 libres** | oui | Application, phpMyAdmin, documentation |

## Le système

L'application est développée et exploitée sous **Windows 10 ou 11**. Comme tout tourne dans
Docker, elle fonctionne aussi sous Linux et macOS : seules les commandes `run` du terminal
Orvaen et le script `scripts/start-app.cmd` sont propres à Windows, et chacune a son
équivalent `docker compose` (voir [Les commandes run](/guide/commandes)).

Compter **environ 3 Go de disque** pour les images, plus la base et les fichiers déposés :

| Image | Taille mesurée |
| --- | --- |
| `php-app-php` (construite depuis le `Dockerfile`) | 874 Mo |
| `mysql:8.4` | 1,12 Go |
| `phpmyadmin` | 821 Mo |
| `node:22-alpine` (documentation) | 239 Mo |

## Docker

- **Docker Desktop**, avec le moteur WSL 2 sous Windows. Son installation demande les droits
  d'administrateur, une seule fois par poste ; le reste du projet n'en demande aucun.
- **Docker Compose v2 ou plus récent** — la commande `docker compose`, avec une espace. Le
  projet est éprouvé avec Compose v5.4.

Vérifier :

```sh
docker compose version
```

::: warning Dans le terminal Orvaen, écrire docker.exe
Le terminal Orvaen embarque son propre moteur Docker léger, appelé par la commande `docker`.
Sur un poste équipé de Docker Desktop, c'est lui qu'il faut atteindre : écrire
**`docker.exe compose …`**. C'est ce que font toutes les commandes de `terminal.json`.
:::

## Git et l'accès au dépôt

Le code est sur GitHub : `https://github.com/rayan-studio/Idem-prefa`. Il faut Git, et un
compte qui a accès au dépôt.

```sh
git --version
```

## Le navigateur

L'interface s'appuie sur des fonctions récentes du navigateur : fenêtres `dialog` modales,
sélecteur `:has()`, couleurs `oklch()` et `color-mix()`, animations `@starting-style`.
**Microsoft Edge ou Google Chrome à jour** conviennent ; c'est sur Edge qu'elle est vérifiée.

`scripts/start-app.cmd` ouvre l'application dans une fenêtre d'application — sans barre
d'adresse — avec Edge ou, à défaut, Chrome. Il s'arrête si aucun des deux n'est installé.

## Internet

Trois moments en ont besoin :

1. **La première construction** : télécharger les images Docker, et installer Poppler et
   Tesseract dans l'image PHP.
2. **Le premier lancement de la documentation** : `npm install` récupère VitePress.
3. **Chaque ouverture de page, côté navigateur.** Les pages chargent leurs bibliothèques depuis
   des CDN :

   | Bibliothèque | Version | Adresse |
   | --- | --- | --- |
   | htmx | 2.0.11 | `cdn.jsdelivr.net` |
   | jQuery | 4.0.0 | `code.jquery.com` |
   | axios (écran de connexion) | 1.20.0 | `cdnjs.cloudflare.com` |
::: danger Un poste sans internet ne peut pas afficher l'application
Le serveur lui-même n'a pas besoin d'internet en fonctionnement, mais le **navigateur** de
chaque poste, si : sans htmx ni jQuery, le tableau de bord ne s'anime pas. Sur un réseau
d'atelier fermé, il faudrait servir ces trois fichiers depuis le serveur.
:::

## Le terminal Orvaen (recommandé)

Le terminal maison d'IDEM, pour Windows : `https://github.com/Orvaen/terminal`. Il lit
`terminal.json` à la racine du projet, où chaque commande est écrite une fois pour toutes :
personne n'a à retenir la ligne `docker` exacte. `run` tout seul en donne la liste.

Il s'installe sans droits d'administrateur, dans le profil de l'utilisateur
(`%LOCALAPPDATA%\Programs`), par le script `install.ps1` de son dépôt.

Sans lui, tout reste possible : chaque commande `run` a son équivalent `docker compose`, donné
dans [Les commandes run](/guide/commandes).

## Les ports

| Port | Service | À libérer si… |
| --- | --- | --- |
| **8080** | L'application | un autre serveur web local l'occupe |
| **8081** | phpMyAdmin | — |
| **8082** | Cette documentation — postes de développement seulement | — |

La base MySQL n'est **pas** exposée sur le poste : elle n'est joignable qu'à l'intérieur du
réseau Docker, par l'application et par phpMyAdmin.

Pour savoir qui occupe un port, sous Windows :

```powershell
netstat -ano | findstr :8080
```

## Pour développer

Rien de plus n'est obligatoire : le code est servi tel quel, sans compilation, et une
modification d'un fichier de `src/` se voit au rechargement de la page. Un éditeur comme
VS Code suffit. Pour écrire cette documentation sans Docker, il faut **Node 18 ou plus** —
voir [Cette documentation](/documentation).
