<?php
// La personne choisit son mot de passe — première connexion, ou après une réinitialisation
// par l'administrateur — et entre.
//
// L'écran de connexion y mène quand action.php répond « creer » : le compte existe et n'a
// pas de mot de passe. Il n'y en a pas d'autre à vérifier ; le compte ne doit simplement pas
// en avoir un déjà (User::creerMotDePasse()).

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
$motDePasse = (string) ($_POST['password'] ?? '');
$refus = User::motDePasseRefus($motDePasse, (string) ($_POST['confirmation'] ?? ''));
if ($login === '') $refuser(400, 'Indiquez votre identifiant.');
if ($refus !== null) $refuser(400, $refus);

try {
    $db = new MyPDO(__DIR__ . '/../my_setting.ini');
    $user = new User($db);
    if (!$user->creerMotDePasse($login, $motDePasse)) {
        // Le compte a déjà un mot de passe — choisi entre-temps, dans un autre onglet ou par
        // quelqu'un d'autre —, ou il n'existe pas : dans les deux cas on se connecte, ou on
        // demande une réinitialisation.
        $refuser(409, 'Ce compte a déjà un mot de passe. Connectez-vous, ou signalez un oubli à l’administrateur.');
    }
    echo json_encode(['success' => true]);
} catch (PDOException $erreur) {
    Journal::erreur('Création du mot de passe', $erreur);
    $refuser(500, 'Enregistrement impossible. Réessayez dans quelques instants.');
}
