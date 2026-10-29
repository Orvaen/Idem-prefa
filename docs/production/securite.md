# Durcir la production

Ce qui reste ouvert sur le serveur, relevé le 28 octobre 2026, et comment le fermer. **Rien
de cette page n'est fait aujourd'hui**, et chaque point est une **décision humaine** : un agent
la présente avec son risque et la modification exacte, puis attend.

Le fonctionnement de la session, du jeton CSRF et des droits est décrit dans
[Sessions et sécurité](/technique/securite).

## Par où commencer

| Priorité | Point | Effort |
| --- | --- | --- |
| 1 | [phpMyAdmin](#phpmyadmin), ouvert au réseau avec les identifiants par défaut | Une ligne de `docker-compose.yml` |
| 2 | [Le réseau](#reseau) : le port 8080 ouvert à qui | Le pare-feu de la machine |
| 3 | [Le cookie de session](#session) sans `HttpOnly` ni `SameSite` | Une ligne du `Dockerfile` |
| 4 | [Les mots de passe de la base](#mots-de-passe), publics dans le dépôt | Une évolution du code |
| 5 | [Les défauts du code](#code) | Des corrections, livrées par une mise à jour |
| 6 | [Le reste](#reste) : versions affichées, images non figées, journal sans limite | Quelques lignes |

## phpMyAdmin {#phpmyadmin}

**Constat.** Le service `phpmyadmin` publie le port **8081** sur toutes les interfaces. Les
identifiants `myuser` / `mypassword` et `root` / `rootpassword` sont écrits dans
`docker-compose.yml`, donc dans le dépôt. Quiconque atteint ce port lit, modifie ou supprime
toute la base. L'image suit `phpmyadmin:latest`, une version qui change sans prévenir.

**Le plus simple : ne le publier que pour la machine elle-même.** Dans `docker-compose.yml` :

```yaml
  phpmyadmin:
    ports:
      - "127.0.0.1:8081:80"
```

Sur un poste de développement, rien ne change : `http://localhost:8081` répond toujours. Sur
le serveur, seul quelqu'un assis devant — ou passé par une connexion à distance — l'atteint.

**Plus strict :** ne pas le démarrer en production. Lui donner un profil, comme la
documentation — `profiles: ["admin"]` —, et le lancer à la demande :
`docker compose --profile admin up -d phpmyadmin`.

**Appliquer :** dans un créneau de [mise à jour](/production/mise-a-jour), puis
`docker compose up -d phpmyadmin`. **Vérifier :** depuis un autre poste, le port 8081 ne
répond plus ; depuis le serveur, `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8081/`
rend `200` — ou rien, avec le profil.

## Le réseau {#reseau}

**Constat.** L'application est servie en **HTTP clair** sur le port 8080, publié sur toutes les
interfaces. Les mots de passe traversent le réseau en clair à la connexion.

| Situation | Ce qu'il faut |
| --- | --- |
| Les postes de l'atelier, sur un réseau fermé | Le pare-feu de la machine n'ouvre le port 8080 qu'à ce réseau ; 8081 à personne |
| Un accès depuis l'extérieur de l'atelier | Un mandataire inverse avec TLS devant l'application — et alors `session.cookie_secure=1` (voir [la session](#session)) |

Un mandataire doit laisser passer **le flux en temps réel** sans le retenir : une réponse qui
reste ouverte une minute, et `X-Accel-Buffering: no`. Voir
[Le flux en temps réel](/technique/temps-reel).

## Le cookie de session {#session}

**Constat.** `Set-Cookie: PHPSESSID=…; path=/` — ni `HttpOnly`, ni `SameSite`, ni `Secure` :
les valeurs par défaut de PHP. Un script injecté dans une page pourrait lire le cookie et
emporter la session.

**La modification**, dans le `Dockerfile`, sur la ligne qui écrit déjà la durée de session :

```dockerfile
RUN printf 'session.gc_maxlifetime=28800\nsession.cookie_httponly=1\nsession.cookie_samesite=Lax\n' > /usr/local/etc/php/conf.d/prefa-session.ini
```

`session.cookie_secure=1` s'y ajoute **seulement** derrière HTTPS : en HTTP, le navigateur ne
renverrait plus le cookie, et personne ne pourrait se connecter.

**Appliquer :** une [mise à jour](/production/mise-a-jour), qui reconstruit l'image.
**Vérifier :** l'en-tête doit finir par `HttpOnly; SameSite=Lax`.

::: code-group

```sh [bash, Git Bash]
curl -sI http://localhost:8080/ | grep -i set-cookie
```

```powershell [PowerShell]
curl.exe -sI http://localhost:8080/ | Select-String -Pattern set-cookie
```

:::

## Les mots de passe de la base {#mots-de-passe}

**Constat.** `mypassword` et `rootpassword` sont dans le dépôt. La base n'est joignable que
depuis les conteneurs : tant que [phpMyAdmin](#phpmyadmin) est fermé au réseau, le risque est
contenu.

**Pourquoi on ne les change pas d'un coup.** Le mot de passe de `myuser` est écrit à trois
endroits, dont deux suivis par git :

| Endroit | Effet d'une modification sur le serveur |
| --- | --- |
| `docker-compose.yml` | Aucun : il ne sert qu'à la création du volume |
| `src/my_setting.ini` | L'application le lit à chaque requête — mais le fichier est suivi par git : le modifier sur le serveur rend le dépôt « sale », et bloque toutes les mises à jour suivantes |
| `terminal.json` | Les commandes `run` qui joignent la base cessent de marcher |

Changer celui de `myuser` demande donc d'abord **une évolution du code** : lire les
identifiants depuis l'environnement, ou depuis un fichier que git ignore. C'est un travail de
développement, livré par une mise à jour.

**Le compte `root`, lui, se change seul** : l'application ne s'en sert pas. Un humain choisit le
nouveau mot de passe et tape :

```sh
docker compose exec -T -e MYSQL_PWD=rootpassword db mysql -uroot -e "ALTER USER 'root'@'%' IDENTIFIED BY 'NOUVEAU'; ALTER USER 'root'@'localhost' IDENTIFIED BY 'NOUVEAU'"
```

`MYSQL_ROOT_PASSWORD` dans `docker-compose.yml` devient alors faux, sans conséquence : il ne
sert qu'à la création du volume. Le nouveau mot de passe se garde hors du dépôt. Un agent ne
tape pas cette commande : le mot de passe passerait par lui.

## Les défauts du code {#code}

Relevés en lisant le code. Ils se corrigent sur un poste de développement, puis arrivent sur
le serveur par une mise à jour — **jamais** par une modification faite sur place.

| Défaut | Risque | Correction |
| --- | --- | --- |
| `auth/action.php` ne régénère pas l'identifiant de session à la connexion | Fixation de session | `session_regenerate_id(true)` après une connexion réussie |
| `actions/create_passivation.php` ne vérifie aucun rôle | Tout compte connecté crée des types de passivation | Le réserver au chargé de production |
| `receptionner_bon_materiel.php` n'exige pas une commande validée | L'interface l'impose, pas le serveur | Le vérifier côté serveur |
| QMOS, DMOS et bons de commande en `MEDIUMBLOB` (16 Mio), envoi accepté jusqu'à 50 Mo | Un gros document échoue à l'enregistrement | Aligner la limite d'envoi, ou passer la colonne en `LONGBLOB` par une migration |
| `deposer_document` : un nom de fichier avec accents ou espaces | Le plan devient introuvable au téléchargement | Ramener le nom à `[A-Za-z0-9._-]`, comme les autres envois |
| `scripts/ccpu_lire.php` inclut un fichier qui n'existe plus | Le script ne tourne pas | Inclure `dashboard/includes/lecture/ccpu.php` |

## Le reste {#reste}

| Point | Constat | Modification |
| --- | --- | --- |
| Les versions affichées | `Server: Apache/2.4.69 (Debian)` et `X-Powered-By: PHP/8.3.36` dans chaque réponse | `expose_php=Off` dans un `.ini` du `Dockerfile` ; `ServerTokens Prod` et `ServerSignature Off` dans une configuration Apache |
| Les images non figées | `phpmyadmin:latest` ; `mysql:8.4` suit les versions 8.4.x | Une version exacte, changée exprès lors d'une mise à jour |
| Le journal du conteneur | Aucune limite de taille (`json-file`, sans option) | `logging: { driver: json-file, options: { max-size: "10m", max-file: "5" } }` sur le service `php` |
| `.env.examples` | Porte `ADMIN_PASS` et `MYSQL_ROOT_PASSWORD`, que rien ne lit, et `APP_ENV=dev` | Ne garder que ce qui sert, et l'écrire pour un poste de développement |
| `myuser` peut supprimer la base | Il a tous les droits sur `myapp` | C'est ce qui permet la restauration sans `root` ; à restreindre seulement avec une autre façon de restaurer |

## Ce qui ne se négocie pas

Ces règles-là ne sont pas des décisions à prendre : elles sont déjà prises.

- **Jamais `APP_ENV=dev` sur le serveur.** Il ouvre la prise de place de n'importe quel compte
  sans mot de passe. Voir [l'incident](/production/surveillance#mode-dev).
- **Les sauvegardes sont aussi sensibles que la base** : mots de passe hachés et documents.
  Voir [Sauvegarde](/production/sauvegarde).
- **Les lectures automatiques restent sur le serveur.** Poppler et Tesseract tournent dans
  l'image ; aucun document ne part vers un service externe, aucun modèle en ligne n'est appelé.
  Ne pas en « améliorer » la lecture en en ajoutant un.
