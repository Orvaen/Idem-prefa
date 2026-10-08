<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier.php';
prefaPost();
if (!$isWorkshopPersonnel && !$canManageWorkshop) prefaError(403, 'Action réservée au personnel atelier et au chef d’atelier.');
$id = filter_var($_POST['affectation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$operation = $_POST['operation'] ?? '';
if (!$id || !in_array($operation, ['progress', 'time', 'steps'], true)) prefaError(400, 'Action invalide.');
if ($operation === 'time' && !$isWorkshopPersonnel) prefaError(403, 'Le pointage est réservé à la personne affectée.');
// Le chef d’atelier et l’administrateur suivent tous les plans ; chacun ne pointe que pour lui-même.
$toutesAffectations = $canManageWorkshop && $operation !== 'time';
try {
    $db->beginTransaction();
    if ($operation === 'time') $db->prepare('SELECT id FROM Utilisateur WHERE id = ? FOR UPDATE')->execute([$actor['id']]);
    $stmt = $db->prepare('SELECT a.* FROM affectation_atelier a JOIN element_atelier e ON e.id = a.id_element JOIN demande_prefabrication d ON d.id = e.id_demande WHERE a.id = ?' . ($toutesAffectations ? '' : ' AND a.id_utilisateur = ?') . ' AND a.actif = 1 AND d.id_statut = 2 FOR UPDATE');
    $stmt->execute($toutesAffectations ? [$id] : [$id, $actor['id']]);
    $assignment = $stmt->fetch();
    if (!$assignment) { $db->rollBack(); prefaError(404, 'Affectation introuvable ou retirée.'); }
    $comment = trim((string) ($_POST['commentaire'] ?? ''));
    if (mb_strlen($comment) > ($operation === 'time' ? 500 : 2000)) { $db->rollBack(); prefaError(400, 'Commentaire trop long.'); }
    if ($operation === 'steps') {
        $revision = filter_var($_POST['etapes_revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $values = $_POST['etapes'] ?? null;
        if ($revision === false || !is_array($values) || count($values) !== count(atelierSteps())) {
            $db->rollBack(); prefaError(400, 'Renseignez les ' . count(atelierSteps()) . ' étapes.');
        }
        $statuses = [];
        foreach (atelierSteps() as $key => $label) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || !in_array($value, ['', 'OK', 'Non OK', 'RAS'], true)) {
                $db->rollBack(); prefaError(400, 'Statut d’étape invalide.');
            }
            $statuses[$key] = $value;
        }
        $stmt = $db->prepare('SELECT revision FROM element_atelier_etapes WHERE id_element = ? FOR UPDATE');
        $stmt->execute([$assignment['id_element']]);
        $currentRevision = (int) ($stmt->fetchColumn() ?: 0);
        if ($currentRevision !== $revision) {
            $db->rollBack(); prefaError(409, 'Les étapes ont été modifiées. Actualisez avant de réessayer.');
        }
        $db->prepare('INSERT INTO element_atelier_etapes (id_element, statuts, revision, modifie_par) VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE statuts = VALUES(statuts), revision = revision + 1, modifie_par = VALUES(modifie_par), date_modification = NOW()')
            ->execute([$assignment['id_element'], json_encode($statuses, JSON_UNESCAPED_UNICODE), $actor['id']]);
        $db->prepare('INSERT INTO element_atelier_etapes_historique (id_element, statuts, revision, modifie_par) VALUES (?, ?, ?, ?)')
            ->execute([$assignment['id_element'], json_encode($statuses, JSON_UNESCAPED_UNICODE), $currentRevision + 1, $actor['id']]);
    } elseif ($operation === 'progress') {
        $progress = filter_var($_POST['avancement'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        $revision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($progress === false || $revision === false) { $db->rollBack(); prefaError(400, 'Avancement invalide (0 à 100 %).'); }
        if ((int) $assignment['revision'] !== $revision) { $db->rollBack(); prefaError(409, 'Cet avancement a changé. Actualisez avant de réessayer.'); }
        $db->prepare('UPDATE affectation_atelier SET avancement = ?, commentaire = ?, revision = revision + 1 WHERE id = ?')->execute([$progress, $comment ?: null, $id]);
        $db->prepare('INSERT INTO avancement_atelier (id_affectation, id_utilisateur, avancement, commentaire) VALUES (?, ?, ?, ?)')->execute([$id, $actor['id'], $progress, $comment ?: null]);
    } else {
        $minutes = filter_var($_POST['minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        $day = (string) ($_POST['date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        $key = (string) ($_POST['cle_saisie'] ?? '');
        if (!$minutes || !$date || $date->format('Y-m-d') !== $day || $day > date('Y-m-d') || !preg_match('/^[a-f0-9]{32}$/', $key)) { $db->rollBack(); prefaError(400, 'Indiquez une date non future et une durée de 1 à 1 440 minutes.'); }
        $duplicate = $db->prepare('SELECT id FROM pointage_atelier WHERE id_utilisateur = ? AND cle_saisie = ?');
        $duplicate->execute([$actor['id'], $key]);
        if (!$duplicate->fetchColumn()) {
            $sum = $db->prepare('SELECT COALESCE(SUM(duree_minutes), 0) FROM pointage_atelier WHERE id_utilisateur = ? AND date_travail = ?');
            // Serialize daily totals for a worker, including across different assignments.
            $sum->execute([$actor['id'], $day]);
            if ((int) $sum->fetchColumn() + $minutes > 1440) { $db->rollBack(); prefaError(400, 'Le total des pointages dépasse 24 heures pour cette journée.'); }
            $db->prepare('INSERT INTO pointage_atelier (id_affectation, id_utilisateur, date_travail, duree_minutes, commentaire, cle_saisie) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $actor['id'], $day, $minutes, $comment ?: null, $key]);
        }
    }
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Enregistrement effectué.']);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Avancement atelier: ' . $e->getMessage());
    prefaError(500, 'Impossible d’enregistrer. Réessayez.');
}
