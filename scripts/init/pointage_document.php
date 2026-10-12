<?php
// Rattache un pointage à la feuille ISO sur laquelle le travail a été fait.
//
// Un élément porte plusieurs ISO. Sans cette colonne, un pointage déclaré sur l'élément
// ne disait pas quelle feuille : sa ligne se proposait alors sur toutes, et la personne
// qui numérote le cordon devait deviner. Elle le dit maintenant.
//
// Nullable : les pointages déjà saisis ne savent pas sur quelle feuille ils portaient, et
// rien ne permet de le retrouver après coup. Ils continuent de se proposer partout, ce
// qui était leur comportement jusqu'ici.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM pointage_atelier LIKE 'id_document'")->fetch()) {
    echo "Le pointage désigne déjà sa feuille ISO.\n";
    exit;
}

$db->exec('ALTER TABLE pointage_atelier
    ADD COLUMN id_document INT DEFAULT NULL AFTER id_affectation,
    ADD CONSTRAINT fk_pointage_document FOREIGN KEY (id_document)
        REFERENCES element_atelier_document (id) ON DELETE SET NULL');

echo "Le pointage désigne sa feuille ISO.\n";
