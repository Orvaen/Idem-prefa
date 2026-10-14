<?php
// Les articles d'un bon de commande, lus dans le PDF déposé sur la demande.
//
// Le bon arrive dans « Commande matériel », avec la demande. Jusqu'ici il n'était qu'un
// PDF de plus : pour savoir ce qui avait été commandé, il fallait l'ouvrir et le lire.
// Les colonnes de lecture suivent celles des CCPU — même lecteur, mêmes réserves — et
// restent vides tant que personne n'a demandé la lecture.
//
// Ce qui est enregistré ici est une lecture, pas une saisie : c'est l'atelier qui arrête
// les quantités, dans le PV « Réception matériel », et ses lignes à lui vivent dans
// bon_livraison_ligne. Relançable sans effet de bord.
$racine = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $racine . '/db.php';
$db = new MyPDO($racine . '/my_setting.ini');

$colonnes = $db->query('SHOW COLUMNS FROM demande_materiel_documents')->fetchAll(PDO::FETCH_COLUMN);

$ajouter = static function (string $nom, string $definition) use ($db, $colonnes): void {
    if (in_array($nom, $colonnes, true)) {
        echo "Colonne $nom déjà là.\n";
        return;
    }
    $db->exec('ALTER TABLE demande_materiel_documents ADD COLUMN ' . $nom . ' ' . $definition);
    echo "Colonne $nom ajoutée.\n";
};

// Comment le bon a été lu : « texte » (PDF d'origine du fournisseur), « ocr » (scan),
// « mixte », ou « echec ». NULL : pas encore lu.
$ajouter('lecture', 'VARCHAR(10) DEFAULT NULL');
$ajouter('erreur', 'VARCHAR(255) DEFAULT NULL');
$ajouter('date_lecture', 'DATETIME DEFAULT NULL');
// L'en-tête du bon : « COMMANDE 957-MAT-I-03-28588 Du 28/09/2026 ». C'est ce numéro-là
// que l'atelier reporte dans le tableau des commandes.
$ajouter('numero_commande', "VARCHAR(40) DEFAULT NULL COMMENT 'numéro lu dans l''en-tête du bon'");
$ajouter('date_commande', 'DATE DEFAULT NULL');
// Qui a relu la liste, et quand. Tant que c'est vide, ce qui s'affiche n'est qu'une
// lecture automatique ; une fois rempli, quelqu'un en répond.
$ajouter('verifie_par', 'INT DEFAULT NULL');
$ajouter('date_verification', 'DATETIME DEFAULT NULL');

$contraintes = $db->query("SELECT constraint_name FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'demande_materiel_documents'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('fk_materiel_documents_verificateur', $contraintes, true)) {
    $db->exec('ALTER TABLE demande_materiel_documents
        ADD CONSTRAINT fk_materiel_documents_verificateur FOREIGN KEY (verifie_par)
            REFERENCES Utilisateur (id) ON DELETE SET NULL');
    echo "Lien vers le vérificateur posé.
";
} else {
    echo "Lien vers le vérificateur déjà là.
";
}

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demande_materiel_lignes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_document BIGINT UNSIGNED NOT NULL,
    -- Le rang imprimé sur le bon, et la page où la ligne a été lue : ensemble, ils
    -- rendent l'ordre du document, qu'on ne retrouverait pas autrement.
    rang SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    reference VARCHAR(80) DEFAULT NULL,
    designation VARCHAR(180) NOT NULL,
    unite VARCHAR(16) NOT NULL DEFAULT 'u',
    quantite DECIMAL(12,3) NOT NULL,
    date_livraison DATE DEFAULT NULL,
    -- Deux indices pour qui relit : la confiance du lecteur, de 0 à 100, et le désaccord
    -- entre la quantité lue et celle que l'arithmétique du bon donne. Ni l'un ni l'autre
    -- ne vaut validation.
    confiance TINYINT UNSIGNED NOT NULL DEFAULT 0,
    douteuse TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_materiel_lignes_document (id_document),
    CONSTRAINT fk_materiel_lignes_document FOREIGN KEY (id_document)
        REFERENCES demande_materiel_documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

// La réception se pointe ligne par ligne. Elle ne modifie pas la commande — une commande
// validée est figée — elle constate ce qui est arrivé contre ce qui a été commandé.
$lignes = $db->query('SHOW COLUMNS FROM demande_materiel_lignes')->fetchAll(PDO::FETCH_COLUMN);

$ajouterLigne = static function (string $nom, string $definition) use ($db, $lignes): void {
    if (in_array($nom, $lignes, true)) {
        echo "Colonne $nom déjà là.\n";
        return;
    }
    $db->exec('ALTER TABLE demande_materiel_lignes ADD COLUMN ' . $nom . ' ' . $definition);
    echo "Colonne $nom ajoutée.\n";
};

$ajouterLigne('recu', 'TINYINT(1) NOT NULL DEFAULT 0');
$ajouterLigne('date_reception', 'DATETIME DEFAULT NULL');
$ajouterLigne('receptionne_par', 'INT DEFAULT NULL');

$liens = $db->query("SELECT constraint_name FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'demande_materiel_lignes'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('fk_materiel_lignes_receptionnaire', $liens, true)) {
    $db->exec('ALTER TABLE demande_materiel_lignes
        ADD CONSTRAINT fk_materiel_lignes_receptionnaire FOREIGN KEY (receptionne_par)
            REFERENCES Utilisateur (id) ON DELETE SET NULL');
    echo "Lien vers le réceptionnaire posé.\n";
} else {
    echo "Lien vers le réceptionnaire déjà là.\n";
}

echo "Articles des bons de commande en place.\n";
