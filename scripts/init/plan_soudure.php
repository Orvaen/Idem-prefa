<?php
// Liste des soudures d'un plan ISO : une ligne par soudure repérée sur le plan, avec
// son soudeur, sa date, son type de soudure et ses contrôles.
//
// Le numéro est unique sur un plan : deux soudures ne peuvent pas porter le même repère,
// sans quoi un contrôle RT ne désignerait plus rien. C'est une donnée de traçabilité.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS plan_soudure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_element INT NOT NULL,
    -- Le repère porté sur le plan : « 1 », « S3 », « A12 »… d'où le texte libre.
    numero VARCHAR(20) NOT NULL,
    id_soudeur INT DEFAULT NULL,
    date_soudure DATE DEFAULT NULL,
    -- Type de soudure : TIG, MIG, 141… laissé libre, les ateliers n'ont pas
    -- tous le même vocabulaire.
    type_soudure VARCHAR(30) NOT NULL DEFAULT '',
    -- Contrôles : radiographie, contrôle visuel et ressuage, comme les colonnes du plan
    -- papier. Vide tant que le contrôle n'est pas fait, puis « OK » ou « Non OK » — mais
    -- laissé libre, comme le type de soudure : un cordon peut être « N/A », « En attente »
    -- ou porter le numéro du rapport du labo.
    rt VARCHAR(20) NOT NULL DEFAULT '',
    vi VARCHAR(20) NOT NULL DEFAULT '',
    pt VARCHAR(20) NOT NULL DEFAULT '',
    -- Le visa engage celui qui le pose : on garde qui et quand, pas juste des initiales.
    vise_par INT DEFAULT NULL,
    date_visa DATETIME DEFAULT NULL,
    saisie_par INT DEFAULT NULL,
    date_saisie DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY soudure_numero_par_plan (id_element, numero),
    KEY idx_soudure_soudeur (id_soudeur),
    CONSTRAINT fk_soudure_element FOREIGN KEY (id_element)
        REFERENCES element_atelier (id) ON DELETE CASCADE,
    CONSTRAINT fk_soudure_soudeur FOREIGN KEY (id_soudeur)
        REFERENCES Utilisateur (id) ON DELETE SET NULL,
    CONSTRAINT fk_soudure_visa FOREIGN KEY (vise_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL,
    CONSTRAINT fk_soudure_saisie FOREIGN KEY (saisie_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

// Les contrôles s'écrivaient « NOK » sur une colonne de 4 caractères. Le plan porte
// « Non OK » : la colonne s'élargit et les anciennes valeurs suivent.
//
// La largeur suit celle du CREATE TABLE ci-dessus, et ne doit jamais lui être inférieure :
// ce script se relance, et une colonne qu'on rétrécit tronque ce qu'elle contient déjà.
foreach (['rt', 'vi', 'pt'] as $controle) {
    $db->exec("ALTER TABLE plan_soudure MODIFY $controle VARCHAR(20) NOT NULL DEFAULT ''");
}
$reprises = $db->exec("UPDATE plan_soudure SET rt = IF(rt = 'NOK', 'Non OK', rt),
    vi = IF(vi = 'NOK', 'Non OK', vi), pt = IF(pt = 'NOK', 'Non OK', pt)
    WHERE 'NOK' IN (rt, vi, pt)");

echo "Table de la liste des soudures en place", $reprises ? " ($reprises ligne(s) reprise(s))" : '', ".\n";
