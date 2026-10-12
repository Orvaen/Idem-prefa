<?php
// Rattache une ligne de la liste des soudures au pointage dont elle est issue.
//
// La liste affiche deux sortes de lignes : les soudures numérotées, et les pointages
// pas encore rattachés, qui apparaissent avec leur soudeur, leur date et leur type mais
// sans numéro. Donner un numéro à l'une de ces lignes la rattache définitivement à son
// pointage, qui cesse alors d'apparaître à part : c'est ce lien qui évite les doublons.
//
// Unique et nullable : un pointage ne nourrit qu'une soudure au plus, et une soudure
// saisie à la main n'en vient d'aucun. Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM plan_soudure LIKE 'id_pointage'")->fetch()) {
    echo "Le lien vers le pointage est déjà en place.\n";
    exit;
}

$db->exec('ALTER TABLE plan_soudure
    ADD COLUMN id_pointage INT DEFAULT NULL AFTER id_soudeur,
    ADD UNIQUE KEY soudure_par_pointage (id_pointage),
    ADD CONSTRAINT fk_soudure_pointage FOREIGN KEY (id_pointage)
        REFERENCES pointage_atelier (id) ON DELETE SET NULL');

echo "Lien soudure / pointage en place.\n";
