<?php
// Certificats matière (CCPU) joints à une demande de préfabrication, et les numéros
// de coulée que la lecture automatique y a reconnus.
//
// Le PDF est stocké en base comme les QMOS et DMOS, mais en LONGBLOB : le formulaire
// annonce 50 Mo par fichier, et un MEDIUMBLOB s'arrête à 16. Les colonnes de lecture
// restent vides tant que le certificat n'a pas été lu ; une lecture se refait à volonté.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demande_ccpu_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_demande INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    contenu LONGBLOB NOT NULL,
    sha256 BINARY(32) NOT NULL,
    -- Comment la page a été lue : « texte » (PDF d'origine), « ocr » (scan), « mixte »,
    -- « aucune » (rien de lisible) ou « echec ». NULL : pas encore lu.
    lecture VARCHAR(10) DEFAULT NULL,
    erreur VARCHAR(255) DEFAULT NULL,
    date_lecture DATETIME DEFAULT NULL,
    certificat VARCHAR(40) DEFAULT NULL,
    dimensions VARCHAR(255) DEFAULT NULL,
    nuances VARCHAR(255) DEFAULT NULL,
    KEY idx_ccpu_documents_demande (id_demande),
    CONSTRAINT fk_ccpu_documents_demande FOREIGN KEY (id_demande)
        REFERENCES demande_prefabrication (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demande_ccpu_coulees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_document BIGINT UNSIGNED NOT NULL,
    numero VARCHAR(20) NOT NULL,
    page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Confiance du lecteur, de 0 à 100, et présence d'une seconde lecture sur la page.
    -- Ce sont des indices pour la personne qui valide, pas une validation.
    confiance TINYINT UNSIGNED NOT NULL DEFAULT 0,
    confirmee TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY ccpu_coulee_par_document (id_document, numero),
    CONSTRAINT fk_ccpu_coulees_document FOREIGN KEY (id_document)
        REFERENCES demande_ccpu_documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Tables des certificats matière en place.\n";
