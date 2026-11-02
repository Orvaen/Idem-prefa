<?php
// Passwords are chosen by their owner, and only on the sign-in screen: an account is created
// without one, the person picks it at the first sign-in — once —; for a new one — forgotten,
// or to change — they ask the administrator, who resets it, and they pick the new one at the
// next sign-in.
//
// Creates its account in a transaction and rolls it back, with the alerts it sends; its PHP
// session is destroyed at the end. Run in the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/api/User.php';
$db = new MyPDO($root . '/my_setting.ini');

function checkMotDePasse(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// --- The rules of a chosen password.
checkMotDePasse(User::motDePasseRefus('court', 'court') !== null, 'A password under the minimum must be refused');
checkMotDePasse(User::motDePasseRefus('assez-long', 'pas-pareil') !== null, 'Two different entries must be refused');
checkMotDePasse(User::motDePasseRefus(str_repeat('x', 73), str_repeat('x', 73)) !== null, 'Beyond 72 bytes bcrypt reads nothing: refused');
checkMotDePasse(User::motDePasseRefus('assez-long', 'assez-long') === null, 'A long enough password typed twice must pass');

if (!User::reinitialisationsEnBase($db)) throw new RuntimeException('Run the 2026-11-02_mot_de_passe migration first');

Session::demarrer();
$db->beginTransaction();
try {
    $user = new User($db);
    $tag = bin2hex(random_bytes(3));
    // L'identifiant est fait de l'initiale du nom et du prénom : c'est le prénom qui porte
    // la marque de l'essai.
    $user->register('Essai', 'Mdp' . $tag, 3, '');
    $identifiant = User::buildIdentifiant('Essai', 'Mdp' . $tag);
    $id = (int) $db->query('SELECT id FROM Utilisateur WHERE identifiant = ' . $db->quote($identifiant))->fetchColumn();

    // --- Created without a password: nothing opens it, and it waits for its owner.
    checkMotDePasse($user->motDePasseACreer($identifiant), 'An account created without a password must wait for one');
    checkMotDePasse(!$user->login($identifiant, ''), 'An empty password must never open an account');
    checkMotDePasse(!$user->demanderReinitialisation($identifiant, 'oubli'), 'An account without a password has nothing to ask for');

    // --- The owner picks it, once; then it opens with it.
    checkMotDePasse($user->creerMotDePasse($identifiant, 'premier-mdp'), 'The first password must be accepted');
    checkMotDePasse(!$user->creerMotDePasse($identifiant, 'second-mdp'), 'A password must not be chosen twice without a reset');
    checkMotDePasse(!$user->motDePasseACreer($identifiant) && $user->login($identifiant, 'premier-mdp'), 'The chosen password must open the account');

    // --- A new one asked for: once, counted for the administrator, and every production
    // manager told — by name, with the reason.
    $avant = User::reinitialisationsEnAttente($db);
    $dernierEvenement = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM evenement')->fetchColumn();
    checkMotDePasse($user->demanderReinitialisation($identifiant, 'changement'), 'A new password must be asked for');
    checkMotDePasse(!$user->demanderReinitialisation($identifiant, 'oubli'), 'A second request must not warn the administrator again');
    checkMotDePasse(User::reinitialisationsEnAttente($db) === $avant + 1, 'The administrator must have one more reset to do');
    $annonces = $db->prepare('SELECT COUNT(*) FROM evenement
        WHERE id > ? AND type = ? AND id_acteur = ? AND donnees->>\'$.motif\' = ?');
    $annonces->execute([$dernierEvenement, Evenement::MOT_DE_PASSE_A_REINITIALISER, $id, 'changement']);
    $admins = (int) $db->query('SELECT COUNT(*) FROM Utilisateur WHERE id_role = ' . Role::ChargeDeProduction->value)->fetchColumn();
    checkMotDePasse((int) $annonces->fetchColumn() === $admins, 'Each production manager must be told once, with the reason');
    // Asking changes nothing by itself: the person keeps working with the current one.
    checkMotDePasse($user->login($identifiant, 'premier-mdp'), 'Until the reset, the current password must still open the account');

    // --- The administrator resets: the request is done, and the owner picks the new one.
    $user->reinitialiserMotDePasse($id);
    checkMotDePasse($user->motDePasseACreer($identifiant) && User::reinitialisationsEnAttente($db) === $avant,
        'A reset must empty the password and close the request');
    checkMotDePasse(!$user->login($identifiant, 'premier-mdp'), 'After a reset, the old password must not open the account');
    checkMotDePasse(!$user->demanderReinitialisation($identifiant, 'changement'), 'Once reset, there is nothing left to ask for');
    checkMotDePasse($user->creerMotDePasse($identifiant, 'le-nouveau'), 'After a reset, the owner must pick a new one');
    checkMotDePasse($user->login($identifiant, 'le-nouveau'), 'The new password must open the account');
} finally {
    $db->rollBack();
    $_SESSION = [];
    session_destroy();
}

echo 'Passwords chosen by their owner on the sign-in screen, a new one asked for and reset by the administrator: OK' . PHP_EOL;
