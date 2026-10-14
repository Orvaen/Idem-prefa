<?php
// Rattache les coulées et les soudures au PDF sur lequel elles ont été saisies.
//
// Un élément d'atelier — « un plan » dans le vocabulaire de l'application — porte
// plusieurs fichiers ISO : le chef d'atelier en dépose un par ligne de tuyauterie. Chacun
// a sa nomenclature et sa liste soudure imprimées, donc ses propres numéros de coulée et
// ses propres cordons. Les ranger sous l'élément seul les mélangeait : les coulées d'un
// ISO s'affichaient sur tous les autres, et la position des cases — relevée sur un plan
// précis — n'avait aucun sens sur ses voisins.
//
// La colonne reste nullable : une ligne de soudure peut naître d'un pointage avant qu'on
// sache sur quel ISO elle tombe. Elle se rattache au moment où on la numérote.
//
// Les lignes déjà saisies partent sur le premier PDF de leur élément : c'est le seul que
// le lecteur ouvrait jusqu'ici, donc c'est bien sur lui qu'elles ont été posées.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$faits = [];
foreach (['plan_nomenclature', 'plan_soudure'] as $table) {
    if ($db->query("SHOW COLUMNS FROM $table LIKE 'id_document'")->fetch()) {
        $faits[] = "$table : déjà rattachée";
        continue;
    }

    $db->exec("ALTER TABLE $table
        ADD COLUMN id_document INT DEFAULT NULL AFTER id_element,
        ADD CONSTRAINT fk_{$table}_document FOREIGN KEY (id_document)
            REFERENCES element_atelier_document (id) ON DELETE CASCADE");

    // Le premier PDF de plan de chaque élément : celui que le lecteur ouvrait.
    $reprises = $db->exec("UPDATE $table t
        JOIN (
            SELECT id_element, MIN(id) AS id_document
            FROM element_atelier_document WHERE type_document = 'plan'
            GROUP BY id_element
        ) premier ON premier.id_element = t.id_element
        SET t.id_document = premier.id_document
        WHERE t.id_document IS NULL");
    $faits[] = "$table : rattachée ($reprises ligne(s) reprise(s))";
}

// Le rang d'une ligne de nomenclature et le numéro d'une soudure sont uniques PAR PDF,
// et non par élément : deux ISO du même élément ont tous deux une ligne 1.
//
// Le nouvel index se pose AVANT que l'ancien ne parte. L'ancien sert d'appui à la clé
// étrangère sur id_element, et MySQL refuse de retirer le dernier index qui la porte ;
// le nouveau commence par la même colonne, il prend donc le relais.
foreach ([
    ['plan_nomenclature', 'nomenclature_rang_par_plan', 'nomenclature_rang_par_document', '(id_element, id_document, rang)'],
    ['plan_soudure', 'soudure_numero_par_plan', 'soudure_numero_par_document', '(id_element, id_document, numero)'],
] as [$table, $ancien, $nouveau, $colonnes]) {
    if ($db->query("SHOW INDEX FROM $table WHERE Key_name = '$nouveau'")->fetch()) {
        $faits[] = "$table : unicité déjà par PDF";
        continue;
    }
    $db->exec("ALTER TABLE $table ADD UNIQUE KEY $nouveau $colonnes");
    if ($db->query("SHOW INDEX FROM $table WHERE Key_name = '$ancien'")->fetch()) {
        $db->exec("ALTER TABLE $table DROP INDEX $ancien");
    }
    $faits[] = "$table : unicité portée au PDF";
}

echo implode("\n", $faits), "\n";
