<?php
// Se connecter à un compte sans son mot de passe, depuis l'écran de connexion.
// Développement uniquement.
//
// C'est le pendant de dashboard/actions/dev_connexion.php, mais pour le cas où personne
// n'est encore connecté : là-bas un chargé de production prend la place d'un autre, ici
// on entre sans compte de départ. Le second verrou de l'autre action — « être déjà
// administrateur » — ne peut donc pas s'appliquer, et APP_ENV reste seul à garder la porte.
//
// C'est assumé : en développement, cette porte est précisément ce qu'on vient chercher.
// En production, APP_ENV ne vaut pas « dev » et ce fichier répond 404 à tout le monde,
// avant même d'ouvrir la base. Le docker-compose livré est en production par défaut, et
// le .env qui bascule en développement n'est pas suivi par git.
require_once __DIR__ . '/../modeles/Environnement.php';

if (!Environnement::estDev()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Page introuvable.']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../modeles/autoload.php';
require_once __DIR__ . '/../api/User.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

Session::demarrer();

// Le jeton vient de la page de connexion elle-même : il ne protège pas un compte — il n'y
// en a pas encore — mais il empêche qu'un autre site poste ici à votre insu.
if (!Session::jetonValide($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Rechargez la page puis réessayez.']);
    exit;
}

$compte = trim((string) ($_POST['compte'] ?? ''));
if ($compte === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Compte manquant.']);
    exit;
}

$db = new MyPDO(__DIR__ . '/../my_setting.ini');
$user = new User($db);

if (!$user->startSession($compte)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ce compte n’existe pas.']);
    exit;
}

session_regenerate_id(true);
Journal::noter(sprintf('[dev] connexion sans mot de passe sur %s', $compte));

echo json_encode(['success' => true]);
