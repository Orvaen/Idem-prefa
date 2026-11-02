<?php
// The weld numbers drawn on an ISO sheet: the rules that decide which number goes where.
// Pure functions on synthetic glyphs — no PDF, no database. Run in the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/dashboard/includes/lecture/plan_reperes.php';

function checkRepere(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// --- Attribution: one number per box, one box per number, best pair first.
$attribues = planReperesAttribuer([
    0 => [1 => 0.99, 2 => 0.70],
    1 => [1 => 0.95, 2 => 0.90],
], [1, 2]);
checkRepere($attribues[0]['numero'] === 1 && $attribues[1]['numero'] === 2, 'The best pair must win, and a taken number must not be given twice');
checkRepere(!$attribues[0]['deduit'] && !$attribues[1]['deduit'], 'Recognised numbers are not deduced');

// --- Below the threshold, nothing is guessed — except the last box with the last number.
$seul = planReperesAttribuer([0 => [1 => 0.99, 2 => 0.2], 1 => [1 => 0.3, 2 => 0.1]], [1, 2]);
checkRepere(($seul[1]['numero'] ?? null) === 2 && $seul[1]['deduit'], 'One box left with one number left: it is that number, marked as deduced');

$deux = planReperesAttribuer([0 => [1 => 0.2, 2 => 0.1, 3 => 0.1], 1 => [1 => 0.1, 2 => 0.2, 3 => 0.1], 2 => [3 => 0.99]], [1, 2, 3]);
checkRepere(!isset($deux[0]) && !isset($deux[1]) && $deux[2]['numero'] === 3, 'Two unread boxes for two numbers must stay unread');

// --- Likeness: a glyph matches itself, and « 1 » is not « 11 ». At the reading resolution a
// « 1 » is about 15 pixels wide and 50 high, a « 11 » about 45.
$traits = static function (int $largeur, array $colonnes): array {
    $rangee = array_fill(0, $largeur, 0);
    foreach ($colonnes as $x) $rangee[$x] = 1;
    return ['l' => $largeur, 'h' => 50, 'g' => array_merge(...array_fill(0, 50, $rangee))];
};
$un = $traits(15, [6, 7, 8]);
$onze = $traits(45, [6, 7, 8, 36, 37, 38]);
checkRepere(planReperesRessemblance($un, $un) > 0.99, 'A glyph must match itself');
checkRepere(planReperesRessemblance($un, $onze) < PLAN_REPERES_RESSEMBLANCE_MINI, 'A « 1 » must not pass for a « 11 »');

// --- Extraction: a stroke crossing the box from side to side goes; a digit touching one side stays.
// A 20 × 12 image: a vertical digit stroke at x = 9 from top to bottom, and a horizontal line
// at y = 3 running across the whole image — beyond the zone on both sides.
$l = 20;
$h = 12;
$bits = str_repeat("\0", $l * $h);
for ($y = 2; $y <= 9; $y++) $bits[$y * $l + 9] = "\1";
for ($x = 0; $x < $l; $x++) $bits[6 * $l + $x] = "\1";
$image = ['l' => $l, 'h' => $h, 'bits' => $bits];
$seulTrait = planReperesGlyphe($image, 4, 2, 15, 9, 3);
checkRepere($seulTrait === null, 'A digit merged with a line crossing the box is one crossing stroke: it must go');

// The same digit, now touched by a stroke that only leaves by the left: it stays, stroke and all.
$bits = str_repeat("\0", $l * $h);
for ($y = 2; $y <= 9; $y++) $bits[$y * $l + 9] = "\1";
for ($x = 0; $x <= 9; $x++) $bits[9 * $l + $x] = "\1";
$image = ['l' => $l, 'h' => $h, 'bits' => $bits];
$chiffre = planReperesGlyphe($image, 4, 2, 15, 9, 3);
checkRepere($chiffre !== null && $chiffre['h'] === 8, 'A digit touching the frame, with a stroke leaving by one side only, must stay');

echo 'Weld numbers on the drawing: attribution, likeness and extraction: OK' . PHP_EOL;
