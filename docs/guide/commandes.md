# Les commandes run

Le terminal Orvaen lit `terminal.json`, à la racine du projet : chaque commande y est écrite
une fois pour toutes. `run` tout seul en donne la liste ; `run <nom>` la lance.

Chaque commande tourne dans son propre shell : les redirections `<` et `>` s'y comportent
comme à la main. Toutes appellent `docker.exe` et non `docker`, pour atteindre Docker Desktop
plutôt que le moteur léger du terminal.

## La stack

| Commande | Ce qu'elle fait | Équivalent `docker compose` |
| --- | --- | --- |
| `run up` | Monte tout, attend que la base réponde, donne les adresses | `docker compose up -d` |
| `run down` | Arrête tout, **sans toucher aux données** | `docker compose down` |
| `run rebuild` | Reconstruit l'image PHP puis remonte — après un changement du `Dockerfile` ou de `scripts/` | `docker compose up -d --build` |
| `run wait` | Attend que MySQL accepte les requêtes | — |
| `run etat` | Les conteneurs qui tournent, et le mode (dev ou prod) | `docker compose ps` |
| `run logs` | Suit le journal d'Apache et de PHP (Ctrl+C pour sortir) | `docker compose logs -f --tail=50 php` |

## Le mode

| Commande | Ce qu'elle fait |
| --- | --- |
| `run dev` | Remonte le conteneur web **en développement**, le temps de cette machine |
| `run prod` | Remonte le conteneur web **en production** |
| `run journal-complet` | Remonte le conteneur web en notant tout dans le journal, y compris le bruit |

Ces trois-là l'emportent sur le fichier `.env`, le temps d'un essai ; `run up` revient à ce
qu'il dit. Voir [Développement et production](/guide/modes).

## La base

| Commande | Ce qu'elle fait |
| --- | --- |
| `run db` | Ouvre un client MySQL sur la base |
| `run schema` | Crée les tables — **sur une base vide seulement** : sur une base remplie, les données de référence feraient des doublons |
| `run dump` | Sauvegarde la base dans `db/dump.sql`, écarté du dépôt par `.gitignore` |
| `run migration -- <fichier>` | Applique une migration — le fichier de la copie de travail, pas celui de l'image |
| `run clean` | **Remise à zéro** : détruit la base et les fichiers déposés, puis recrée les tables |

```sh
run migration -- scripts/migrations/2026-10-07_prefa_references.php
```

::: danger run clean efface tout
La base, les comptes, les demandes et tous les fichiers déposés. Il recrée ensuite des tables
vides. À ne jamais lancer sur le serveur de l'atelier.
:::

## Les comptes et les données d'essai {#admin}

| Commande | Ce qu'elle fait |
| --- | --- |
| `run admin <Nom> <Prénom> [rôle] [courriel]` | Crée un compte, et montre son mot de passe une fois |
| `run demo` | Pose les comptes d'essai des quatre profils et un jeu de demandes |

```sh
run admin Dupont Jean                                    # chargé de production, par défaut
run admin Dupont Jean --role chef-atelier
run admin Dupont Jean "chef d'atelier"                   # le métier, entre guillemets
run admin Dupont Jean --role chef-atelier --courriel jean.dupont@eitc-be.com
run admin Dupont Jean --mdp "celui que je veux"
```

Le rôle s'écrit sous n'importe lequel de ses trois noms ; accents, majuscules et pluriel sont
indifférents. Un rôle inconnu n'écrit rien et affiche ce tableau :

| Dans le journal | Le métier | En base |
| --- | --- | --- |
| `charge-production` | Chargé de production | Administrateur |
| `chef-atelier` | Chef d'atelier | Gestionnaire |
| `personnel-atelier` | Personnel atelier | Utilisateur |
| `charge-affaire` | Chargé d'affaire | Demandeur |

::: warning Les guillemets comptent
Le rôle est le troisième mot : un nom, un prénom ou un rôle en deux mots sans guillemets laisse
son second mot filer dans l'argument suivant, le courriel. Un courriel qui n'est pas une adresse
est refusé, et rien n'est écrit.
:::

L'équivalent sans terminal, avec tous les arguments :

```sh
docker compose exec -T php php -- --nom Dupont --prenom Jean --role chef-atelier --courriel jean.dupont@eitc-be.com < scripts/init/add_user.php
```

## Les essais

| Commande | Ce qu'elle fait |
| --- | --- |
| `run test` | Tous les essais, et s'arrête au premier rouge. Ils lisent la base : `run demo` d'abord |
| `run pages` | Dessine les six écrans principaux et vérifie qu'aucun ne part en erreur |

Le détail est dans [Les essais automatiques](/technique/essais).

## La documentation

| Commande | Ce qu'elle fait |
| --- | --- |
| `run docs` | Lance cette documentation sur http://localhost:8082 — `run up` la lance aussi, sur un poste dont le `.env` porte `COMPOSE_PROFILES=docs` |
| `run docs-verifier` | La construit comme pour la publier : échoue sur un lien mort ou une page cassée |

## Pourquoi « le fichier du disque, pas celui de l'image »

Le `Dockerfile` **copie** `scripts/` dans l'image au moment de la construction. Une commande
tapée à la main comme `docker compose exec php php /scripts/init/add_user.php` lit donc la copie
figée au dernier `run rebuild` : un script modifié depuis n'y est pas, et l'on croit que « la
correction ne change rien ».

Les commandes `run admin`, `run demo` et `run migration` envoient au contraire le fichier de la
copie de travail par l'entrée standard (`php < scripts/…`) : ce qu'on vient de modifier part tel
quel. Les essais, eux, sont montés en lecture seule dans `/tests`.
