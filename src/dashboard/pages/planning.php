<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
require_once __DIR__ . '/../includes/planning.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier.php';

if (!$canViewAllPrefa) prefaError(403, 'Acces au planning non autorise.');

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
$start = planningDate($_GET['start'] ?? '') ?? $today->modify('monday this week');
// Le zoom libre de la timeline a disparu : la période affichée se choisit ici.
$length = (int) ($_GET['period'] ?? 28);
if (!in_array($length, [7, 28, 56, 84], true)) $length = 28;
$end = $start->modify('+' . ($length - 1) . ' days');
// Le calendrier est dessine plus large que la periode demandee : une periode de marge
// de chaque cote. Le glisser devient alors du simple defilement, fluide, et ne
// recharge qu'en fin de geste. $start / $end restent la periode affichee, celle du
// titre et du selecteur.
$marge = $length;
$renduStart = $start->modify('-' . $marge . ' days');
$renduJours = $length * 3;
$renduEnd = $renduStart->modify('+' . ($renduJours - 1) . ' days');

$creators = $db->query("SELECT DISTINCT u.id, u.prenom, u.name, TRIM(CONCAT(u.prenom, ' ', u.name)) AS nom FROM Utilisateur u JOIN demande_prefabrication d ON d.idUsers = u.id WHERE d.id_statut = 2 ORDER BY u.prenom, u.name, u.id")->fetchAll();
$creatorFilter = filter_var($_GET['creator'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$requestSearch = is_string($_GET['request'] ?? '') ? trim($_GET['request'] ?? '') : '';
$requestNumber = ltrim(ltrim($requestSearch, '#'), '0');
$creatorNames = array_column($creators, 'nom', 'id');
if ($creatorFilter && !isset($creatorNames[$creatorFilter])) $creatorFilter = null;

$hoursSettings = prefaHoursSettings($db);

$requests = $db->query("SELECT d.id, d.nom_affaire, d.urgent, d.idUsers AS creator_id, d.heures_chiffrees,
    d.date_fin_prevue, d.date_livraison_prevue, d.date_debut_planifiee, d.date_fin_planifiee,
    d.planning_jours_ouvres, d.planning_revision, d.date_validation, d.pouces_total_iso,
    d.commentaire_validation, d.plan_bpe_iso,
    m.libelle AS matiere_libelle,
    p.libelle AS passivation_libelle,
    TRIM(CONCAT(staff.prenom, ' ', staff.name)) AS personnel_atelier_nom,
    TRIM(CONCAT(val.prenom, ' ', val.name)) AS valideur_nom,
    TRIM(CONCAT(u.prenom, ' ', u.name)) AS demandeur
    FROM demande_prefabrication d
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    LEFT JOIN type_matiere m ON m.id = d.id_matiere
    LEFT JOIN type_passivation p ON p.id = d.id_passivation
    LEFT JOIN Utilisateur staff ON staff.id = d.id_personnel_atelier
    LEFT JOIN Utilisateur val ON val.id = d.valide_par
    WHERE d.id_statut = 2
    ORDER BY d.urgent DESC, d.date_livraison_prevue, d.id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($requests as &$req) {
    $rId = (int) $req['id'];
    $req['reference_demande'] = prefaReference($rId);
    $req['reference_complete'] = prefaReferenceComplete($rId);
    $req['plans'] = prefaAttachments($rId);

    $elemStmt = $db->prepare('SELECT e.id, e.reference, e.libelle, s.statuts FROM element_atelier e
        LEFT JOIN element_atelier_etapes s ON s.id_element = e.id WHERE e.id_demande = ? ORDER BY e.id');
    $elemStmt->execute([$rId]);
    $elemList = $elemStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($elemList as &$element) {
        $element['statuts'] = $element['statuts'] ? json_decode($element['statuts'], true) : [];
    }
    unset($element);
    $req['elements'] = $elemList;

    $comms = [];
    if (!empty($req['commentaire_validation'])) {
        $comms[] = [
            'date' => $req['date_validation'] ? atelierLocalDateTime($req['date_validation'], 'd/m/Y') : date('d/m/Y'),
            'auteur' => $req['valideur_nom'] ?: 'Administrateur',
            'texte' => $req['commentaire_validation']
        ];
    }
    try {
        $stmtComm = $db->prepare("SELECT a.commentaire AS texte, a.date_saisie, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
            FROM avancement_atelier a
            JOIN affectation_atelier aff ON aff.id = a.id_affectation
            JOIN element_atelier e ON e.id = aff.id_element
            JOIN Utilisateur u ON u.id = a.id_utilisateur
            WHERE e.id_demande = ? AND a.commentaire IS NOT NULL AND TRIM(a.commentaire) != ''
            ORDER BY a.date_saisie DESC");
        $stmtComm->execute([$rId]);
        while ($c = $stmtComm->fetch(PDO::FETCH_ASSOC)) {
            $comms[] = [
                'date' => atelierLocalDateTime($c['date_saisie'], 'd/m/Y'),
                'auteur' => $c['auteur'] ?: 'Opérateur atelier',
                'texte' => $c['texte']
            ];
        }
    } catch (PDOException) {
    }
    $req['commentaires'] = $comms;
}
unset($req);

$visible = array_values(array_filter(
    $requests,
    static fn($r) => (!$creatorFilter || (int) $r['creator_id'] === $creatorFilter)
        && ($requestSearch === '' || (string) $r['id'] === $requestNumber || stripos($r['reference_demande'], $requestSearch) !== false)
));

$unplanned = [];
$planned = [];
foreach ($visible as $request) {
    if (!$request['date_debut_planifiee'] || !$request['date_fin_planifiee']) {
        $unplanned[] = $request;
    } else {
        $planned[] = $request;
    }
}

$days = [];
$weeks = [];
$todayCol = null;
$months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

for ($index = 0; $index < $renduJours; $index++) {
    $date = $renduStart->modify('+' . $index . ' days');
    $weekend = (int) $date->format('N') > 5;
    $isToday = $date->format('Y-m-d') === $today->format('Y-m-d');
    if ($isToday) $todayCol = $index + 1;
    $wNum = 'S' . $date->format('W');
    if (!isset($weeks[$wNum])) {
        $weeks[$wNum] = ['label' => $wNum, 'count' => 0];
    }
    $weeks[$wNum]['count']++;
    $days[] = [
        'date' => $date,
        'index' => $index + 1,
        'dayNum' => $date->format('j'),
        'weekend' => $weekend,
        'today' => $isToday,
        'week' => $wNum,
    ];
}

// Une couleur par demandeur. L'ordre est figé sur toutes les demandes validées, pas sur
// celles affichées : filtrer une période ne doit pas repeindre les affaires restantes.
// Six emplacements au plus (3 teintes validées × 2 remplissages), au-delà : « Autres ».
$personnes = [];
foreach ($requests as $request) {
    $creatorId = (int) $request['creator_id'];
    if (!$creatorId || isset($personnes[$creatorId])) continue;
    $personnes[$creatorId] = ['nom' => $request['demandeur'] ?: 'Non renseigné', 'slot' => 0];
}
ksort($personnes);
$rang = 0;
foreach ($personnes as &$personne) {
    $personne['slot'] = $rang < 6 ? ++$rang : 0;
}
unset($personne);

$personneSlot = static fn($creatorId) => $personnes[(int) $creatorId]['slot'] ?? 0;

$ganttRows = [];
foreach ($planned as $req) {
    $first = planningDate($req['date_debut_planifiee']);
    $last = planningDate($req['date_fin_planifiee']);
    if (!$first || !$last) continue;
    $visibleStart = max($first, $renduStart);
    $visibleEnd = min($last, $renduEnd);
    $isVisible = ($first <= $renduEnd && $last >= $renduStart);

    $colStart = 1;
    $span = 1;
    if ($isVisible) {
        $colStart = (int) $renduStart->diff($visibleStart)->days + 1;
        $colEnd = (int) $renduStart->diff($visibleEnd)->days + 1;
        $span = max(1, $colEnd - $colStart + 1);
    }

    $ganttRows[] = [
        'request' => $req,
        'isVisible' => $isVisible,
        'colStart' => $colStart,
        'span' => $span,
        'colorClass' => 'planning-person-' . $personneSlot($req['creator_id']),
    ];
}

$firstDisplayed = $days[0]['date'];
$lastDisplayed = $days[count($days) - 1]['date'];
$rangeLabel = $firstDisplayed->format('j') . ' ' . $months[(int) $firstDisplayed->format('n')] . ' — ' . $lastDisplayed->format('j') . ' ' . $months[(int) $lastDisplayed->format('n')] . ' ' . $lastDisplayed->format('Y');

$outside = array_values(array_filter($visible, static fn($r) => $r['date_debut_planifiee'] && $r['date_fin_planifiee'] && ($r['date_debut_planifiee'] > $end->format('Y-m-d') || $r['date_fin_planifiee'] < $start->format('Y-m-d'))));
$nextRequest = null;
if ($outside) {
    usort($outside, static fn($a, $b) => $a['date_debut_planifiee'] <=> $b['date_debut_planifiee']);
    foreach ($outside as $r) {
        if ($r['date_debut_planifiee'] > $end->format('Y-m-d')) {
            $nextRequest = $r;
            break;
        }
    }
    $nextRequest ??= $outside[count($outside) - 1];
}

$initialSelectedId = null;
?>
<section class="planning-page" aria-labelledby="planning-title" data-days-per-inch="<?= prefaEscape($hoursSettings['days']) ?>" data-hours-per-inch="<?= prefaEscape($hoursSettings['rate']) ?>" data-selected-id="<?= (int) ($initialSelectedId ?: 0) ?>">
    <header class="planning-heading">
        <div>
            <h1 id="planning-title">Planning d'atelier</h1>
            <p class="planning-subtitle">Visualisation et ordonnancement temporel des demandes de préfabrication.</p>
        </div>
        <button type="button" class="planning-button planning-refresh-button" id="planning-refresh">
            <span>Actualiser</span>
        </button>
    </header>

    <form id="planning-filters" class="planning-toolbar">
        <div class="floating-field floating-always"><label for="planning-request-filter">Numéro de demande</label><input class="floating-control" id="planning-request-filter" type="search" name="request" value="<?= prefaEscape($requestSearch) ?>" placeholder="Ex. : 26-DP-001"></div>
        <div class="planning-date-nav">
            <button type="button" class="planning-button planning-icon-button" data-planning-shift="-1" aria-label="Période précédente" title="Période précédente">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m14 6-6 6 6 6" />
                </svg>
            </button>
            <button type="button" class="planning-button" data-planning-today>Aujourd’hui</button>
            <button type="button" class="planning-button planning-icon-button" data-planning-shift="1" aria-label="Période suivante" title="Période suivante">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m10 6 6 6-6 6" />
                </svg>
            </button>
        </div>
        <div class="floating-field floating-always"><label for="planning-start">Semaine du</label><input class="floating-control" id="planning-start" type="date" name="start" value="<?= $start->format('Y-m-d') ?>" required></div>
        <div class="floating-field floating-always"><label for="planning-period">Vue</label><select class="floating-control" id="planning-period" name="period">
                <option value="28" <?= $length === 28 ? 'selected' : '' ?>>4 semaines (Vue standard)</option>
                <option value="7" <?= $length === 7 ? 'selected' : '' ?>>1 semaine</option>
                <option value="56" <?= $length === 56 ? 'selected' : '' ?>>8 semaines</option>
                <option value="84" <?= $length === 84 ? 'selected' : '' ?>>12 semaines</option>
            </select></div>
        <div class="floating-field floating-always"><label for="planning-creator-filter">Demandeur</label><select class="floating-control" id="planning-creator-filter" name="creator">
                <option value="">Tous les demandeurs</option><?php foreach ($creators as $creator): ?><option value="<?= (int) $creator['id'] ?>" <?= $creatorFilter === (int) $creator['id'] ? 'selected' : '' ?>><?= prefaEscape($creator['nom']) ?></option><?php endforeach; ?>
            </select></div>
    </form>
    <p id="planning-page-message" class="planning-feedback" role="status" aria-live="polite"></p>

    <div class="planning-calendar-heading">
        <h2><?= prefaEscape($rangeLabel) ?></h2>
    </div>
    <?php if ($nextRequest): ?>
        <div class="planning-outside-period"><span><?= count($outside) ?> demande(s) planifiée(s) hors période</span><button type="button" class="planning-button" data-planning-go-to="<?= prefaEscape($nextRequest['date_debut_planifiee']) ?>" data-planning-target="<?= (int) $nextRequest['id'] ?>">Voir le <?= prefaEscape(prefaFormatDate($nextRequest['date_debut_planifiee'])) ?></button></div>
    <?php endif; ?>

    <div class="gantt-outer-container">
        <div class="gantt-scroll-wrapper" role="region" aria-label="Planning Gantt des affaires" tabindex="0">
            <div class="gantt-layout" style="--gantt-days: <?= count($days) ?>;" data-gantt-marge="<?= $marge ?>">

                <!-- En-tête des colonnes -->
                <div class="gantt-header-row">
                    <!-- Coin supérieur gauche (label affaire) -->
                    <div class="gantt-header-corner">Affaires</div>

                    <div class="gantt-header-cols">
                    <!-- Ligne 1 : Semaines -->
                    <div class="gantt-weeks-header">
                        <?php foreach ($weeks as $w): ?>
                            <div class="gantt-week-cell" style="grid-column: span <?= $w['count'] ?>;">
                                <?= prefaEscape($w['label']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Ligne 2 : Numéros des jours -->
                    <div class="gantt-days-header">
                        <?php foreach ($days as $day): ?>
                            <div class="gantt-day-number <?= $day['weekend'] ? 'is-weekend' : 'is-workday' ?> <?= $day['today'] ? 'is-today' : '' ?>">
                                <?= $day['dayNum'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                </div>

                <!-- Corps du Gantt avec les affaires -->
                <div class="gantt-body">
                    <!-- Colonnes d'arrière-plan avec couleurs des jours (vert/gris) -->
                    <div class="gantt-background-grid" aria-hidden="true">
                        <?php foreach ($days as $day): ?>
                            <div class="gantt-bg-col <?= $day['weekend'] ? 'is-weekend' : 'is-workday' ?> <?= $day['today'] ? 'is-today' : '' ?>"></div>
                        <?php endforeach; ?>
                        <?php if ($todayCol !== null): ?>
                            <div class="gantt-today-line" style="grid-column: <?= $todayCol ?>;" title="Aujourd’hui"></div>
                        <?php endif; ?>
                    </div>

                    <!-- Lignes des affaires -->
                    <?php if (empty($ganttRows)): ?>
                        <div class="gantt-empty-notice">
                            <p>Aucune affaire planifiée dans cette période.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($ganttRows as $row): $r = $row['request'];
                            $titleAffaire = $r['nom_affaire'] ?: $r['demandeur']; ?>
                            <div class="gantt-row <?= $r['id'] == $initialSelectedId ? 'is-selected' : '' ?>" data-affaire-id="<?= (int) $r['id'] ?>">
                                <!-- Étiquette blanche à gauche -->
                                <div class="gantt-label-col">
                                    <button type="button" class="gantt-affaire-badge" data-select-affaire="<?= (int) $r['id'] ?>" title="<?= prefaEscape($titleAffaire) ?>">
                                        <?= prefaEscape($titleAffaire) ?>
                                    </button>
                                </div>
                                <!-- Piste de la barre de Gantt -->
                                <div class="gantt-track">
                                    <?php if ($row['isVisible']): ?>
                                        <button type="button" class="gantt-bar <?= $row['colorClass'] ?> <?= $r['urgent'] ? 'is-urgent' : '' ?> <?= $r['id'] == $initialSelectedId ? 'is-selected' : '' ?>"
                                            <?= $isAdmin ? 'data-plan-id' : 'data-view-plan-id' ?>="<?= (int) $r['id'] ?>"
                                            style="grid-column: <?= $row['colStart'] ?> / span <?= $row['span'] ?>;"
                                            title="<?= prefaEscape('Demande ' . $r['reference_demande'] . ' · ' . $titleAffaire . ' · ' . prefaFormatDate($r['date_debut_planifiee']) . ' au ' . prefaFormatDate($r['date_fin_planifiee'])) ?>"
                                            aria-label="<?= prefaEscape('Demande ' . $r['reference_demande'] . ' · ' . $titleAffaire . ' · ' . prefaFormatDate($r['date_debut_planifiee']) . ' au ' . prefaFormatDate($r['date_fin_planifiee'])) ?>"></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <?php
    // Légende des couleurs : les demandeurs effectivement présents dans la période affichée.
    $legende = [];
    foreach ($ganttRows as $row) {
        $creatorId = (int) $row['request']['creator_id'];
        if (!$row['isVisible'] || isset($legende[$creatorId])) continue;
        $legende[$creatorId] = [
            'nom' => $row['request']['demandeur'] ?: 'Non renseigné',
            'slot' => $personneSlot($creatorId),
        ];
    }
    uasort($legende, static fn($a, $b) => $a['slot'] <=> $b['slot']);
    ?>
    <?php if ($legende): ?>
        <div class="planning-legend">
            <span class="planning-legend-title">Demandeur</span>
            <ul class="planning-legend-list">
                <?php foreach ($legende as $personne): ?>
                    <li class="planning-legend-item">
                        <span class="planning-legend-swatch planning-person-<?= (int) $personne['slot'] ?>" aria-hidden="true"></span>
                        <?= prefaEscape($personne['nom']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- PANNEAU INFÉRIEUR : 4 BLOCS (Disposition de la maquette) -->
    <section class="planning-details-panel" id="planning-details-panel" aria-labelledby="planning-panel-title" hidden>
        <h2 id="planning-panel-title" class="sr-only">Détails de l'affaire sélectionnée</h2>

        <!-- BLOC 1: INFORMATION GÉNÉRALE -->
        <div class="planning-card planning-card-info">
            <div class="planning-card-header">Information générale</div>
            <div class="planning-card-body">
                <dl class="planning-info-list">
                    <div class="planning-info-item">
                        <dt>Date de début :</dt>
                        <dd id="detail-date-debut">-</dd>
                    </div>
                    <div class="planning-info-item">
                        <dt>Date de fin :</dt>
                        <dd id="detail-date-fin">-</dd>
                    </div>
                    <div class="planning-info-item">
                        <dt>Personnel associé :</dt>
                        <dd id="detail-personnel">-</dd>
                    </div>
                    <div class="planning-info-item">
                        <dt>Matière :</dt>
                        <dd id="detail-matiere">-</dd>
                    </div>
                    <div class="planning-info-item">
                        <dt>Plan :</dt>
                        <dd id="detail-plan">-</dd>
                    </div>
                </dl>
            </div>
            <div class="planning-card-footer">
                <button type="button" class="planning-button planning-action-btn" id="btn-edit-general" <?= !$isAdmin ? 'disabled' : '' ?>>Modifier</button>
            </div>
        </div>

        <!-- BLOC 2: DÉTAIL AVANCEMENT -->
        <div class="planning-card planning-card-avancement">
            <div class="planning-card-header">Détail avancement</div>
            <div class="planning-card-body planning-table-container">
                <table class="planning-matrix-table" id="matrix-avancement">
                    <thead id="matrix-head">
                        <tr>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="matrix-body">
                        <!-- Rempli dynamiquement en JS -->
                    </tbody>
                </table>
            </div>
            <div class="planning-card-footer">
                <button type="button" class="planning-button" id="btn-export-avancement">Exporter avancement</button>
            </div>
        </div>

        <!-- BLOC 3: COMMENTAIRE -->
        <div class="planning-card planning-card-comments">
            <div class="planning-card-header">Commentaire</div>
            <div class="planning-card-body planning-comments-container" id="comments-list">
                <p class="planning-empty-text">Sélectionnez une affaire.</p>
            </div>
            <div class="planning-card-footer">
                <button type="button" class="planning-button" id="btn-export-commentaires">Exporter commentaires</button>
            </div>
        </div>

        <!-- BLOC 4: LIVRAISON -->
        <div class="planning-card planning-card-livraison">
            <div class="planning-card-header">Livraison</div>
            <div class="planning-card-body">
                <div class="planning-delivery-info">
                    <p>Départ de livraison prévu le : <strong id="detail-depart-livraison">-</strong></p>
                    <p>Réception de livraison prévu le : <strong id="detail-reception-livraison">-</strong></p>
                </div>
            </div>
            <div class="planning-card-footer">
                <button type="button" class="planning-button planning-action-btn" id="btn-edit-livraison" <?= !$isAdmin ? 'disabled' : '' ?>>Modifier</button>
            </div>
        </div>
    </section>

    <!-- SECTION DEMANDES À PLANIFIER (COLLAPSIBLE) -->
    <?php if ($unplanned): ?>
        <details class="planning-backlog" id="planning-backlog-section">
            <summary class="planning-backlog-summary">
                <strong>Demandes en attente de planification</strong> <span class="planning-badge-count"><?= count($unplanned) ?></span>
            </summary>
            <div class="planning-backlog-list">
                <?php foreach ($unplanned as $request): ?>
                    <div class="planning-backlog-item">
                        <div>
                            <strong><?= prefaEscape($request['reference_demande']) ?></strong>
                            <?php if ($request['urgent']): ?><span class="planning-urgent-badge">Urgente</span><?php endif; ?>
                            <span class="planning-backlog-owner"><?= prefaEscape($request['nom_affaire'] ?: $request['demandeur']) ?></span>
                        </div>
                        <div class="planning-backlog-meta">
                            <span><?= prefaEscape(prefaFormatDays((float) $request['heures_chiffrees'] / 24)) ?></span>
                        </div>
                        <button type="button" class="planning-button" <?= $isAdmin ? 'data-plan-id' : 'data-view-plan-id' ?>="<?= (int) $request['id'] ?>"><?= $isAdmin ? 'Planifier' : 'Détails' ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <!-- MODAL DE PLANIFICATION -->
    <?php if ($isAdmin): ?>
        <dialog id="planning-dialog" class="planning-dialog" aria-labelledby="planning-dialog-title">
            <form id="planning-edit-form" class="planning-dialog-form" data-static-fields>
                <div class="planning-dialog-heading">
                    <div class="planning-dialog-title-wrap">
                        <span class="planning-dialog-badge">Planifier la demande</span>
                        <h2 id="planning-dialog-title">Demande</h2>
                    </div>
                    <button type="button" class="planning-dialog-close" data-planning-close aria-label="Fermer">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <div id="planning-request-description" class="planning-request-chips"></div>

                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                <input type="hidden" name="id">
                <input type="hidden" name="revision">

                <div class="planning-dialog-fields">
                    <div class="planning-field-group">
                        <label for="planning-task-start" class="planning-label">
                            Début planifié
                        </label>
                        <input id="planning-task-start" name="start" type="date" class="planning-date-input" required>
                    </div>

                    <div class="planning-field-group">
                        <label for="planning-task-end" class="planning-label">
                            Fin planifiée
                        </label>
                        <input id="planning-task-end" name="end" type="date" class="planning-date-input" required>
                    </div>
                </div>
                <small class="planning-help-text">Fin calculée selon le chiffrage en jours ouvrés.</small>

                <div id="planning-preview" class="planning-preview" aria-live="polite"></div>
                <p id="planning-save-message" class="planning-feedback" role="status" aria-live="polite"></p>

                <div class="planning-dialog-actions">
                    <button type="button" class="planning-btn-remove" id="planning-remove">
                        Retirer du planning
                    </button>
                    <div class="planning-dialog-actions-right">
                        <button type="button" class="planning-btn-cancel" data-planning-close>Annuler</button>
                        <button type="submit" class="planning-btn-submit">
                            Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <!-- DONNÉES JSON EMBARQUÉES POUR LE CLIENT -->
    <script id="planning-data" type="application/json">
        <?= json_encode($requests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    </script>

    <!-- Les étapes viennent d'atelierSteps() : la matrice suit la liste du serveur, sans
         quoi une étape ajoutée en PHP s'enregistre sans jamais s'afficher ici. -->
    <script id="planning-steps" type="application/json">
        <?= json_encode(atelierSteps(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    </script>
</section>
