<?php
// L'avancement déclaré par l'atelier devient une proposition que le chef valide.
//
// Avant ce changement, la personne affectée tapait un pourcentage et il était pris tel
// quel : rien ne distinguait un chiffre annoncé d'un chiffre reconnu. Le chef d'atelier
// voyait le même nombre que l'ouvrier, sans avoir rien dit.
//
// Trois choses à retenir, donc : combien de soudures ont été déclarées faites, sur
// combien le plan en portait au moment de la déclaration, et ce que le chef en a fait.
//
// Le total est figé dans la ligne — et non recalculé à l'affichage — parce que la liste
// des soudures grandit : « 7 sur 7 » déclaré lundi doit encore se lire « 7 sur 7 » le
// jour où le plan en compte douze.
//
// Les lignes déjà en base passent en « valide » : elles avaient bel et bien déplacé le
// pourcentage, et les relire comme des propositions en attente ferait apparaître des
// dizaines de validations à faire sur du travail terminé. Relançable.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$colonne = static fn (string $nom): bool =>
    (bool) $db->query("SHOW COLUMNS FROM avancement_atelier LIKE " . $db->quote($nom))->fetch();

if (!$db->query("SHOW TABLES LIKE 'avancement_atelier'")->fetch()) {
    echo "La table avancement_atelier n’existe pas encore : installez d’abord db/schema.sql.\n";
    exit(1);
}

$ajouts = [];
if (!$colonne('soudures')) {
    $ajouts[] = "ADD COLUMN soudures smallint unsigned NULL AFTER avancement";
}
if (!$colonne('soudures_total')) {
    $ajouts[] = "ADD COLUMN soudures_total smallint unsigned NULL AFTER soudures";
}
if (!$colonne('etat')) {
    $ajouts[] = "ADD COLUMN etat enum('propose','valide','refuse') NOT NULL DEFAULT 'valide' AFTER commentaire";
}
if (!$colonne('valide_par')) {
    $ajouts[] = "ADD COLUMN valide_par int NULL AFTER etat";
    $ajouts[] = "ADD COLUMN date_validation datetime NULL AFTER valide_par";
    $ajouts[] = "ADD KEY fk_avancement_valide (valide_par)";
    $ajouts[] = "ADD CONSTRAINT fk_avancement_valide FOREIGN KEY (valide_par)"
        . " REFERENCES Utilisateur (id) ON DELETE SET NULL";
}

if (!$ajouts) {
    echo "La validation de l’avancement est déjà en place.\n";
    exit;
}

$db->exec('ALTER TABLE avancement_atelier ' . implode(', ', $ajouts));

// L'index qui sert la seule question posée à chaque affichage : « cette affectation a-t-elle
// une déclaration en attente ? »
if (!$db->query("SHOW INDEX FROM avancement_atelier WHERE Key_name = 'idx_avancement_attente'")->fetch()) {
    $db->exec('ALTER TABLE avancement_atelier ADD KEY idx_avancement_attente (id_affectation, etat, id)');
}

$anciennes = (int) $db->query("SELECT COUNT(*) FROM avancement_atelier WHERE etat = 'valide'")->fetchColumn();
echo "Validation de l’avancement en place. $anciennes saisie(s) existante(s) comptent comme validées.\n";
