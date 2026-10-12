<?php
// Type de soudure déclaré par la personne au moment de son pointage.
//
// À savoir : le type figure aussi sur chaque ligne de plan_soudure. Ce champ-ci est
// la déclaration de l'ouvrier sur sa journée, celui de plan_soudure est le type
// retenu pour le cordon. Les deux peuvent diverger — c'est le prix d'une saisie
// libre, assumé. En cas de doute sur la conformité, c'est plan_soudure qui fait foi.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$existe = $db->query("SHOW COLUMNS FROM pointage_atelier LIKE 'type_soudure'")->fetch();
if ($existe) {
    echo "Le type de soudure est déjà présent sur les pointages.\n";
    exit;
}

$db->exec("ALTER TABLE pointage_atelier
    ADD COLUMN type_soudure VARCHAR(30) NOT NULL DEFAULT '' AFTER duree_minutes");

echo "Type de soudure ajouté aux pointages.\n";
