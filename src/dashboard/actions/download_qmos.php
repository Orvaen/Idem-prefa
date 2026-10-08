<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier.php';

// L'adresse porte la référence de la demande, pas son identifiant technique.
$id = prefaIdDepuisReference((string) ($_GET['ref'] ?? ''));
$fileId = filter_var($_GET['file'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id) prefaError(400, 'Demande invalide.');
if (isset($_GET['file']) && !$fileId) prefaError(400, 'Document invalide.');
if ($isWorkshopPersonnel && (!$fileId || !atelierDocumentAllowed($db, (int) $actor['id'], $id, 'qmos', (string) $fileId))) prefaError(404, 'Document introuvable ou inaccessible.');

$stmt = $db->prepare('SELECT q.id, q.contenu FROM demande_qmos_documents q JOIN demande_prefabrication d ON d.id = q.id_demande WHERE d.id = ?'
    . ($fileId ? ' AND q.id = ?' : '') . ($canViewAllPrefa || $isWorkshopPersonnel ? '' : ' AND d.idUsers = ?') . ' ORDER BY q.id LIMIT 1');
$params = [$id];
if ($fileId) $params[] = $fileId;
if (!$canViewAllPrefa && !$isWorkshopPersonnel) $params[] = $actor['id'];
$stmt->execute($params);
$document = $stmt->fetch();

if (!$document) prefaError(404, 'Document introuvable ou inaccessible.');
$pdf = $document['contenu'];

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (($_GET['preview'] ?? '') === '1' ? 'inline' : 'attachment') . '; filename="QMOS-' . $document['id'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
