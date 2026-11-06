<?php
// Son propre mot de passe, depuis Paramètres → Compte. Il ne s'y change pas : on demande au
// chargé de production de le réinitialiser, et l'on choisit le nouveau en se reconnectant —
// comme après un oubli. Le chargé de production réinitialise le sien lui-même : sa session
// se ferme, et l'écran de connexion lui fait choisir le nouveau tout de suite.
//
// Le compte vient de la session, jamais du formulaire. La réponse rend la ligne, que la
// fenêtre remplace.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/mot_de_passe.php';
prefaPost();

$operation = (string) ($_POST['operation'] ?? '');
if (!in_array($operation, ['demander', 'reinitialiser'], true)) prefaError(400, 'Action inconnue.');

$user = new User($db);
header('Content-Type: application/json; charset=utf-8');

if ($operation === 'reinitialiser') {
    if (!$isAdmin) prefaError(403, 'Demandez-le au chargé de production : il le réinitialisera.');
    $code = $user->reinitialiserMotDePasse($utilisateur->id);
    Journal::noter('mot de passe réinitialisé par son titulaire');
    // Tout de suite à l'écran de connexion : la session ne reste pas ouverte sur un compte qui
    // attend son mot de passe. Le code part avec la réponse : la page le montre avant de
    // quitter, et c'est avec lui qu'on choisit le nouveau.
    Session::fermer();
    echo json_encode([
        'success' => true,
        'message' => 'Mot de passe réinitialisé.',
        'connexion' => '../',
        'codeAcces' => [
            'soi' => true,
            'identifiant' => $utilisateur->identifiant,
            'code' => $code['code'],
            'jusqua' => prefaFormatDate(gmdate('Y-m-d H:i:s', $code['jusqua'])),
        ],
    ]);
    exit;
}

if ($isAdmin) prefaError(400, 'Réinitialisez-le vous-même.');
if (!User::reinitialisationsEnBase($db)) {
    prefaError(409, 'La demande n’existe pas encore sur ce serveur : prévenez le chargé de production de vive voix.');
}
$retenue = $user->demanderReinitialisation($utilisateur->identifiant, 'changement');
$etat = motDePasseEtat($db, $utilisateur->id);

echo json_encode([
    'success' => true,
    // Pas retenue : demandée depuis un autre onglet, ou déjà réinitialisé entre-temps.
    'message' => match (true) {
        $retenue => 'Demande envoyée au chargé de production.',
        $etat['reinitialise'] => 'Il est déjà réinitialisé : reconnectez-vous pour choisir le nouveau.',
        default => 'Votre demande est déjà partie.',
    },
    'html' => motDePasseParametre($etat, false),
]);
