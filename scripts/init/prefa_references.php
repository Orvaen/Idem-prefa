<?php
// Idempotent migration: existing references and deleted numbers are preserved.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/prefa_references.php';
$db = new MyPDO($root . '/my_setting.ini');
$db->exec('CREATE TABLE IF NOT EXISTS prefa_reference_compteur (
    annee SMALLINT UNSIGNED PRIMARY KEY,
    dernier_numero INT UNSIGNED NOT NULL
) ENGINE=InnoDB');
$db->exec('CREATE TABLE IF NOT EXISTS prefa_reference (
    id_demande INT PRIMARY KEY,
    annee SMALLINT UNSIGNED NOT NULL,
    numero INT UNSIGNED NOT NULL,
    reference VARCHAR(24) NOT NULL UNIQUE,
    UNIQUE KEY reference_annuelle (annee, numero)
) ENGINE=InnoDB');
if ((int) $db->query("SELECT GET_LOCK('prefa_reference_migration', 30)")->fetchColumn() !== 1) {
    throw new RuntimeException('Une migration des références est déjà en cours.');
}
try {
    $db->beginTransaction();
    // Le numéro tient sur quatre chiffres. Cette requête répare aussi bien les
    // références absentes que celles encore sur trois chiffres : elle recalcule la
    // forme attendue et ne touche que les lignes qui s'en écartent.
    $db->exec("UPDATE prefa_reference
        SET reference = CONCAT(LPAD(MOD(annee, 100), 2, '0'), '-DP-', CASE WHEN numero < 10000 THEN LPAD(numero, 4, '0') ELSE CAST(numero AS CHAR) END)
        WHERE CAST(reference AS BINARY) <> CAST(CONCAT(LPAD(MOD(annee, 100), 2, '0'), '-DP-', CASE WHEN numero < 10000 THEN LPAD(numero, 4, '0') ELSE CAST(numero AS CHAR) END) AS BINARY)");
    $requests = $db->query('SELECT d.id, d.date_creation FROM demande_prefabrication d
        LEFT JOIN prefa_reference r ON r.id_demande = d.id WHERE r.id_demande IS NULL
        ORDER BY d.date_creation, d.id FOR UPDATE')->fetchAll();
    foreach ($requests as $request) {
        $date = new DateTimeImmutable($request['date_creation'] ?: 'now', new DateTimeZone('UTC'));
        $year = (int) $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y');
        prefaAssignReference($db, (int) $request['id'], $year);
    }
    $db->commit();
    echo count($requests) . " référence(s) attribuée(s).\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('prefa_reference_migration')");
}
