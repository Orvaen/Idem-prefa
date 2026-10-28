# IDEM- Préfabrication

Ce site web gére les demandes de préfabrication, réaliser en php et docker.

## Installation

Tout se lance depuis le **terminal Orvaen**, à la racine du projet. Il lit `terminal.json`,
où chaque commande est écrite une fois pour toutes : personne n'a à retenir la ligne
`docker` exacte, ni à la retaper de travers. `run` tout seul en donne la liste.

```
run up                  # monte la stack, attend que la base réponde, et dit où aller
run schema              # crée les tables — sur une base VIDE seulement
run admin Dupont Jean   # crée le premier compte, et montre son mot de passe une fois
```

`db/schema.sql` contient la structure complète (30 tables et leurs clés étrangères) et les
seules données sans lesquelles l'application ne tourne pas : les rôles, les statuts de
demande et le paramétrage du rendement. Il ne contient aucun compte, aucune demande et
aucun document. Il ne supprime aucune table : l'appliquer sur une base déjà remplie échoue
au lieu d'effacer quoi que ce soit.

Pour se fabriquer de quoi travailler, `run demo` pose les comptes d'essai des quatre
profils et un jeu de demandes. `run clean` fait le chemin inverse : il détruit la base et
les fichiers déposés, puis recrée les tables.

| | |
| --- | --- |
| `run etat` | qui tourne, et si l'on est en dev ou en prod |
| `run logs` | le journal d'Apache et de PHP |
| `run db` | un client MySQL sur la base |
| `run dump` | sauvegarde dans `db/dump.sql` |
| `run rebuild` | reconstruit l'image — après un changement de `Dockerfile` ou de `scripts/` |
| `run down` | arrête tout, sans toucher aux données |

Les sauvegardes complètes de la base ne sont pas suivies par git : elles contiennent les
mots de passe hachés et les documents déposés.

## Développement ou production

L'application tourne dans l'un des deux modes, selon la variable `APP_ENV` lue dans
l'environnement du serveur. **Le défaut est la production** : `docker-compose.yml` est
livré avec `APP_ENV: ${APP_ENV:-prod}`, donc une machine qui démarre sans rien de plus
tourne en production.

### Monter en production

Rien à faire, c'est le défaut : `run up` suffit. Le serveur de l'atelier n'a pas de
fichier `.env`.

### Monter en développement

Poser un fichier `.env` à côté de `docker-compose.yml`. Il est ignoré par git : il ne
peut donc pas partir en production par mégarde.

```
echo APP_ENV=dev > .env
run up
```

### Basculer d'un mode à l'autre

`run dev` et `run prod` posent la variable le temps de remonter le conteneur web. Elle
l'emporte sur le fichier `.env`, ce qui permet d'essayer l'autre mode sans rien modifier —
et `run up` revient à ce que dit le fichier.

```
run prod      # ce poste, en production, le temps d'un essai
run up        # retour au mode du fichier .env
```

La variable n'est lue qu'au démarrage du conteneur : changer de mode le recrée, et les
sessions ouvertes tombent.

### Savoir où l'on est

Dans l'application, un repère **DEV** est posé à côté du logo, dans la barre latérale. Il
n'apparaît qu'en développement. Depuis le terminal, `run etat` le dit — et le journal
l'annonce à chaque démarrage, en première ligne.

### Ce que le développement ouvre

Le chargé de production peut prendre la place de n'importe quel compte sans son mot de
passe, depuis la section « Développement » du menu des comptes, pour essayer les quatre
profils sans se déconnecter. Son propre compte reste dans la liste des comptes connectés :
il y revient d'un clic.

En production, l'action qui le permet répond `404` à tout le monde, y compris à un
visiteur non connecté — rien n'indique qu'elle existe.

## L'API

Tout ce qui commence par `/api/` est servi par `src/api/index.php` : Apache y renvoie les
adresses qui ne correspondent à aucun fichier, il n'y a donc ni `index.php` au milieu ni
réécriture d'URL. La réponse a toujours la même forme :

```json
{ "ok": true,  "donnees": … }
{ "ok": false, "erreur": { "code": "introuvable", "message": "Demande introuvable." } }
```

Le code d'erreur est pour le programme, il ne bouge pas quand on reformule ; le message est
pour la personne devant l'écran.

### Trois couches, deux règles

| | rôle |
| --- | --- |
| `src/modeles/` | le métier : l'objet, ses requêtes SQL, et qui a le droit de le voir |
| `src/api/ressources/` | l'HTTP : lire l'adresse, vérifier ce qui vient du dehors, rendre du JSON |
| `src/api/Requete.php`, `Reponse.php` | le socle, commun à toutes les ressources |

Les deux règles se vérifient d'un `grep` : **aucun SQL hors d'un modèle**, **aucun `echo`
hors d'une `Reponse`**. Une ressource retourne, elle n'imprime pas.

### Les adresses

```
POST   /api/session              ouvrir une session  { "identifiant": "…", "mot_de_passe": "…" }
DELETE /api/session              la fermer
GET    /api/moi                  le compte connecté, son rôle et ses droits
GET    /api/demandes             la liste, limitée à ce que la personne a le droit de voir
GET    /api/demandes/26-DP-0042  une demande
PATCH  /api/demandes/26-DP-0042  la valider ou la refuser  { "statut": 2 ou 3, "motif": "…" }
DELETE /api/demandes/26-DP-0042  la supprimer, avec ses pièces jointes
```

`statut` vaut `2` pour valider — la demande prend sa place dans le planning — et `3` pour
refuser, et le motif est alors obligatoire : le demandeur doit savoir quoi corriger. Seul
un chargé de production décide. Une demande déjà tranchée répond `409`, pas `200` : deux
écrans ouverts sur la même liste ne peuvent pas se contredire.

Déposer une demande n'y est pas : le formulaire valide quatre cents lignes de champs et
reçoit des fichiers, ce qui ne se déplace pas à la légère. `POST /api/demandes` répond
`405`, et le dit.

Une demande s'adresse par sa **référence**, pas par son identifiant technique : c'est elle
que l'atelier lit et recopie, et c'est déjà elle qui circule dans les liens du tableau de
bord. L'identifiant interne ne sort jamais de la base — il n'est même pas dans le JSON.

Les filtres de la liste, tous facultatifs et combinables : `statut=2` (ou `statut=1,2`),
`urgent=1`, `demandeur=7`, `q=pont` (cherché dans la référence, le chantier, le statut, la
passivation et le demandeur), `page=2`, `par_page=50` (200 au plus).

Une méthode qui écrit demande le jeton de session, en en-tête `X-Jeton` ou dans le corps —
sans lui, `403 jeton_invalide`. C'est l'ouverture de session qui le donne, dans sa réponse,
et il vaut pour toute la session. Les écrans du tableau de bord le portent déjà, dans
l'attribut `data-csrf` de la page.

### L'essayer

L'API tient une session, comme le tableau de bord : on ouvre la session une fois, on garde
le cookie, et on s'en sert ensuite. `.cookies` est le bocal, ignoré par git.

```
curl -s -c .cookies -X POST -H "Content-Type: application/json" -d "{\"identifiant\": \"d.jean\", \"mot_de_passe\": \"…\"}" localhost:8080/api/session
curl -s -b .cookies "localhost:8080/api/demandes?statut=2&urgent=1&par_page=5"
curl -s -b .cookies localhost:8080/api/demandes/26-DP-0001
curl -s -b .cookies -X PATCH -H "Content-Type: application/json" -H "X-Jeton: <le jeton rendu plus haut>" -d "{\"statut\": 2}" localhost:8080/api/demandes/26-DP-0001
```

Le compte est celui que `run admin` a créé.

`req`, l'autre outil du terminal, va plus vite pour une requête isolée — `req GET
localhost:8080/api/moi` — mais il ne garde pas le cookie d'une requête à l'autre : sur
cette API, il répondra `non_connecte`.

### Les essais automatiques

```
run test      # tous les essais
run pages     # seulement le dessin des six écrans
```

`tests/api_demandes.php` crée ses propres comptes et demandes, vérifie ce que chacun des
quatre rôles a le droit de voir, les filtres, la recherche, la pagination, les décisions,
les suppressions et les réponses 400, 403, 404, 405 et 409, puis annule tout : la base est
laissée exactement comme elle était. Les autres essais, eux, lisent les données en place —
`run demo` d'abord, sinon ils se plaignent de ne trouver aucune demande.

## Lire le journal

```
run logs
```

Une ligne par fait, et la première colonne dit laquelle regarder :

```
** 09:11:58 app  démarrage en mode dev
   09:12:04 200 GET /dashboard/dashboard.php?vue=atelier  r.pierre/chef-atelier 139ms
** 09:12:31 php POST /dashboard/actions/review_prefa.php  r.pierre/chef-atelier  demande.validee demande 42 affaire=Pont 3
!! 09:13:02 php POST /dashboard/actions/save_atelier.php  r.pierre/chef-atelier  Atelier — PDOException : SQLSTATE[23000]… (save_atelier.php:118)
!! 09:13:02 500 POST /dashboard/actions/save_atelier.php  r.pierre/chef-atelier 23ms  << http://localhost:8080/dashboard/dashboard.php?vue=atelier  172.18.0.1
```

| Marque | Ce que c'est |
| --- | --- |
| *(rien)* | une requête qui a abouti |
| `**` | un fait de l'application : connexion, déconnexion, demande validée |
| `!!` | un échec : code 400 ou plus, avertissement ou erreur de PHP |

Les colonnes sont l'heure (celle des écrans, Europe/Paris), le code — ou `php` pour un
message —, la méthode, l'adresse, le compte et son rôle, puis la durée. Une ligne en échec
ajoute d'où venait la requête et de quelle machine.

Ce qui suit le compte est son **rôle** — `charge-production`, `chef-atelier`,
`personnel-atelier`, `charge-affaire` — et non l'environnement du serveur. Le mode, lui,
est annoncé une fois, à la première ligne du démarrage.

Ce qui n'est pas noté tant que ça va bien : les fichiers statiques, le flux d'événements —
qui rend la main toutes les minutes pour chaque onglet ouvert — et les requêtes qu'Apache
s'adresse à lui-même. Un échec, lui, est toujours noté, y compris sur une feuille de style.
Pour tout voir le temps d'une recherche :

```
run journal-complet    # tout est noté, y compris le bruit
run up                 # retour au journal normal
```

Ne garder que les ennuis :

```
run logs | grep "!!"
```

Pour noter quelque chose depuis le code, sans rien préparer : `Journal::noter('ce qui
vient d'arriver')` et `Journal::erreur('ce qui a raté', $exception)`. La seconde ajoute le
nom de l'exception, son message et l'endroit exact. Le format des lignes vit dans
`docker/apache-journal.conf`, le reste dans `src/journal.php`.

## Les scripts

`scripts/README.md` dit ce que contient chaque dossier et quand s'''en servir, en trois
familles : `init/` pour les comptes et les jeux d'''essai, `migrations/` pour mettre à jour
une base déjà en service, et la racine pour les outils de poste.

Sur une base neuve, aucune migration n'''est à lancer : `db/schema.sql` en porte déjà le
résultat.

## Créer un compte depuis le terminal

```
run admin --nom "Dupont" --prenom "Albert"
```

L'identifiant est fabriqué comme dans l'application — première lettre du nom, un point, le
prénom : `d.jean`. Le rôle par défaut est chargé de production, celui du premier compte
d'une installation.

Sans mot de passe, le script **en tire un au sort et le montre une fois**. Il n'est écrit
nulle part ailleurs : notez-le avant de fermer le terminal. C'est voulu — un mot de passe
par défaut dans un fichier finit par ne jamais être changé, et un mot de passe tapé dans
le terminal reste dans l'historique du shell.

```
run admin Dupont Jean --role chef-atelier --courriel jean.dupont@eitc-be.com
run admin Dupont Jean --mdp "celui que je veux"
```

Le rôle s'écrit dans n'importe lequel de ses trois noms : celui du journal, le métier, ou
le libellé de la base. Accents, majuscules et pluriel sont indifférents.

| Dans le journal | Le métier | En base |
| --- | --- | --- |
| `charge-production` | Chargé de production | Administrateur |
| `chef-atelier` | Chef d'atelier | Gestionnaire |
| `personnel-atelier` | Personnel atelier | Utilisateur |
| `charge-affaire` | Chargé d'affaire | Demandeur |

```
run admin Dupont Jean "chef d'atelier"    # le métier, tel que le client le nomme
run admin Dupont Jean Gestionnaire        # le libellé qu'on lit dans la base
```

Un rôle qu'il ne reconnaît pas n'écrit rien et fait afficher ce tableau.

**Les guillemets comptent.** Le rôle est le troisième mot : un nom — ou un rôle — en deux
mots sans guillemets laisse son second mot filer dans l'argument suivant, qui est le
courriel. `run admin --nom rayan --prenom El Manssouri` créait ainsi un compte dont le
courriel valait « Manssouri », sans un mot de travers. Le courriel qui n'est pas une
adresse est maintenant refusé, et rien n'est écrit — comme pour un identifiant déjà pris.
Le compte créé redit son courriel, pour qu'un argument décalé se voie tout de suite.

## Références des demandes

Les références des demandes suivent le format `26-DP-0001`, avec un compteur par année
de création. Sur une base créée avec `db/schema.sql`, il n'y a rien à faire. Sur une base
plus ancienne, appliquer une fois cette migration (elle peut être relancée sans
renuméroter les demandes) :

```
run migration -- scripts/migrations/2026-10-07_prefa_references.php
```

`run migration` envoie le fichier de la copie de travail, et non celui que l'image a
figé au dernier `run rebuild` : ce qu'on vient de modifier part tel quel.

Les identifiants techniques restent inchangés. Les références supprimées restent
réservées et ne sont pas réutilisées.
