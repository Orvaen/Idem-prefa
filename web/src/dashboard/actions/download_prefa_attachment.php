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
$estPdf = (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf';

// Le lecteur de l'application demande les pages en images, à cette même adresse : le droit
// de les voir est celui du document, vérifié juste au-dessus. Il n'y a plus d'autre lecteur —
// celui du navigateur, dans une iframe, n'affichait rien dans l'appli Android.
//
// Rendues depuis une copie, dans le cache des aperçus (pdfPagesDepuisContenu), et non à côté
// du fichier : les images rempliraient le dossier des pièces jointes de la demande.
if ($estPdf) {
    require_once __DIR__ . '/../includes/lecture/pdf_pages.php';
    if (pdfPagesDemandees($_GET)) pdfPagesRepondre(pdfPagesDepuisContenu((string) file_get_contents($path)), $_GET);
}

$preview = ($_GET['preview'] ?? '') === '1' && $estPdf;
header('Content-Type: ' . ($preview ? 'application/pdf' : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
