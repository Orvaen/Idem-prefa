<?php
// Les affaires confiées qu'un chargé d'affaire n'a pas encore vues : la pastille « +1 » sur
// l'entrée « Accueil ». Ce qui a été vu tient dans un curseur sur le journal des
// événements — l'identifiant du dernier lu.
//
// Le curseur part du dernier événement existant : on n'annonce pas, le jour de la mise à
// jour, toutes les affaires confiées depuis l'ouverture du serveur.
//
// Relançable : la colonne n'est ajoutée qu'une fois.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$colonne = $db->query("SHOW COLUMNS FROM Utilisateur LIKE 'dernier_evenement_vu'")->fetch();
if ($colonne) {
    echo "Colonne Utilisateur.dernier_evenement_vu déjà là.\n";
    return;
}

$db->exec("ALTER TABLE Utilisateur
    ADD COLUMN dernier_evenement_vu BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'le dernier événement que la personne a vu sur son accueil'");
$dernier = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM evenement')->fetchColumn();
$db->prepare('UPDATE Utilisateur SET dernier_evenement_vu = ?')->execute([$dernier]);
echo "Colonne Utilisateur.dernier_evenement_vu ajoutée, calée sur l'événement $dernier.\n";
