# L'API

Tout ce qui commence par `/api/` est servi par `src/api/index.php`. Apache y renvoie les
adresses qui ne correspondent à aucun fichier (`FallbackResource`, voir le `Dockerfile`) : il
n'y a ni `index.php` dans l'adresse ni réécriture d'URL. Les fichiers qui existent sous
`src/api/` — `api/users/get.php` par exemple — passent avant et restent servis directement.

## La forme des réponses

Toujours la même :

```json
{ "ok": true,  "donnees": … }
{ "ok": false, "erreur": { "code": "introuvable", "message": "Demande introuvable." } }
```

Le **code** est pour le programme : il ne bouge pas quand on reformule. Le **message** est pour
la personne devant l'écran.

## Trois couches, deux règles

| Couche | Rôle |
| --- | --- |
| `src/modeles/` | Le métier : l'objet, ses requêtes SQL, et qui a le droit de le voir |
| `src/api/ressources/` | L'HTTP : lire l'adresse, vérifier ce qui vient du dehors, rendre du JSON |
| `src/api/Requete.php`, `Reponse.php` | Le socle, commun à toutes les ressources |

Les deux règles se vérifient d'un `grep` : **aucun SQL hors d'un modèle**, **aucun `echo` hors
d'une `Reponse`**. Une ressource retourne, elle n'imprime pas.

## Les adresses

| Méthode | Adresse | Ce qu'elle fait |
| --- | --- | --- |
| `POST` | `/api/session` | Ouvre une session — `{ "identifiant": "…", "mot_de_passe": "…" }` |
| `DELETE` | `/api/session` | La ferme |
| `GET` | `/api/moi` | Le compte connecté, son rôle et ses droits |
| `GET` | `/api/demandes` | La liste, limitée à ce que la personne a le droit de voir |
| `GET` | `/api/demandes/26-DP-0042` | Une demande |
| `PATCH` | `/api/demandes/26-DP-0042` | La valider ou la refuser — `{ "statut": 2 ou 3, "motif": "…" }` |
| `DELETE` | `/api/demandes/26-DP-0042` | La supprimer, avec ses pièces jointes |

### Valider ou refuser

`statut` vaut `2` pour **valider** — la demande prend sa place dans le planning — et `3` pour
**refuser** ; le motif est alors obligatoire, le demandeur doit savoir quoi corriger. Seul un
chargé de production décide. Une demande déjà tranchée répond `409`, pas `200` : deux écrans
ouverts sur la même liste ne peuvent pas se contredire.

### Déposer une demande

Pas par l'API : le formulaire valide plusieurs centaines de lignes de champs et reçoit des
fichiers. `POST /api/demandes` répond `405`, et le dit.

### L'adresse d'une demande

Une demande s'adresse par sa **référence**, pas par son identifiant technique : c'est elle que
l'atelier lit et recopie, et c'est déjà elle qui circule dans les liens du tableau de bord.
L'identifiant interne ne sort jamais de la base — il n'est même pas dans le JSON.

### Les filtres de la liste

Tous facultatifs et combinables :

| Paramètre | Exemple | Effet |
| --- | --- | --- |
| `statut` | `statut=2`, `statut=1,2` | Un ou plusieurs statuts |
| `urgent` | `urgent=1` | Les urgentes seulement |
| `demandeur` | `demandeur=7` | Les demandes d'un compte |
| `q` | `q=pont` | Cherché dans la référence, le chantier, le statut, la passivation et le demandeur |
| `page`, `par_page` | `page=2&par_page=50` | La pagination — 200 par page au plus |

## Le jeton

Une méthode qui **écrit** demande le jeton de session, en en-tête `X-Jeton` ou dans le corps —
sans lui, `403 jeton_invalide`. C'est l'ouverture de session qui le donne, dans sa réponse, et
il vaut pour toute la session. Les écrans du tableau de bord le portent déjà, dans l'attribut
`data-csrf` de la page.

## Les codes d'erreur

Définis dans `src/api/Reponse.php` et les ressources :

| Statut HTTP | Code | Quand |
| --- | --- | --- |
| `400` | `demande_invalide` | Une donnée manque ou ne se lit pas |
| `401` | `non_connecte` | Pas de session |
| `401` | `identifiants_incorrects` | Ouverture de session refusée |
| `403` | `jeton_invalide` | Une écriture sans le jeton de session |
| `403` | `interdit` | Le rôle n'a pas ce droit |
| `404` | `introuvable` | La demande n'existe pas, **ou la personne n'a pas le droit de la voir** — ou l'adresse ne correspond à aucune ressource |
| `405` | `methode_refusee` | La méthode n'existe pas sur cette adresse |
| `409` | `deja_tranchee` | La demande est déjà validée ou refusée |
| `409` | `duree_hors_limite` | À la cadence réglée, la demande dépasserait ce que le planning sait poser |
| `409` | `suppression_impossible` | La demande ne peut plus être supprimée |

Une demande que l'on n'a pas le droit de voir répond `404` et non `403` : un `403` confirmerait
qu'elle existe.

## L'essayer

L'API tient une session, comme le tableau de bord : on l'ouvre une fois, on garde le cookie, et
on s'en sert ensuite. `.cookies` est le bocal, écarté par `.gitignore`.

```sh
curl -s -c .cookies -X POST -H "Content-Type: application/json" \
     -d "{\"identifiant\": \"d.jean\", \"mot_de_passe\": \"…\"}" localhost:8080/api/session
curl -s -b .cookies "localhost:8080/api/demandes?statut=2&urgent=1&par_page=5"
curl -s -b .cookies localhost:8080/api/demandes/26-DP-0001
curl -s -b .cookies -X PATCH -H "Content-Type: application/json" -H "X-Jeton: <le jeton>" \
     -d "{\"statut\": 2}" localhost:8080/api/demandes/26-DP-0001
```

`req`, l'outil HTTP du terminal Orvaen, va plus vite pour une requête isolée —
`req GET localhost:8080/api/moi` — mais il ne garde pas le cookie d'une requête à l'autre : sur
cette API, il répondra `non_connecte`.

## Ce que l'API ne couvre pas

Le tableau de bord n'appelle pas l'API pour ses écrans : il passe par les **actions** de
`src/dashboard/actions/`, des scripts PHP qui répondent en JSON. Voir
[Architecture](/technique/architecture).
