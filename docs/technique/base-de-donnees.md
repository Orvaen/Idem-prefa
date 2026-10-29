# Base de données

MySQL 8.4, une base `myapp`, **37 tables** en InnoDB et `utf8mb4`. Le schéma complet d'une
installation neuve est dans `db/schema.sql`.

## Les tables, par domaine

### Comptes et rôles

| Table | Ce qu'elle porte |
| --- | --- |
| `Utilisateur` | Un compte : `identifiant` (la connexion), `password` (bcrypt), `name` (le nom), `prenom`, `email`, `id_role`, `dernier_evenement_vu` |
| `role` | Les quatre rôles — voir [Rôles et droits](/fonctionnalites/roles) |
| `signature_utilisateur` | La signature de chacun, tracée dans les paramètres : ses traits (`dessin`, en JSON), rangés par versions. Celle en cours n'a pas de `date_retrait` ; une signature refaite ou supprimée reste tant qu'un cordon la porte |

### Affaires et demandes

| Table | Ce qu'elle porte |
| --- | --- |
| `affaire` | Un chantier : `nom` unique, `id_charge_affaire` |
| `demande_prefabrication` | Une demande : son demandeur (`idUsers`), son affaire, son statut, l'urgence, les pouces et les heures chiffrées, les contrôles, le revêtement, la passivation, la matière, la décision (`valide_par`, `date_validation`, `commentaire_validation`) et le planning (`date_debut_planifiee`, `date_fin_planifiee`, `planning_revision`) |
| `statut_demande` | 1 En attente, 2 Validée, 3 Refusée |
| `prefa_reference`, `prefa_reference_compteur` | Les références `AA-DP-NNNN`, et leur compteur annuel |
| `type_passivation`, `type_matiere` | Les listes du formulaire, complétées par le choix « Autre » |
| `parametrage_prefabrication` | Une ligne : le temps par pouce ISO |
| `unite_materiel` | Les unités proposées en signalant un manque (Paramètres → Matériel). Un manque garde la sienne en texte : retirer une unité ne réécrit rien |
| `demande_qmos_documents`, `demande_dmos_documents` | Les QMOS et DMOS d'une demande, **en BLOB** |

### Atelier

| Table | Ce qu'elle porte |
| --- | --- |
| `element_atelier` | Un plan d'une demande : `reference` unique dans la demande |
| `element_atelier_document` | Les documents rattachés à un plan, ses pages, ses numéros de ligne par page |
| `affectation_atelier` | Un plan confié à une personne pour un travail (`plan`, `montage`, `soudage`) : `avancement` validé, `revision`. `actif` vaut 1, ou NULL une fois l'affectation remplacée — ce qui garde l'historique sous l'index unique |
| `avancement_atelier` | Les déclarations : `etat` (propose, valide, refuse), les soudures ou les étapes comptées |
| `element_atelier_etapes`, `…_historique` | Les sept étapes d'un plan, en JSON, et leurs révisions |
| `pointage_atelier` | Les anciens pointages d'heures. Rien ne l'écrit plus ; ses lignes servent encore de propositions au chef |

### Plans, soudures, coulées

| Table | Ce qu'elle porte |
| --- | --- |
| `plan_nomenclature` | Une case de coulée : la ligne, la page, la coulée, sa position en fractions de page |
| `plan_soudure` | Un cordon : numéro, soudeur, date, type, RT / VI / PT — VI s'affiche « VT », le mot du plan —, visa (`vise_par`, `date_visa` : qui a validé, et quand ; `id_signature` : sa signature ce jour-là) |
| `demande_ccpu_documents` | Les certificats matière, **en BLOB**, et le résultat de leur lecture |
| `demande_ccpu_coulees` | Les coulées lues : numéro, page, confiance, `confirmee` |

::: warning confirmee n'est pas une validation humaine
`demande_ccpu_coulees.confirmee` veut dire « la lecture a retrouvé ce numéro ailleurs sur la
page ». Une coulée n'est validée qu'une fois choisie par l'encadrement dans la nomenclature du
plan.
:::

### PV

| Table | Ce qu'elle porte |
| --- | --- |
| `pv_prefabrication` | Un PV, unique par demande et par type : `statut` (a_faire, fait, sans_objet), `revision` |
| `pv_document` | Un fichier de PV, sur le disque : `cle`, `categorie` (document ou photo), `taille` |

### Matériel

| Table | Ce qu'elle porte |
| --- | --- |
| `demande_materiel_documents` | Un bon de commande, **en BLOB**, le résultat de sa lecture, son numéro et sa date. `verifie_par` renseigné : la commande est validée et figée |
| `demande_materiel_lignes` | Les articles d'un bon : confiance, `douteuse`, le pointage `recu` |
| `bon_livraison`, `bon_livraison_ligne` | Les commandes fournisseur saisies dans le PV Réception |
| `demande_approvisionnement` | Les manques signalés par l'atelier : `motif`, `statut` (nouvelle, vue, traitee) |

### Événements

| Table | Ce qu'elle porte |
| --- | --- |
| `evenement` | Le journal des faits que le flux en temps réel diffuse : `type`, `id_demande`, `id_acteur`, `id_destinataire`, `donnees` en JSON |

### Les tables qui ne servent plus

`charger_affaier`, `revetements`, `demande_qmos` et `demande_dmos` (un document par demande,
remplacées par les tables `…_documents`) ne sont lues par aucun code. Les retirer demande une
migration.

## Les données de référence

Insérées par `db/schema.sql` — l'application ne tourne pas sans elles :

| Table | Valeurs |
| --- | --- |
| `role` | 1 Administrateur, 2 Gestionnaire, 3 Utilisateur, 5 Demandeur — il n'y a pas de 4 |
| `statut_demande` | 1 En attente, 2 Validée, 3 Refusée |
| `unite_materiel` | u, PCE, m, kg, barre, lot — à compléter dans les paramètres |
| `parametrage_prefabrication` | id 1 : 24 heures par pouce ISO — la journée de l'atelier compte 24 h, c'est une durée de four, pas un horaire de bureau |

## Où vivent les fichiers

| Quoi | Où |
| --- | --- |
| QMOS, DMOS, bons de commande | En base, `MEDIUMBLOB` (16 Mio au plus) |
| Certificats CCPU | En base, `LONGBLOB` |
| Plans et pièces jointes | `src/dashboard/uploads/prefa/<id demande>/<32 hex>-<nom>`, avec leurs aperçus et leurs lectures en cache |
| Documents et photos des PV | `src/dashboard/uploads/pv/<id PV>/<32 hex>-<nom>` |

::: warning Une limite qui ne tombe pas juste
L'application accepte des fichiers de 50 Mo, mais un `MEDIUMBLOB` n'en tient que 16 Mio : un
QMOS, un DMOS ou un bon de commande plus gros que 16 Mio ne s'enregistrera pas.
:::

## Faire évoluer le schéma

1. Écrire une **migration** dans `scripts/migrations/`, nommée `AAAA-MM-JJ_sujet.php` : la date
   en tête range les migrations dans l'ordre.
2. La rendre **relançable** : elle vérifie ce qui existe déjà et le dit (« Colonne X déjà là »).
3. L'appliquer : `run migration -- scripts/migrations/AAAA-MM-JJ_sujet.php`.
4. **Régénérer `db/schema.sql`** — voir [Sauvegarde, mise à jour](/guide/exploitation#regenerer-db-schema-sql).
5. Dire dans `scripts/README.md` si elle réécrit des données.

Il y a aujourd'hui 31 migrations, du 2026-10-07 au 2026-10-28. Sur une base neuve, aucune n'est à
lancer : `db/schema.sql` en porte déjà le résultat.

## Les pièges connus

- **La colonne `` ` QMOS` ``** de `demande_prefabrication` garde son espace initiale : le code la
  lit ainsi. La renommer demande une migration coordonnée avec ses cinq sites d'emploi.
- **`Utilisateur.email`** porte deux index uniques identiques, et `identifiant` aucun : l'unicité
  de l'identifiant est vérifiée par le code, pas par la base.
- **`evenement`** n'a aucune clé étrangère : un événement survit à la suppression de la demande
  ou du compte qu'il cite.
- **Les dates sont en UTC** en base ; l'affichage les convertit en Europe/Paris
  (`prefaFormatDate()`).
