<?php
// Confie chaque affaire existante à un chargé d'affaire.
//
// L'affaire est désormais ouverte par le chargé de production, qui désigne qui la suit :
// le chargé d'affaire ne dépose plus que sur les siennes. Les affaires d'avant n'ont été
// confiées à personne — elles naissaient toutes seules au premier nom tapé — et sans cette
// reprise, plus personne ne pourrait déposer sur un chantier déjà commencé.
//
// À qui ? À celui qui a déposé ses demandes. S'ils sont plusieurs, au plus assidu, puis au
// plus ancien à égalité : c'est la lecture la plus fidèle de ce qui s'est passé. Une
// affaire sans aucune demande reste sans chargé — rien ne dit à qui elle revient, et c'est
// au chargé de production de trancher depuis son écran.
//
// Ne touche jamais une affaire déjà confiée. Relançable.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

if (!$db->query("SHOW COLUMNS FROM affaire LIKE 'id_charge_affaire'")->fetch()) {
    echo "La colonne id_charge_affaire n’existe pas : lancez affaires.php d’abord.\n";
    exit(1);
}

$orphelines = $db->query('SELECT COUNT(*) FROM affaire WHERE id_charge_affaire IS NULL')->fetchColumn();
if (!$orphelines) {
    echo "Toutes les affaires sont déjà confiées.\n";
    exit;
}

// Le dépositaire le plus fréquent de chaque affaire, parmi les comptes qui sont encore
// des chargés d'affaire : un compte passé chef d'atelier entre-temps ne peut plus déposer.
$candidats = $db->query('SELECT d.id_affaire, d.idUsers, COUNT(*) AS combien, MIN(d.id) AS premiere
    FROM demande_prefabrication d
    JOIN Utilisateur u ON u.id = d.idUsers AND u.id_role = 5
    JOIN affaire a ON a.id = d.id_affaire AND a.id_charge_affaire IS NULL
    GROUP BY d.id_affaire, d.idUsers
    ORDER BY d.id_affaire, combien DESC, premiere ASC')->fetchAll(PDO::FETCH_ASSOC);

$retenu = [];
foreach ($candidats as $ligne) {
    // Le tri a placé le bon en tête de chaque affaire : les suivants sont ignorés.
    $retenu[(int) $ligne['id_affaire']] ??= (int) $ligne['idUsers'];
}

$confier = $db->prepare('UPDATE affaire SET id_charge_affaire = ? WHERE id = ? AND id_charge_affaire IS NULL');
foreach ($retenu as $affaire => $charge) {
    $confier->execute([$charge, $affaire]);
}

$restantes = $db->query('SELECT nom FROM affaire WHERE id_charge_affaire IS NULL ORDER BY nom')->fetchAll(PDO::FETCH_COLUMN);

printf("%d affaire(s) confiée(s) d’après qui en a déposé les demandes.\n", count($retenu));
if ($restantes) {
    printf("%d sans demande d’un chargé d’affaire, laissée(s) à confier depuis l’écran Affaires :\n", count($restantes));
    foreach ($restantes as $nom) echo "  - $nom\n";
}
