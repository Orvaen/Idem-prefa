<?php
// L'administrateur réinitialise un mot de passe : il se vide, et la personne en choisit un
// nouveau à sa prochaine connexion (auth/creer_mot_de_passe.php). C'est la suite d'une
// demande — un oubli signalé à l'écran de connexion, ou un changement demandé dans les
// paramètres —, qui est alors traitée ; mais on peut aussi le faire de soi-même, pour un
// compte dont on doute.
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

$compte = $db->prepare('SELECT identifiant FROM Utilisateur WHERE id = ?');
$compte->execute([$id]);
$identifiant = $compte->fetchColumn();
if ($identifiant === false) prefaError(404, 'Compte introuvable.');

(new User($db))->reinitialiserMotDePasse($id);
Journal::noter('mot de passe réinitialisé pour ' . $identifiant);

// La pastille d'« Utilisateurs » suit : une demande de moins à traiter.
header('HX-Trigger: ' . json_encode(['reinitialisationsEnAttente' => ['compte' => User::reinitialisationsEnAttente($db)]]));
$details = $db->prepare('SELECT u.*, r.name AS role_name' . signatureColonneCompte($db) . ' FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = ?');
$details->execute([$id]);
userRow($details->fetch(), $utilisateur->id);
