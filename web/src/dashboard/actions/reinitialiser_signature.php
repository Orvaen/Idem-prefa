<?php
// L'administrateur réinitialise la signature d'un compte. Validée une fois par la personne,
// elle ne se change pas autrement (includes/atelier/signature.php) : elle est retirée — elle
// reste sur les soudures qu'elle a déjà visées, comme sur le papier —, et la personne en trace
// une nouvelle dans ses paramètres, qu'elle valide à son tour.
//
// Répond par la ligne du compte, comme reinitialiser_mot_de_passe.php : l'écran la remplace
// sans recharger.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/user_row.php';

prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut réinitialiser une signature.');
if (!signaturesEnBase($db)) prefaError(409, 'Les signatures n’existent pas encore sur ce serveur.');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Compte invalide.');
// La sienne se refait dans Paramètres → Compte : l'administrateur y garde la main.
if ($id === $utilisateur->id) prefaError(400, 'La vôtre se refait dans Paramètres → Compte.');

$lecture = $db->prepare('SELECT identifiant, prenom, name FROM Utilisateur WHERE id = ?');
$lecture->execute([$id]);
$compte = $lecture->fetch();
if (!$compte) prefaError(404, 'Compte introuvable.');

signatureSupprimer($db, $id);
Journal::noter('signature réinitialisée pour ' . $compte['identifiant']);

// La ligne n'en change pas d'aspect — elle ne montre pas la signature — : le toast dit que
// c'est fait (js/composants/toast.js).
header('HX-Trigger: ' . json_encode(['toast' => 'Signature de ' . trim($compte['prenom'] . ' ' . $compte['name'])
    . ' réinitialisée : la personne en tracera une nouvelle.']));

$details = $db->prepare('SELECT u.*, r.name AS role_name' . signatureColonneCompte($db)
    . ' FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = ?');
$details->execute([$id]);
userRow($details->fetch(), $utilisateur->id);
