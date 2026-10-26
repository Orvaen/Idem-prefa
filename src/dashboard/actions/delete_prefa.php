<?php
// Supprimer une demande, depuis la liste.
//
// Qui peut effacer quoi, et dans quel état — plus les pièces jointes qui partent avec —
// vit dans Demande::supprimer(). L'API efface par la même méthode.
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Demande invalide.');

$demande = Demande::visible($db, $utilisateur, $id);
if (!$demande) prefaError(404, 'Demande introuvable.');

try {
    $effacee = $demande->supprimer($db, $utilisateur);
} catch (DomainException $refus) {
    prefaError(403, $refus->getMessage());
} catch (Throwable $erreur) {
    Journal::erreur('Suppression demande de préfabrication', $erreur);
    prefaError(500, 'Impossible de supprimer la demande. Réessayez.');
}

if (!$effacee) prefaError(409, 'Seules les demandes en attente peuvent être supprimées.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'Demande supprimée.']);
