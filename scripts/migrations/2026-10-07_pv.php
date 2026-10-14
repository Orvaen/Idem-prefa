<?php
// Crée la table des PV (procès-verbaux) d'une demande de préfabrication.
//
// Un PV par type et par demande : réception matériel, fin de fabrication, conformité,
// passivation. Les documents déposés vivent sur disque, comme les pièces jointes des
// demandes ; la table n'en garde que le nom de stockage.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pv_prefabrication (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_demande INT NOT NULL,
    type ENUM('reception_materiel', 'fin_fabrication', 'conformite', 'passivation') NOT NULL,
    statut ENUM('a_faire', 'fait', 'sans_objet') NOT NULL DEFAULT 'a_faire',
    date_pv DATE DEFAULT NULL,
    commentaire VARCHAR(1000) DEFAULT NULL,
    maj_par INT DEFAULT NULL,
    date_maj DATETIME DEFAULT NULL,
    -- Verrou optimiste : deux personnes peuvent ouvrir le même PV.
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY pv_demande_type (id_demande, type),
    CONSTRAINT pv_demande_fk FOREIGN KEY (id_demande)
        REFERENCES demande_prefabrication (id) ON DELETE CASCADE,
    CONSTRAINT pv_auteur_fk FOREIGN KEY (maj_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pv_document (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pv INT NOT NULL,
    -- Nom de stockage sur disque : <32 hex>-<nom nettoyé>
    cle VARCHAR(255) NOT NULL,
    nom VARCHAR(190) NOT NULL,
    taille INT UNSIGNED NOT NULL DEFAULT 0,
    depose_par INT DEFAULT NULL,
    date_depot DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY pv_document_cle (id_pv, cle),
    CONSTRAINT pv_document_pv_fk FOREIGN KEY (id_pv)
        REFERENCES pv_prefabrication (id) ON DELETE CASCADE,
    CONSTRAINT pv_document_auteur_fk FOREIGN KEY (depose_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Tables des PV en place.\n";

$dossier = $root . '/dashboard/uploads/pv';
if (!is_dir($dossier) && !@mkdir($dossier, 0775, true)) {
    fwrite(STDERR, "Impossible de créer $dossier\n");
    exit(1);
}
// Ce script tourne en ligne de commande, souvent en root, alors que les dépôts sont
// faits par le serveur web. Sans alignement sur le propriétaire du dossier parent,
// Apache ne peut pas créer les sous-dossiers des PV et tout dépôt échoue.
$parent = dirname($dossier);
$proprietaire = @fileowner($parent);
$groupe = @filegroup($parent);
if ($proprietaire !== false && @fileowner($dossier) !== $proprietaire) {
    if (!@chown($dossier, $proprietaire) || ($groupe !== false && !@chgrp($dossier, $groupe))) {
        fwrite(STDERR, "Ajustez les droits : $dossier doit appartenir au même compte que $parent.\n");
        exit(1);
    }
    echo "Propriétaire aligné sur $parent.\n";
}

echo "Dossier des documents : $dossier\n";
