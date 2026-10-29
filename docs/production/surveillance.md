# Surveiller et dépanner

Le contrôle de santé, à faire avant et après toute intervention, puis ce qu'on fait devant
chaque symptôme. Les commandes suivent [les conventions d'écriture](/production/#commandes).

## Le contrôle de santé {#controle}

Dix points, tous en lecture seule. Libre, sans demander. Chaque écart se note dans le compte
rendu.

| # | Point | Commande | Attendu |
| --- | --- | --- | --- |
| 1 | Les conteneurs | `docker compose ps` | `db`, `php` et `phpmyadmin` à `Up` |
| 2 | Le mode | `docker compose exec -T php printenv APP_ENV` | `prod` — sinon, [incident de sécurité](#mode-dev) |
| 3 | La base | `docker compose exec -T -e MYSQL_PWD=mypassword db mysqladmin -h127.0.0.1 -umyuser ping` | `mysqld is alive` |
| 4 | L'application | voir ci-dessous | `200` |
| 5 | Les fichiers déposés, protégés | voir ci-dessous | `403` — un `200` est un incident : les documents sont lisibles sans connexion |
| 6 | Le flux en temps réel | voir ci-dessous | `204` : il répond, et refuse un visiteur sans session |
| 7 | La lecture automatique | `docker compose exec -T php tesseract --list-langs` | `eng`, `fra` et `osd` |
| 8 | Le journal du dernier jour | `docker compose logs --since 24h php`, en ne gardant que les lignes `!!` | Aucune, ou chacune expliquée |
| 9 | Le disque | `docker system df`, puis `docker compose exec -T db du -sh /var/lib/mysql` | De la place libre sur la machine ; un volume qui ne bondit pas d'un contrôle à l'autre |
| 10 | La dernière sauvegarde | `ls -l ../sauvegardes-idem` | Sa date — à signaler si elle a plus d'un jour |

Les points 4 à 6, depuis la machine elle-même :

::: code-group

```sh [bash, Git Bash]
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/dashboard/uploads/
curl -s -o /dev/null -w '%{http_code}\n' --max-time 5 http://localhost:8080/api/flux.php
```

```powershell [PowerShell]
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8080/
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8080/dashboard/uploads/
curl.exe -s -o NUL -w "%{http_code}`n" --max-time 5 http://localhost:8080/api/flux.php
```

:::

Sous PowerShell, `curl` seul est un alias d'`Invoke-WebRequest` : écrire `curl.exe`.

Le point 8, pour ne garder que les échecs :

::: code-group

```sh [bash, Git Bash]
docker compose logs --since 24h php | grep '!!'
```

```powershell [PowerShell]
docker compose logs --since 24h php | Select-String '!!'
```

:::

Une ligne `!!` porte le code, l'adresse, le compte, et pour une erreur PHP le fichier et la
ligne. Le format est décrit dans [Le journal](/technique/journal).

## Comparer la base au schéma {#schema}

Après une mise à jour, ou devant une erreur `500` inexpliquée : la base en service a-t-elle la
forme que le code attend ? `db/schema.sql` décrit cette forme.

```sh
docker compose exec -T -e MYSQL_PWD=mypassword db sh -c 'mysqldump --no-data --skip-comments --skip-set-charset --skip-add-drop-table --no-tablespaces -umyuser --result-file=/tmp/schema-en-service.sql myapp'
docker compose cp db:/tmp/schema-en-service.sql ../sauvegardes-idem/schema-en-service.sql
docker compose exec -T db rm /tmp/schema-en-service.sql
git diff --no-index db/schema.sql ../sauvegardes-idem/schema-en-service.sql
```

`git diff` rend `1` dès qu'il y a une différence : ce n'est pas une erreur. **Les seules
différences attendues :**

- en `-`, l'en-tête commenté de `db/schema.sql` et ses trois lignes `INSERT` (rôles, statuts,
  paramétrage) ;
- les lignes `) ENGINE=InnoDB …` de la base, qui portent en plus `AUTO_INCREMENT=n`.

Toute autre différence — une colonne, une clé, une table — veut dire qu'une migration n'est pas
passée, ou que `db/schema.sql` n'a pas suivi une migration. **S'arrêter** et la recopier dans le
compte rendu.

## Garder le journal avant de recréer {#journal}

Le journal vit dans le conteneur web : le recréer — `up -d --build`, `down`, un changement de
mode — l'efface. Avant de recréer un conteneur pour comprendre un incident, le copier :

::: code-group

```sh [bash, Git Bash]
docker compose logs --no-color --timestamps php > "../sauvegardes-idem/journal-php-$(date +%Y%m%d-%H%M).txt"
```

```powershell [PowerShell]
docker compose logs --no-color --timestamps php > "../sauvegardes-idem/journal-php-$(Get-Date -Format yyyyMMdd-HHmm).txt"
```

:::

C'est l'exception à la règle des redirections : le journal n'est que du texte, et un
réencodage par PowerShell le laisse lisible.

## Après un redémarrage de la machine {#redemarrage}

Aucun conteneur n'a de politique de redémarrage : **après un redémarrage, l'application reste
arrêtée** jusqu'à ce qu'on la relance.

1. Docker doit tourner : `docker info` ne doit pas répondre d'erreur. Sous Windows, Docker
   Desktop ne démarre qu'à l'ouverture d'une session — sans session ouverte, rien ne tourne.
2. Si **aucun** conteneur du projet n'est `Up`, le remonter est libre, sans demander : rien de
   ce qui tourne n'est recréé.

   ```sh
   docker compose up -d
   ```

3. Attendre la base, puis le contrôle de santé.

Pour que l'application reparte seule, deux décisions humaines : `restart: unless-stopped` sur
les services `php`, `db` et `phpmyadmin` de `docker-compose.yml`, et un Docker qui démarre avec
la machine — un moteur Docker installé comme service sous Linux, ou Docker Desktop réglé pour
démarrer à l'ouverture d'une session qui s'ouvre seule.

## Les symptômes

Le niveau d'autorisation de chaque action est celui des [règles](/production/#autorisations) :
« sur accord » veut dire qu'on décrit l'action dans le compte rendu et qu'on attend.

### Personne ne joint l'application

| Constat | Cause | Action |
| --- | --- | --- |
| `docker info` échoue | Docker n'est pas démarré — souvent après un redémarrage | Le démarrer, puis [remonter](#redemarrage) |
| `docker compose ps` ne montre rien | L'application a été arrêtée, ou la machine a redémarré | [Remonter](#redemarrage) — libre si rien ne tourne |
| `php` n'est pas `Up` | Le conteneur web s'est arrêté | [Garder le journal](#journal), `docker compose logs --tail=50 php`, puis `docker compose up -d` — sur accord |
| Tout est `Up`, le point 4 rend `200` sur la machine, mais pas depuis un poste | Le réseau ou le pare-feu | Rendre compte : c'est hors de Docker |

### Un poste affiche des écrans inertes, les autres non

Les pages chargent htmx depuis un CDN ; ce poste ne l’atteint pas — un proxy, un
filtrage. La console du navigateur (F12) le confirme par une erreur de chargement de `htmx.js`.
Rien à faire sur le serveur : rendre compte.

### Une action répond 500

1. Le journal : `docker compose logs --since 1h php`, lignes `!!`.
2. `SQLSTATE[42S22]` (*Unknown column*) ou `SQLSTATE[42S02]` (*Table doesn't exist*) : la
   base n'a pas la forme du code. [Comparer au schéma](#schema), et trouver la migration qui
   manque — l'appliquer relève d'une [mise à jour](/production/mise-a-jour#migrations), sur
   accord.
3. `Data too long for column 'contenu'` : un document de plus de 16 Mio rangé en base (QMOS,
   DMOS, bon de commande). L'envoi accepte 50 Mo, la colonne non : c'est un défaut connu,
   à signaler.
4. Autre chose : recopier la ligne exacte, avec le fichier et la ligne qu'elle cite.

### Tout le monde a été déconnecté en même temps

Le conteneur web a été recréé ou redémarré : les sessions vivent dedans. `docker compose ps`
montre depuis quand il tourne, et le journal porte la ligne `** … app  démarrage en mode prod`
de ce moment-là. Rien à réparer ; trouver qui l'a relancé, et pourquoi.

### « Rechargez la page puis réessayez »

Le jeton du formulaire ne correspond plus à la session — une session expirée, ou ouverte dans
un autre onglet. La personne recharge la page. Rien à faire sur le serveur.

### La base ne répond pas

`SQLSTATE[HY000] [2002]` dans le journal, ou le point 3 en échec.

1. `docker compose ps db` : le conteneur tourne-t-il ?
2. `docker compose logs --tail=50 db` : MySQL dit pourquoi — un disque plein, le plus souvent.
3. Rendre compte. Ne **jamais** supprimer le volume, ni des fichiers dans `/var/lib/mysql`.

### La lecture automatique répond 503

Poppler ou Tesseract manque dans l'image : elle date d'avant leur ajout. Le point 7 le
confirme. Reconstruire (`docker compose up -d --build`) — sur accord, puisque tout le monde
est déconnecté.

### Les alertes en temps réel n'arrivent plus

1. Le point 6 doit rendre `204`.
2. Si un mandataire inverse a été placé devant l'application, il doit laisser passer le flux
   sans le retenir : l'application envoie `X-Accel-Buffering: no`, mais tous ne le lisent pas.
3. Côté poste, le navigateur se reconnecte seul ; recharger la page relance le flux.

Voir [Le flux en temps réel](/technique/temps-reel).

### Le disque se remplit {#disque}

| Ce qui grossit | Comment le voir | Ce qu'on peut faire |
| --- | --- | --- |
| Le cache de construction de Docker | `docker system df`, ligne *Build Cache* — plus de 3 Go après quelques semaines de reconstructions | `docker builder prune` : sans effet sur les données — sur accord |
| La base et ses journaux binaires (30 jours) | `docker compose exec -T db du -sh /var/lib/mysql` | Rendre compte : purger les journaux binaires est une décision humaine |
| Le journal du conteneur, sans limite de taille | Grandit avec le conteneur | Il repart à zéro à la recréation ; une limite (`logging` dans `docker-compose.yml`) se décide |
| Les sauvegardes | `ls -l ../sauvegardes-idem` | Les copier ailleurs — un humain décide lesquelles supprimer |

Jamais `docker system prune --volumes`, `docker volume prune`, ni rien qui vise un volume.

### APP_ENV vaut dev sur le serveur {#mode-dev}

**C'est un incident de sécurité** : n'importe quel chargé de production peut prendre la place
de n'importe quel compte, sans mot de passe. Le signaler **tout de suite** à un humain, avant
toute autre chose.

La cause est presque toujours un fichier `.env` posé sur le serveur, ou une variable `APP_ENV`
dans l'environnement de la session. Le corriger — retirer la cause, puis
`docker compose up -d php` — se fait sur accord, et le compte rendu dit qui avait posé ce
fichier.

### Un plan déposé ne se télécharge pas

Un nom de fichier avec des accents ou des espaces, déposé par l'action `deposer_document`, peut
devenir introuvable au téléchargement : c'est un défaut connu. Ne pas renommer de fichier à la
main dans `src/dashboard/uploads/` — la base cite le nom exact. Rendre compte.
