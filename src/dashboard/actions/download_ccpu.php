<?php
require_once __DIR__ . '/../includes/prefa_context.php';

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fileId = filter_var($_GET['file'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Demande invalide.');
if (isset($_GET['file']) && !$fileId) prefaError(400, 'Document invalide.');
// Le certificat matière décrit la demande, pas un plan : comme la commande de matériel,
// il n'est pas rattaché à un élément d'atelier. Encadrement et demandeur propriétaire.
if (!$canViewAllPrefa) {
    $proprietaire = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
    $proprietaire->execute([$id, $actor['id']]);
    if (!$proprietaire->fetchColumn()) prefaError(404, 'Document introuvable ou inaccessible.');
}

$stmt = $db->prepare('SELECT doc.id, doc.contenu FROM demande_ccpu_documents doc
    JOIN demande_prefabrication d ON d.id = doc.id_demande
    WHERE d.id = ?' . ($fileId ? ' AND doc.id = ?' : '') . ' ORDER BY doc.id LIMIT 1');
$params = [$id];
if ($fileId) $params[] = $fileId;
$stmt->execute($params);
$document = $stmt->fetch();
if (!$document) prefaError(404, 'Document introuvable ou inaccessible.');
$pdf = $document['contenu'];

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (($_GET['preview'] ?? '') === '1' ? 'inline' : 'attachment') . '; filename="CCPU-' . $document['id'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
