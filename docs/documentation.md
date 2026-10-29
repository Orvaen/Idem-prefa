# Cette documentation

Elle est écrite en Markdown dans `docs/`, à côté du code qu'elle décrit, et servie par
[VitePress](https://vitepress.dev) 1.6.4 — un générateur de documentation de l'écosystème Vue,
installé par npm.

## La lancer

::: code-group

```sh [Terminal Orvaen]
run docs
```

```sh [docker compose]
docker compose up -d docs
```

```sh [Node sur le poste]
cd docs
npm install
npm run dev
```

:::

Elle répond sur **http://localhost:8082**, en mode développement : chaque fichier enregistré
recharge la page ouverte.

Le service `docs` porte le **profil Compose `docs`** : il ne démarre que si on le demande. Sur
un poste de développement, la ligne `COMPOSE_PROFILES=docs` du fichier `.env` le lance avec le
reste à chaque `run up` ; `run docs` le lance seul. Sur le serveur de l'atelier, qui n'a pas de
`.env`, il ne démarre pas : un serveur de développement n'a rien à faire en production.

Avec Docker, rien n'est à installer sur le poste : le service `docs` de `docker-compose.yml`
tourne dans l'image `node:22-alpine`, installe VitePress au démarrage et garde `node_modules`
dans un volume à lui (`docs_node_modules`) — les binaires compilés pour Linux n'ont rien à faire
dans la copie de travail Windows. Sans Docker, il faut Node 18 ou plus.

## Les scripts npm

Dans `docs/package.json` :

| Script | Ce qu'il fait |
| --- | --- |
| `npm run dev` | Le serveur de développement, sur le port 8082 |
| `npm run build` | Le site statique, dans `docs/.vitepress/dist/` — échoue sur un lien mort |
| `npm run preview` | Sert le site construit, sur le port 8082 |

`run docs-verifier` lance `npm run build` dans le conteneur : c'est la vérification à faire
avant de pousser une modification de la documentation.

## L'arborescence

```
docs/
├── .vitepress/
│   ├── config.mts            la navigation, la barre latérale, la recherche, les libellés
│   └── theme/                le thème par défaut, aux couleurs de l'application
├── public/                   le logo, servi tel quel
├── index.md                  l'accueil
├── guide/                    démarrer et exploiter
├── fonctionnalites/          ce que fait chaque écran, et pour qui
├── technique/                l'architecture, la base, l'API, la sécurité, les essais
├── direction-artistique.md   la DA de l'interface
├── workflow-atelier.md       le modèle des affectations de l'atelier
└── documentation.md          cette page
```

`node_modules/`, `.vitepress/cache/` et `.vitepress/dist/` sont écartés par `.gitignore`.

## Ajouter une page

1. Créer le fichier Markdown dans le bon dossier : `docs/fonctionnalites/ma-page.md`.
2. L'ajouter à la barre latérale, dans `sidebar` de `docs/.vitepress/config.mts` :

   ```ts
   { text: 'Ma page', link: '/fonctionnalites/ma-page' },
   ```

3. Vérifier : `run docs-verifier`.

Les liens entre pages s'écrivent sans extension : `[Le planning](/fonctionnalites/planning)`.

## Ce que VitePress ajoute au Markdown

Des encadrés :

```md
::: tip Le titre
Un conseil.
:::

::: warning
Une précaution.
:::

::: danger
Ce qui casse ou efface.
:::
```

Des onglets de code — comme « Terminal Orvaen / docker compose » sur ces pages :

````md
::: code-group

```sh [Terminal Orvaen]
run up
```

```sh [docker compose]
docker compose up -d
```

:::
````

Et une recherche plein texte, sans service externe : elle indexe toutes les pages à la
construction.

::: warning Les accolades et les balises
VitePress lit le Markdown comme un gabarit Vue : deux accolades ouvrantes, ou une balise HTML
écrite telle quelle dans une phrase, sont interprétées. Écrire les noms de balises et le code
entre accents graves — `` `<dialog>` `` —, où ils restent du texte.
:::

## Le ton

Écrire pour celui qui arrive : ce que fait la chose, comment s'en servir, ce qui peut mal
tourner. Une page par sujet, des tableaux pour ce qui se compare, les commandes exactes dans des
blocs de code. Le pourquoi d'une décision technique a sa place quand il évite de refaire une
erreur — le détail de son histoire reste dans le message de commit.
