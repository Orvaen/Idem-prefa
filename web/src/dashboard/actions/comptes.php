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
    if (!$user->login($login, $password)) prefaError(401, 'Identifiant ou mot de passe incorrect.');
    comptesRemember($precedent);
    comptesRemember((string) Session::identifiant());
    session_regenerate_id(true);
} elseif ($operation === 'creer') {
    // Un compte qui attend son premier mot de passe — tout juste créé, ou réinitialisé : comme
    // à l'écran de connexion, son code d'accès et le mot de passe choisi
    // (User::creerMotDePasse()), puis il rejoint les comptes de l'appareil.
    $login = trim((string) ($_POST['login'] ?? ''));
    $code = (string) ($_POST['code'] ?? '');
    $nouveau = (string) ($_POST['nouveau'] ?? '');
    if ($login === '' || trim($code) === '') prefaError(400, 'Renseignez l’identifiant et le code reçu.');
    $refus = User::motDePasseRefus($nouveau, (string) ($_POST['confirmation'] ?? ''));
    if ($refus !== null) prefaError(400, $refus);
    $precedent = (string) Session::identifiant();
    $refus = $user->creerMotDePasse($login, $code, $nouveau);
    if ($refus !== null) prefaError(403, $refus);
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
