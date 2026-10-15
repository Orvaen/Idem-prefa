<?php
require_once __DIR__ . '/../includes/prefa_context.php';
if (!$canEditPrefa) prefaError(403, 'Suppression réservée aux demandeurs.');
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
prefaPost();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Demande invalide.');

$check = $db->prepare('SELECT idUsers, id_statut FROM demande_prefabrication WHERE id = ?');
$check->execute([$id]);
$request = $check->fetch();
if (!$request) prefaError(404, 'Demande introuvable.');
if (!$isAdmin && (int) $request['idUsers'] !== (int) $actor['id']) prefaError(403, 'Vous ne pouvez pas supprimer cette demande.');
if (!$isAdmin && (int) $request['id_statut'] !== 1) prefaError(409, 'Seules les demandes en attente peuvent être supprimées.');

try {
    $db->beginTransaction();
    $delete = $db->prepare('DELETE FROM demande_prefabrication WHERE id = ?' . ($isAdmin ? '' : ' AND idUsers = ? AND id_statut = 1'));
    $delete->execute($isAdmin ? [$id] : [$id, $actor['id']]);
    if ($delete->rowCount() !== 1) throw new RuntimeException('La demande ne peut plus être supprimée.');
    $db->commit();

    $directory = prefaAttachmentDirectory((int) $id);
    foreach (prefaAttachments((int) $id) as $attachment) {
        $path = $directory . '/' . $attachment['key'];
        if (is_file($path)) unlink($path);
    }
    if (is_dir($directory)) rmdir($directory);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Demande supprimée.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Suppression demande de préfabrication : ' . $e->getMessage());
    prefaError(500, 'Impossible de supprimer la demande. Réessayez.');
}
