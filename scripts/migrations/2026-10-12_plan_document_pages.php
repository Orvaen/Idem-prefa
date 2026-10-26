<?php
// Le nombre de pages de chaque fichier ISO, retenu en base.
//
// La liste des soudures se remplit page par page : le volet doit donc savoir combien de
// pages porte chaque PDF pour en afficher autant de listes. Le relever à l'affichage
// voudrait dire lancer pdfinfo sur chaque fichier de chaque élément à chaque ouverture de
// la liste des demandes — des dizaines de lectures de PDF pour une page de tableau.
//
// Le dépôt d'un plan l'écrit désormais lui-même ; ce script rattrape les fichiers déjà là.
//
// Relançable sans effet de bord : seuls les fichiers encore comptés pour une page sont
// relus, et un plan d'une seule page le reste.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/demande/prefa_attachments.php';
$db = new MyPDO($root . '/my_setting.ini');

$faits = [];

if ($db->query("SHOW COLUMNS FROM element_atelier_document LIKE 'pages'")->fetch()) {
    $faits[] = 'element_atelier_document : colonne pages déjà présente';
} else {
    $db->exec('ALTER TABLE element_atelier_document
        ADD COLUMN pages SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER cle_document');
    $faits[] = 'element_atelier_document : colonne pages ajoutée';
}

$aCompter = $db->query("SELECT d.id, d.cle_document, e.id_demande
    FROM element_atelier_document d
    JOIN element_atelier e ON e.id = d.id_element
    WHERE d.type_document = 'plan' AND d.pages <= 1")->fetchAll();

$ecrire = $db->prepare('UPDATE element_atelier_document SET pages = ? WHERE id = ?');
$comptes = 0;
$illisibles = 0;
foreach ($aCompter as $document) {
    $chemin = prefaAttachmentDirectory((int) $document['id_demande']) . '/' . $document['cle_document'];
    if (!is_file($chemin)) { $illisibles++; continue; }

    $sortie = [];
    exec('pdfinfo ' . escapeshellarg($chemin) . ' 2>/dev/null', $sortie);
    if (!preg_match('/^Pages:\s+(\d+)/m', implode("\n", $sortie), $trouve)) { $illisibles++; continue; }

    $pages = max(1, (int) $trouve[1]);
    if ($pages > 1) {
        $ecrire->execute([$pages, (int) $document['id']]);
        $comptes++;
    }
}

$faits[] = count($aCompter) . ' fichier(s) relu(s) : ' . $comptes . ' cahier(s) de plusieurs pages'
    . ($illisibles ? ', ' . $illisibles . ' illisible(s) ou absent(s)' : '');

echo implode("\n", $faits), "\n";
