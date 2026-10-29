# Développement et production

L'application tourne dans l'un de deux modes, selon la variable `APP_ENV` lue au démarrage du
conteneur web. **Le défaut est la production** : `docker-compose.yml` porte
`APP_ENV: ${APP_ENV:-prod}`, donc une machine qui démarre sans rien de plus tourne en
production.

## Monter en production

Rien à faire, c'est le défaut : `run up` suffit. Le serveur de l'atelier n'a pas de fichier
`.env`.

## Monter en développement

Poser un fichier `.env` à côté de `docker-compose.yml`. Il est ignoré par git : il ne peut donc
pas partir en production par mégarde.

```sh
echo APP_ENV=dev > .env
run up
```

## Basculer le temps d'un essai

`run dev` et `run prod` posent la variable le temps de remonter le conteneur web. Elle
l'emporte sur le fichier `.env`, ce qui permet d'essayer l'autre mode sans rien modifier — et
`run up` revient à ce que dit le fichier.

```sh
run prod      # ce poste, en production, le temps d'un essai
run up        # retour au mode du fichier .env
```

::: warning Changer de mode déconnecte
La variable n'est lue qu'au démarrage du conteneur : changer de mode le recrée, et les sessions
ouvertes tombent.
:::

## Savoir où l'on est

| Où | Comment |
| --- | --- |
| Dans l'application | Un repère **dev** à côté du logo, dans la barre du haut. Il n'apparaît qu'en développement. |
| Dans le terminal | `run etat` affiche la valeur d'`APP_ENV`. |
| Dans le journal | La première ligne du démarrage l'annonce : `** 09:11:58 app  démarrage en mode dev`. |

## Ce que le développement ouvre

Une seule chose : **prendre la place de n'importe quel compte, sans son mot de passe**. Depuis
le menu des comptes (en haut à droite), section « Développement », on essaie les quatre profils
sans se déconnecter. Son propre compte reste dans la liste des comptes connectés : on y revient
d'un clic.

En production, l'action qui le permet répond `404` à tout le monde, y compris à un visiteur non
connecté — rien n'indique qu'elle existe. La liste des comptes n'est même pas lue en base.

## Le journal complet

Par défaut, le journal laisse de côté ce qui ne dit rien : les fichiers statiques, le flux
d'événements — qui rend la main toutes les minutes pour chaque onglet ouvert — et les requêtes
qu'Apache s'adresse à lui-même. Pour tout voir le temps d'une recherche :

```sh
run journal-complet    # tout est noté, y compris le bruit
run up                 # retour au journal normal
```

Un échec, lui, est toujours noté. Voir [Le journal](/technique/journal).
