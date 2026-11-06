<?php
// Le compte de cet identifiant attend-il son premier mot de passe ?
//
// L'écran de connexion le demande dès que l'identifiant est saisi : à la première connexion
// — ou après une réinitialisation —, il n'y a pas de mot de passe à taper, et l'écran passe
// tout de suite à « Votre mot de passe ». Il montrait le champ « Mot de passe » sans rien
// dire, et la personne restait devant, bloquée, sans savoir qu'il fallait le laisser vide.
//
// Ce que la réponse révèle, auth/action.php le révélait déjà — il répond « creer » à qui
// envoie l'identifiant d'un tel compte, sans mot de passe. Un compte qui n'existe pas et un
// compte qui a son mot de passe répondent pareil : on ne devine pas les noms par ici.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/User.php';

Session::demarrer();
header('Content-Type: application/json; charset=utf-8');

$refuser = static function (int $statut, string $message): never {
    http_response_code($statut);
    echo json_encode(['creer' => false, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $refuser(405, 'Méthode non autorisée.');
if (!Session::jetonValide($_POST['csrf'] ?? null)) $refuser(403, 'La page a expiré : rechargez-la.');

$login = trim((string) ($_POST['login'] ?? ''));
if ($login === '') {
    echo json_encode(['creer' => false]);
    exit;
}

try {
    $db = new MyPDO(__DIR__ . '/../my_setting.ini');
    echo json_encode(['creer' => (new User($db))->motDePasseACreer($login)]);
} catch (PDOException $erreur) {
    Journal::erreur('Compte à créer', $erreur);
    // L'écran reste sur la connexion : « Se connecter » passe par auth/action.php, qui fait
    // la même vérification.
    $refuser(500, 'Le serveur ne répond pas.');
}
