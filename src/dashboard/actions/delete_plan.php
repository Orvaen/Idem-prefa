<?php
// Retrait d'une feuille ISO d'un élément, par le chef d'atelier.
//
// Il dépose ses plans lui-même, il doit pouvoir en retirer un : une feuille déposée par
// erreur, une révision périmée, un cahier qu'on ne veut plus. Jusqu'ici la seule façon
// d'y arriver était de décocher le fichier dans « Gérer les fichiers », qui réécrit tous
// les rattachements de l'élément d'un bloc.
//
// Ce qui a été posé sur la feuille — numéros de coulée, liste des soudures — part avec
// elle : ces relevés sont faits sur ce dessin-là, aux positions de ce dessin-là, et ne
// veulent rien dire ailleurs. La cascade de la base s'en charge.
//
// Le PDF quitte aussi les pièces de la demande, sauf s'il sert encore à un autre élément.
// Il avait été déposé pour cette feuille : le laisser sans personne pour le lire ne ferait
// qu'allonger la liste des fichiers.
//
// Plusieurs feuilles peuvent partir d'un coup : un cahier déposé par erreur en fait quinze,
// et les sortir une par une est une corvée. Une feuille refusée refuse tout le lot — mieux
// vaut une liste intacte qu'une moitié retirée sans qu'on sache laquelle.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/plan_document.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Seul l’encadrement peut retirer un plan.');

$elementId = filter_var($_POST['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Élément invalide.');

$stmt = $db->prepare('SELECT e.id_demande FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE e.id = ? AND d.id_statut = 2');
$stmt->execute([$elementId]);
$requestId = (int) $stmt->fetchColumn();
if (!$requestId) prefaError(404, 'Élément introuvable ou demande non validée.');

// Une feuille, ou plusieurs : « documents[] » pour un lot, « document » pour une seule.
$demandes = $_POST['documents'] ?? ($_POST['document'] ?? null);
if (!is_array($demandes)) $demandes = $demandes === null ? [] : [$demandes];
if (!$demandes || count($demandes) > 200) prefaError(400, 'Feuille invalide.');

$feuilles = [];
$verifier = $db->prepare("SELECT cle_document FROM element_atelier_document
    WHERE id = ? AND id_element = ? AND type_document = 'plan'");
foreach ($demandes as $demande) {
    $documentId = filter_var($demande, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$documentId || isset($feuilles[$documentId])) continue;
    $verifier->execute([$documentId, $elementId]);
    $cle = (string) $verifier->fetchColumn();
    // Une seule feuille étrangère fait échouer le lot : retirer les autres quand même
    // laisserait croire que tout est parti.
    if ($cle === '') prefaError(404, 'Feuille introuvable sur cet élément.');
    $feuilles[$documentId] = $cle;
}
if (!$feuilles) prefaError(400, 'Feuille invalide.');

// Compté avant le retrait : la réponse doit pouvoir dire ce qui est parti, sinon on
// découvre la liste soudure disparue en rouvrant le volet.
$compter = static function (PDO $db, string $sql, int $documentId): int {
    $stmt = $db->prepare($sql);
    $stmt->execute([$documentId]);
    return (int) $stmt->fetchColumn();
};
$coulees = 0;
$soudures = 0;
foreach (array_keys($feuilles) as $documentId) {
    $coulees += $compter($db, "SELECT COUNT(*) FROM plan_nomenclature WHERE id_document = ? AND numero_coulee <> ''", $documentId);
    $soudures += $compter($db, 'SELECT COUNT(*) FROM plan_soudure WHERE id_document = ?', $documentId);
}

try {
    $db->beginTransaction();
    $retirer = $db->prepare('DELETE FROM element_atelier_document WHERE id = ?');
    foreach (array_keys($feuilles) as $documentId) $retirer->execute([$documentId]);
    $db->commit();
} catch (Throwable $erreur) {
    $db->rollBack();
    prefaError(500, 'Retrait impossible.');
}

// La même pièce jointe peut être rattachée à un autre élément de la demande : on ne
// retire le fichier que lorsque plus personne ne le lit.
$dossier = prefaAttachmentDirectory($requestId);
$autre = $db->prepare('SELECT 1 FROM element_atelier_document WHERE cle_document = ? LIMIT 1');
$noms = [];
$fichiersRetires = 0;
$partages = 0;
foreach ($feuilles as $cle) {
    $noms[] = preg_replace('/^[a-f0-9]{32}-/', '', $cle);
    $autre->execute([$cle]);
    if ($autre->fetchColumn()) { $partages++; continue; }
    if (@unlink($dossier . '/' . $cle)) $fichiersRetires++;
    // Les tableaux lus et l'aperçu rastérisé n'ont plus d'objet : ils sont nommés d'après
    // la clé du fichier, et un futur dépôt en aura une autre.
    $prefixe = substr($cle, 0, 32);
    foreach (glob($dossier . '/lignes-' . $prefixe . '*.json') ?: [] as $cache) @unlink($cache);
    foreach (glob($dossier . '/apercu-' . $prefixe . '*.jpg') ?: [] as $apercu) @unlink($apercu);
}

$combien = count($feuilles);
$message = $combien === 1
    ? 'Feuille retirée : ' . $noms[0] . '.'
    : $combien . ' feuilles retirées.';
if ($coulees || $soudures) {
    $parties = [];
    if ($coulees) $parties[] = $coulees . ($coulees > 1 ? ' coulées posées' : ' coulée posée');
    if ($soudures) $parties[] = $soudures . ($soudures > 1 ? ' soudures' : ' soudure');
    // Le verbe s'accorde avec ce qui est parti, le pronom avec les feuilles : « 1 coulée
    // posée est partie avec elles » quand on en retire plusieurs.
    $message .= ' ' . implode(' et ', $parties)
        . (($coulees + $soudures) > 1 ? ' sont parties' : ' est partie')
        . ($combien === 1 ? ' avec elle.' : ' avec elles.');
}
if ($partages) {
    $message .= $partages === 1
        ? ' Un PDF reste rattaché à un autre plan de la demande.'
        : ' ' . $partages . ' PDF restent rattachés à un autre plan de la demande.';
}
if ($fichiersRetires) {
    $message .= $fichiersRetires === 1
        ? ' Le PDF a quitté les fichiers de la demande.'
        : ' Les ' . $fichiersRetires . ' PDF ont quitté les fichiers de la demande.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'documents' => array_keys($feuilles),
    'noms' => $noms,
    'fichiers_retires' => $fichiersRetires,
    'message' => $message,
]);
