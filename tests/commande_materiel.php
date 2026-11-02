<?php
// Run in the PHP container. materielEnregistrer() opens its own transaction, so this test
// cannot run inside one: it deletes its order at the end, and the lines go with it.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/materiel/materiel_bon.php';
$db = new MyPDO($root . '/my_setting.ini');
function checkBon(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function refusedBon(callable $change): bool
{
    try {
        $change();
        return false;
    } catch (DomainException) {
        return true;
    }
}
$requestId = (int) $db->query('SELECT id FROM demande_prefabrication LIMIT 1')->fetchColumn();
$userId = (int) $db->query('SELECT id FROM Utilisateur LIMIT 1')->fetchColumn();
if (!$requestId || !$userId) throw new RuntimeException('A request and a user are needed for the test');
$db->prepare("INSERT INTO demande_materiel_documents (id_demande, nom, contenu, sha256, lecture, date_lecture)
    VALUES (?, 'TEST.pdf', '', UNHEX(SHA2('', 256)), 'ocr', NOW())")->execute([$requestId]);
$id = (int) $db->lastInsertId();
try {
    $lines = [
        ['reference' => 'TEST-A', 'designation' => 'Tube', 'quantite' => '2', 'unite' => 'm', 'date_livraison' => ''],
        ['reference' => 'TEST-B', 'designation' => 'Bride', 'quantite' => '4', 'unite' => 'u', 'date_livraison' => ''],
    ];

    checkBon(refusedBon(fn () => materielRouvrir($db, $id)), 'An order that is not validated must not be reopened');

    materielEnregistrer($db, $id, $lines, $userId, true);
    checkBon(materielEstFige(materielDocument($db, $id)), 'Validating must freeze the order');
    checkBon(refusedBon(fn () => materielEnregistrer($db, $id, $lines, $userId)), 'A frozen order must refuse corrections');

    $received = (int) $db->query("SELECT id FROM demande_materiel_lignes WHERE id_document = $id AND reference = 'TEST-A'")->fetchColumn();
    materielPointerReception($db, $id, [$received => true], $userId);

    materielRouvrir($db, $id);
    $document = materielDocument($db, $id);
    checkBon(!materielEstFige($document) && materielEtat($document) === 'a_verifier', 'Reopening must put the order back to « À vérifier »');
    checkBon(materielRecus($document) === ['recus' => 1, 'total' => 2], 'Reopening must keep lines and receptions');

    $lines[1]['quantite'] = '5';
    checkBon(!refusedBon(fn () => materielEnregistrer($db, $id, $lines, $userId)), 'A reopened order must accept corrections');
    $document = materielDocument($db, $id);
    checkBon(materielRecus($document)['recus'] === 1, 'Correcting a reopened order must keep its receptions');
    checkBon((float) $document['lignes'][1]['quantite'] === 5.0, 'The correction must be saved');
} finally {
    $db->prepare('DELETE FROM demande_materiel_documents WHERE id = ?')->execute([$id]);
}
echo "Commande matériel OK\n";
