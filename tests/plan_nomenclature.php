<?php
// The bill of materials read on an ISO sheet: where each word goes, and how a line names its
// article. Pure functions — no PDF, no OCR, no database. Run in the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/dashboard/includes/lecture/plan_nomenclature.php';

function checkNomenclature(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// --- Placing words: two columns, each preceded by a blank; three row lines, so two rows.
$debuts = ['quantite' => 60, 'description' => 200];
$lignesY = [0, 66, 132];
$places = planNomenclatureRanger([
    ['texte' => '3.3M', 'cx' => 90, 'cy' => 33],
    ['texte' => 'TUBE', 'cx' => 240, 'cy' => 33],
    ['texte' => 'INOX', 'cx' => 320, 'cy' => 35],
    // Just before its column, within half the blank: still that column.
    ['texte' => '1', 'cx' => 175, 'cy' => 100],
    // Under the last line, and above the first: no row.
    ['texte' => 'X', 'cx' => 90, 'cy' => 140],
    ['texte' => 'Y', 'cx' => 90, 'cy' => -4],
], $debuts, $lignesY);
checkNomenclature(count($places) === 4, 'Words outside the rows must be dropped');
checkNomenclature($places[0] === ['rang' => 0, 'colonne' => 'quantite', 'texte' => '3.3M'], 'A word goes to the row under its top line, and to its column');
checkNomenclature($places[1]['colonne'] === 'description' && $places[2]['colonne'] === 'description', 'Words of one cell stay in that cell');
checkNomenclature($places[3]['rang'] === 1 && $places[3]['colonne'] === 'description', 'Half the blank before a column belongs to it');

// --- Cleaning a cell: one space between words, stray signs off both ends.
checkNomenclature(planNomenclatureNettoyer('  TUBE   INOX RS - 316L - ') === 'TUBE INOX RS - 316L', 'A cell must be trimmed of spaces and stray dashes');
checkNomenclature(planNomenclatureNettoyer('COUDE ISO - 90° - 5D') === 'COUDE ISO - 90° - 5D', 'Inner signs and the degree must stay');

// --- Naming the article: the description, then the size the chief has to order.
checkNomenclature(planNomenclatureDesignation(['description' => 'BRIDE TOURNANTE EMBOUTIE - 316L', 'dn' => '200', 'ep' => ''])
    === 'BRIDE TOURNANTE EMBOUTIE - 316L DN200', 'A bare number in the DN column is a nominal diameter');
checkNomenclature(planNomenclatureDesignation(['description' => 'TUBE INOX RS - 316L', 'dn' => '200', 'ep' => '2'])
    === 'TUBE INOX RS - 316L DN200 EP2', 'The thickness follows the diameter');
checkNomenclature(planNomenclatureDesignation(['description' => 'BOULONNERIE INOX', 'dn' => '20X100', 'ep' => ''])
    === 'BOULONNERIE INOX 20X100', 'A dimension in the DN column is written as printed, without « DN »');
checkNomenclature(planNomenclatureDesignation(['description' => '', 'dn' => '200', 'ep' => '2']) === '',
    'Without a description there is no article: a size alone names nothing');

echo 'Bill of materials on the drawing: placing, cleaning and naming: OK' . PHP_EOL;
