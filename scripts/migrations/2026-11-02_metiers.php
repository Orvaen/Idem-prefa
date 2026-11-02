<?php
// Les métiers de l'atelier sur un plan : débiteur, assembleur, pointeur, soudeur,
// passiveur. Le chef d'atelier les donne plan par plan à n'importe qui du personnel ; une
// même personne peut en tenir plusieurs sur le même plan, et un métier peut revenir à
// plusieurs personnes.
//
// Ils s'ajoutent aux trois travaux d'un plan (Plan / ISO, Montage, Suivi soudage), qui ne
// changent pas : un métier ne porte pas d'avancement. Il donne accès au plan, comme un
// travail.
//
// Crée la table, sans rien y écrire. Relançable : si elle est là, rien n'est touché.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if ($db->query("SHOW TABLES LIKE 'affectation_metier'")->fetch()) {
    echo "Table affectation_metier déjà là.\n";
} else {
    $db->exec("CREATE TABLE affectation_metier (
        id int NOT NULL AUTO_INCREMENT,
        id_element int NOT NULL,
        id_utilisateur int NOT NULL,
        metier enum('debit','assemblage','pointage','soudage','passivation') NOT NULL,
        affecte_par int NOT NULL,
        date_affectation datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_metier (id_element, id_utilisateur, metier),
        KEY idx_metier_utilisateur (id_utilisateur),
        KEY fk_metier_affecte_par (affecte_par),
        CONSTRAINT fk_metier_element FOREIGN KEY (id_element) REFERENCES element_atelier (id) ON DELETE CASCADE,
        CONSTRAINT fk_metier_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES Utilisateur (id),
        CONSTRAINT fk_metier_affecte_par FOREIGN KEY (affecte_par) REFERENCES Utilisateur (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    echo "Table affectation_metier créée.\n";
}
