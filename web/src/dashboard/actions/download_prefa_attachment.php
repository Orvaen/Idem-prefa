<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';

// L'adresse porte la référence de la demande, pas son identifiant technique.
$id = prefaIdDepuisReference((string) ($_GET['ref'] ?? ''));
$key = (string) ($_GET['file'] ?? '');
if (!$id || !preg_match('/^[a-f0-9]{32}-[A-Za-z0-9._-]+$/', $key)) prefaError(400, 'Document invalide.');

if ($isWorkshopPersonnel) {
    if (!atelierDocumentAllowed($db, (int) $actor['id'], $id, 'plan', $key)) prefaError(404, 'Document introuvable ou inaccessible.');
} else {
    $stmt = $db->prepare('SELECT id FROM demande_prefabrication WHERE id = ?' . ($canViewAllPrefa ? '' : ' AND idUsers = ?'));
    $stmt->execute($canViewAllPrefa ? [$id] : [$id, $actor['id']]);
    if (!$stmt->fetchColumn()) prefaError(404, 'Document introuvable ou inaccessible.');
}

$path = prefaAttachmentDirectory($id) . '/' . $key;
if (!is_file($path)) prefaError(404, 'Document introuvable.');

$name = substr($key, 33);
$preview = ($_GET['preview'] ?? '') === '1'
    && (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf';
header('Content-Type: ' . ($preview ? 'application/pdf' : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
