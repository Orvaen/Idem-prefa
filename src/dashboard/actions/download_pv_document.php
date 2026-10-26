<?php
// Sert un document de PV. Le droit de lecture suit celui de la demande : l'atelier
// consulte les PV des demandes où il est affecté, le reste est réservé à l'encadrement.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/pv.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Document invalide.');

$stmt = $db->prepare('SELECT d.cle, d.nom, p.id AS id_pv, p.id_demande
    FROM pv_document d JOIN pv_prefabrication p ON p.id = d.id_pv
    WHERE d.id = ?');
$stmt->execute([$id]);
$document = $stmt->fetch();
if (!$document) prefaError(404, 'Document introuvable.');

if (!$canViewAllPrefa) {
    if ($isWorkshopPersonnel) {
        $stmt = $db->prepare('SELECT 1 FROM affectation_atelier a
            JOIN element_atelier e ON e.id = a.id_element
            WHERE a.id_utilisateur = ? AND a.actif = 1 AND e.id_demande = ? LIMIT 1');
        $stmt->execute([$actor['id'], $document['id_demande']]);
    } else {
        $stmt = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
        $stmt->execute([$document['id_demande'], $actor['id']]);
    }
    if (!$stmt->fetchColumn()) prefaError(404, 'Document introuvable ou inaccessible.');
}

$chemin = pvDocumentDirectory((int) $document['id_pv']) . '/' . $document['cle'];
if (!is_file($chemin)) prefaError(404, 'Document introuvable.');

$type = (new finfo(FILEINFO_MIME_TYPE))->file($chemin);
$apercu = ($_GET['preview'] ?? '') === '1' && in_array($type, ['application/pdf', 'image/png', 'image/jpeg'], true);
header('Content-Type: ' . ($apercu ? $type : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($apercu ? 'inline' : 'attachment') . '; filename="' . $document['nom'] . '"');
header('Content-Length: ' . filesize($chemin));
readfile($chemin);
