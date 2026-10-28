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

// Rien de reconnu : avant de renvoyer « saisissez à la main », on regarde si ce n'est pas
// un autre document. Le cas courant est le certificat matière (EN 10204 3.1) déposé à la
// place du bon — même fournisseur, même enveloppe, et sur un PV de réception les deux
// arrivent ensemble. Le dire évite de chercher pourquoi « la lecture ne marche plus ».
$certificat = null;
if (!$lu['lignes']) {
    $ccpu = ccpuLire($chemin);
    if ($ccpu['certificat'] || $ccpu['coulees']) {
        $certificat = [
            'numero' => $ccpu['certificat'],
            'coulees' => array_values(array_map(static fn (array $c): string => (string) $c['numero'], $ccpu['coulees'])),
            'nuances' => $ccpu['nuances'],
            'dimensions' => $ccpu['dimensions'],
        ];
    }
}

// L'échéance la plus proche devient la date de livraison de la commande : c'est celle
// que l'atelier guette. Le chef la corrige si le bon arrive en plusieurs fois.
$echeances = array_values(array_filter(array_map(static fn (array $l): ?string => $l['date_livraison'] ?? null, $lu['lignes'])));
sort($echeances);

if ($lu['lignes']) {
    $message = count($lu['lignes']) . ' article(s) lus' . ($lu['lecture'] === 'ocr' ? ' par reconnaissance d’image — relisez les références et les quantités, puis enregistrez.' : ' — relisez, puis enregistrez.');
} elseif ($certificat) {
    $message = '« ' . $document['nom'] . ' » est un certificat matière'
        . ($certificat['numero'] ? ' (n° ' . $certificat['numero'] . ')' : '')
        . ', pas un bon de commande : il n’a pas d’articles à lire.'
        . ($certificat['coulees'] ? ' Coulées relevées : ' . implode(', ', $certificat['coulees']) . '.' : '');
} else {
    $message = 'Aucun article reconnu dans « ' . $document['nom'] . ' ». Saisissez-les à la main.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'nom' => $document['nom'],
    'lecture' => $lu['lecture'],
    'pages' => $lu['pages'],
    'numero' => $lu['numero'],
    'date' => $lu['date'],
    'livraison' => $echeances[0] ?? null,
    'lignes' => $lu['lignes'],
    'certificat' => $certificat,
    // Une lecture par OCR se trompe sur des caractères qui se ressemblent, et personne
    // ne doit l'enregistrer sans avoir relu : l'écran le dit.
    'message' => $message,
]);
