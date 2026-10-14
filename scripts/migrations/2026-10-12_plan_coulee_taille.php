<?php
// Largeur d'un repère de coulée sur le plan.
//
// Comme la position, elle est exprimée en fraction de la largeur de l'image, jamais en
// pixels : le repère doit couvrir toujours la même portion du plan, qu'on le regarde à
// 50 % ou à 400 %. Un repère dimensionné en pixels écraserait le tableau une fois
// dézoomé et deviendrait minuscule une fois agrandi.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM plan_nomenclature LIKE 'pos_w'")->fetch()) {
    echo "La largeur des repères est déjà en place.\n";
    exit;
}

$db->exec("ALTER TABLE plan_nomenclature
    ADD COLUMN pos_w DECIMAL(7,6) NOT NULL DEFAULT 0.090000 AFTER pos_y");

echo "Largeur des repères de coulée en place.\n";
