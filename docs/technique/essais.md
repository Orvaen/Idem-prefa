# Les essais automatiques

```sh
run test      # tous les essais, arrêt au premier rouge
run pages     # seulement le rendu des six écrans principaux
```

Ce sont des scripts PHP, lancés dans le conteneur web : `tests/` y est monté en lecture seule sur
`/tests`. Un essai qui échoue lève une exception — code de sortie 255 — ; un essai qui passe
écrit une ligne qui finit par `: OK`.

```sh
docker compose exec -T php php /tests/roles.php
```

## Ce que chacun vérifie

| Essai | Ce qu'il vérifie | Ce qu'il lui faut |
| --- | --- | --- |
| `roles.php` | Les trois noms de chaque rôle, les variantes saisies, les refus, l'absence du rôle 4 | Rien |
| `encodage.php` | Qu'aucun texte n'est encodé deux fois, dans 70 colonnes — en lecture seule | Le schéma |
| `dashboard_vues.php` | Que chaque entrée du menu mène à une page qui existe, que ses filtres se lisent, qu'il y a une seule entrée par défaut, et le repli sur l'accueil | Un compte, s'il en existe |
| `api_demandes.php` | La visibilité par rôle, les filtres, la recherche, la pagination, les décisions et leurs événements, les suppressions, les réponses 400, 403, 404, 405 et 409 | **Rien** : il crée ses comptes et ses demandes dans une transaction, puis l'annule |
| `prefa_references.php` | La numérotation annuelle, le passage d'année, l'unicité, le non-réemploi d'une référence supprimée | Le schéma |
| `atelier_plans.php` | La création et la modification d'un plan, l'unicité de son nom, les suppressions en cascade | Une demande et un compte |
| `avancement_etapes.php` | Le calcul par étapes et par cordons, la déclaration, la validation | Une demande validée et deux comptes |
| `prefa_pages.php <écran>` | Le rendu sans avertissement de `plans_iso`, `lists_prefa`, `planning`, `create_prefa`, `atelier`, `soudures`, et quelques contrôles du balisage | Les données de démonstration |

::: tip Sur une base vide
Seuls `roles`, `encodage`, `dashboard_vues`, `api_demandes` et `prefa_references` passent :
les autres lisent les données en place. `run demo` d'abord.
:::

## Écrire un essai

1. Un fichier dans `tests/`, qui inclut ce dont il a besoin :

   ```php
   <?php
   $root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
   require_once $root . '/db.php';
   require_once $root . '/modeles/autoload.php';

   function verifier(bool $condition, string $message): void
   {
       if (!$condition) throw new RuntimeException($message);
   }

   verifier(Role::depuisLeTexte('chef d\'atelier') === Role::ChefDAtelier, 'Le métier doit se reconnaître');

   echo 'Ce que l\'essai vérifie : OK' . PHP_EOL;
   ```

2. **Ne rien laisser derrière soi** : écrire dans une transaction et l'annuler à la fin, comme
   `api_demandes.php`.
3. L'ajouter à la liste `test` de `terminal.json`.
