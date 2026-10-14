<?php
// Le chef vise des cordons depuis l'écran « Soudures ».
//
// C'est la même signature que dans la fenêtre du plan, posée depuis l'écran où l'on regarde
// le travail de l'atelier plan par plan. Les contrôles RT, VI et PT ne passent pas par ici :
// ils se cochent en regardant le dessin, et le dessin est dans la fenêtre.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/soudure.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Seul l’encadrement vise une soudure.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// La demande doit être ouverte au travail : on ne vise pas sur un plan refermé.
$stmt = $db->prepare('SELECT 1 FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE e.id = ? AND d.id_statut = 2');
$stmt->execute([$elementId]);
if (!$stmt->fetchColumn()) prefaError(404, 'Plan introuvable ou demande non validée.');

$recues = $_POST['visas'] ?? [];
if (!is_array($recues)) prefaError(400, 'Saisie invalide.');
if (count($recues) > 500) prefaError(400, 'Trop de cordons envoyés.');

$visas = [];
foreach ($recues as $id => $vise) {
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id) $visas[$id] = trim((string) $vise) !== '';
}

try {
    $db->beginTransaction();
    $bilan = soudureViser($db, $elementId, $visas, (int) $actor['id']);
    $db->commit();
} catch (PDOException $erreur) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Visa soudures: ' . $erreur->getMessage());
    prefaError(500, 'Impossible d’enregistrer. Réessayez.');
}

$mots = [];
if ($bilan['poses']) $mots[] = $bilan['poses'] . ($bilan['poses'] === 1 ? ' soudure visée' : ' soudures visées');
if ($bilan['retires']) $mots[] = $bilan['retires'] . ($bilan['retires'] === 1 ? ' visa retiré' : ' visas retirés');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => $mots ? implode(' · ', $mots) . '.' : 'Rien à changer.',
]);
