<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_hours.php';
require_once __DIR__ . '/../includes/atelier/planning.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';

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
              AND a.etat <> 'refuse'
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

// Le titre dit la période choisie. Le calendrier est rendu trois fois plus large (une
// période de marge de chaque côté, pour le glisser) : lire ses bornes donnait « 3 octobre —
// 25 décembre » pour quatre semaines affichées.
$firstDisplayed = $start;
$lastDisplayed = $end;
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

/** Les icônes du volet : le trait des volets des PV, 16 px, la couleur du texte sourd. */
function planningIcone(string $nom): string
{
    $traces = [
        'debut' => '<rect x="3.5" y="5" width="17" height="15" rx="2" /><path d="M3.5 10h17M8 3v4M16 3v4" />',
        'fin' => '<rect x="3.5" y="5" width="17" height="15" rx="2" /><path d="M3.5 10h17M8 3v4M16 3v4M9.5 15l2 2 3.5-3.5" />',
        'duree' => '<circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3 2" />',
        'charge' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />',
        'personne' => '<circle cx="12" cy="8" r="3.5" /><path d="M5 20c.7-3.6 3.5-5.5 7-5.5s6.3 1.9 7 5.5" />',
        'atelier' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5l3 3 5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z" />',
        'matiere' => '<path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5z" /><path d="m4 8.5 8 4.5 8-4.5M12 13v7" />',
        'plan' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" />',
        'camion' => '<path d="M3 6.5h11v9H3zM14 9.5h4l3 3v3h-7" /><circle cx="7" cy="17.5" r="1.8" /><circle cx="17.5" cy="17.5" r="1.8" />',
    ];
    return '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
        . ($traces[$nom] ?? '') . '</svg>';
}
?>
<section class="page prefa-page prefa-list atelier-page planning-page" id="planning-page" aria-labelledby="planning-title" data-days-per-inch="<?= prefaEscape($hoursSettings['days']) ?>" data-hours-per-inch="<?= prefaEscape($hoursSettings['rate']) ?>" data-selected-id="<?= (int) ($initialSelectedId ?: 0) ?>">
  <div class="prefa-shell">
    <header class="prefa-heading prefa-list-heading">
        <div>
            <h1 id="planning-title">Planning</h1>
            <p>Les demandes validées dans le temps de l’atelier : cliquez une barre pour la planifier ou la suivre.</p>
        </div>
        <div class="prefa-vue-outils">
            <button type="button" class="pv-outil" id="planning-refresh">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                </svg>
                Actualiser
            </button>
        </div>
    </header>

    <!-- La barre d'outils des listes : recherche à gauche, puis la période, puis les
         réglages de vue, tous à 32 px. -->
    <form id="planning-filters" class="prefa-filters planning-toolbar" role="search">
        <div class="filtres-recherche">
            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
            <input id="planning-request-filter" type="search" name="request" value="<?= prefaEscape($requestSearch) ?>" placeholder="N° de demande" aria-label="Numéro de demande">
        </div>
        <div class="planning-date-nav" role="group" aria-label="Période">
            <button type="button" class="planning-button planning-icon-button" data-planning-shift="-1" aria-label="Période précédente" title="Période précédente">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m14 6-6 6 6 6" /></svg>
            </button>
            <button type="button" class="planning-button" data-planning-today>Aujourd’hui</button>
            <button type="button" class="planning-button planning-icon-button" data-planning-shift="1" aria-label="Période suivante" title="Période suivante">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m10 6 6 6-6 6" /></svg>
            </button>
        </div>
        <span class="planning-periode"><?= prefaEscape($rangeLabel) ?></span>
        <div class="planning-reglages">
            <div class="planning-champ">
                <label for="planning-start" class="sr-only">Semaine du</label>
                <input class="planning-controle planning-controle-date" id="planning-start" type="date" name="start" value="<?= $start->format('Y-m-d') ?>" required title="Semaine du">
            </div>
            <span class="planning-select">
                <label for="planning-period" class="sr-only">Vue</label>
                <select class="planning-controle" id="planning-period" name="period">
                    <option value="7" <?= $length === 7 ? 'selected' : '' ?>>1 semaine</option>
                    <option value="28" <?= $length === 28 ? 'selected' : '' ?>>4 semaines</option>
                    <option value="56" <?= $length === 56 ? 'selected' : '' ?>>8 semaines</option>
                    <option value="84" <?= $length === 84 ? 'selected' : '' ?>>12 semaines</option>
                </select>
                <svg class="planning-select-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
            </span>
            <span class="planning-select">
                <label for="planning-creator-filter" class="sr-only">Demandeur</label>
                <select class="planning-controle" id="planning-creator-filter" name="creator">
                    <option value="">Tous les demandeurs</option><?php foreach ($creators as $creator): ?><option value="<?= (int) $creator['id'] ?>" <?= $creatorFilter === (int) $creator['id'] ? 'selected' : '' ?>><?= prefaEscape($creator['nom']) ?></option><?php endforeach; ?>
                </select>
                <svg class="planning-select-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
            </span>
        </div>
    </form>
    <p id="planning-page-message" class="planning-feedback" role="status" aria-live="polite"></p>

    <?php if ($nextRequest): ?>
        <div class="planning-outside-period"><span><?= count($outside) ?> demande<?= count($outside) > 1 ? 's planifiées' : ' planifiée' ?> hors de cette période</span><button type="button" class="planning-button planning-button-discret" data-planning-go-to="<?= prefaEscape($nextRequest['date_debut_planifiee']) ?>" data-planning-target="<?= (int) $nextRequest['id'] ?>">Voir le <?= prefaEscape(prefaFormatDate($nextRequest['date_debut_planifiee'])) ?></button></div>
    <?php endif; ?>

    <div class="gantt-outer-container">
        <div class="gantt-scroll-wrapper" role="region" aria-label="Planning Gantt des affaires" tabindex="0">
            <div class="gantt-layout" style="--gantt-days: <?= count($days) ?>;" data-gantt-marge="<?= $marge ?>">

                <!-- En-tête des colonnes -->
                <div class="gantt-header-row">
                    <!-- Coin supérieur gauche (label affaire) -->
                    <div class="gantt-header-corner">Demande</div>

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
                            <!-- « / span 1 » : un élément absolu dont la colonne n'a pas de fin s'étend jusqu'au
                                 bout de la grille, et le trait se centrait 27 jours plus loin. -->
                            <div class="gantt-today-line" style="grid-column: <?= $todayCol ?> / span 1;" title="Aujourd’hui"></div>
                        <?php endif; ?>
                    </div>

                    <!-- Lignes des affaires -->
                    <?php if (empty($ganttRows)): ?>
                        <div class="gantt-empty-notice">
                            <p>Aucune affaire planifiée dans cette période.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($ganttRows as $row): $r = $row['request'];
                            $titleAffaire = $r['nom_affaire'] ?: 'Sans affaire'; ?>
                            <div class="gantt-row <?= $r['id'] == $initialSelectedId ? 'is-selected' : '' ?>" data-affaire-id="<?= (int) $r['id'] ?>">
                                <!-- Étiquette blanche à gauche -->
                                <div class="gantt-label-col">
                                    <button type="button" class="gantt-affaire-badge" data-ouvrir="<?= (int) $r['id'] ?>" title="<?= prefaEscape($r['reference_demande'] . ' · ' . $titleAffaire) ?>">
                                        <span class="gantt-ref"><?= prefaEscape($r['reference_demande']) ?></span>
                                        <span class="gantt-nom"><?= prefaEscape($titleAffaire) ?></span>
                                        <?php if ($r['urgent']): ?><span class="gantt-urgent" title="Urgente" aria-label="Urgente">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" /></svg>
                                        </span><?php endif; ?>
                                    </button>
                                </div>
                                <!-- Piste de la barre de Gantt -->
                                <div class="gantt-track">
                                    <?php if ($row['isVisible']): ?>
                                        <button type="button" class="gantt-bar <?= $row['colorClass'] ?> <?= $r['id'] == $initialSelectedId ? 'is-selected' : '' ?>"
                                            data-ouvrir="<?= (int) $r['id'] ?>"
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

    <!-- Les demandes sans dates : un groupe sous le calendrier, comme les groupes des
         listes, et non une carte repliée qu'on oubliait d'ouvrir. -->
    <?php if ($unplanned): ?>
        <section class="planning-attente" aria-labelledby="planning-attente-titre">
            <h2 id="planning-attente-titre" class="planning-attente-titre">À planifier <span class="prefa-groupe-compte"><?= count($unplanned) ?></span></h2>
            <ul class="planning-attente-liste">
                <?php foreach ($unplanned as $request):
                    $heures = (int) $request['pouces_total_iso'] > 0
                        ? (int) $request['pouces_total_iso'] * (float) $hoursSettings['rate']
                        : (float) $request['heures_chiffrees']; ?>
                    <li>
                        <button type="button" class="planning-attente-ligne" data-ouvrir="<?= (int) $request['id'] ?>">
                            <span class="gantt-ref"><?= prefaEscape($request['reference_demande']) ?></span>
                            <span class="planning-attente-nom"><?= prefaEscape($request['nom_affaire'] ?: '—') ?></span>
                            <?php if ($request['urgent']): ?><span class="pv-puce pv-puce-a_faire">Urgente</span><?php endif; ?>
                            <span class="planning-attente-meta"><?= prefaEscape($request['demandeur'] ?: '—') ?></span>
                            <span class="planning-attente-meta"><?= prefaEscape(prefaFormatDays($heures / 24)) ?></span>
                            <span class="planning-attente-meta">Livraison <?= prefaEscape(prefaFormatDate($request['date_livraison_prevue'])) ?></span>
                            <span class="planning-attente-action"><?= $isAdmin ? 'Planifier' : 'Voir' ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
  </div>

    <!-- Le volet d'une demande : le même panneau flottant que les PV et la fiche d'une
         demande. Il remplace la fenêtre de planification ET les quatre cartes du bas — un
         clic ouvrait les deux, l'une par-dessus les autres. Rempli par planning.js. -->
    <div id="planning-volet" class="prefa-detail-row planning-volet-rang" hidden>
        <div class="pv-volet" role="dialog" aria-labelledby="planning-volet-titre">
            <header class="pv-volet-tete">
                <div>
                    <h2 id="planning-volet-titre">Demande</h2>
                    <p id="planning-volet-sous-titre"></p>
                </div>
                <div class="pv-volet-outils">
                    <button type="button" class="prefa-detail-close" data-close-target="planning-volet" aria-label="Fermer le volet" title="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </header>

            <section class="pv-volet-section">
                <div class="pv-volet-titre-rang">
                    <h3 class="pv-volet-titre">Planification</h3>
                    <span id="planning-volet-etat" class="pv-puce"></span>
                </div>
                <?php if ($isAdmin): ?>
                    <form id="planning-edit-form" data-static-fields>
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                        <input type="hidden" name="id">
                        <input type="hidden" name="revision">
                        <dl class="pv-proprietes">
                            <div><dt><?= planningIcone('debut') ?><label for="planning-task-start">Début</label></dt>
                                <dd><input id="planning-task-start" class="pv-volet-champ" name="start" type="date" required></dd></div>
                            <div><dt><?= planningIcone('fin') ?><label for="planning-task-end">Fin</label></dt>
                                <dd><input id="planning-task-end" class="pv-volet-champ" name="end" type="date" required></dd></div>
                            <div><dt><?= planningIcone('duree') ?>Durée</dt><dd id="planning-preview"></dd></div>
                            <div><dt><?= planningIcone('charge') ?>Charge estimée</dt><dd id="planning-volet-charge"></dd></div>
                        </dl>
                        <p class="pv-volet-note">La fin se recalcule sur le chiffrage, en jours ouvrés, quand on change le début.</p>
                        <div class="planning-volet-actions">
                            <button type="button" class="planning-retirer" id="planning-remove">Retirer du planning</button>
                            <span id="planning-save-message" class="pv-message" role="status" aria-live="polite"></span>
                            <button type="submit" class="pv-enregistrer">Enregistrer</button>
                        </div>
                    </form>
                <?php else: ?>
                    <dl class="pv-proprietes">
                        <div><dt><?= planningIcone('debut') ?>Début</dt><dd id="planning-volet-debut"></dd></div>
                        <div><dt><?= planningIcone('fin') ?>Fin</dt><dd id="planning-volet-fin"></dd></div>
                        <div><dt><?= planningIcone('charge') ?>Charge estimée</dt><dd id="planning-volet-charge"></dd></div>
                    </dl>
                <?php endif; ?>
            </section>

            <section class="pv-volet-section">
                <h3 class="pv-volet-titre">Demande</h3>
                <dl class="pv-proprietes">
                    <div><dt><?= planningIcone('personne') ?>Demandeur</dt><dd id="planning-volet-demandeur"></dd></div>
                    <div><dt><?= planningIcone('atelier') ?>Personnel atelier</dt><dd id="planning-volet-personnel"></dd></div>
                    <div><dt><?= planningIcone('matiere') ?>Matière</dt><dd id="planning-volet-matiere"></dd></div>
                    <div><dt><?= planningIcone('plan') ?>Plan</dt><dd id="planning-volet-plan"></dd></div>
                    <div><dt><?= planningIcone('camion') ?>Départ livraison</dt><dd id="planning-volet-depart"></dd></div>
                    <div><dt><?= planningIcone('camion') ?>Réception</dt><dd id="planning-volet-reception"></dd></div>
                </dl>
            </section>

            <section class="pv-volet-section">
                <div class="pv-volet-titre-rang">
                    <h3 class="pv-volet-titre">Avancement</h3>
                    <button type="button" class="planning-exporter" id="btn-export-avancement">Exporter</button>
                </div>
                <div class="planning-matrice-scroll">
                    <table class="planning-matrix-table" id="matrix-avancement">
                        <thead id="matrix-head"><tr><th></th></tr></thead>
                        <tbody id="matrix-body"></tbody>
                    </table>
                </div>
            </section>

            <section class="pv-volet-section">
                <div class="pv-volet-titre-rang">
                    <h3 class="pv-volet-titre">Commentaires</h3>
                    <button type="button" class="planning-exporter" id="btn-export-commentaires">Exporter</button>
                </div>
                <div id="comments-list" class="planning-commentaires"></div>
            </section>
        </div>
    </div>

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
