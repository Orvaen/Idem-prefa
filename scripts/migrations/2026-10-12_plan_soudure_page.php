<?php
// La liste des soudures se remplit page par page du plan déposé.
//
// Un cahier ISO déposé en un seul PDF porte une liste soudure par page : les cordons de
// la page 3 s'écrivent dans le tableau de la page 3. Sans cette colonne, toutes les
// soudures d'un cahier tombaient dans le tableau de sa première page, et le numéro 1 ne
// pouvait exister qu'une fois pour tout le cahier alors que chaque isométrique a le sien.
//
// Les lignes déjà saisies restent sur la page 1 : c'est la seule que le lecteur montrait,
// donc c'est bien sur elle qu'elles ont été relevées.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$faits = [];

if ($db->query("SHOW COLUMNS FROM plan_soudure LIKE 'page'")->fetch()) {
    $faits[] = 'plan_soudure : colonne page déjà présente';
} else {
    $db->exec('ALTER TABLE plan_soudure
        ADD COLUMN page SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER id_document');
    $faits[] = 'plan_soudure : colonne page ajoutée';
}

// Le numéro de cordon est unique PAR PAGE, et non par fichier : deux isométriques du même
// cahier ont tous deux une soudure n° 1.
//
// Le nouvel index se pose AVANT que l'ancien ne parte. L'ancien sert d'appui aux clés
// étrangères sur id_element et id_document, et MySQL refuse de retirer le dernier index
// qui les porte ; le nouveau commence par les mêmes colonnes, il prend donc le relais.
if ($db->query("SHOW INDEX FROM plan_soudure WHERE Key_name = 'soudure_numero_par_page'")->fetch()) {
    $faits[] = 'plan_soudure : unicité déjà portée à la page';
} else {
    $db->exec('ALTER TABLE plan_soudure
        ADD UNIQUE KEY soudure_numero_par_page (id_element, id_document, page, numero)');
    if ($db->query("SHOW INDEX FROM plan_soudure WHERE Key_name = 'soudure_numero_par_document'")->fetch()) {
        $db->exec('ALTER TABLE plan_soudure DROP INDEX soudure_numero_par_document');
    }
    $faits[] = 'plan_soudure : unicité portée à la page';
}

echo implode("\n", $faits), "\n";
