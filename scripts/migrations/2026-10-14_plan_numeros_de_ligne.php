<?php

/**
 * Le numéro de ligne de chaque feuille d'un cahier ISO, retenu à côté de son plan.
 *
 * Un cahier ne numérote pas ses pages. Le sommaire du lecteur affichait donc « Page 1 »,
 * « Page 2 »… quand la feuille qu'on a en main, elle, porte « YU-PRE-108B » dans son
 * cartouche : pour retrouver à l'écran la feuille posée sur l'établi, il fallait les
 * ouvrir une par une.
 *
 * Le numéro est lu dans le texte du PDF au dépôt, comme le nombre de pages l'est déjà :
 * le volet n'a pas à rouvrir un cahier de quinze A3 chaque fois qu'il s'affiche.
 *
 * Ce script ajoute la colonne et rattrape les plans déjà déposés.
 *
 * Idempotent : à relancer sans risque. Un plan dont la lecture ne donne rien est
 * retenté au prochain passage, puisqu'il reste à NULL.
 *
 *   docker exec -i php-app-php-1 php < scripts/init/plan_numeros_de_ligne.php
 */
$racine = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $racine . '/db.php';
require_once $racine . '/dashboard/includes/lecture/plan_numeros.php';

$db = new MyPDO($racine . '/my_setting.ini');

$colonnes = $db->query('SHOW COLUMNS FROM element_atelier_document')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('numeros_ligne', $colonnes, true)) {
    $db->exec("ALTER TABLE element_atelier_document
               ADD COLUMN numeros_ligne JSON DEFAULT NULL
                   COMMENT 'numéro de ligne du cartouche, par page : {\"1\":\"YU-PRE-101\"}'
                   AFTER pages");
    echo "Colonne numeros_ligne ajoutée.\n";
} else {
    echo "Colonne numeros_ligne déjà là.\n";
}

// Les plans déjà déposés. On ne relit que ceux qui n'ont rien : la lecture est sûre mais
// elle coûte une seconde par cahier, et ce script est fait pour être relancé.
$plans = $db->query("SELECT d.id, d.cle_document, e.id_demande
                     FROM element_atelier_document d
                     JOIN element_atelier e ON e.id = d.id_element
                     WHERE d.type_document = 'plan' AND d.numeros_ligne IS NULL
                     ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);

if (!$plans) {
    echo "Aucun plan à rattraper.\n";
    return;
}

$enregistrer = $db->prepare('UPDATE element_atelier_document SET numeros_ligne = ? WHERE id = ?');
$lus = 0;
$muets = 0;

foreach ($plans as $plan) {
    $chemin = $racine . '/dashboard/uploads/prefa/' . (int) $plan['id_demande'] . '/' . $plan['cle_document'];
    $numeros = planNumerosDeLigne($chemin);
    if (!$numeros) {
        $muets++;
        echo "  plan #{$plan['id']} : rien lu", is_file($chemin) ? "\n" : " (fichier absent)\n";
        continue;
    }
    $enregistrer->execute([json_encode($numeros), (int) $plan['id']]);
    $lus++;
    echo "  plan #{$plan['id']} : " . count($numeros) . " page(s) — " . implode(', ', array_slice($numeros, 0, 3))
        . (count($numeros) > 3 ? '…' : '') . "\n";
}

echo "$lus plan(s) renseignés, $muets sans numéro lisible.\n";
