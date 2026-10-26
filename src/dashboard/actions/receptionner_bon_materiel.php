<?php
// Pointe la réception des articles d'un bon : ce qui est arrivé, et ce qui ne l'est pas.
//
// Action à part de l'enregistrement de la commande, et c'est voulu : une commande validée
// est figée — on ne retouche pas ce qui a été commandé — mais la marchandise continue
// d'arriver, parfois en deux fois, et il faut bien pouvoir la pointer. Le pointage ne
// touche donc qu'aux colonnes de réception.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
prefaPost();

// Celui qui reçoit la marchandise est à l'atelier : même porte que l'écran lui-même.
if (!$canViewAllPrefa) prefaError(403, 'La réception est pointée par le chargé de production ou le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');

$stmt = $db->prepare('SELECT doc.id FROM demande_materiel_documents doc
    JOIN demande_prefabrication d ON d.id = doc.id_demande
    WHERE doc.id = ?');
$stmt->execute([$idDocument]);
if (!$stmt->fetchColumn()) prefaError(404, 'Bon introuvable ou inaccessible.');

$recu = json_decode((string) ($_POST['lignes'] ?? '[]'), true);
if (!is_array($recu)) prefaError(400, 'Pointage illisible.');

// { identifiant de ligne : reçu ou non }. Les identifiants sont vérifiés par la requête
// elle-même, qui exige que la ligne appartienne à ce bon.
$pointages = [];
foreach ($recu as $idLigne => $estRecu) {
    $idLigne = filter_var($idLigne, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$idLigne) prefaError(400, 'Pointage illisible.');
    $pointages[$idLigne] = (bool) $estRecu;
}
if (count($pointages) > MATERIEL_LIGNES_MAX) prefaError(400, 'Trop de lignes pointées d’un coup.');

try {
    materielPointerReception($db, $idDocument, $pointages, (int) $actor['id']);
} catch (Throwable $exception) {
    Journal::erreur('Pointage du bon ' . $idDocument, $exception);
    prefaError(500, 'Pointage impossible. Réessayez.');
}

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

['recus' => $recus, 'total' => $total] = materielRecus($document);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'document' => materielPayload($document),
    'message' => $recus === 0
        ? 'Aucun article reçu pour l’instant.'
        : ($recus === $total
            ? 'Commande reçue en entier.'
            : $recus . ' article' . ($recus > 1 ? 's' : '') . ' sur ' . $total . ' reçu' . ($recus > 1 ? 's' : '') . '.'),
]);
