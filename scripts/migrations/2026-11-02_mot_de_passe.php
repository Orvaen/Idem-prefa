<?php
// Le mot de passe se choisit à la première connexion. Pour en changer — oublié, ou
// simplement à remplacer —, on demande à l'administrateur de le réinitialiser, et l'on
// choisit le nouveau à sa connexion suivante.
//
// Un compte sans mot de passe — tout juste créé, ou réinitialisé — porte un mot de passe
// vide : la colonne existe, rien n'est à ajouter pour lui. Seule la demande de
// réinitialisation a besoin d'une place, le temps que l'administrateur la traite.
//
// Ajoute la colonne, sans rien y écrire. Relançable : si elle est là, rien n'est touché.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW COLUMNS FROM Utilisateur LIKE 'reinitialisation_demandee_le'")->fetch()) {
    echo "Colonne Utilisateur.reinitialisation_demandee_le déjà là.\n";
} else {
    $db->exec("ALTER TABLE Utilisateur
        ADD COLUMN reinitialisation_demandee_le datetime DEFAULT NULL
            COMMENT 'la personne demande un nouveau mot de passe — oublié, ou à changer : l''administrateur réinitialise ; NULL sinon' AFTER password");
    echo "Colonne Utilisateur.reinitialisation_demandee_le ajoutée.\n";
}
