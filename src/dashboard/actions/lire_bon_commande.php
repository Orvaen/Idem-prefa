<?php
// Lit les articles d'un bon de commande déposé dans le PV « Réception matériel ».
//
// La lecture ne décide de rien : elle renvoie ce qu'elle a cru lire, et le chef d'atelier
// corrige avant d'enregistrer. Rien n'est écrit en base ici.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/pv.php';
require_once __DIR__ . '/../includes/lecture/commandes_lignes.php';
prefaPost();
if (!$canFillPv) prefaError(403, 'Les bons de commande sont lus par le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Document invalide.');

// Le droit de lire le document est celui de la demande : on repasse par le PV plutôt que
// de faire confiance à l'identifiant reçu.
$stmt = $db->prepare('SELECT d.cle, d.nom, p.id AS id_pv, p.id_demande, p.type
    FROM pv_document d JOIN pv_prefabrication p ON p.id = d.id_pv
    WHERE d.id = ?');
$stmt->execute([$idDocument]);
$document = $stmt->fetch();
if (!$document || $document['type'] !== 'reception_materiel') prefaError(404, 'Document introuvable.');

$stmt = $db->prepare('SELECT id FROM demande_prefabrication WHERE id = ? AND id_statut = 2');
$stmt->execute([$document['id_demande']]);
if (!$stmt->fetchColumn()) prefaError(404, 'Demande introuvable ou non validée.');

$chemin = pvDocumentDirectory((int) $document['id_pv']) . '/' . $document['cle'];
if (!is_file($chemin)) prefaError(404, 'Document introuvable.');

$manquants = ccpuOutilsManquants();
if ($manquants) prefaError(503, 'Lecture impossible : ' . implode(', ', $manquants) . ' n’est pas installé.');

// La lecture d'un scan de quatre pages prend quelques secondes : la session est rendue
// avant, sinon les autres pages de la même personne attendraient derrière celle-ci.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$lu = commandeLignesLire($chemin);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'nom' => $document['nom'],
    'lecture' => $lu['lecture'],
    'pages' => $lu['pages'],
    'lignes' => $lu['lignes'],
    // Ce que l'écran doit dire avant que le chef d'atelier ne valide : une lecture par OCR
    // se trompe sur des caractères qui se ressemblent, et personne ne doit l'enregistrer
    // sans avoir relu.
    'message' => $lu['lignes']
        ? count($lu['lignes']) . ' article(s) lus' . ($lu['lecture'] === 'ocr' ? ' par reconnaissance d’image — relisez les références et les quantités.' : '.')
        : 'Aucun article reconnu dans ce document. Saisissez-les à la main.',
]);
