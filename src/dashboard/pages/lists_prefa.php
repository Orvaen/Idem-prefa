<?php

require_once __DIR__ . '/../includes/prefa_context.php';
// return et non exit : cette page est aussi incluse par dashboard.php, qui a encore le
// pied de page à écrire. « return » au premier niveau d'un fichier inclus rend la main à
// celui qui l'inclut, et termine le script quand c'est le navigateur qui l'appelle.
if ($isWorkshopPersonnel) {
    require __DIR__ . '/atelier.php';
    return;
}
require_once __DIR__ . '/../partials/prefa_list_data.php';
require_once __DIR__ . '/../includes/vue/filtres.php';

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
    $pageTitle = 'Mes demandes';
    $pageDescription = 'Vos demandes de préfabrication, rangées par affaire.';
}

if ($isWorkshopChief) {
    // La prise en charge n'existe plus : une demande validée est ouverte à l'atelier, et
    // plusieurs chefs y travaillent.
    $pageDescription = 'Suivez les demandes et préparez les plans de celles qui sont validées.';
}
if ($isWorkshopPersonnel) {
    $pageTitle = 'Mes plans';
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

            <!-- Filtres : une pastille par critère, qui ouvre sa liste à cocher. Le
                 balisage vient de includes/vue/filtres.php ; le rafraîchissement sérialise ce
                 formulaire, il n'a donc rien de particulier à savoir. -->
            <form
                id="prefa-filters"
                class="prefa-filters"
                role="search">

                <!-- La recherche n'est pas un filtre à choix : elle garde son champ, en
                     tête de barre, à l'échelle des barres d'outils. -->
                <div class="filtres-recherche">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input
                        id="prefa-search"
                        type="search"
                        name="q"
                        value="<?= prefaEscape($q) ?>"
                        aria-label="Rechercher une demande"
                        placeholder="N° de demande, affaire…">
                </div>

                <?php
                $barre = '';

                if ($canViewAllPrefa) {
                    $barre .= filtreRendu('user', 'Utilisateur', array_map(
                        static fn (array $u): array => [
                            'id' => (int) $u['id'],
                            'libelle' => trim(($u['prenom'] ?? '') . ' ' . ($u['name'] ?? '')),
                        ],
                        $users
                    ), $userId, ['aucun' => 'Aucun utilisateur à ce nom.']);
                }

                $barre .= filtreRendu('status', 'Statut', array_map(
                    static fn (array $s): array => ['id' => (int) $s['id'], 'libelle' => (string) $s['libelle']],
                    $statuses
                ), $status);

                // L'écran des demandes urgentes est un filtre figé : on ne propose pas de
                // le défaire, sinon l'entrée du menu ne voudrait plus rien dire.
                if ($urgentView) {
                    echo '<input type="hidden" name="view" value="urgent">';
                    echo '<input type="hidden" name="priority[]" value="1">';
                } else {
                    $barre .= filtreRendu('priority', 'Priorité', [
                        ['id' => 1, 'libelle' => 'Urgentes'],
                        ['id' => 0, 'libelle' => 'Normales'],
                    ], $priority);
                }

                $pose = $userId || $status || (!$urgentView && $priority);
                echo filtresBarre($barre, (bool) $pose);
                ?>

            </form>

        <?php endif; ?>


        <!-- Résultats -->
        <div id="prefa-results">

            <?php if ($canViewAllPrefa): ?>

                <span
                    id="prefa-urgent-total"
                    data-count="<?= (int) $urgentPending ?>"
                    hidden></span>

                <?php // Trois fois le même mot : « 0 en attente au total · 0 urgente(s) en
                      // attente · 0 demande(s) affichée(s) ». Les nombres portent le sens, le
                      // reste les sépare — et le « (s) » d'un pluriel qu'on n'ose pas trancher
                      // se lit comme un formulaire administratif.
                      //
                      // Le compte des urgentes prend la teinte des alertes : c'est le seul
                      // des trois sur lequel on agit le jour même. ?>
                <p class="prefa-list-summary">
                    <strong><?= (int) $pendingTotal ?></strong> en attente
                    <span class="prefa-list-summary-point" aria-hidden="true">·</span>
                    <strong class="prefa-list-summary-urgent"><?= (int) $urgentPending ?></strong> urgente<?= $urgentPending > 1 ? 's' : '' ?>
                    <span class="prefa-list-summary-point" aria-hidden="true">·</span>
                    <strong><?= (int) $total ?></strong> affichée<?= $total > 1 ? 's' : '' ?>
                </p>

            <?php else: ?>

                <p class="prefa-list-summary">
                    <strong><?= (int) $total ?></strong> demande<?= $total > 1 ? 's' : '' ?> affichée<?= $total > 1 ? 's' : '' ?>
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

                            <th scope="col" class="prefa-nombre">
                                Pouces
                            </th>

                            <th scope="col" class="prefa-nombre">
                                Temps atelier
                            </th>

                            <th scope="col">
                                Demandeur
                            </th>

                            <?php if ($vueParAffaire): ?>
                                <!-- Rangée par statut, la liste l'annonce dans le titre de
                                     chaque groupe : la colonne ne servirait qu'à le redire. -->
                                <th scope="col">
                                    Statut
                                </th>
                            <?php endif; ?>

                            <th scope="col">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <?php
                    // Neuf colonnes dans les deux rangements : l'affaire disparaît quand elle
                    // coiffe le groupe, le statut quand c'est lui. Posé avant les deux
                    // branches : la ligne « aucune demande » et le volet de détail s'en servent.
                    $colonnes = 9;
                    ?>

                    <?php if (!empty($requests)): ?>

                        <?php
                        // Les demandes arrivent rangées — par affaire chez le demandeur, par
                        // statut ailleurs — et chaque groupe s'annonce une fois, avec son
                        // compte. Sans ce titre, on lirait une liste à plat où rien ne dit où
                        // commence le groupe suivant.
                        $cleGroupe = static fn (array $ligne): string => $vueParAffaire
                            ? trim((string) ($ligne['nom_affaire'] ?? ''))
                            : (string) (int) $ligne['id_statut'];
                        $parGroupe = [];
                        foreach ($requests as $ligne) {
                            $cle = $cleGroupe($ligne);
                            $parGroupe[$cle] = ($parGroupe[$cle] ?? 0) + 1;
                        }
                        // Une phrase sous chaque statut, pour qui découvre l'écran : ce que
                        // le groupe attend, ou ce qu'il est devenu.
                        $aideStatut = [
                            1 => $isAdmin ? 'à examiner, puis valider ou refuser' : 'en attente de validation',
                            2 => 'ouvertes à l’atelier',
                        ];
                        $groupeCourant = null;
                        ?>

                        <?php foreach ($requests as $row): ?>
                            <?php $groupe = $cleGroupe($row); ?>
                            <?php if ($groupe !== $groupeCourant): ?>
                                <?php $groupeCourant = $groupe; ?>
                                <tbody class="prefa-groupe">
                                    <tr>
                                        <th scope="colgroup" colspan="<?= $colonnes ?>">
                                            <?php if ($vueParAffaire): ?>
                                                <span class="prefa-groupe-nom"><?= prefaEscape($groupe !== '' ? $groupe : 'Sans affaire') ?></span>
                                            <?php else: ?>
                                                <span class="prefa-groupe-nom prefa-groupe-statut status-<?= (int) $row['id_statut'] ?>"><?= prefaEscape($row['libelle']) ?></span>
                                            <?php endif; ?>
                                            <span class="prefa-groupe-compte"><?= (int) $parGroupe[$groupe] ?></span>
                                            <?php if (!$vueParAffaire && isset($aideStatut[(int) $row['id_statut']])): ?>
                                                <span class="prefa-groupe-aide"><?= prefaEscape($aideStatut[(int) $row['id_statut']]) ?></span>
                                            <?php endif; ?>
                                        </th>
                                    </tr>
                                </tbody>
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
