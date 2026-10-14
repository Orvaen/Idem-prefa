<?php
// Nomenclature de matériel d'un plan ISO : les lignes relevées sur le plan, et pour
// chacune le numéro de coulée du tube ou de l'accessoire employé.
//
// La coulée est stockée en texte et non en clé étrangère vers demande_ccpu_coulees :
// le cahier des charges impose la saisie manuelle à côté du menu déroulant, et une
// coulée peut figurer sur un bon papier avant d'avoir son certificat en base. La
// colonne id_coulee garde le lien quand la valeur vient bien d'un certificat lu.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS plan_nomenclature (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_element INT NOT NULL,
    -- Rang de la ligne sur le plan : c'est le « N° » de la colonne de gauche.
    rang SMALLINT UNSIGNED NOT NULL,
    quantite VARCHAR(20) NOT NULL DEFAULT '',
    dn VARCHAR(20) NOT NULL DEFAULT '',
    epaisseur VARCHAR(20) NOT NULL DEFAULT '',
    description VARCHAR(180) NOT NULL DEFAULT '',
    numero_coulee VARCHAR(20) NOT NULL DEFAULT '',
    -- Renseigné quand la coulée a été choisie dans la liste d'un certificat lu.
    -- Mis à NULL si le certificat disparaît : la valeur saisie, elle, reste.
    id_coulee BIGINT UNSIGNED DEFAULT NULL,
    saisie_par INT DEFAULT NULL,
    date_saisie DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY nomenclature_rang_par_plan (id_element, rang),
    KEY idx_nomenclature_coulee (id_coulee),
    CONSTRAINT fk_nomenclature_element FOREIGN KEY (id_element)
        REFERENCES element_atelier (id) ON DELETE CASCADE,
    CONSTRAINT fk_nomenclature_coulee FOREIGN KEY (id_coulee)
        REFERENCES demande_ccpu_coulees (id) ON DELETE SET NULL,
    CONSTRAINT fk_nomenclature_saisie FOREIGN KEY (saisie_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Table de la nomenclature des plans en place.\n";
