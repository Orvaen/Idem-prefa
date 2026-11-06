<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../../api/User.php';

prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut créer un compte.');

header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');
$name = trim($_POST['name'] ?? '');
$role = $_POST['role'] ?? '';
$prenom = trim($_POST['prenom'] ?? '');

// Pas de mot de passe : la personne choisit le sien à sa première connexion, avec le code
// d'accès que la création rend. L'administrateur n'en connaît donc aucun ; il transmet
// l'identifiant et le code, montré une fois (window.montrerCodeAcces).
if ($name === '' || $prenom === '' || $role === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Veuillez remplir tous les champs obligatoires.'
    ]);

    exit;
}

// Le rôle vient du formulaire : il décide des droits du compte, donc il est relu en base
// plutôt que recopié tel quel.
$role = filter_var($role, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$role) prefaError(400, 'Choisissez un rôle dans la liste.');
$known = $db->prepare('SELECT 1 FROM role WHERE id = ?');
$known->execute([$role]);
if (!$known->fetchColumn()) prefaError(400, 'Choisissez un rôle dans la liste.');

$user = new User($db);

try {
    $code = $user->register($name, $prenom, $role, '', $email);
    $nouveau = (int) $db->lastInsertId();
    $identifiant = User::buildIdentifiant($name, $prenom);
    Journal::noter('code d’accès donné pour ' . $identifiant);

    echo json_encode([
        'success' => true,
        'message' => 'Compte créé : ' . $identifiant . '.',
        // Pour le choisir aussitôt là où on l'a créé — le champ « Chargé d'affaire » d'une
        // affaire (affaires.js) : son identifiant et son libellé, comme dans les listes.
        'compte' => ['id' => $nouveau, 'libelle' => trim($prenom . ' ' . $name)],
        'codeAcces' => [
            'nom' => trim($prenom . ' ' . $name),
            'identifiant' => $identifiant,
            'code' => $code['code'],
            'jusqua' => prefaFormatDate(gmdate('Y-m-d H:i:s', $code['jusqua'])),
        ],
    ]);

} catch (DomainException $e) {
    http_response_code(409);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => "Impossible de créer l'utilisateur."
    ]);
}
