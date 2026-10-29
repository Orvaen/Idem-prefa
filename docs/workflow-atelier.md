# Affectations atelier par élément

Les tables concernées — `element_atelier` et `affectation_atelier` — sont dans `db/schema.sql`, qui porte le schéma complet. Les trois fichiers `.sql` que ce paragraphe citait n’ont jamais existé sous ce nom.

## Rôles

Le créateur reste `demande_prefabrication.idUsers`. Seul le chargé d’affaire (Demandeur) dépose une demande ; l’administrateur — le chargé de production — valide, refuse, modifie et planifie. Une demande validée est ouverte à l’atelier : le chef y crée les éléments et les affecte, sans étape de prise en charge. Le rôle Utilisateur / Personnel atelier consulte ses éléments attribués et met à jour leur exécution.

## Modèle

`element_atelier` identifie un objet concret d’une demande : un plan ISO, une tuyauterie ou un suivi. Il contient `id`, `id_demande`, `reference`, `libelle`, `cree_par` et `date_creation`. Le chef crée un élément nommé et lui rattache les fichiers existants de la demande. La référence est unique dans une demande.

`element_atelier_document` rattache explicitement les documents accessibles à un élément. Les plans actuels sont des fichiers sur disque, identifiés par la demande et une clé de fichier ; QMOS et DMOS possèdent déjà des identifiants en base. Les liens doivent être vérifiés côté serveur, et les identifiants de fichiers ne doivent jamais permettre de sortir du dossier de la demande.

`affectation_atelier` contient `id`, `id_element`, `id_utilisateur`, `type_affectation` (`plan`, `montage`, `soudage`), `affecte_par`, `date_affectation`, `actif`, `date_fin_affectation`, `avancement`, `commentaire` et `revision`. L’élément détermine la demande ; `id_element` référence une seule table avec une clé étrangère. Le statut est dérivé de l’avancement : 0 % à commencer, 1–99 % en cours, 100 % terminé. La révision empêche l’écrasement d’une mise à jour plus récente.

Une contrainte unique autorise une seule affectation active pour chaque couple élément / type de travail. `actif = 1` marque l’affectation en cours ; `actif = NULL` marque les anciennes affectations, ce qui permet d’en conserver plusieurs. Réaffecter au même utilisateur ne remet pas son avancement à zéro.

Plusieurs éléments d’une demande peuvent être distribués à différentes personnes. Des travaux différents sur le même élément peuvent aussi être confiés à des personnes différentes. Le changement d’affectation doit conserver l’historique du travail effectué par chaque utilisateur.

La prise en charge n’existe plus : l’opération `take` de `save_atelier.php` répond `410`. Les colonnes `pris_en_charge_par` et `date_prise_en_charge` restent en base, sans usage.

## Visibilité

Le personnel atelier obtient une liste de ses affectations, avec l’élément, le travail demandé et les documents rattachés. L’accès à une affectation ne donne pas accès à tous les fichiers ni aux autres affectations de la demande. Les contrôles doivent s’appliquer à la liste, au détail, au téléchargement et aux écritures.

Les demandeurs continuent de consulter leurs demandes et l’administrateur conserve la gestion globale. Le chef consulte les demandes validées et ses propres demandes non validées.

## Avancement et pointages

Les mises à jour d’avancement référencent l’affectation et enregistrent leur auteur et leur date dans `avancement_atelier`. L’atelier ne saisit pas de pourcentage : il se calcule (`includes/atelier/avancement.php`).

- Le suivi soudage d’un plan qui porte une liste de soudures se mesure aux cordons : renseignés sur prévus. Enregistrer ses cordons dans « Mes soudures » déclare le chiffre.
- Tous les autres travaux — montage, plan / ISO, et le suivi soudage d’un plan sans liste — se mesurent aux étapes du plan : celles qui sont « OK » sur celles qui s’appliquent. Une étape « RAS » ne s’applique pas et sort du total ; « Non OK » et une étape vide restent à faire. Enregistrer les étapes déclare le chiffre pour chaque travail du plan qui s’y mesure.

Le chiffre calculé part en déclaration, que le chef d’atelier valide ou refuse ; il est posé d’office quand c’est le chef qui a changé les étapes. Une déclaration retient le compte qui l’a produite — « 4 étapes sur 6 », « 7 soudures sur 12 » — figé au jour où elle est faite. Le chef garde la main sur un pourcentage direct, comme autorité. Le suivi soudage possède sa propre affectation, son avancement et son compte rendu.

Les pointages référencent l’affectation et l’utilisateur, avec date de travail non future et durée positive en minutes. Le total journalier ne peut pas dépasser 1 440 minutes. Une clé de saisie empêche le double enregistrement lors d’un renvoi du même formulaire. La vue personnelle conserve les 100 derniers pointages, même après une réaffectation.

## Transition

La colonne historique `id_personnel_atelier` et ses données sont conservées. Une ancienne affectation globale n’est pas convertie automatiquement en affectation de tous les plans : les fichiers peuvent inclure des pièces jointes générales. Le détail affiche ces anciennes affectations au chef pour qu’il les répartisse explicitement. Elles ne donnent plus accès à la demande entière dans la nouvelle vue du personnel.

La mise en service couvre la migration, l’UI du chef, la liste du personnel et les autorisations des documents. L’ancien endpoint `assign_workshop.php` retourne 410 et ne crée plus d’affectation globale.

## Vérifications automatisées

Deux essais couvrent ce modèle :

- `tests/atelier_plans.php` — la création et la modification d’un élément, l’unicité de sa référence dans la demande, les suppressions en cascade ;
- `tests/avancement_etapes.php` — le calcul de l’avancement par étapes et par cordons, la déclaration et sa validation par le chef.

Les points ci-dessous ne sont **pas** couverts par un essai automatique, et se vérifient à la main avec les comptes d’essai (`run demo`) :

- Deux utilisateurs affectés à deux plans différents d’une même demande ne voient que leurs éléments et documents respectifs.
- Une affectation de soudage ne permet pas de modifier un montage confié à quelqu’un d’autre.
- Une réaffectation retire l’accès à l’ancien utilisateur et conserve son historique.
- Le personnel ne crée, ne valide et ne planifie aucune demande.
