<?php
// Le résumé d'un plan (atelier/plan_resume.php) : les numéros resserrés en plages, les cases
// de coulée rendues à leur ligne, et le résumé d'un plan de la base. Lancé dans le conteneur
// PHP ; rien n'est écrit.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/atelier/plan_resume.php';
$db = new MyPDO($root . '/my_setting.ini');

function verifier(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// ----- Les plages

verifier(planResumePlages([1, 2, 3, 4, 5, 8]) === '1 à 5, 8', 'Une suite se resserre');
verifier(planResumePlages([4, 5]) === '4, 5', 'Deux numéros qui se suivent restent deux');
verifier(planResumePlages(['12', '10', '11', '7']) === '7, 10 à 12', 'Les numéros se rangent avant de se resserrer');
verifier(planResumePlages(['A1', 3]) === '3, A1', 'Un numéro qui n’en est pas un suit, tel quel');
verifier(planResumePlages([]) === '', 'Rien ne fait rien');

// ----- Les cases de coulée, rendues à leur ligne par leur hauteur

$lignes = ['nomenclature' => ['lignes' => [
    ['y' => 0.10, 'h' => 0.02, 'repere' => '1', 'description' => 'TUBE INOX 316L', 'dn' => '200', 'ep' => '2'],
    ['y' => 0.12, 'h' => 0.02, 'repere' => '2', 'description' => 'COUDE 90°', 'dn' => '200', 'ep' => ''],
    ['y' => 0.14, 'h' => 0.02, 'repere' => '3', 'description' => 'BRIDE', 'dn' => '', 'ep' => ''],
]]];
// Dans le désordre des rangs : le chef en a ajouté une au-dessus.
$cases = [
    ['pos_y' => 0.121, 'pos_h' => 0.018, 'description' => '', 'numero_coulee' => '99999', 'id_coulee' => null],
    ['pos_y' => 0.101, 'pos_h' => 0.018, 'description' => '', 'numero_coulee' => '12345', 'id_coulee' => 7],
    ['pos_y' => 0.300, 'pos_h' => 0.018, 'description' => 'Ajoutée', 'numero_coulee' => '55555', 'id_coulee' => null],
];
$coulees = planResumeCoulees($lignes, $cases);
verifier($coulees['nomenclature'] === 'lue', 'Une nomenclature gardée est lue');
verifier(array_column($coulees['sans_coulee'], 'repere') === ['3'], 'La ligne 3 n’a pas de case : elle n’a pas de coulée');
verifier($coulees['sans_coulee'][0]['article'] === 'BRIDE', 'La ligne sans coulée dit son article');
verifier(array_column($coulees['hors_ccpu'], 'coulee') === ['99999', '55555'],
    'Une coulée tapée sans certificat se signale, sur sa ligne comme hors des lignes');
verifier($coulees['hors_ccpu'][0]['repere'] === '2', 'La case se rend à la ligne qu’elle couvre, pas à son rang');
verifier(planResumeCoulees(null, [])['nomenclature'] === 'pas lue', 'Une page pas encore lue le dit');
verifier(planResumeCoulees(['nomenclature' => null], [])['nomenclature'] === 'non reconnue', 'Une page sans nomenclature reconnue le dit');

// ----- Un plan de la base, s'il y en a un

$element = (int) $db->query("SELECT id_element FROM element_atelier_document WHERE type_document = 'plan' ORDER BY id LIMIT 1")->fetchColumn();
if ($element) {
    $resume = planResume($db, $element);
    $pages = (int) $db->query('SELECT COALESCE(SUM(GREATEST(pages, 1)), 0) FROM element_atelier_document
        WHERE type_document = \'plan\' AND id_element = ' . $element)->fetchColumn();
    verifier(count($resume['feuilles']) === $pages, 'Une entrée par page de chaque cahier du plan');
    foreach ($resume['feuilles'] as $feuille) {
        verifier(count($feuille['a_renseigner']) <= (int) $feuille['imprimees'], 'On ne manque pas plus de soudures que la liste n’en porte');
        verifier(in_array($feuille['nomenclature'], ['lue', 'non reconnue', 'pas lue'], true), 'La nomenclature d’une page a un état connu');
        verifier($feuille['nom'] !== '', 'Chaque feuille a un nom');
    }
}

echo "Plan summary: ranges, coulée boxes back to their line, and a plan from the database: OK\n";
