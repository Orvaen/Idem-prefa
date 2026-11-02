<?php
// The workshop trades on a plan — débiteur, assembleur, pointeur, soudeur, passiveur: one
// person can hold several on the same plan, one trade can go to several people, and a trade
// alone opens the plan to whoever holds it, like a work assignment does.
//
// Needs a validated request; everything it writes is rolled back. Run in the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/atelier/atelier.php';
require_once $root . '/dashboard/includes/atelier/soudure_atelier.php';
$db = new MyPDO($root . '/my_setting.ini');

function checkMetier(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

if (!metiersEnBase($db)) throw new RuntimeException('Run the 2026-11-02_metiers migration first');
$requestId = (int) $db->query('SELECT id FROM demande_prefabrication WHERE id_statut = 2 LIMIT 1')->fetchColumn();
if (!$requestId) throw new RuntimeException('A validated request is needed for the test');

$db->beginTransaction();
try {
    $chief = (int) $db->query('SELECT id FROM Utilisateur LIMIT 1')->fetchColumn();
    $insert = $db->prepare("INSERT INTO Utilisateur (name, prenom, identifiant, password, id_role) VALUES ('Essai', ?, ?, '-', 3)");
    $tag = bin2hex(random_bytes(4));
    $insert->execute(['Un', 'essai-metier-a-' . $tag]);
    $alice = (int) $db->lastInsertId();
    $insert->execute(['Deux', 'essai-metier-b-' . $tag]);
    $bob = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO element_atelier (id_demande, reference, libelle, cree_par) VALUES (?, ?, ?, ?)')
        ->execute([$requestId, 'TEST-METIER-' . $tag, 'Plan d’essai', $chief]);
    $plan = (int) $db->lastInsertId();

    // --- Nobody on the plan yet: no access.
    checkMetier(soudureAtelierAffectation($db, $plan, $alice) === null, 'Without work or trade, the plan must stay closed');

    // --- Several trades for one person, and one trade for several people.
    $donner = $db->prepare('INSERT INTO affectation_metier (id_element, id_utilisateur, metier, affecte_par) VALUES (?, ?, ?, ?)');
    $donner->execute([$plan, $alice, 'soudage', $chief]);
    $donner->execute([$plan, $alice, 'debit', $chief]);
    $donner->execute([$plan, $bob, 'soudage', $chief]);

    $parMetier = atelierMetiersDuPlan($db, $plan);
    checkMetier(array_keys($parMetier) === array_keys(atelierMetiers()), 'Every trade must be listed, held or not');
    checkMetier(count($parMetier['soudage']) === 2 && $parMetier['assemblage'] === [], 'A trade must carry all its holders, and only them');
    checkMetier((atelierMetiersDe($db, $alice)[$plan] ?? []) === ['debit', 'soudage'], 'A person’s trades must come in working order');

    // --- The same trade twice for the same person is refused by the table.
    try {
        $donner->execute([$plan, $alice, 'soudage', $chief]);
        throw new RuntimeException('The same trade must not be given twice to the same person');
    } catch (PDOException $refus) {
        checkMetier($refus->getCode() === '23000', 'The duplicate trade must be refused by the unique key');
    }

    // --- A trade alone opens the plan: welds, the list of plans, the plan files.
    checkMetier(soudureAtelierAffectation($db, $plan, $alice) !== null, 'A trade alone must open the plan');
    checkMetier(in_array($plan, array_column(soudureAtelierPlans($db, $alice), 'element'), true), 'A trade-only plan must be in the person’s plans');
    $acces = $db->prepare('SELECT COUNT(*) FROM element_atelier WHERE id = ? AND id IN ' . atelierPlansDeSql($db));
    $acces->execute([$plan, $bob, $bob]);
    checkMetier((int) $acces->fetchColumn() === 1, 'The shared access rule must count trades');

    // --- Taking the trade back closes the plan again.
    $db->prepare('DELETE FROM affectation_metier WHERE id_element = ? AND id_utilisateur = ?')->execute([$plan, $bob]);
    checkMetier(soudureAtelierAffectation($db, $plan, $bob) === null, 'Without its trade, the plan must close again');
} finally {
    $db->rollBack();
}

echo 'Trades on a plan: several per person, several per trade, and access: OK' . PHP_EOL;
