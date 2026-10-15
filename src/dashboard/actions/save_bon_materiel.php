<?php
// Enregistre les articles d'un bon tels que l'encadrement les a corrigés.
//
// La lecture propose, elle ne décide pas : c'est ici que la liste devient une liste dont
// quelqu'un répond. Le nom de qui a corrigé et la date sont retenus avec elle.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
prefaPost();

if (!$canViewAllPrefa) prefaError(403, 'Les bons de commande sont corrigés par le chargé de production ou le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');

$stmt = $db->prepare('SELECT doc.id, doc.date_lecture FROM demande_materiel_documents doc
    JOIN demande_prefabrication d ON d.id = doc.id_demande
    WHERE doc.id = ?');
$stmt->execute([$idDocument]);
$bon = $stmt->fetch();
if (!$bon) prefaError(404, 'Bon introuvable ou inaccessible.');

// Les lignes arrivent en JSON : quatre champs sur quarante lignes feraient quatre
// tableaux parallèles de cent soixante cases, et plus personne ne saurait lequel va
// avec lequel.
$lignes = json_decode((string) ($_POST['lignes'] ?? '[]'), true);
if (!is_array($lignes)) prefaError(400, 'Liste d’articles illisible.');

try {
    $articles = materielEnregistrer($db, $idDocument, $lignes, (int) $actor['id']);
} catch (DomainException $exception) {
    prefaError(422, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Enregistrement du bon ' . $idDocument . ' : ' . $exception->getMessage());
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'document' => materielPayload($document),
    'message' => $articles
        ? $articles . ' article' . ($articles > 1 ? 's' : '') . ' enregistré' . ($articles > 1 ? 's' : '') . '.'
        : 'Liste vidée : ce bon ne porte plus aucun article.',
]);
