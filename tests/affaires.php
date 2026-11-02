<?php
// Run in the PHP container. All fixture changes are rolled back.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/modeles/Affaire.php';
$db = new MyPDO($root . '/my_setting.ini');
function checkAffaire(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function refused(callable $change): bool
{
    try {
        $change();
        return false;
    } catch (DomainException) {
        return true;
    }
}
$db->beginTransaction();
try {
    // Two chargés d'affaire of our own: the demo base has only one.
    $name = 'TEST-' . bin2hex(random_bytes(8));
    $insert = $db->prepare("INSERT INTO Utilisateur (name, id_role, password, prenom, identifiant) VALUES (?, 5, '-', 'Test', ?)");
    $insert->execute([$name, $name . '-a']);
    $first = (int) $db->lastInsertId();
    $insert->execute([$name, $name . '-b']);
    $second = (int) $db->lastInsertId();

    $open = Affaire::ouvrir($db, $name . ' open', null);
    checkAffaire(!refused(fn () => Affaire::modifier($db, $open, $name . ' open', $first)), 'An affaire without a chargé must accept one');
    checkAffaire(Affaire::parId($db, $open)->idChargeAffaire === $first, 'The chargé must be saved');

    $locked = Affaire::ouvrir($db, $name . ' locked', $first);
    checkAffaire(!refused(fn () => Affaire::modifier($db, $locked, $name . ' renamed', $first)), 'Renaming must keep working');
    checkAffaire(refused(fn () => Affaire::modifier($db, $locked, $name . ' renamed', $second)), 'A designated chargé must not be replaced');
    checkAffaire(refused(fn () => Affaire::modifier($db, $locked, $name . ' renamed', null)), 'A designated chargé must not be removed');
    $read = Affaire::parId($db, $locked);
    checkAffaire($read->nom === $name . ' renamed' && $read->idChargeAffaire === $first, 'A refused change must leave the affaire as it was');

    // Deleting the account is the only way out: the foreign key sets the column back to NULL.
    $db->prepare('DELETE FROM Utilisateur WHERE id = ?')->execute([$first]);
    checkAffaire(!refused(fn () => Affaire::modifier($db, $locked, $name . ' renamed', $second)), 'An affaire whose chargé was deleted must accept a new one');
} finally {
    $db->rollBack();
}
echo "Affaires OK\n";
