<?php
// Create one account. Execute via CLI in the PHP container:
//
//   run admin Dupont Jean
//   run admin Dupont Jean --role chef-atelier --courriel jean.dupont@eitc-be.com
//
// It used to hold one fixed account — name, email and password written here in clear —
// which made every installation share the same administrator password. Now it takes what
// it needs, and makes up a password when none is given, printing it once.
//
// Account creation itself goes through User::register(): the identifier format, the
// duplicate check and the hashing are the application's business, not this script's.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
chdir('/var/www/html');
require_once '/var/www/html/db.php';
require_once '/var/www/html/api/User.php';

function fin(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$options = getopt('', ['nom:', 'prenom:', 'role:', 'courriel:', 'mot-de-passe:']);
$nom = trim((string) ($options['nom'] ?? ''));
$prenom = trim((string) ($options['prenom'] ?? ''));
$courriel = trim((string) ($options['courriel'] ?? ''));
$motDePasse = (string) ($options['mot-de-passe'] ?? '');
$roleDemande = trim((string) ($options['role'] ?? '')) ?: 'charge-production';

if ($nom === '' || $prenom === '') {
    fin('Nom et prénom sont nécessaires : run admin <nom> <prenom> [--role …] [--courriel …]');
}

// Le rôle se nomme, il ne se numérote pas : « 5 » ne dit pas qu'il s'agit du chargé
// d'affaire, et le numéro 4 n'existe même pas.
$role = null;
foreach (Role::cases() as $cas) {
    if ($cas->abrege() === $roleDemande) $role = $cas;
}
if ($role === null) {
    $connus = implode(', ', array_map(static fn (Role $r): string => $r->abrege(), Role::cases()));
    fin('Rôle inconnu : ' . $roleDemande . '. Les rôles sont : ' . $connus . '.');
}

// Un mot de passe qu'on ne donne pas est tiré au sort et montré une fois. Mieux qu'un mot
// de passe par défaut, que personne ne change, et mieux qu'un mot de passe tapé dans le
// terminal, qui reste dans l'historique du shell.
$tireAuSort = $motDePasse === '';
if ($tireAuSort) {
    $motDePasse = substr(strtr(base64_encode(random_bytes(18)), '+/', '23'), 0, 16);
}

try {
    $db = new MyPDO();
    $user = new User($db);
    $user->register($nom, $prenom, $role->value, $motDePasse, $courriel !== '' ? $courriel : null);
} catch (DomainException $erreur) {
    fin($erreur->getMessage());
} catch (Throwable $erreur) {
    fin('Compte non créé : ' . $erreur->getMessage());
}

echo 'Compte créé : ', User::buildIdentifiant($nom, $prenom), ' (', $role->metier(), ')', PHP_EOL;
if ($tireAuSort) {
    echo 'Mot de passe : ', $motDePasse, PHP_EOL;
    echo 'Notez-le maintenant : il n\'est écrit nulle part, et il ne se relit pas.', PHP_EOL;
}
