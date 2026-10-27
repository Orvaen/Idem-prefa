<?php
require_once __DIR__ . '/../../dashboard/includes/prefa_context.php';
require_once __DIR__ . '/../../dashboard/includes/vue/user_row.php';

if (!$isAdmin) prefaError(403, 'Seul un administrateur peut modifier un compte.');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Compte invalide.');

$sql = "SELECT u.*, r.name AS role_name FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = :id";
$stmt = $db->prepare($sql);
$stmt->execute([
    ':id' => $id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) prefaError(404, 'Compte introuvable.');

// « view=1 » : l'annulation d'une modification redemande la ligne telle qu'elle est.
if (($_GET['view'] ?? '') === '1') {
    userRow($user, $utilisateur->id);
} else {
    userRowEdition($user);
}
