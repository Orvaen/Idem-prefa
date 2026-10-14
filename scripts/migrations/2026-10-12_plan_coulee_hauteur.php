<?php
// Hauteur d'un repère de coulée, en fraction de la hauteur de l'image.
//
// La largeur seule ne suffisait pas : une case de nomenclature a deux dimensions, et un
// repère doit pouvoir épouser exactement la sienne. Comme la position et la largeur,
// la hauteur est une fraction du plan — jamais des pixels, sans quoi le repère
// changerait de taille relative à chaque zoom.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM plan_nomenclature LIKE 'pos_h'")->fetch()) {
    echo "La hauteur des repères est déjà en place.\n";
    exit;
}

$db->exec("ALTER TABLE plan_nomenclature
    ADD COLUMN pos_h DECIMAL(7,6) NOT NULL DEFAULT 0.018000 AFTER pos_w");

echo "Hauteur des repères de coulée en place.\n";
