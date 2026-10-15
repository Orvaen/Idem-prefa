<?php
// Run from stdin in the PHP container. All fixture changes are rolled back.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/atelier/atelier.php';
$db = new MyPDO($root . '/my_setting.ini');
function checkPlan(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$requestId = (int) $db->query('SELECT id FROM demande_prefabrication LIMIT 1')->fetchColumn();
$userId = (int) $db->query('SELECT id FROM Utilisateur LIMIT 1')->fetchColumn();
if (!$requestId || !$userId) throw new RuntimeException('A request and a user are needed for the test');
$db->beginTransaction();
try {
    $name = 'TEST-' . bin2hex(random_bytes(8));
    $insert = $db->prepare('INSERT INTO element_atelier (id_demande, reference, libelle, cree_par) VALUES (?, ?, ?, ?)');
    $insert->execute([$requestId, $name, 'Original description', $userId]);
    $elementId = (int) $db->lastInsertId();
    $insert->execute([$requestId, $name . '-duplicate', 'Other plan', $userId]);
    $otherId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO element_atelier_document (id_element, type_document, cle_document) VALUES (?, 'plan', ?)")->execute([$elementId, str_repeat('a', 32) . '-test.pdf']);
    $db->prepare("INSERT INTO affectation_atelier (id_element, id_utilisateur, type_affectation, affecte_par) VALUES (?, ?, 'plan', ?)")->execute([$elementId, $userId, $userId]);
    $assignmentId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO pointage_atelier (id_affectation, id_utilisateur, date_travail, duree_minutes, cle_saisie) VALUES (?, ?, CURDATE(), 30, ?)")->execute([$assignmentId, $userId, bin2hex(random_bytes(16))]);
    $db->prepare('INSERT INTO avancement_atelier (id_affectation, id_utilisateur, avancement) VALUES (?, ?, 50)')->execute([$assignmentId, $userId]);
    $db->prepare("INSERT INTO element_atelier_etapes (id_element, statuts, modifie_par) VALUES (?, '{}', ?)")->execute([$elementId, $userId]);

    atelierChangeElement($db, $requestId, $elementId, 'update_element', '  ' . $name . '-renamed  ', '  Updated description  ');
    $read = $db->prepare('SELECT reference, libelle FROM element_atelier WHERE id = ?');
    $read->execute([$elementId]);
    checkPlan($read->fetch() === ['reference' => $name . '-renamed', 'libelle' => 'Updated description'], 'Name and description must be saved and trimmed');
    $read = $db->prepare('SELECT COUNT(*) FROM affectation_atelier WHERE id_element = ?');
    $read->execute([$elementId]);
    checkPlan((int) $read->fetchColumn() === 1, 'Editing must preserve assignments');

    foreach ([['', 'Valid'], [str_repeat('x', 81), 'Valid'], ['Valid', str_repeat('x', 181)], [[], 'Valid']] as [$reference, $description]) {
        try {
            atelierChangeElement($db, $requestId, $elementId, 'update_element', $reference, $description);
            throw new RuntimeException('Invalid plan input accepted');
        } catch (InvalidArgumentException) {}
    }
    foreach (['update_element', 'delete_element'] as $operation) {
        try {
            atelierChangeElement($db, $requestId + 1000000, $elementId, $operation, 'Valid', 'Valid');
            throw new RuntimeException('Plan in another request accepted');
        } catch (InvalidArgumentException) {}
    }
    try {
        atelierChangeElement($db, $requestId, $elementId, 'update_element', $name . '-duplicate', 'Valid');
        throw new RuntimeException('Duplicate plan name accepted');
    } catch (PDOException $error) {
        checkPlan($error->getCode() === '23000', 'Duplicate names must be rejected');
    }

    atelierChangeElement($db, $requestId, $elementId, 'delete_element');
    foreach (['element_atelier' => 'id', 'element_atelier_document' => 'id_element', 'affectation_atelier' => 'id_element', 'element_atelier_etapes' => 'id_element'] as $table => $column) {
        $read = $db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $read->execute([$elementId]);
        checkPlan((int) $read->fetchColumn() === 0, 'Deletion cascade: ' . $table);
    }
    foreach (['pointage_atelier', 'avancement_atelier'] as $table) {
        $read = $db->prepare("SELECT COUNT(*) FROM $table WHERE id_affectation = ?");
        $read->execute([$assignmentId]);
        checkPlan((int) $read->fetchColumn() === 0, 'Tracking deletion cascade: ' . $table);
    }
    $read = $db->prepare('SELECT COUNT(*) FROM element_atelier WHERE id = ?');
    $read->execute([$otherId]);
    checkPlan((int) $read->fetchColumn() === 1, 'Other plans must remain intact');
} finally {
    $db->rollBack();
}
echo "Plan editing, validation, request ownership, uniqueness and deletion cascade: OK\n";
