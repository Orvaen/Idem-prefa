<?php
// Valide une commande, et pointe du même geste ce qui est déjà arrivé.
//
// Une commande se vérifie contre son PDF, puis se valide : elle est figée, et se reçoit. On
// ouvrait pourtant son volet le bon de livraison en main, sur une commande pas encore
// validée, et rien ne s'y cochait — « pourquoi j'ai le bouton Valider la commande, alors que je ne
// peux pas cocher puis valider ? » (6 novembre 2026). L'écran laisse donc cocher à toutes
// les étapes, et les coches d'une commande à valider partent avec sa validation, jamais
// avant : une réception se pointe sur une commande validée.
//
// Les articles ne sont pas réécrits : on vient de les vérifier dans la liste, et leurs
// corrections s'enregistrent à part (save_bon_materiel.php, « Corriger les articles »).
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
prefaPost();

// Même porte que l'écran : la commande se vérifie là où elle se lit.
if (!$canViewAllPrefa) prefaError(403, 'Les bons de commande sont validés par le chargé de production ou le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');
if (materielEtat($document) === 'a_lire') prefaError(409, 'Le bon n’est pas encore lu : attendez la fin de sa lecture.');
if (!$document['lignes']) prefaError(422, 'Une commande sans article ne se valide pas. Lisez le bon ou ajoutez ses articles.');

$pointages = materielPointagesPostes($_POST['lignes'] ?? '[]');
if ($pointages === null) prefaError(400, 'Pointage illisible.');
if (count($pointages) > MATERIEL_LIGNES_MAX) prefaError(400, 'Trop de lignes pointées d’un coup.');

try {
    materielValider($db, $idDocument, (int) $actor['id']);
} catch (Throwable $exception) {
    Journal::erreur('Validation du bon ' . $idDocument, $exception);
    prefaError(500, 'Validation impossible. Réessayez.');
}

// La commande est validée : si les coches ne passent pas, on le dit, et elles se pointent
// de nouveau par « Valider la réception ».
try {
    materielPointerReception($db, $idDocument, $pointages, (int) $actor['id']);
} catch (Throwable $exception) {
    Journal::erreur('Pointage du bon ' . $idDocument . ' à sa validation', $exception);
    prefaError(500, 'Commande validée, mais les articles cochés n’ont pas pu être enregistrés. Cochez-les de nouveau, puis « Valider la réception ».');
}

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

$recus = count(array_filter($pointages));
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'document' => materielPayload($document),
    'message' => 'Commande validée.' . ($recus
        ? ' ' . $recus . ' article' . ($recus > 1 ? 's' : '') . ' reçu' . ($recus > 1 ? 's' : '') . '.'
        : ' Cochez les articles à mesure qu’ils arrivent.'),
]);
