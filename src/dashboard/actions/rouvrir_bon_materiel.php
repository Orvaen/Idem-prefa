<?php
// Rouvre une commande validée : c'est « Modifier », sur la ligne du bon.
//
// Elle repasse « À vérifier », et ses articles se corrigent de nouveau dans le volet. Les
// réceptions déjà pointées restent ; il faudra la valider encore une fois.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
prefaPost();

if (!$canViewAllPrefa) prefaError(403, 'Les bons de commande sont corrigés par le chargé de production ou le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');
if (!materielDocument($db, $idDocument)) prefaError(404, 'Bon introuvable.');

try {
    materielRouvrir($db, $idDocument);
} catch (DomainException $exception) {
    // Déjà rouverte, par quelqu'un d'autre ou dans un autre onglet : ce n'est pas une panne.
    prefaError(409, $exception->getMessage());
} catch (Throwable $exception) {
    Journal::erreur('Réouverture du bon ' . $idDocument, $exception);
    prefaError(500, 'Impossible de rouvrir cette commande. Réessayez.');
}

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'document' => materielPayload($document),
    'message' => 'Commande rouverte : corrigez ses articles, puis validez-la de nouveau.',
]);
