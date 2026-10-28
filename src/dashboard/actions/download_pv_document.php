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

// Le sommaire du visualiseur : le nombre de pages, puis une vignette par page. Le droit
// est celui du document lui-même, vérifié juste au-dessus.
if ($type === 'application/pdf' && (isset($_GET['pages']) || isset($_GET['vignette']))) {
    require_once __DIR__ . '/../includes/lecture/commandes_lignes.php';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if (isset($_GET['pages'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['pages' => commandeLignesNombreDePages($chemin)]);
        exit;
    }

    $page = filter_var($_GET['vignette'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
    if (!$page) prefaError(400, 'Page invalide.');
    // Rasterisée une fois et gardée à côté du PDF : on reconnaît une page à son allure,
    // on n'y lit rien — 30 dpi suffisent et pèsent une dizaine de kilo-octets.
    $vignette = $chemin . '.p' . $page . '.jpg';
    if (!is_file($vignette)) {
        $base = substr($vignette, 0, -4);
        exec(sprintf('pdftoppm -r 30 -jpeg -f %d -l %d -singlefile %s %s 2>/dev/null',
            $page, $page, escapeshellarg($chemin), escapeshellarg($base)), $sortie, $code);
        if ($code !== 0 || !is_file($vignette)) prefaError(404, 'Page introuvable.');
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($vignette));
    readfile($vignette);
    exit;
}

$apercu = ($_GET['preview'] ?? '') === '1' && in_array($type, ['application/pdf', 'image/png', 'image/jpeg'], true);
header('Content-Type: ' . ($apercu ? $type : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($apercu ? 'inline' : 'attachment') . '; filename="' . $document['nom'] . '"');
header('Content-Length: ' . filesize($chemin));
readfile($chemin);
