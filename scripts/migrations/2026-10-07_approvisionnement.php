<?php
// Crée les tables du module matériel.
//
// Seule demande_approvisionnement est exploitée par l'application : elle porte les
// signalements de matériel manquant remontés par l'atelier. Les deux tables de bon de
// livraison sont posées en vue du rattachement à un fournisseur, prévu plus tard ;
// aucun écran ne les lit aujourd'hui.
// Relançable sans effet de bord.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bon_livraison (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_demande INT NOT NULL,
    numero VARCHAR(40) NOT NULL,
    fournisseur VARCHAR(120) NOT NULL,
    date_bl DATE NOT NULL,
    statut ENUM('attendu', 'partielle', 'complete', 'refusee') NOT NULL DEFAULT 'attendu',
    commentaire VARCHAR(1000) DEFAULT NULL,
    receptionne_par INT DEFAULT NULL,
    date_reception DATETIME DEFAULT NULL,
    -- Verrou optimiste : deux chefs d'atelier peuvent ouvrir le même bon.
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY bon_livraison_numero (numero),
    KEY bon_livraison_demande (id_demande),
    CONSTRAINT bon_livraison_demande_fk FOREIGN KEY (id_demande)
        REFERENCES demande_prefabrication (id) ON DELETE CASCADE,
    CONSTRAINT bon_livraison_receptionnaire_fk FOREIGN KEY (receptionne_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bon_livraison_ligne (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_bon INT NOT NULL,
    ordre INT NOT NULL DEFAULT 0,
    reference_article VARCHAR(80) DEFAULT NULL,
    designation VARCHAR(180) NOT NULL,
    unite VARCHAR(16) NOT NULL DEFAULT 'u',
    quantite_attendue DECIMAL(12,3) NOT NULL,
    -- NULL tant que la ligne n'a pas été pointée ; 0 = rien reçu.
    quantite_recue DECIMAL(12,3) DEFAULT NULL,
    commentaire VARCHAR(500) DEFAULT NULL,
    KEY bon_livraison_ligne_bon (id_bon),
    CONSTRAINT bon_livraison_ligne_bon_fk FOREIGN KEY (id_bon)
        REFERENCES bon_livraison (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS demande_approvisionnement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_demande INT NOT NULL,
    -- Renseignée quand le besoin vient d'un manquant constaté à la réception.
    id_ligne_bl INT DEFAULT NULL,
    designation VARCHAR(180) NOT NULL,
    quantite DECIMAL(12,3) NOT NULL,
    unite VARCHAR(16) NOT NULL DEFAULT 'u',
    motif ENUM('oubli', 'erreur', 'fabrication', 'autre') NOT NULL,
    commentaire VARCHAR(1000) DEFAULT NULL,
    demande_par INT NOT NULL,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('nouvelle', 'vue', 'traitee') NOT NULL DEFAULT 'nouvelle',
    traite_par INT DEFAULT NULL,
    date_traitement DATETIME DEFAULT NULL,
    KEY demande_approvisionnement_demande (id_demande),
    KEY demande_approvisionnement_statut (statut),
    CONSTRAINT demande_approvisionnement_demande_fk FOREIGN KEY (id_demande)
        REFERENCES demande_prefabrication (id) ON DELETE CASCADE,
    CONSTRAINT demande_approvisionnement_ligne_fk FOREIGN KEY (id_ligne_bl)
        REFERENCES bon_livraison_ligne (id) ON DELETE SET NULL,
    CONSTRAINT demande_approvisionnement_auteur_fk FOREIGN KEY (demande_par)
        REFERENCES Utilisateur (id) ON DELETE CASCADE,
    CONSTRAINT demande_approvisionnement_traitant_fk FOREIGN KEY (traite_par)
        REFERENCES Utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

// Le volet approvisionnement a été ramené à un simple suivi de signalement :
// deux états au lieu de cinq, et plus de champ de réponse. Les deux opérations sont
// relançables telles quelles.
$colonnes = $db->query('SHOW COLUMNS FROM demande_approvisionnement')->fetchAll(PDO::FETCH_COLUMN);
if (in_array('reponse', $colonnes, true)) {
    $db->exec('ALTER TABLE demande_approvisionnement DROP COLUMN reponse');
    echo "Colonne reponse retirée.\n";
}
$type = (string) $db->query("SHOW COLUMNS FROM demande_approvisionnement LIKE 'statut'")->fetch(PDO::FETCH_ASSOC)['Type'];
if (str_contains($type, 'commandee')) {
    // Tout ce qui n'était pas « nouvelle » devient « traité ».
    $db->exec("UPDATE demande_approvisionnement SET statut = 'livree' WHERE statut <> 'nouvelle'");
    $db->exec("ALTER TABLE demande_approvisionnement MODIFY statut ENUM('nouvelle', 'vue', 'traitee') NOT NULL DEFAULT 'nouvelle'");
    $db->exec("UPDATE demande_approvisionnement SET statut = 'traitee' WHERE statut = ''");
    echo "Statuts ramenés à deux valeurs.\n";
}
// État intermédiaire ajouté après coup : « vue » dit que le chef d'atelier a vu le
// signalement, sans prétendre qu'il est résolu. Rattrape aussi bien les bases à deux
// valeurs que celles passées par le nom provisoire « en_cours ». Relançable.
if (!str_contains($type, "'vue'")) {
    $db->exec("ALTER TABLE demande_approvisionnement MODIFY statut ENUM('nouvelle', 'en_cours', 'vue', 'traitee') NOT NULL DEFAULT 'nouvelle'");
    $db->exec("UPDATE demande_approvisionnement SET statut = 'vue' WHERE statut = 'en_cours'");
    $db->exec("ALTER TABLE demande_approvisionnement MODIFY statut ENUM('nouvelle', 'vue', 'traitee') NOT NULL DEFAULT 'nouvelle'");
    echo "État « vue » ajouté.\n";
}

echo "Tables en place.\n";
