<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../../api/User.php';

// Le test d'identifiant dit si un compte existe : il reste réservé à qui gère les comptes.
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut vérifier un identifiant.');

header('Content-Type: application/json; charset=utf-8');

$name = trim($_POST['name'] ?? '');
$prenom = trim($_POST['prenom'] ?? '');

if ($name === '' || $prenom === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Saisissez le nom et le prénom.']);
    exit;
}

try {
    $user = new User($db);
    $identifiant = User::buildIdentifiant($name, $prenom);
    $available = !$user->identifiantExists($identifiant);
    echo json_encode([
        'available' => $available,
        'message' => $available
            ? $identifiant . ' — disponible'
            : $identifiant . ' — déjà utilisé'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Vérification impossible. Modifiez le nom ou le prénom pour réessayer.']);
}
