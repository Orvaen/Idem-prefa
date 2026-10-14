<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/prefa_workshop.php';
require_once __DIR__ . '/../includes/atelier.php';
require_once __DIR__ . '/../includes/ccpu_demande.php';
require_once __DIR__ . '/../includes/commandes.php';
require_once __DIR__ . '/../includes/materiel_bon.php';
require_once __DIR__ . '/../includes/prefa_documents.php';

if (!$canManageWorkshop) {
    prefaError(403, 'Cette page est réservée au chef d’atelier et à l’administrateur.');
}

$workshopUsers = prefaWorkshopPersonnel($db);
// L'administrateur désigne le responsable ; la liste ne change pas d'une ligne à l'autre.
$chiefs = $isAdmin
    ? $db->query("SELECT id, TRIM(CONCAT(prenom, ' ', name)) AS nom FROM Utilisateur WHERE id_role = 2 ORDER BY prenom, name, id")->fetchAll()
    : [];

// Récupérer toutes les demandes validées (statut = 2)
$stmt = $db->query("
    SELECT d.*, s.libelle, u.name, u.prenom,
           TRIM(CONCAT(taker.prenom, ' ', taker.name)) AS prise_en_charge_nom,
           p.libelle AS passivation_libelle, m.libelle AS matiere_libelle
    FROM demande_prefabrication d
    JOIN statut_demande s ON s.id = d.id_statut
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    LEFT JOIN Utilisateur taker ON taker.id = d.pris_en_charge_par
    LEFT JOIN type_passivation p ON p.id = d.id_passivation
    LEFT JOIN type_matiere m ON m.id = d.id_matiere
    WHERE d.id_statut = 2
    ORDER BY d.urgent DESC, d.date_livraison_prevue ASC, d.id DESC
");
$validatedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Préparer les pièces jointes, QMOS et DMOS en bloc
$attachmentsByRequest = [];
$qmosByRequest = [];
$dmosByRequest = [];
$materielByRequest = [];
$ccpuByRequest = [];
$commandesByRequest = [];

if ($validatedRequests) {
    $requestIds = array_column($validatedRequests, 'id');
    foreach ($requestIds as $rId) {
        $attachmentsByRequest[$rId] = prefaAttachments((int) $rId);
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    $qmosQuery = $db->prepare("SELECT id, id_demande, nom FROM demande_qmos_documents WHERE id_demande IN ($placeholders) ORDER BY id");
    $qmosQuery->execute($requestIds);
    foreach ($qmosQuery->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $qmosByRequest[$doc['id_demande']][] = $doc;
    }

    $dmosQuery = $db->prepare("SELECT id, id_demande, nom FROM demande_dmos_documents WHERE id_demande IN ($placeholders) ORDER BY id");
    $dmosQuery->execute($requestIds);
    foreach ($dmosQuery->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $dmosByRequest[$doc['id_demande']][] = $doc;
    }

    $materielByRequest = materielParDemandes($db, $requestIds);

    $ccpuByRequest = ccpuParDemandes($db, $requestIds);

    // Le matériel commandé ne s'affiche que dans la fiche de ceux qui tiennent les PV :
    // inutile d'aller le chercher pour les autres.
    if ($canFillPv) $commandesByRequest = commandesParDemandes($db, $requestIds);
}
// Nombre de plans par demande
$planCounts = [];
if ($validatedRequests) {
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $countStmt = $db->prepare("
        SELECT id_demande, COUNT(*) AS nb_plans
        FROM element_atelier
        WHERE id_demande IN ($placeholders)
        GROUP BY id_demande
    ");
    $countStmt->execute($requestIds);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $planCounts[(int) $c['id_demande']] = (int) $c['nb_plans'];
    }
}

$totalValidated = count($validatedRequests);
$isPlansIsoPage = true;
$toTakeCharge = count(array_filter($validatedRequests, fn($r) => ($planCounts[(int) $r['id']] ?? 0) === 0));
$totalPlans = array_sum($planCounts);
?>

<section class="page prefa-page prefa-list plans-iso-page" id="plans-iso-page" aria-labelledby="plans-iso-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="plans-iso-title">Plans / ISO & Affectations</h1>
                <p>Préparez les plans et ISO, rattachez les documents techniques et organisez les affectations.</p>
            </div>
            <button type="button" id="refresh-plans-iso">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                </svg>
                Actualiser
            </button>
        </header>

        <div class="prefa-filters" role="search">
            <div class="floating-field">
                <label for="plans-iso-filter-search">Rechercher</label>
                <input id="plans-iso-filter-search" class="floating-control" type="search" placeholder="N° de demande, affaire ou demandeur">
            </div>
        </div>

        <?php if (!$validatedRequests): ?>
            <p class="prefa-table-empty plans-iso-empty">Aucune demande validée pour le moment.</p>
        <?php else: ?>
            <div class="prefa-table-scroll" role="region" aria-label="Plans et affectations des demandes" tabindex="0">
                <table class="prefa-table plans-iso-table" id="plans-iso-list">
                    <thead><tr>
                        <th scope="col">Demande n°</th>
                        <th scope="col">Nom de la demande</th>
                        <th scope="col">Demandeur</th>
                        <th scope="col">Date limite de livraison</th>
                        <th scope="col">Plans / ISO</th>
                        <th scope="col">Priorité</th>
                        <th scope="col">Actions</th>
                    </tr></thead>
                <?php foreach ($validatedRequests as $index => $req):
                    $row = $req;
                    $requestId = (int) $row['id'];
                    $nbPlans = $planCounts[$requestId] ?? 0;
                    $ownerName = trim(($row['prenom'] ?? '') . ' ' . ($row['name'] ?? ''));
                    $filterData = mb_strtolower(prefaReference($requestId) . ' ' . $requestId . ' ' . ($row['nom_affaire'] ?? '') . ' ' . $ownerName);
                ?>
                    <tbody class="prefa-request plans-iso-request<?= !empty($row['urgent']) ? ' is-urgent-pending' : '' ?>"
                        id="plans-iso-card-<?= $requestId ?>"
                        data-search="<?= prefaEscape($filterData) ?>"
                        >

                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete($requestId)) ?></td>
                            <td class="prefa-affaire"><?= prefaEscape($row['nom_affaire'] ?: '—') ?></td>
                            <td><?= prefaEscape($ownerName) ?></td>
                            <td><?= !empty($row['date_livraison_prevue']) ? prefaEscape(prefaFormatDate($row['date_livraison_prevue'])) : '—' ?></td>
                            <td><?= $nbPlans ?> plan<?= $nbPlans > 1 ? 's' : '' ?></td>
                            <td><?php if (!empty($row['urgent'])): ?><span class="prefa-urgent">Urgente</span><?php else: ?>Normale<?php endif; ?></td>
                            <td>
                                <button type="button" class="prefa-toggle" aria-expanded="false" aria-controls="prefa-detail-<?= $requestId ?>">Détails</button>
                                <button type="button" class="prefa-toggle plans-iso-toggle" aria-expanded="false" aria-controls="plans-iso-detail-<?= $requestId ?>">Plans & affectations</button>
                                <button type="button" class="prefa-toggle atelier-open-plan-create" data-dialog="atelier-create-dialog-<?= $requestId ?>">Ajouter un plan</button>
                            </td>
                        </tr>
                        <tr id="plans-iso-detail-<?= $requestId ?>" class="plans-iso-detail prefa-detail-row" hidden><td colspan="7">
                            <div class="plans-iso-body">
                                <div class="prefa-detail-heading">
                                    <div>
                                        <h2>Plans &amp; affectations <span><?= prefaEscape(prefaReferenceComplete($requestId)) ?></span></h2>
                                        <p><?= prefaEscape($row['nom_affaire'] ?: 'Demande sans nom') ?></p>
                                    </div>
                                    <button type="button" class="prefa-detail-close" data-close-target="plans-iso-detail-<?= $requestId ?>" aria-label="Fermer le volet">✕</button>
                                </div>

                                <?php require __DIR__ . '/../partials/atelier_request.php'; ?>
                            </div>
                        </td></tr>
                        <tr id="prefa-detail-<?= $requestId ?>" class="prefa-detail-row" hidden><td colspan="8">
                            <div class="prefa-table-detail">
                                <?php require __DIR__ . '/../partials/prefa_detail.php'; ?>
                            </div>
                        </td></tr>
                    </tbody>
                <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
