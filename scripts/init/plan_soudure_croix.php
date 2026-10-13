<?php
// Ramène les contrôles RT, VI et PT à une croix ou rien.
//
// Les trois colonnes portaient une mention libre, « OK » ou « Non OK » le plus souvent.
// Sur le plan, la colonne fait deux pour cent de la feuille : « Non OK » s'y écrase, et
// l'atelier coche de toute façon ses contrôles sur le papier. Une case cochée, donc, qui
// écrit une croix ; rien quand elle ne l'est pas.
//
// Ce qui valait « Non OK » devient une case vide : le contrôle non conforme ne se
// distingue plus du contrôle pas encore fait. C'est le choix assumé de la feuille papier,
// où seule la croix se porte.
//
// Toute valeur non vide devient la croix — y compris « N/A » ou un numéro de rapport
// qu'un atelier aurait saisi : la colonne ne peut plus les rendre. Relançable.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if (!$db->query("SHOW COLUMNS FROM plan_soudure LIKE 'rt'")->fetch()) {
    echo "La table plan_soudure n’existe pas encore : lancez plan_soudure.php d’abord.\n";
    exit(1);
}

// Ce qui va disparaître, dit avant de le faire : un « Non OK » effacé ne se retrouve pas.
$perdus = $db->query("SELECT COUNT(*) FROM plan_soudure
    WHERE rt = 'Non OK' OR vi = 'Non OK' OR pt = 'Non OK'")->fetchColumn();
if ($perdus) echo "⚠ $perdus ligne(s) portaient « Non OK » : leur case devient vide.\n";

$aRanger = $db->query("SELECT COUNT(*) FROM plan_soudure
    WHERE (rt <> '' AND rt <> 'X') OR (vi <> '' AND vi <> 'X') OR (pt <> '' AND pt <> 'X')")->fetchColumn();
if (!$aRanger) {
    echo "Les contrôles sont déjà en croix.\n";
    exit;
}

// « Non OK » d'abord : sans ça, la règle générale en ferait une croix.
$db->exec("UPDATE plan_soudure SET
    rt = IF(rt = 'Non OK', '', rt),
    vi = IF(vi = 'Non OK', '', vi),
    pt = IF(pt = 'Non OK', '', pt)");
$db->exec("UPDATE plan_soudure SET
    rt = IF(rt = '', '', 'X'),
    vi = IF(vi = '', '', 'X'),
    pt = IF(pt = '', '', 'X')");

echo "Contrôles ramenés à la croix : $aRanger ligne(s) revues.\n";
