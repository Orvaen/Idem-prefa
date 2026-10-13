<?php
// L'affaire devient une vraie entité, et une affaire porte plusieurs demandes.
//
// Jusqu'ici « l'affaire » n'était qu'une chaîne libre recopiée sur chaque demande : deux
// demandes du même chantier n'étaient reliées que par un texte identique, et « BATIMENT A »
// contre « Bâtiment A » en faisait deux. Rien ne permettait non plus de rattacher un chargé
// d'affaire à une affaire — le droit existait sur la fiche du client, pas dans la base.
//
// Le nom reste recopié sur la demande (nom_affaire) : c'est lui qui s'affiche partout, et le
// remplacer par une jointure dans onze fichiers est un autre chantier. Ce script pose la
// clé ; l'affichage suivra.
//
// La reprise regroupe sur le nom, à la casse et aux accents près — c'est ce que fait la
// collation utf8mb4_0900_ai_ci de la colonne. « BATIMENT A » et « Bâtiment a » deviennent
// donc une seule affaire, ce qui est le but.
//
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$faits = [];

if ($db->query("SHOW TABLES LIKE 'affaire'")->fetch()) {
    $faits[] = 'affaire : table déjà présente';
} else {
    $db->exec("CREATE TABLE affaire (
        id INT NOT NULL AUTO_INCREMENT,
        nom VARCHAR(255) NOT NULL,
        id_charge_affaire INT DEFAULT NULL COMMENT 'Le chargé d''affaire, rôle 5',
        date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY affaire_nom (nom),
        KEY fk_affaire_charge (id_charge_affaire),
        CONSTRAINT fk_affaire_charge FOREIGN KEY (id_charge_affaire)
            REFERENCES Utilisateur (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $faits[] = 'affaire : table créée';
}

if ($db->query("SHOW COLUMNS FROM demande_prefabrication LIKE 'id_affaire'")->fetch()) {
    $faits[] = 'demande_prefabrication : colonne id_affaire déjà présente';
} else {
    $db->exec('ALTER TABLE demande_prefabrication
        ADD COLUMN id_affaire INT DEFAULT NULL AFTER nom_affaire,
        ADD KEY fk_demande_affaire (id_affaire),
        ADD CONSTRAINT fk_demande_affaire FOREIGN KEY (id_affaire)
            REFERENCES affaire (id) ON DELETE SET NULL');
    $faits[] = 'demande_prefabrication : colonne id_affaire ajoutée';
}

// Une affaire par nom rencontré, puis chaque demande rattachée à la sienne.
$db->exec("INSERT IGNORE INTO affaire (nom)
    SELECT DISTINCT TRIM(nom_affaire) FROM demande_prefabrication
    WHERE nom_affaire IS NOT NULL AND TRIM(nom_affaire) <> ''");
// Le rattachement se refait à chaque passage, et pas seulement pour les demandes sans
// affaire : une demande renommée doit rejoindre la bonne, sans quoi deux demandes du même
// chantier resteraient sur deux affaires selon leur ancien nom.
$rattachees = $db->exec("UPDATE demande_prefabrication d
    JOIN affaire a ON a.nom = TRIM(d.nom_affaire)
    SET d.id_affaire = a.id
    WHERE d.id_affaire IS NULL OR d.id_affaire <> a.id");

$affaires = (int) $db->query('SELECT COUNT(*) FROM affaire')->fetchColumn();
$orphelines = (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE id_affaire IS NULL')->fetchColumn();
$faits[] = "reprise : $affaires affaire(s), $rattachees demande(s) rattachée(s)"
    . ($orphelines ? ", $orphelines sans nom d'affaire" : '');

echo implode("\n", $faits), "\n";
