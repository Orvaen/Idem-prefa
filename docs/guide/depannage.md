# Dépannage

Les ennuis déjà rencontrés, et ce qui les règle. Le premier réflexe, presque toujours :

```sh
run logs | grep "!!"
```

Une ligne marquée `!!` est un échec — un code 400 ou plus, un avertissement ou une erreur PHP —,
avec le fichier et la ligne en cause. Voir [Le journal](/technique/journal).

## Au lancement

### « Docker n'est pas installé » dans le terminal Orvaen

Le terminal embarque son propre moteur Docker léger, appelé par `docker`. Sur un poste équipé
de Docker Desktop, écrire **`docker.exe compose …`** — c'est ce que font toutes les commandes
`run`. Vérifier aussi que Docker Desktop est démarré.

### Un port est déjà pris

`run up` échoue avec *port is already allocated* : un autre programme occupe 8080, 8081 ou
8082.

```powershell
netstat -ano | findstr :8080
```

La dernière colonne est le numéro du processus. Le fermer, ou changer le port à gauche dans
`docker-compose.yml` (`"8090:80"` par exemple).

### La première construction est longue

C'est normal : elle télécharge les images et installe Poppler et Tesseract. Les suivantes
réutilisent le cache.

## À l'écran

### L'écran reste blanc, ou rien ne réagit

Les pages chargent htmx depuis un CDN : sans internet sur le **poste**, le tableau de
bord ne s'anime pas. Ouvrir les outils de développement du navigateur (F12), onglet Console :
une erreur de chargement de `htmx.js` le confirme.

### On revient sans cesse à l'écran de connexion

La session a expiré — 8 heures d'inactivité — ou le conteneur web a été recréé (`run down`,
`run rebuild`, changement de mode) : les sessions vivent dedans. Se reconnecter.

### Une modification de CSS ou de JS ne se voit pas

Les feuilles et les scripts portent une empreinte (`?v=…`) calculée sur le fichier : une
modification change l'adresse. Si l'écran garde l'ancienne version, c'est que la **page** est
restée en cache : recharger avec `Ctrl+F5`.

## Après une mise à jour

### Une action répond 500 depuis la mise à jour

Presque toujours une **migration oubliée** : le code écrit une colonne que la base n'a pas
encore. Le journal le dit (`PDOException : SQLSTATE[42S22] … Unknown column`). Appliquer les
migrations manquantes — voir [Mettre à jour un serveur](/guide/exploitation#mettre-a-jour-un-serveur).

### La lecture d'un certificat ou d'un bon échoue

Poppler et Tesseract sont installés **dans l'image** : si elle n'a pas été reconstruite depuis
leur ajout, ils n'y sont pas. `run rebuild`, puis vérifier :

```sh
docker compose exec php tesseract --version
```

### Un script de scripts/ modifié ne change rien

Le `Dockerfile` copie `scripts/` dans l'image : une commande tapée à la main lit la copie figée.
Passer par `run admin`, `run demo` ou `run migration`, qui envoient le fichier du disque — ou
`run rebuild`.

## Les essais

### « Aucune demande », « A request and a user are needed »

Les essais lisent la base en place : sur une base vide, ils n'ont rien à vérifier. `run demo`
d'abord. Seul `tests/api_demandes.php` fabrique ses propres données, et les retire ensuite.

### « No administrator available for the rendering check »

L'essai des écrans `atelier` et `soudures` cherche un compte du personnel d'atelier : `run demo`
en pose deux.

## La documentation

### http://localhost:8082 ne répond pas

```sh
run docs
docker compose logs --tail=30 docs
```

Au premier lancement, le conteneur installe VitePress (`npm install`) : une vingtaine de
secondes. Si internet manquait à ce moment-là, le relancer : `docker compose restart docs`.

### Une page modifiée ne se recharge pas

`docs/` est monté depuis Windows : VitePress va relire les fichiers toutes les 300 ms
(`usePolling` dans `docs/.vitepress/config.mts`). Si rien ne bouge, `docker compose restart docs`.
