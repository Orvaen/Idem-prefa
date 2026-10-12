<?php
// Position d'un numéro de coulée sur le plan.
//
// Le chef d'atelier pose les repères lui-même, en cliquant sur la colonne « N° COULÉE »
// du plan qu'il a joint. C'est la seule façon fiable : chaque bureau d'études place son
// tableau ailleurs, et celui-ci est souvent pivoté à 90°.
//
// Les coordonnées sont des fractions de l'image (0 à 1), pas des pixels : le plan se
// lit à n'importe quel zoom, sur un écran d'atelier comme sur un portable, et un
// changement de résolution de l'aperçu ne déplace rien.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM plan_nomenclature LIKE 'pos_x'")->fetch()) {
    echo "Les positions des coulées sont déjà en place.\n";
    exit;
}

$db->exec('ALTER TABLE plan_nomenclature
    ADD COLUMN pos_x DECIMAL(7,6) DEFAULT NULL AFTER rang,
    ADD COLUMN pos_y DECIMAL(7,6) DEFAULT NULL AFTER pos_x');

echo "Positions des coulées sur le plan en place.\n";
