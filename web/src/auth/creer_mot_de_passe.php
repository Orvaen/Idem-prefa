<?php
// La personne choisit son mot de passe — première connexion, ou après une réinitialisation
// par l'administrateur — avec le code d'accès qu'il lui a donné, et entre.
//
// L'écran de connexion y mène quand action.php répond « creer » : le compte existe et attend
// son mot de passe. Le code est la seule preuve qu'on est la bonne personne : sans lui,
// n'importe qui prenait le compte avec son identifiant (User::creerMotDePasse()).

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
$code = (string) ($_POST['code'] ?? '');
$motDePasse = (string) ($_POST['password'] ?? '');
$refus = User::motDePasseRefus($motDePasse, (string) ($_POST['confirmation'] ?? ''));
if ($login === '') $refuser(400, 'Indiquez votre identifiant.');
if (trim($code) === '') $refuser(400, 'Tapez le code que l’administrateur vous a donné.');
if ($refus !== null) $refuser(400, $refus);

try {
    $db = new MyPDO(__DIR__ . '/../my_setting.ini');
    $user = new User($db);
    // Refusé : un code faux ou passé, un compte sans code, ou qui a déjà son mot de passe —
    // choisi entre-temps, dans un autre onglet. La raison est celle à dire telle quelle.
    $refus = $user->creerMotDePasse($login, $code, $motDePasse);
    if ($refus !== null) $refuser(403, $refus);
    echo json_encode(['success' => true]);
} catch (PDOException $erreur) {
    Journal::erreur('Création du mot de passe', $erreur);
    $refuser(500, 'Enregistrement impossible. Réessayez dans quelques instants.');
}
