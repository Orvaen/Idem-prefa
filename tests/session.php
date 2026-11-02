<?php
// A sign-in is worth two hours, counted from the password — not from the last click. The
// switch between remembered accounts happens without a password, so it must not push the
// deadline back; once past it, the remembered accounts go too.
//
// Uses a PHP session of its own, in the container's session directory, and destroys it at
// the end. No database. Run from the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/modeles/autoload.php';

function checkSession(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

Session::demarrer();
try {
    // --- A password sign-in starts the clock.
    Session::ouvrir('essai.session');
    Session::authentifiee();
    checkSession(Session::reste() > Session::DUREE - 5, 'A fresh sign-in must have its two hours ahead');
    checkSession(Session::identifiant() === 'essai.session', 'A fresh sign-in must be signed in');

    // --- Switching to a remembered account does not push the deadline back.
    $_SESSION['connexion'] = time() - 3600;
    Session::retenirCompte('essai.session');
    Session::retenirCompte('essai.autre');
    Session::ouvrir('essai.autre');
    checkSession(Session::reste() <= Session::DUREE - 3600, 'Switching accounts must not restart the two hours');

    // --- A new password check restarts them.
    Session::authentifiee();
    checkSession(Session::reste() > Session::DUREE - 5, 'A password check must restart the two hours');

    // --- Past the deadline: nobody is signed in, the remembered accounts are gone, and the
    // sign-in screen is told why — once.
    $_SESSION['connexion'] = time() - Session::DUREE;
    checkSession(Session::identifiant() === null, 'Past two hours, the session must be closed');
    checkSession(Session::comptes() === [], 'Past two hours, the remembered accounts must go: switching needs no password');
    checkSession(Session::vientDExpirer(), 'The sign-in screen must learn that the session expired');
    checkSession(!Session::vientDExpirer(), 'It must say so once, not at every visit');

    // --- A session opened before the rule has no time: its count starts now.
    Session::ouvrir('essai.session');
    unset($_SESSION['connexion']);
    checkSession(Session::identifiant() === 'essai.session' && Session::reste() > Session::DUREE - 5,
        'A session from before the rule must get its two hours from now, not be thrown out');
} finally {
    $_SESSION = [];
    session_destroy();
}

echo 'Sign-in lasts two hours from the password: OK' . PHP_EOL;
