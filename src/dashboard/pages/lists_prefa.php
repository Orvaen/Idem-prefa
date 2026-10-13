<?php

require_once __DIR__ . '/../includes/prefa_context.php';
if ($isWorkshopPersonnel) {
    require __DIR__ . '/atelier.php';
    exit;
}
require_once __DIR__ . '/../partials/prefa_list_data.php';

// Les chefs d'atelier ne changent pas d'une ligne à l'autre : une seule requête,
// et seulement pour l'administrateur, qui est le seul à désigner quelqu'un d'autre.
$chiefs = $isAdmin
    ? $db->query("SELECT id, TRIM(CONCAT(prenom, ' ', name)) AS nom FROM Utilisateur WHERE id_role = 2 ORDER BY prenom, name, id")->fetchAll()
    : [];

/*
|--------------------------------------------------------------------------
| Valeurs par défaut
|--------------------------------------------------------------------------
*/

$partial = $partial ?? false;
$pendingTotal = $pendingTotal ?? 0;
$urgentPending = $urgentPending ?? 0;
$total = $total ?? 0;

$q = $q ?? '';
$userId = $userId ?? '';
$status = $status ?? '';
$priority = $priority ?? '';

$users = $users ?? [];
$statuses = $statuses ?? [];
$requests = $requests ?? [];

/*
|--------------------------------------------------------------------------
| Titre et description
|--------------------------------------------------------------------------
*/

if ($urgentView) {
    $pageTitle = 'Demandes urgentes';
    $pageDescription = 'Traitez en priorité les demandes signalées comme urgentes.';
} elseif ($canViewAllPrefa) {
    $pageTitle = 'Demandes de préfabrication';
    $pageDescription = 'Vérifiez les demandes en attente avant de les valider ou de les refuser.';
} else {
    $pageTitle = 'Mes affaires';
    $pageDescription = 'Vos chantiers et les demandes de préfabrication de chacun.';
}

if ($isWorkshopChief) {
    $pageDescription = 'Suivez vos demandes et prenez en charge les demandes validées pour les affecter à un utilisateur atelier.';
}
if ($isWorkshopPersonnel) {
    $pageTitle = 'Mes affectations';
    $pageDescription = 'Consultez les demandes validées qui vous sont affectées.';
}

?>

<?php if (!$partial): ?>

    <section class="page prefa-page prefa-list">

        <div class="prefa-shell">

            <!-- En-tête -->
            <div class="prefa-heading prefa-list-heading">

                <div>
                    <h1><?= prefaEscape($pageTitle) ?></h1>
                    <p><?= prefaEscape($pageDescription) ?></p>
                </div>

                <button type="button" id="refresh-prefa">

                    <svg
                        class="icon"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.75"
                        stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>

                    Actualiser

                </button>

            </div>

            <!-- Filtres -->
            <form
                id="prefa-filters"
                class="prefa-filters"
                role="search">

                <!-- Recherche -->
                <div class="floating-field">

                    <label for="prefa-search">
                        Rechercher
                    </label>

                    <input
                        id="prefa-search"
                        class="floating-control"
                        type="search"
                        name="q"
                        value="<?= prefaEscape($q) ?>"
                        placeholder="N° de demande">

                </div>


                <?php if ($canViewAllPrefa): ?>

                    <!-- Utilisateur -->
                    <div class="floating-field floating-always">

                        <label for="prefa-filter-user">
                            Utilisateur
                        </label>

                        <select
                            id="prefa-filter-user"
                            class="floating-control"
                            name="user">

                            <option value="">
                                Tous
                            </option>

                            <?php foreach ($users as $user): ?>

                                <?php
                                $userName = trim(
                                    ($user['prenom'] ?? '') . ' ' .
                                        ($user['name'] ?? '')
                                );
                                ?>

                                <option
                                    value="<?= (int) $user['id'] ?>"
                                    <?= (int) $userId === (int) $user['id']
                                        ? 'selected'
                                        : '' ?>>
                                    <?= prefaEscape($userName) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    

                <?php endif; ?>


                <!-- Statut -->
                <div class="floating-field floating-always">

                    <label for="prefa-filter-status">
                        Statut
                    </label>

                    <select
                        id="prefa-filter-status"
                        class="floating-control"
                        name="status">

                        <option value="">
                            Tous
                        </option>

                        <?php foreach ($statuses as $item): ?>

                            <option
                                value="<?= (int) $item['id'] ?>"
                                <?= $status === (string) $item['id']
                                    ? 'selected'
                                    : '' ?>>
                                <?= prefaEscape($item['libelle']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <?php if ($urgentView): ?>

                    <input
                        type="hidden"
                        name="view"
                        value="urgent">

                    <input
                        type="hidden"
                        name="priority"
                        value="1">

                <?php else: ?>

                    <!-- Priorité -->
                    <div class="floating-field floating-always">

                        <label for="prefa-filter-priority">
                            Priorité
                        </label>

                        <select
                            id="prefa-filter-priority"
                            class="floating-control"
                            name="priority">

                            <option value="">
                                Toutes
                            </option>

                            <option
                                value="1"
                                <?= $priority === '1'
                                    ? 'selected'
                                    : '' ?>>
                                Urgentes
                            </option>

                            <option
                                value="0"
                                <?= $priority === '0'
                                    ? 'selected'
                                    : '' ?>>
                                Normales
                            </option>

                        </select>

                    </div>

                <?php endif; ?>

            </form>

        <?php endif; ?>


        <!-- Résultats -->
        <div id="prefa-results">

            <?php if ($canViewAllPrefa): ?>

                <span
                    id="prefa-urgent-total"
                    data-count="<?= (int) $urgentPending ?>"
                    hidden></span>

                <p class="prefa-list-summary">

                    <strong>
                        <?= (int) $pendingTotal ?> en attente au total
                    </strong>

                    · <?= (int) $urgentPending ?> urgente(s) en attente

                    · <?= (int) $total ?> demande(s) affichée(s)

                </p>

            <?php else: ?>

                <p class="prefa-list-summary">
                    <?= (int) $total ?> demande(s) affichée(s)
                </p>

            <?php endif; ?>


            <!-- Tableau -->
            <div
                class="prefa-table-scroll"
                role="region"
                aria-label="Liste des demandes"
                tabindex="0">

                <table class="prefa-table">

                    <thead>

                        <tr>

                            <th scope="col">
                                Demande n°
                            </th>

                            <?php if (!$vueParAffaire): ?>
                                <!-- Rangée sous le nom de son affaire, une demande n'a pas
                                     besoin de le répéter à chaque ligne. -->
                                <th scope="col">
                                    Affaire
                                </th>
                            <?php endif; ?>

                            <th scope="col">
                                Création
                            </th>

                            <th scope="col">
                                Fin prévue
                            </th>

                            <th scope="col">
                                Priorité
                            </th>

                            <th scope="col">
                                Pouces
                            </th>

                            <th scope="col">
                                Temps atelier
                            </th>

                            <th scope="col">
                                Demandeur
                            </th>

                            <th scope="col">
                                Statut
                            </th>

                            <th scope="col">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <?php
                    // Une colonne de moins quand l'affaire coiffe déjà ses demandes. Posé
                    // avant les deux branches : la ligne « aucune demande » s'en sert aussi,
                    // et sans valeur son colspan retombait à une seule colonne.
                    $colonnes = $vueParAffaire ? 9 : 10;
                    ?>

                    <?php if (!empty($requests)): ?>

                        <?php
                        // Une affaire porte plusieurs demandes : chez le demandeur, elles
                        // se rangent sous le nom de leur chantier, annoncé une fois. Sans
                        // ce titre, « Mes affaires » montrerait une liste de demandes.
                        $affaireCourante = null;
                        $parAffaire = [];
                        if ($vueParAffaire) {
                            foreach ($requests as $ligne) {
                                $nom = trim((string) ($ligne['nom_affaire'] ?? ''));
                                $parAffaire[$nom] = ($parAffaire[$nom] ?? 0) + 1;
                            }
                        }
                        ?>

                        <?php foreach ($requests as $row): ?>

                            <?php if ($vueParAffaire): ?>
                                <?php $nomAffaire = trim((string) ($row['nom_affaire'] ?? '')); ?>
                                <?php if ($nomAffaire !== $affaireCourante): ?>
                                    <?php $affaireCourante = $nomAffaire; ?>
                                    <tbody class="prefa-affaire-groupe">
                                        <tr>
                                            <th colspan="<?= $colonnes ?>" scope="colgroup">
                                                <?= prefaEscape($nomAffaire !== '' ? $nomAffaire : 'Sans affaire') ?>
                                                <span><?= $parAffaire[$nomAffaire] ?> demande<?= $parAffaire[$nomAffaire] > 1 ? 's' : '' ?></span>
                                            </th>
                                        </tr>
                                    </tbody>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php
                            require __DIR__
                                . '/../partials/prefa_list_row.php';
                            ?>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tbody>

                            <tr>

                                <td
                                    class="prefa-table-empty"
                                    colspan="<?= $colonnes ?>">
                                    Aucune demande trouvée.
                                </td>

                            </tr>

                        </tbody>

                    <?php endif; ?>

                </table>

            </div>

        </div>


        <?php if (!$partial): ?>

        </div>

    </section>

<?php endif; ?>
