# Les scripts du projet

Trois familles, et elles ne se lancent pas dans les mêmes circonstances.

| Dossier | Ce que c'est | Quand on s'en sert |
|---|---|---|
| `init/` | Créer des comptes et des données de départ | À l'installation, et pour se fabriquer un jeu d'essai |
| `migrations/` | Faire évoluer le schéma d'une base **déjà en service** | Quand on met à jour un serveur existant |
| racine | Outils de poste et du conteneur | Au besoin |

---

## `init/` — ce qu'on lance exprès

| Script | Ce qu'il fait |
|---|---|
| `add_user.php` | Crée un compte : nom, prénom, et au choix le rôle, le courriel, le mot de passe. Le rôle s'écrit sous l'un de ses trois noms (`chef-atelier`, « Chef d'atelier », « Gestionnaire »). Sans mot de passe, il en tire un au sort et le montre une fois. |
| `add_test_users.php` | Pose les comptes d'essai des quatre profils. Relançable sans doublon. |
| `add_demo_data.php` | Remplit la base de demandes et de plans de démonstration. Ne touche pas à l'existant. |

```
run admin Dupont Jean --role chef-atelier
run admin Dupont Jean --role "Chef d'atelier"   # le même, dit autrement
run demo                                        # les comptes d'essai et le jeu de démonstration
```

`add_user.php` ne fabrique rien lui-même : il appelle `User::register()`, comme le ferait
l'application. Le format de l'identifiant, le refus des doublons, le refus d'un courriel
qui n'est pas une adresse et le hachage du mot de passe sont décidés à un seul endroit.
La traduction des trois noms d'un rôle, elle, vit dans `Role::depuisLeTexte()`.

---

## `migrations/` — pour une base déjà remplie

**Sur une base neuve, il n'y a rien à lancer ici.** `db/schema.sql` contient déjà le
résultat de toutes ces migrations : 35 tables, 278 colonnes et 54 clés étrangères,
vérifiées identiques à la base en service. Installez le schéma, c'est tout.

Ces scripts ne servent qu'à **rattraper une base créée avant un changement** — typiquement
le serveur de l'atelier, qu'on met à jour sans le vider.

Deux propriétés les rendent maniables :

- **ils sont datés** — le nom porte le jour où le changement est né, donc l'ordre se lit
  sans ouvrir un fichier ;
- **ils sont relançables** — chacun vérifie ce qui existe déjà et le dit (« Colonne X déjà
  là »). Les relancer tous, dans l'ordre, sur une base à jour ne refait pas le travail.

Pour autant, ils ne sont pas tous inoffensifs : neuf d'entre eux réécrivent des données, et
deux en suppriment. La liste est juste en dessous — lisez-la avant de tout lancer sur une
base en service.

### Neuf d'entre elles touchent aux données

Le mot « migration » laisse croire qu'on ne change que la forme des tables. Ce n'est pas
vrai de toutes : celles-ci réécrivent aussi ce qu'elles contiennent, et il vaut mieux
savoir laquelle avant de la lancer sur une base en service.

| Migration | Ce qu'elle écrit |
|---|---|
| `2026-10-07_roles` | Renomme les rôles et **supprime le rôle 4**, qui n'existe plus |
| `2026-10-07_approvisionnement` | Convertit les statuts d'approvisionnement vers la nouvelle liste |
| `2026-10-12_plan_document_pages` | Compte les pages de chaque PDF déjà déposé et les retient |
| `2026-10-12_plan_soudure` | Reprend les contrôles « NOK » en « Non OK » |
| `2026-10-13_plan_soudure_croix` | Ramène ces mêmes contrôles à une croix ou rien |
| `2026-10-13_affaire_charge` | Confie chaque affaire existante à un chargé d'affaire |
| `2026-10-13_evenements` | **Supprime les événements de plus de sept jours** |
| `2026-10-14_plan_numeros_de_ligne` | Relit les plans qui n'ont aucun numéro de ligne — plusieurs secondes par plan |
| `2026-10-27_pv_refonte` | **Fusionne le PV « Conformité » dans « Fin de fabrication »** de la même demande : documents déplacés (lignes et fichiers), commentaires mis bout à bout, état fusionné. Ajoute la catégorie « photo » aux documents |
| `2026-10-28_affaires_vues` | Ajoute `Utilisateur.dernier_evenement_vu` et le cale sur le dernier événement du journal, pour qu'aucune ancienne affaire ne s'annonce comme nouvelle |

Les vingt et une autres ne font qu'ajouter des tables et des colonnes.

Dans le doute, lancez-les tous dans l'ordre alphabétique : la date en tête les range
chronologiquement, et quelques-uns dépendent d'un précédent — `materiel_lignes` suppose
`materiel_documents`.

Elles s'appliquent une par une, dans l'ordre du nom :

```
run migration -- scripts/migrations/2026-10-07_roles.php
run migration -- scripts/migrations/2026-10-07_approvisionnement.php
```

Il n'y a pas de commande qui les lance toutes : le tableau ci-dessus est à lire avant,
et dix d'entre elles touchent aux données.

---

## À la racine

| Script | Ce qu'il fait |
|---|---|
| `app-entrypoint.sh` | Point d'entrée du conteneur web. Lancé par Docker, jamais à la main — le `Dockerfile` le copie à part. |
| `ccpu_lire.php` | Lit un certificat matière en ligne de commande et affiche ce qui a été reconnu. Sert à essayer la lecture sur un nouveau modèle de certificat avant de s'y fier. |
| `start-app.cmd` | Démarre les conteneurs et ouvre le navigateur sur l'application. |
| `count-lines.cmd` / `.ps1` | Compte les lignes de code du projet, par extension. |

---

## Les deux façons de lancer un script, et leur piège

Un script peut venir de deux endroits, et ce n'est pas le même fichier.

Tous les `run` envoient **le fichier de votre copie de travail** — par l'entrée standard
pour `admin`, `demo` et `migration`, par le montage `/tests` pour `test` et `pages`. Ce
que vous venez de modifier part tel quel, sans rien reconstruire.

```
docker.exe compose exec php php /scripts/init/add_user.php
```

Celle-ci, tapée à la main, lit `/scripts` **dans l'image**, que le `Dockerfile` a copié au
moment du build. Un script ajouté ou modifié depuis n'y est pas : il faut un `run rebuild`
pour que l'image rattrape. C'est la différence qui fait dire « ma correction ne change
rien » alors que le fichier est bien à jour sur le disque.
