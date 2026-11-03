<?php
// La liste des unités du matériel, tenue par le chargé de production dans les paramètres :
// ajouter, renommer, retirer. Chaque réponse rend la liste entière, dans son ordre, et la
// fenêtre la remplace : une unité ajoutée se range à sa place sans que le script ait à la
// chercher.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/unites.php';
prefaPost();

if (!$isAdmin) prefaError(403, 'Les unités sont tenues par le chargé de production.');
if (!unitesEnBase($db)) {
    prefaError(409, 'La liste des unités n’existe pas encore sur ce serveur : passez la migration 2026-10-29_unites_materiel.');
}

$operation = (string) ($_POST['operation'] ?? '');
if (!in_array($operation, ['ajouter', 'renommer', 'supprimer'], true)) prefaError(400, 'Action inconnue.');
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($operation !== 'ajouter' && !$id) prefaError(400, 'Unité invalide.');
$libelle = (string) ($_POST['libelle'] ?? '');

try {
    if ($operation === 'ajouter') {
        uniteAjouter($db, $libelle);
        $message = 'Unité ajoutée.';
    } elseif ($operation === 'renommer') {
        uniteRenommer($db, $id, $libelle);
        $message = 'Unité renommée. Les manques déjà signalés gardent l’ancien nom.';
    } else {
        uniteSupprimer($db, $id);
        $message = 'Unité retirée de la liste. Les manques qui la portent la gardent.';
    }
} catch (DomainException $refus) {
    prefaError(422, $refus->getMessage());
} catch (PDOException $erreur) {
    Journal::erreur('Unités du matériel', $erreur);
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => $message,
    'html' => unitesLignesRendu(unitesMateriel($db)),
]);
