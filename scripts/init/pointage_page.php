<?php
// La page du cahier sur laquelle le pointage a été fait.
//
// Un pointage qui n'a pas encore son cordon se propose dans la liste des soudures. Sans
// page, il se proposait sur TOUTES les pages du cahier : huit pointages sur quinze pages
// faisaient cent vingt propositions, les mêmes partout, et la liste ne changeait pas
// quand on tournait la page.
//
// Les pointages déjà saisis partent sur la page 1 : c'est la seule que le lecteur
// montrait quand ils ont été déclarés.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM pointage_atelier LIKE 'page'")->fetch()) {
    echo "pointage_atelier : colonne page déjà présente\n";
    exit;
}

$db->exec('ALTER TABLE pointage_atelier
    ADD COLUMN page SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER id_document');
echo "pointage_atelier : colonne page ajoutée\n";
