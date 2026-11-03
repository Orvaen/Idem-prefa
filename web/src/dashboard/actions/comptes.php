<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/comptes.php';
require_once __DIR__ . '/../../api/User.php';
prefaPost();

$operation = $_POST['operation'] ?? '';
$compte = trim((string) ($_POST['compte'] ?? ''));
$user = new User($db);
$redirect = null;

if ($operation === 'add') {
    // Ajouter un compte demande son mot de passe ; basculer ensuite n’en demandera plus.
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($login === '' || $password === '') prefaError(400, 'Renseignez l’identifiant et le mot de passe.');
    $precedent = (string) Session::identifiant();
    if (!$user->login($login, $password)) prefaError(401, 'Identifiants incorrects.');
    comptesRemember($precedent);
    comptesRemember((string) Session::identifiant());
    session_regenerate_id(true);
} elseif ($operation === 'switch') {
    if (!in_array($compte, comptesSession(), true)) prefaError(403, 'Ce compte n’est pas connecté sur cet appareil.');
    if ($compte !== Session::identifiant()) {
        if (!$user->startSession($compte)) {
            comptesForget($compte);
            prefaError(404, 'Ce compte n’existe plus.');
        }
        session_regenerate_id(true);
    }
} elseif ($operation === 'remove') {
    comptesForget($compte);
    if ($compte === Session::identifiant()) {
        // Retirer le compte actif bascule sur le premier compte encore valide…
        $suivant = null;
        foreach (comptesStored() as $identifiant) {
            if ($user->startSession($identifiant)) { $suivant = $identifiant; break; }
        }
        if ($suivant !== null) {
            session_regenerate_id(true);
        } else {
            // … et s’il n’en reste aucun, la session est fermée immédiatement.
            Session::fermer();
            $redirect = '../auth/logout.php';
        }
    }
} else {
    prefaError(400, 'Action invalide.');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'redirect' => $redirect]);
