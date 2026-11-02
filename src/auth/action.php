<?php

require_once __DIR__ . './../db.php';
require_once __DIR__ . './../api/User.php';

Session::demarrer();

header('Content-Type: application/json; charset=utf-8');

$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';

try {
    $db = new MyPDO(__DIR__ . '/../my_setting.ini');
    $user = new User($db);
    // Un compte sans mot de passe — tout juste créé, ou réinitialisé à sa demande — ne se
    // refuse pas : la personne choisit le sien, et l'écran de connexion le lui demande
    // (creer_mot_de_passe.php). Rien n'est ouvert ici.
    if ($login !== '' && $user->motDePasseACreer($login)) {
        echo json_encode([
            'success' => false,
            'creer' => true,
            'message' => 'Choisissez votre mot de passe.',
        ]);
        exit;
    }
    if (!$user->login($login, $password)) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Identifiants incorrects.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Utilisateur connecté.'
    ]);

} catch (Exception $e) {
    Journal::erreur('Connexion impossible', $e);
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Connexion au serveur indisponible. Veuillez réessayer dans quelques instants.'
    ]);
}
