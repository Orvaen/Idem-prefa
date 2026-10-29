# Le journal

```sh
run logs                 # le suivre en direct (Ctrl+C pour sortir)
run logs | grep "!!"     # ne garder que les ennuis
```

## Une ligne par fait

```
** 09:11:58 app  démarrage en mode dev
   09:12:04 200 GET /dashboard/dashboard.php?vue=atelier  r.pierre/chef-atelier 139ms
** 09:12:31 php POST /dashboard/actions/review_prefa.php  r.pierre/chef-atelier  demande.validee demande 42 affaire=Pont 3
!! 09:13:02 php POST /dashboard/actions/save_atelier.php  r.pierre/chef-atelier  Atelier — PDOException : SQLSTATE[23000]… (save_atelier.php:118)
!! 09:13:02 500 POST /dashboard/actions/save_atelier.php  r.pierre/chef-atelier 23ms  << http://localhost:8080/dashboard/dashboard.php?vue=atelier  172.18.0.1
```

La première colonne dit laquelle regarder :

| Marque | Ce que c'est |
| --- | --- |
| *(rien)* | Une requête qui a abouti |
| `**` | Un fait de l'application : démarrage, connexion, déconnexion, demande validée… |
| `!!` | Un échec : code 400 ou plus, avertissement ou erreur de PHP |

Puis viennent l'heure (celle des écrans, Europe/Paris), le code — ou `php` pour un message —, la
méthode, l'adresse, le compte et son rôle, et la durée. Une ligne en échec ajoute d'où venait la
requête et de quelle machine.

Ce qui suit le compte est son **rôle** — `charge-production`, `chef-atelier`,
`personnel-atelier`, `charge-affaire` —, pas l'environnement du serveur. Le mode, lui, est
annoncé une fois, à la première ligne du démarrage.

## Ce qui n'est pas noté

Tant que ça va bien : les fichiers statiques, le flux d'événements — qui rend la main toutes les
minutes pour chaque onglet ouvert — et les requêtes qu'Apache s'adresse à lui-même. **Un échec,
lui, est toujours noté**, y compris sur une feuille de style. Pour tout voir le temps d'une
recherche :

```sh
run journal-complet    # tout est noté, y compris le bruit
run up                 # retour au journal normal
```

## Noter depuis le code

Sans rien préparer :

```php
Journal::noter('ce qui vient d\'arriver');
Journal::erreur('ce qui a raté', $exception);
```

La seconde ajoute le nom de l'exception, son message et l'endroit exact.

## Comment c'est monté

| Pièce | Où | Ce qu'elle fait |
| --- | --- | --- |
| Le format des lignes | `docker/apache-journal.conf` | Remplace le format `combined` d'Apache, deux cents caractères par requête dont la moitié répétait le nom du navigateur |
| Les erreurs de PHP | `Dockerfile` | `log_errors=On`, `display_errors=Off` : une erreur imprimée avant les en-têtes empêchait PHP de répondre 500, et l'échec ressortait en 200 |
| Le reste | `src/journal.php` | Chargé avant chaque script (`auto_prepend_file`) : il attrape ce que PHP signale et donne à l'application de quoi noter ses propres faits |

L'heure du journal est celle de l'atelier (`TZ=Europe/Paris` dans l'image). PHP, lui, garde
`date.timezone` en UTC : les dates enregistrées en base ne changent pas.
