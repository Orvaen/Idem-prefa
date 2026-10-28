<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/prefa_workshop.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/materiel/ccpu_demande.php';
require_once __DIR__ . '/../includes/materiel/commandes.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
require_once __DIR__ . '/../includes/demande/prefa_documents.php';

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
$totalPlans = array_sum($planCounts);

/*
|--------------------------------------------------------------------------
| Groupes et onglets — sur le modèle de la liste des demandes
|--------------------------------------------------------------------------
|
| Toutes les demandes de cet écran sont validées : les ranger par statut ne dirait rien.
| Ce qui les distingue ici, c'est le travail de l'atelier : celles qui n'ont encore aucun
| plan, et celles dont les plans sont en cours. Ce sont les deux groupes, et les deux onglets.
*/

$groupesIso = [
    'a-preparer' => ['libelle' => 'À préparer', 'aide' => 'aucun plan rattaché', 'demandes' => []],
    'en-preparation' => ['libelle' => 'En préparation', 'aide' => 'plans en cours', 'demandes' => []],
];
foreach ($validatedRequests as $req) {
    $cle = ($planCounts[(int) $req['id']] ?? 0) === 0 ? 'a-preparer' : 'en-preparation';
    $groupesIso[$cle]['demandes'][] = $req;
}

$aujourdhui = date('Y-m-d');
$urgentesIso = count(array_filter($validatedRequests, static fn (array $r): bool => !empty($r['urgent'])));
$enRetardIso = count(array_filter(
    $validatedRequests,
    static fn (array $r): bool => !empty($r['date_livraison_prevue']) && $r['date_livraison_prevue'] < $aujourdhui
));

/** Le signe du groupe : un cercle pointillé qui attend ses plans, un demi-cercle quand ils avancent. */
$iconeGroupeIso = static function (string $groupe): string {
    return $groupe === 'a-preparer'
        ? '<svg class="prefa-statut-icone status-1" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.1" /></svg>'
        : '<svg class="prefa-statut-icone plans-iso-en-cours" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.6" />'
            . '<path d="M8 4a4 4 0 0 1 0 8z" fill="currentColor" /></svg>';
};
?>

<section class="page prefa-page prefa-list plans-iso-page" id="plans-iso-page" aria-labelledby="plans-iso-title" data-densite="confortable">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="plans-iso-title">Plans / ISO & Affectations</h1>
                <p>Préparez les plans et ISO, rattachez les documents techniques et organisez les affectations.</p>
            </div>

            <?php // Les mêmes outils de vue que la liste des demandes, au même endroit. ?>
            <div class="prefa-vue-outils">
                <div class="prefa-densite" role="group" aria-label="Densité du tableau">
                    <button type="button" class="prefa-densite-choix" data-densite="confortable"
                            aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="3" width="12" height="2.6" rx="1" />
                            <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
                            <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
                        </svg>
                    </button>
                    <button type="button" class="prefa-densite-choix" data-densite="compact"
                            aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
                        </svg>
                    </button>
                </div>

                <button type="button" id="refresh-plans-iso">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>
                    Actualiser
                </button>
            </div>
        </header>

        <?php // La recherche de la liste des demandes : un champ à la hauteur des pastilles, pas
              // un champ de formulaire à étiquette flottante. Elle filtre sur place — toutes les
              // demandes validées sont déjà dans la page. ?>
        <div class="prefa-filters" role="search">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="plans-iso-filter-search" type="search"
                       aria-label="Rechercher une demande"
                       placeholder="N° de demande, affaire, demandeur…">
            </div>
        </div>

        <?php if (!$validatedRequests): ?>
            <p class="prefa-table-empty plans-iso-empty">Aucune demande validée pour le moment.</p>
        <?php else: ?>

            <div class="prefa-onglets-barre">
                <div class="prefa-onglets" role="group" aria-label="Avancement des plans">
                    <button type="button" class="prefa-onglet plans-iso-onglet" data-groupe="" aria-pressed="true">
                        Toutes <span class="prefa-onglet-compte"><?= (int) $totalValidated ?></span>
                    </button>
                    <?php foreach ($groupesIso as $cle => $groupe): ?>
                        <button type="button" class="prefa-onglet plans-iso-onglet" data-groupe="<?= $cle ?>" aria-pressed="false">
                            <?= prefaEscape($groupe['libelle']) ?>
                            <span class="prefa-onglet-compte"><?= count($groupe['demandes']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="prefa-list-summary">
                    <span class="<?= $urgentesIso > 0 ? 'est-a-traiter' : '' ?>">
                        <strong><?= (int) $urgentesIso ?></strong> urgente<?= $urgentesIso > 1 ? 's' : '' ?>
                    </span>
                    <span class="<?= $enRetardIso > 0 ? 'est-a-traiter' : '' ?>">
                        <strong><?= (int) $enRetardIso ?></strong> en retard
                    </span>
                </p>
            </div>

            <div class="prefa-table-scroll" role="region" aria-label="Plans et affectations des demandes" tabindex="0">
                <table class="prefa-table plans-iso-table" id="plans-iso-list">
                    <thead><tr>
                        <th scope="col">Demande n°</th>
                        <th scope="col" class="prefa-affaire">Affaire</th>
                        <th scope="col">Livraison prévue</th>
                        <th scope="col" class="prefa-nombre">Plans / ISO</th>
                        <th scope="col">Demandeur</th>
                        <th scope="col">Actions</th>
                    </tr></thead>

                <?php foreach ($groupesIso as $cleGroupe => $groupe): ?>
                    <?php if (!$groupe['demandes']) continue; ?>

                    <tbody class="prefa-groupe plans-iso-groupe" data-groupe="<?= $cleGroupe ?>">
                        <tr>
                            <th scope="colgroup" colspan="6">
                                <span class="prefa-groupe-nom prefa-groupe-statut"><?= $iconeGroupeIso($cleGroupe) ?><?= prefaEscape($groupe['libelle']) ?></span>
                                <span class="prefa-groupe-compte"><?= count($groupe['demandes']) ?></span>
                                <span class="prefa-groupe-aide"><?= prefaEscape($groupe['aide']) ?></span>
                            </th>
                        </tr>
                    </tbody>

                <?php foreach ($groupe['demandes'] as $req):
                    $row = $req;
                    $requestId = (int) $row['id'];
                    $nbPlans = $planCounts[$requestId] ?? 0;
                    $ownerName = trim(($row['prenom'] ?? '') . ' ' . ($row['name'] ?? ''));
                    $initiales = mb_strtoupper(mb_substr(trim((string) ($row['prenom'] ?? '')), 0, 1) . mb_substr(trim((string) ($row['name'] ?? '')), 0, 1));
                    $filterData = mb_strtolower(prefaReference($requestId) . ' ' . $requestId . ' ' . ($row['nom_affaire'] ?? '') . ' ' . $ownerName);
                    $enRetard = !empty($row['date_livraison_prevue']) && $row['date_livraison_prevue'] < $aujourdhui;
                    $joursDeRetard = $enRetard
                        ? (new DateTimeImmutable((string) $row['date_livraison_prevue']))->diff(new DateTimeImmutable('today'))->days
                        : 0;
                ?>
                    <tbody class="prefa-request plans-iso-request<?= !empty($row['urgent']) ? ' is-urgent-pending' : '' ?>"
                        id="plans-iso-card-<?= $requestId ?>"
                        data-groupe="<?= $cleGroupe ?>"
                        data-search="<?= prefaEscape($filterData) ?>">

                        <tr>
                            <?php // Le numéro seul, avec la place de l'urgence réservée devant :
                                  // le chantier est dans la colonne d'à côté. ?>
                            <td class="prefa-reference">
                                <span class="prefa-urgence">
                                    <?php if (!empty($row['urgent'])): ?>
                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                                            stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Urgente">
                                            <title>Demande urgente</title>
                                            <path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" />
                                        </svg>
                                    <?php endif; ?>
                                </span><?= prefaEscape(prefaReference($requestId)) ?>
                            </td>
                            <td class="prefa-affaire"><?= prefaEscape($row['nom_affaire'] ?: '—') ?></td>
                            <td class="prefa-echeance">
                                <span class="prefa-date"><?= !empty($row['date_livraison_prevue']) ? prefaEscape(prefaFormatDate($row['date_livraison_prevue'])) : '—' ?></span>
                                <?php if ($enRetard): ?>
                                    <span class="prefa-retard"><?= (int) $joursDeRetard ?> j de retard</span>
                                <?php endif; ?>
                            </td>
                            <td class="prefa-nombre"><?= (int) $nbPlans ?></td>
                            <td class="prefa-secondaire">
                                <span class="prefa-personne">
                                    <span class="prefa-avatar" aria-hidden="true"><?= prefaEscape($initiales) ?></span>
                                    <?= prefaEscape($ownerName) ?>
                                </span>
                            </td>
                            <?php // Une commande sur la ligne — celle que la demande attend : un
                                  // premier plan tant qu'elle n'en a pas, ses affectations
                                  // ensuite. Le reste passe dans le menu, comme sur la liste. ?>
                            <td class="prefa-actions">
                                <span class="prefa-decision">
                                    <?php if ($nbPlans === 0): ?>
                                        <button type="button" class="prefa-ligne-valider atelier-open-plan-create"
                                                data-dialog="atelier-create-dialog-<?= $requestId ?>">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                                            Ajouter un plan
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="prefa-ligne-contour plans-iso-toggle"
                                                aria-expanded="false" aria-controls="plans-iso-detail-<?= $requestId ?>">
                                            Plans &amp; affectations
                                        </button>
                                    <?php endif; ?>
                                </span>
                                <button type="button" class="prefa-actions-declencheur" aria-haspopup="menu" aria-expanded="false"
                                        aria-label="Actions sur la demande <?= prefaEscape(prefaReference($requestId)) ?>">
                                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
                                    </svg>
                                </button>
                                <div class="prefa-actions-menu" role="menu" hidden>
                                    <button type="button" role="menuitem" class="prefa-toggle" aria-expanded="false" aria-controls="prefa-detail-<?= $requestId ?>">Détails</button>
                                    <?php if ($nbPlans === 0): ?>
                                        <button type="button" role="menuitem" class="prefa-toggle plans-iso-toggle" aria-expanded="false" aria-controls="plans-iso-detail-<?= $requestId ?>">Plans &amp; affectations</button>
                                    <?php else: ?>
                                        <button type="button" role="menuitem" class="atelier-open-plan-create" data-dialog="atelier-create-dialog-<?= $requestId ?>">Ajouter un plan</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr id="plans-iso-detail-<?= $requestId ?>" class="plans-iso-detail prefa-detail-row" hidden><td colspan="6">
                            <div class="plans-iso-body">
                                <?php // L'en-tête du volet, sur le modèle de la fiche : ce que c'est, laquelle,
                                      // de quelle affaire. Le titre redisait l'affaire, puis la ligne du
                                      // dessous la redisait encore. ?>
                                <?php // L'en-tête des volets (PV, fiche) : la référence en titre, l'affaire
                                      // dessous, la croix dans son cadre. ?>
                                <header class="pv-volet-tete plans-iso-entete">
                                    <div>
                                        <h2><?= prefaEscape(prefaReference($requestId)) ?></h2>
                                        <p><?= prefaEscape($row['nom_affaire'] ?: 'Demande sans nom') ?> · Plans &amp; affectations</p>
                                    </div>
                                    <div class="pv-volet-outils">
                                        <button type="button" class="prefa-detail-close" data-close-target="plans-iso-detail-<?= $requestId ?>" aria-label="Fermer le volet" title="Fermer">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                                        </button>
                                    </div>
                                </header>

                                <?php require __DIR__ . '/../partials/atelier_request.php'; ?>
                            </div>
                        </td></tr>
                        <tr id="prefa-detail-<?= $requestId ?>" class="prefa-detail-row" hidden><td colspan="6">
                            <div class="prefa-table-detail">
                                <?php require __DIR__ . '/../partials/prefa_detail.php'; ?>
                            </div>
                        </td></tr>
                    </tbody>
                <?php endforeach; ?>
                <?php endforeach; ?>

                    <tbody class="plans-iso-aucune" hidden>
                        <tr><td class="prefa-table-empty" colspan="6">Aucune demande ne correspond à cette recherche.</td></tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
