<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/planning.php';
prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut organiser le planning.');

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$operation = $_POST['operation'] ?? 'save';
if (!$id || $revision === false || !in_array($operation, ['save', 'remove'], true)) prefaError(400, 'Planification invalide.');
$start = $operation === 'save' ? planningDate($_POST['start'] ?? '') : null;
$end = $operation === 'save' ? planningDate($_POST['end'] ?? '') : null;
if ($operation === 'save' && (!$start || !$end)) prefaError(400, 'Choisissez une date de début et une date de fin.');
if ($operation === 'save' && $end < $start) prefaError(400, 'La fin planifiée doit être le même jour ou après le début planifié.');

try {
    $db->beginTransaction();
    $find = $db->prepare('SELECT * FROM demande_prefabrication WHERE id = ? FOR UPDATE');
    $find->execute([$id]);
    $request = $find->fetch();
    if (!$request || (int) $request['id_statut'] !== 2) {
        $db->rollBack();
        prefaError(409, 'Seules les demandes validées peuvent être planifiées. Actualisez le planning.');
    }
    if ((int) $request['planning_revision'] !== $revision) {
        $db->rollBack();
        prefaError(409, 'Cette planification a été modifiée. Actualisez le planning avant de réessayer.');
    }
    if ($operation === 'remove') {
        $save = $db->prepare('UPDATE demande_prefabrication SET date_debut_planifiee = NULL, date_fin_planifiee = NULL, planning_revision = planning_revision + 1, DateUpdate = NOW() WHERE id = ?');
        $save->execute([$id]);
        $message = 'Demande ' . prefaReferenceComplete($id) . ' supprimée du planning.';
    } else {
        $period = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
        $save = $db->prepare('UPDATE demande_prefabrication SET date_debut_planifiee = ?, date_fin_planifiee = ?, planning_jours_ouvres = 1, planning_revision = planning_revision + 1, DateUpdate = NOW() WHERE id = ?');
        $save->execute([$period['start'], $period['end'], $id]);
        $message = 'Planning enregistré.';
    }
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'planning' => $operation === 'save' ? ['id' => (int) $id, 'start' => $period['start'], 'creator' => (int) $request['idUsers']] : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    if ($db->inTransaction()) $db->rollBack();
    prefaError(400, $error->getMessage());
} catch (PDOException $error) {
    if ($db->inTransaction()) $db->rollBack();
    prefaError(500, 'Impossible d’enregistrer le planning. Réessayez.');
}
