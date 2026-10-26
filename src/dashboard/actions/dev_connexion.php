<?php
// Prendre la place d'un compte sans son mot de passe. Développement uniquement.
//
// Deux verrous, et il faut les deux :
//   1. le serveur tourne en développement — APP_ENV=dev, lu dans son environnement ;
//   2. la requête porte le jeton de la session en cours.
//
// Le premier passe avant tout le reste, et notamment avant le contexte, qui répondrait 401
// à un visiteur non connecté. Un 401 dit qu'il y a quelque chose à cette adresse ; en
// production, cette action ne doit exister pour personne, pas même en creux.
//
// Il y avait un troisième verrou : être chargé de production. Il ne protégeait rien —
// auth/dev_connexion.php, juste à côté, ouvre n'importe quel compte à qui n'est même pas
// connecté — et il coûtait cher à l'usage : une fois passé sur un compte d'atelier, on ne
// pouvait plus sauter vers un troisième sans repasser par l'administrateur, et la liste
// disparaissait sans dire pourquoi. En développement, cette porte est ce qu'on vient
// chercher ; en production, le fichier répond 404 à tout le monde.
require_once __DIR__ . '/../../modeles/Environnement.php';

if (!Environnement::estDev()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Page introuvable.']);
    exit;
}

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/comptes.php';
require_once __DIR__ . '/../../api/User.php';

prefaPost();

$compte = trim((string) ($_POST['compte'] ?? ''));
if ($compte === '') prefaError(400, 'Compte manquant.');

// Relevé avant la bascule : après, la session porte déjà le nouveau nom.
$precedent = (string) $utilisateur->identifiant;

$user = new User($db);
if (!$user->startSession($compte)) prefaError(404, 'Ce compte n’existe pas.');

// Les deux comptes rejoignent la liste des comptes connectés : on revient à son propre
// compte d'un clic, sans ressaisir de mot de passe.
comptesRemember($precedent);
comptesRemember($compte);
session_regenerate_id(true);

// Une prise de place laisse une trace, même en développement : c'est la seule façon de
// comprendre, plus tard, pourquoi une donnée porte le nom de quelqu'un d'autre.
Journal::noter(sprintf('[dev] %s a pris la place de %s', $precedent, $compte));

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
