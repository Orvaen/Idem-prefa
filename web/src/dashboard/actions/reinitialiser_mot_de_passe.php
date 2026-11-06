<?php
// L'administrateur réinitialise un mot de passe : il cède la place à un code d'accès, que
// l'administrateur donne à la personne ; avec lui, elle en choisit un nouveau à sa prochaine
// connexion (auth/creer_mot_de_passe.php). C'est la suite d'une demande — un oubli signalé à
// l'écran de connexion, ou un changement demandé dans les paramètres —, qui est alors
// traitée ; mais on peut aussi le faire de soi-même, pour un compte dont on doute. Sur un
// compte qui attendait déjà, c'est un nouveau code : perdu, passé, ou d'avant les codes.
//
// Le code n'est rendu qu'ici, une fois : l'en-tête HX-Trigger le porte à la fenêtre qui le
// montre (window.montrerCodeAcces, js/composants/alerte.js). Il n'est écrit nulle part.
//
// Répond par la ligne du compte, comme edit_user.php : l'écran la remplace sans recharger.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../../api/User.php';
require_once __DIR__ . '/../includes/vue/user_row.php';

prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut réinitialiser un mot de passe.');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Compte invalide.');
// Le sien se réinitialise dans Paramètres → Compte, qui ferme aussitôt la session : d'ici,
// elle resterait ouverte sur un compte sans mot de passe.
if ($id === $utilisateur->id) prefaError(400, 'Le vôtre se réinitialise dans Paramètres → Compte.');

$lecture = $db->prepare('SELECT identifiant, prenom, name FROM Utilisateur WHERE id = ?');
$lecture->execute([$id]);
$compte = $lecture->fetch();
if (!$compte) prefaError(404, 'Compte introuvable.');

$code = (new User($db))->reinitialiserMotDePasse($id);
Journal::noter('code d’accès donné pour ' . $compte['identifiant']);

// La pastille d'« Utilisateurs » suit : une demande de moins à traiter. Et la fenêtre du code
// dit que c'est fait : la ligne ne change que d'une pastille, et l'administrateur croyait que
// rien ne s'était passé.
header('HX-Trigger: ' . json_encode([
    'reinitialisationsEnAttente' => ['compte' => User::reinitialisationsEnAttente($db)],
    'codeAcces' => [
        'nom' => trim($compte['prenom'] . ' ' . $compte['name']),
        'identifiant' => $compte['identifiant'],
        'code' => $code['code'],
        'jusqua' => prefaFormatDate(gmdate('Y-m-d H:i:s', $code['jusqua'])),
    ],
]));
$details = $db->prepare('SELECT u.*, r.name AS role_name' . signatureColonneCompte($db) . ' FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = ?');
$details->execute([$id]);
userRow($details->fetch(), $utilisateur->id);
