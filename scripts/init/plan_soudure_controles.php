<?php
// Ouvre les colonnes de contrôle RT, VI et PT à autre chose que « OK » / « Non OK ».
//
// Les trois colonnes avaient la largeur juste pour ces deux réponses. Mais un atelier
// n'écrit pas toujours l'une des deux : un cordon peut être « N/A » parce qu'il n'est
// pas soumis à radiographie, « En attente » tant que le labo n'a pas rendu, ou porter le
// numéro du rapport. Même raison que les procédés de soudage du pointage : on propose un
// vocabulaire, on ne l'impose pas.
//
// Vingt caractères : assez pour une mention courte, assez court pour rester lisible dans
// la colonne du plan, qui ne fait que deux pour cent de la feuille. Relançable.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

$colonne = $db->query("SHOW COLUMNS FROM plan_soudure LIKE 'rt'")->fetch();
if (!$colonne) {
    echo "La table plan_soudure n’existe pas encore : lancez plan_soudure.php d’abord.\n";
    exit(1);
}
if (stripos((string) $colonne['Type'], 'varchar(20)') !== false) {
    echo "Les colonnes de contrôle acceptent déjà la saisie libre.\n";
    exit;
}

$db->exec("ALTER TABLE plan_soudure
    MODIFY COLUMN rt VARCHAR(20) NOT NULL DEFAULT '',
    MODIFY COLUMN vi VARCHAR(20) NOT NULL DEFAULT '',
    MODIFY COLUMN pt VARCHAR(20) NOT NULL DEFAULT ''");

echo "Contrôles RT, VI et PT ouverts à la saisie libre.\n";
