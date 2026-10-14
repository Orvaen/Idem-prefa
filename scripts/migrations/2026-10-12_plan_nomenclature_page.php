<?php
// Les numéros de coulée se posent page par page du plan déposé.
//
// Un cahier ISO déposé en un seul PDF porte une nomenclature par page : chaque
// isométrique a sa colonne « N° COULÉE » à remplir. Sans cette colonne, seules les cases
// de la première page existaient, et les quatorze autres nomenclatures restaient vides —
// alors que la détection les reconnaît toutes.
//
// Les cases déjà posées restent sur la page 1 : c'est la seule que le lecteur montrait,
// donc c'est bien sur elle qu'elles ont été relevées.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$faits = [];

if ($db->query("SHOW COLUMNS FROM plan_nomenclature LIKE 'page'")->fetch()) {
    $faits[] = 'plan_nomenclature : colonne page déjà présente';
} else {
    $db->exec('ALTER TABLE plan_nomenclature
        ADD COLUMN page SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER id_document');
    $faits[] = 'plan_nomenclature : colonne page ajoutée';
}

// Le rang d'une ligne est unique PAR PAGE, et non par fichier : chaque isométrique du
// cahier a sa ligne 1.
//
// Le nouvel index se pose AVANT que l'ancien ne parte. L'ancien sert d'appui aux clés
// étrangères sur id_element et id_document, et MySQL refuse de retirer le dernier index
// qui les porte ; le nouveau commence par les mêmes colonnes, il prend donc le relais.
if ($db->query("SHOW INDEX FROM plan_nomenclature WHERE Key_name = 'nomenclature_rang_par_page'")->fetch()) {
    $faits[] = 'plan_nomenclature : unicité déjà portée à la page';
} else {
    $db->exec('ALTER TABLE plan_nomenclature
        ADD UNIQUE KEY nomenclature_rang_par_page (id_element, id_document, page, rang)');
    if ($db->query("SHOW INDEX FROM plan_nomenclature WHERE Key_name = 'nomenclature_rang_par_document'")->fetch()) {
        $db->exec('ALTER TABLE plan_nomenclature DROP INDEX nomenclature_rang_par_document');
    }
    $faits[] = 'plan_nomenclature : unicité portée à la page';
}

echo implode("\n", $faits), "\n";
