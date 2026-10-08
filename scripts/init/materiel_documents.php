<?php
// Fiches matériel jointes à une demande de préfabrication.
//
// Même forme que les documents QMOS et DMOS : le PDF est stocké en base, avec son
// empreinte, et disparaît avec la demande. Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demande_materiel_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_demande INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    contenu MEDIUMBLOB NOT NULL,
    sha256 BINARY(32) NOT NULL,
    KEY idx_materiel_documents_demande (id_demande),
    CONSTRAINT fk_materiel_documents_demande FOREIGN KEY (id_demande)
        REFERENCES demande_prefabrication (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Table des fiches matériel en place.\n";
