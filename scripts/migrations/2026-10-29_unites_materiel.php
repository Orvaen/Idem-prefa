<?php
// Les unités qu'on propose en signalant un manque de matériel, tenues par le chargé de
// production dans les paramètres.
//
// L'unité était un champ libre : « u », « U », « pce », « pièce » pour la même chose, et
// rien pour l'empêcher. Elle se choisit maintenant dans une liste, que l'administration
// complète, renomme ou épure.
//
// Les manques déjà signalés gardent l'unité qu'ils portent, en texte : retirer une unité
// de la liste ne touche pas à l'historique.
//
// Crée la table et y pose six unités de départ si elle est vide. Relançable : une liste
// déjà là n'est pas touchée.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW TABLES LIKE 'unite_materiel'")->fetch()) {
    echo "Table unite_materiel déjà là.\n";
} else {
    $db->exec("CREATE TABLE unite_materiel (
        id int NOT NULL AUTO_INCREMENT,
        libelle varchar(16) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_unite_materiel_libelle (libelle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    echo "Table unite_materiel créée.\n";
}

if ((int) $db->query('SELECT COUNT(*) FROM unite_materiel')->fetchColumn() === 0) {
    // Celles qu'on lit sur les bons IDEM et qu'on écrit à l'atelier.
    $poser = $db->prepare('INSERT INTO unite_materiel (libelle) VALUES (?)');
    foreach (['u', 'PCE', 'm', 'kg', 'barre', 'lot'] as $libelle) $poser->execute([$libelle]);
    echo "Six unités de départ posées : u, PCE, m, kg, barre, lot.\n";
} else {
    echo "La liste des unités a déjà des entrées : elle n’est pas touchée.\n";
}
