<?php
// La signature de chacun : tracée une fois dans ses paramètres, elle vise les cordons qu'il
// valide — la case VISA du plan la porte.
//
// Une signature est rangée par versions. La refaire en crée une ligne ; l'ancienne reste
// tant qu'un cordon la porte, parce qu'un cordon visé garde la signature du jour où il l'a
// été : comme sur le papier, changer la sienne ne retouche pas ce qui est déjà signé.
//
// Crée la table, puis la colonne du cordon qui désigne sa signature. Aucune donnée n'est
// écrite : les cordons déjà visés le restent au nom, jusqu'à ce que celui qui les a visés
// trace sa signature — elle s'y pose alors (signatureEnregistrer()). Relançable : ce qui est
// déjà là n'est pas touché.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW TABLES LIKE 'signature_utilisateur'")->fetch()) {
    echo "Table signature_utilisateur déjà là.\n";
} else {
    $db->exec("CREATE TABLE signature_utilisateur (
        id int NOT NULL AUTO_INCREMENT,
        id_utilisateur int NOT NULL,
        dessin json NOT NULL COMMENT 'les traits, en hauteurs de signature : {\"proportion\":3.2,\"traits\":[[[x,y],…],…]}',
        date_creation datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_retrait datetime DEFAULT NULL COMMENT 'refaite ou supprimée ce jour-là ; NULL : la signature en cours',
        PRIMARY KEY (id),
        KEY fk_signature_utilisateur (id_utilisateur),
        CONSTRAINT fk_signature_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    echo "Table signature_utilisateur créée.\n";
}

// La colonne en dernier : c'est elle que l'application regarde pour savoir si les
// signatures sont là (signaturesEnBase()), et elle suppose la table.
if ($db->query("SHOW COLUMNS FROM plan_soudure LIKE 'id_signature'")->fetch()) {
    echo "Colonne plan_soudure.id_signature déjà là.\n";
} else {
    $db->exec("ALTER TABLE plan_soudure
        ADD COLUMN id_signature int DEFAULT NULL COMMENT 'la signature posée avec le visa' AFTER date_visa,
        ADD KEY fk_soudure_signature (id_signature),
        ADD CONSTRAINT fk_soudure_signature FOREIGN KEY (id_signature) REFERENCES signature_utilisateur (id) ON DELETE SET NULL");
    echo "Colonne plan_soudure.id_signature ajoutée.\n";
}
