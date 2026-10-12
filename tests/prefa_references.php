<?php
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/prefa_references.php';
$db = new MyPDO($root . '/my_setting.ini');
function checkReference(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$before = $db->query('SELECT * FROM prefa_reference ORDER BY id_demande')->fetchAll();
$db->beginTransaction();
try {
    // Dedicated test years and IDs; every change is rolled back.
    $db->exec('DELETE FROM prefa_reference WHERE annee IN (9998, 9999)');
    $db->exec('DELETE FROM prefa_reference_compteur WHERE annee IN (9998, 9999)');
    checkReference(prefaAssignReference($db, -1, 9998) === '98-DP-0001', 'First annual number');
    checkReference(prefaAssignReference($db, -2, 9998) === '98-DP-0002', 'Sequential number');
    $db->exec('DELETE FROM prefa_reference WHERE id_demande = -1');
    checkReference(prefaAssignReference($db, -3, 9998) === '98-DP-0003', 'Deleted numbers must not be reused');
    checkReference(prefaAssignReference($db, -4, 9999) === '99-DP-0001', 'New year resets the counter');
    $db->exec('UPDATE prefa_reference_compteur SET dernier_numero = 999 WHERE annee = 9999');
    checkReference(prefaAssignReference($db, -5, 9999) === '99-DP-1000', 'Numbers above 999 must remain unique');
    try {
        $db->exec("INSERT INTO prefa_reference VALUES (-6, 9999, 1000, '99-DP-1000')");
        throw new RuntimeException('Duplicate reference accepted');
    } catch (PDOException $error) {
        checkReference($error->getCode() === '23000', 'Unique reference constraint');
    }
} finally {
    $db->rollBack();
}
checkReference($before === $db->query('SELECT * FROM prefa_reference ORDER BY id_demande')->fetchAll(), 'Existing references must remain unchanged');
echo "Annual sequence, rollover, deletion, uniqueness and rollback: OK\n";
