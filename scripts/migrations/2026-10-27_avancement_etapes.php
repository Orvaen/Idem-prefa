<?php
// L'avancement se calcule sur les étapes du plan : la déclaration retient combien.
//
// L'atelier ne tape plus de pourcentage. Enregistrer les étapes d'un plan — Débit,
// Découpage, Pliage… — déclare pour chacun de ses travaux le compte des étapes faites sur
// celles qui s'appliquent, et le chef valide comme il validait les cordons.
//
// Le compte est figé dans la ligne, comme celui des soudures : « 4 étapes sur 6 » déclaré
// lundi doit encore se lire ainsi le jour où une étape passe en RAS et le total tombe à 5.
//
// Ne touche à aucune donnée : les lignes déjà en base n'ont pas de compte d'étapes, et se
// lisent comme avant — leur seul pourcentage. Relançable.
//
// Tant qu'elle n'est pas passée, l'application déclare quand même, sans le compte : voir
// avancementEtapesEnBase().
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$colonne = static fn (string $nom): bool =>
    (bool) $db->query("SHOW COLUMNS FROM avancement_atelier LIKE " . $db->quote($nom))->fetch();

if (!$db->query("SHOW TABLES LIKE 'avancement_atelier'")->fetch()) {
    echo "La table avancement_atelier n’existe pas encore : installez d’abord db/schema.sql.\n";
    exit(1);
}
if (!$colonne('soudures_total')) {
    echo "Passez d’abord 2026-10-14_avancement_valide.php : cette migration se range après ses colonnes.\n";
    exit(1);
}

$ajouts = [];
if (!$colonne('etapes')) {
    $ajouts[] = "ADD COLUMN etapes tinyint unsigned NULL AFTER soudures_total";
}
if (!$colonne('etapes_total')) {
    $ajouts[] = "ADD COLUMN etapes_total tinyint unsigned NULL AFTER etapes";
}

if (!$ajouts) {
    echo "Le compte d’étapes des déclarations est déjà en place.\n";
    exit;
}

$db->exec('ALTER TABLE avancement_atelier ' . implode(', ', $ajouts));
echo "Compte d’étapes des déclarations en place.\n";
