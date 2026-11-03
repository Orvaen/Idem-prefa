<?php

/**
 * Le point d'entrée de l'API.
 *
 * Apache y renvoie tout ce qui commence par /api/ et ne correspond à aucun fichier
 * (FallbackResource, dans le Dockerfile) : les adresses restent donc celles qu'on écrit,
 * /api/demandes/12, sans index.php au milieu ni réécriture d'URL.
 *
 * Ce fichier ne fait que trois choses : savoir qui demande, refuser ce qui doit l'être, et
 * passer la main à la ressource. Tout le reste vit dans ressources/.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/autoload.php';

Session::demarrer();
$db = new MyPDO(__DIR__ . '/../my_setting.ini');
$requete = Requete::courante();

// Une méthode qui écrit demande le jeton. Le navigateur joint le cookie de session à
// toute requête, y compris celles qu'un autre site déclenche : le jeton est ce que cet
// autre site ne peut pas connaître. Il s'envoie en en-tête X-Jeton, ou dans le corps.
//
// L'ouverture de session en est dispensée, et elle seule : on n'a pas encore de jeton
// quand on se connecte. La fermeture, elle, n'en est pas dispensée — sans quoi n'importe
// quelle page du web pourrait déconnecter l'atelier en boucle.
$ouvreLaSession = $requete->segment(0) === 'session' && $requete->methode === 'POST';
if (!in_array($requete->methode, ['GET', 'HEAD'], true) && !$ouvreLaSession) {
    $jeton = $_SERVER['HTTP_X_JETON'] ?? $requete->champ('jeton');
    if (!Session::jetonValide(is_string($jeton) ? $jeton : null)) {
        Reponse::erreur(403, 'jeton_invalide', 'Rechargez la page puis réessayez.')->envoyer();
    }
}

// La session est la seule ressource ouverte à qui n'est pas connecté : sans elle, on ne
// pourrait jamais l'être.
if ($requete->segment(0) === 'session') {
    Connexion::repondre($requete, $db)->envoyer();
}

$utilisateur = Session::utilisateur($db);
if (!$utilisateur) Reponse::nonConnecte()->envoyer();

(match ($requete->segment(0)) {
    'moi' => Moi::repondre($requete, $db, $utilisateur),
    'demandes' => Demandes::repondre($requete, $db, $utilisateur),
    default => Reponse::introuvable('Cette adresse ne correspond à aucune ressource.'),
})->envoyer();
