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
// duplicate check, the email check and the hashing are the application's business, not
// this script's.
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

/** str_pad compte les octets : « Chargé » en vaut sept, et la colonne se décalait. */
function colonne(string $texte, int $largeur): string
{
    return $texte . str_repeat(' ', max(2, $largeur - mb_strlen($texte)));
}

$options = getopt('', ['nom:', 'prenom:', 'role:', 'courriel:', 'mot-de-passe:']);
$nom = trim((string) ($options['nom'] ?? ''));
$prenom = trim((string) ($options['prenom'] ?? ''));
$courriel = trim((string) ($options['courriel'] ?? ''));
$motDePasse = (string) ($options['mot-de-passe'] ?? '');
$roleDemande = trim((string) ($options['role'] ?? ''));

if ($nom === '' || $prenom === '') {
    fin('Nom et prénom sont nécessaires : run admin <nom> <prénom> [<rôle>] [--courriel …]');
}

// Le rôle se nomme, il ne se numérote pas : « 5 » ne dit pas qu'il s'agit du chargé
// d'affaire, et le numéro 4 n'existe même pas. Le nom attendu est celui qu'on a sous les
// yeux, dans n'importe lequel des trois vocabulaires du projet.
$role = $roleDemande === '' ? Role::ChargeDeProduction : Role::depuisLeTexte($roleDemande);
if ($role === null) {
    // Lister quatre abrégés ne suffisait pas : celui qui se trompe ne sait pas lequel des
    // trois vocabulaires le script attend, et relire le même vocabulaire ne le lui apprend
    // pas. Les trois noms de chaque profil sont donc montrés côte à côte, pour qu'il
    // reconnaisse le sien — et le mot de travers vient souvent d'un nom en deux mots.
    $profils = array_map(
        static fn (Role $r): string => '  ' . colonne($r->abrege(), 20)
            . colonne($r->metier(), 23) . '« ' . $r->libelleEnBase() . ' » en base',
        Role::cases()
    );
    fin('Rôle inconnu : « ' . $roleDemande . ' ».' . PHP_EOL . PHP_EOL
        . 'Les quatre profils, et les trois noms de chacun. N\'importe lequel des trois convient,' . PHP_EOL
        . 'accents, majuscules et pluriel indifférents :' . PHP_EOL
        . implode(PHP_EOL, $profils) . PHP_EOL . PHP_EOL
        . 'Le rôle est le troisième mot, après le nom et le prénom :' . PHP_EOL
        . '  run admin Dupont Jean chef-atelier' . PHP_EOL . PHP_EOL
        . 'Un nom ou un rôle en deux mots tient entre guillemets — sinon son second mot part' . PHP_EOL
        . 'dans l\'argument suivant, et c\'est en général lui qu\'on lit ci-dessus :' . PHP_EOL
        . '  run admin "El Manssouri" Rayan "chargé de production"');
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

// Le courriel est redit : c'est le seul champ qui accepte du texte libre, et un argument
// décalé y atterrissait sans que rien ne le montre.
echo 'Compte créé : ', User::buildIdentifiant($nom, $prenom), ' (', $role->metier(), ')',
    $courriel !== '' ? ', courriel ' . $courriel : ', sans courriel', PHP_EOL;
if ($tireAuSort) {
    echo 'Mot de passe : ', $motDePasse, PHP_EOL;
    echo 'Notez-le maintenant : il n\'est écrit nulle part, et il ne se relit pas.', PHP_EOL;
}
