<?php

/**
 * Le bon de commande fournisseur, tel qu'il arrive dans la réception matériel.
 *
 * La table bon_livraison existait déjà — numéro, fournisseur, date, statut, lien à la
 * demande — mais il lui manquait deux choses que porte le document papier :
 *
 *  - une seconde date de livraison. Sur un bon, chaque ligne a la sienne ; quand la
 *    commande arrive en deux fois, l'atelier doit voir les deux échéances sans ouvrir
 *    le PDF.
 *  - le lieu de livraison. Celui du bon n'est pas toujours l'atelier : « IDEM SPAIN,
 *    Poligono Industrial El Saladar, Totana » sur celui que j'ai sous les yeux. Savoir
 *    où ça arrive change qui doit le réceptionner.
 *
 * L'état « reçu / non reçu » réutilise l'énumération en place : « attendu » et
 * « complete ». En ajouter deux qui disent la même chose rendrait la colonne illisible.
 *
 * Idempotent : à relancer sans risque.
 *
 *   docker exec -i php-app-php-1 php < scripts/init/commandes.php
 */
$racine = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $racine . '/db.php';

$db = new MyPDO($racine . '/my_setting.ini');

$colonnes = $db->query('SHOW COLUMNS FROM bon_livraison')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('date_livraison_2', $colonnes, true)) {
    $db->exec("ALTER TABLE bon_livraison
               ADD COLUMN date_livraison_2 DATE DEFAULT NULL
                   COMMENT 'seconde échéance quand la commande arrive en deux fois'
                   AFTER date_bl");
    echo "Colonne date_livraison_2 ajoutée.\n";
} else {
    echo "Colonne date_livraison_2 déjà là.\n";
}

if (!in_array('lieu_livraison', $colonnes, true)) {
    $db->exec("ALTER TABLE bon_livraison
               ADD COLUMN lieu_livraison VARCHAR(255) DEFAULT NULL
                   COMMENT 'où la marchandise arrive, repris de l''adresse de livraison du bon'
                   AFTER date_livraison_2");
    echo "Colonne lieu_livraison ajoutée.\n";
} else {
    echo "Colonne lieu_livraison déjà là.\n";
}

// La date du bon était obligatoire. Une commande peut être saisie avant qu'on connaisse
// sa date de livraison : on ne force pas une date inventée pour pouvoir enregistrer.
$type = $db->query("SHOW COLUMNS FROM bon_livraison LIKE 'date_bl'")->fetch(PDO::FETCH_ASSOC);
if ($type && strtoupper((string) $type['Null']) === 'NO') {
    $db->exec('ALTER TABLE bon_livraison MODIFY COLUMN date_bl DATE DEFAULT NULL');
    echo "date_bl devient facultative.\n";
} else {
    echo "date_bl déjà facultative.\n";
}
