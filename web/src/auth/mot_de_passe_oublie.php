<?php
// « Mot de passe oublié », depuis l'écran de connexion : la personne le signale, chaque
// administrateur l'apprend — dans « Utilisateurs », et tout de suite s'il a l'application
// ouverte. Il réinitialise ; la personne choisit un nouveau mot de passe à sa connexion
// suivante. La même demande que « Demander » dans les paramètres, faite sans être connecté
// (User::demanderReinitialisation()).
//
// La réponse est la même que le compte existe ou non : sans cela, l'écran dirait à qui
// essaie des noms lesquels sont des comptes.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/User.php';

Session::demarrer();
header('Content-Type: application/json; charset=utf-8');

$refuser = static function (int $statut, string $message): never {
    http_response_code($statut);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $refuser(405, 'Méthode non autorisée.');
if (!Session::jetonValide($_POST['csrf'] ?? null)) $refuser(403, 'La page a expiré : rechargez-la.');

$login = trim((string) ($_POST['login'] ?? ''));
if ($login === '') $refuser(400, 'Indiquez votre identifiant.');

try {
    $db = new MyPDO(__DIR__ . '/../my_setting.ini');
    if (!User::reinitialisationsEnBase($db)) {
        $refuser(503, 'Prévenez directement le chargé de production : il réinitialisera votre mot de passe.');
    }
    (new User($db))->demanderReinitialisation($login, 'oubli');
    echo json_encode([
        'success' => true,
        'message' => 'C’est noté : l’administrateur est prévenu. Il vous donnera un code : avec lui, vous choisirez un nouveau mot de passe en vous connectant.',
    ]);
} catch (PDOException $erreur) {
    Journal::erreur('Mot de passe oublié', $erreur);
    $refuser(500, 'Le signalement n’a pas pu partir. Réessayez dans quelques instants.');
}
