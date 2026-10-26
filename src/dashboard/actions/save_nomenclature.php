<?php
// Enregistre la nomenclature d'un plan. Réservé à qui gère l'atelier : c'est une donnée
// de traçabilité, pas un brouillon.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/plan_document.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Seul l’encadrement peut saisir la nomenclature.');

$elementId = filter_var($_POST['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// Le plan doit appartenir à une demande validée : on ne saisit pas la nomenclature
// d'une demande encore en attente ou refusée.
$stmt = $db->prepare('SELECT e.id FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE e.id = ? AND d.id_statut = 2');
$stmt->execute([$elementId]);
if (!$stmt->fetchColumn()) prefaError(404, 'Plan introuvable ou demande non validée.');

// La feuille ISO visée. Un élément en porte plusieurs, chacune avec sa nomenclature et sa
// liste soudure : écrire sans préciser laquelle toucherait la mauvaise.
$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucun plan rattaché à cet élément.');
$documentId = $plan['id'];

// La page visée dans cette feuille : un cahier ISO porte une nomenclature par page, et
// les cases de la page 3 n'ont rien à voir avec celles de la page 4.
$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > (int) $plan['pages']) prefaError(400, 'Page du plan invalide.');

// Refus d'une écriture bâtie sur un état périmé : elle effacerait en silence ce
// qu'un autre a saisi entre-temps.
$empreinte = (string) ($_POST['empreinte'] ?? '');
if ($empreinte !== '' && !hash_equals(nomenclatureEmpreinte($db, $elementId, $documentId, $page), $empreinte)) {
    prefaError(409, 'La nomenclature a changé depuis l’ouverture de cette page. Rechargez-la avant d’enregistrer, sinon vous effaceriez la saisie de quelqu’un d’autre.');
}

$lignes = $_POST['lignes'] ?? [];
if (!is_array($lignes)) prefaError(400, 'Nomenclature invalide.');
if (count($lignes) > 200) prefaError(400, 'Une nomenclature ne peut pas dépasser 200 lignes.');

try {
    $total = nomenclatureEnregistrer($db, $elementId, $documentId, $page, $lignes, (int) $actor['id']);
} catch (PDOException $erreur) {
    prefaError(500, 'Enregistrement impossible.');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'lignes' => $total,
    'empreinte' => nomenclatureEmpreinte($db, $elementId, $documentId, $page),
    'message' => $total === 0
        ? 'Aucune coulée renseignée.'
        : $total . ($total === 1 ? ' coulée enregistrée.' : ' coulées enregistrées.'),
]);
