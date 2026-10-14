<?php
// Aligne la table role sur les quatre rôles réellement utilisés par l'application.
// Relançable sans effet de bord : les renommages sont idempotents et la suppression
// du rôle 4 (doublon historique du rôle 5) n'a lieu que s'il ne reste aucun compte dessus.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$libelles = [
    1 => 'Administrateur',
    2 => 'Gestionnaire',
    3 => 'Utilisateur',
    5 => 'Demandeur',
];

$db->beginTransaction();
try {
    $orphelins = (int) $db->query('SELECT COUNT(*) FROM Utilisateur WHERE id_role = 4')->fetchColumn();
    if ($orphelins > 0) {
        throw new RuntimeException("Le rôle 4 compte encore $orphelins compte(s) : basculez-les sur le rôle 5 avant de relancer.");
    }

    $renommer = $db->prepare('UPDATE role SET name = ? WHERE id = ?');
    foreach ($libelles as $id => $nom) {
        $renommer->execute([$nom, $id]);
    }
    $db->exec('DELETE FROM role WHERE id = 4');

    $db->commit();
} catch (Throwable $erreur) {
    $db->rollBack();
    fwrite(STDERR, 'Migration annulée : ' . $erreur->getMessage() . PHP_EOL);
    exit(1);
}

echo "Rôles :" . PHP_EOL;
foreach ($db->query('SELECT r.id, r.name, COUNT(u.id) AS comptes
    FROM role r LEFT JOIN Utilisateur u ON u.id_role = r.id
    GROUP BY r.id, r.name ORDER BY r.id') as $role) {
    printf("  %d  %-16s %d compte(s)%s", $role['id'], $role['name'], $role['comptes'], PHP_EOL);
}
