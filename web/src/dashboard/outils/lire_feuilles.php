<?php
/**
 * Lit, en tâche de fond, tout ce que les écrans montrent des pages d'un plan : liste
 * soudure, repères, nomenclature, images (includes/lecture/plan_index.php).
 *
 * La lecture d'une page coûte près de trois secondes : aucun écran ne peut la faire pour
 * quinze feuilles sans laisser la personne devant du vide. Ce script fait le tour une fois,
 * dès que le plan est déposé — sur la demande ou dans Plans / ISO — ; les écrans n'ont plus
 * qu'à lire ce qui est gardé.
 *
 * Lancé par planIndexerDemandeEnFond() et planDocumentLireEnFond(), jamais par le web : il
 * vit sous la racine du site, d'où la première ligne. Un verrou par document évite deux
 * lectures du même en même temps.
 *
 * Usage : php lire_feuilles.php <identifiant de l'élément>      les plans d'un plan d'atelier
 *         php lire_feuilles.php --demande <identifiant> [<clé>]   ceux d'une demande, ou l'un
 *         php lire_feuilles.php --tous                            tous — après une mise à jour
 *                                                                 qui change une lecture
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$racine = dirname(__DIR__, 2);
require_once $racine . '/db.php';
require_once $racine . '/dashboard/includes/lecture/plan_index.php';

$db = new MyPDO($racine . '/my_setting.ini');

// Ce qu'il faut lire : une liste de couples demande, clé du fichier.
$documents = [];
if (($argv[1] ?? '') === '--demande') {
    $requestId = (int) ($argv[2] ?? 0);
    if ($requestId < 1) exit(1);
    foreach (isset($argv[3]) ? [(string) $argv[3]] : planIndexDocumentsDemande($requestId) as $cle) {
        $documents[] = [$requestId, $cle];
    }
} elseif (($argv[1] ?? '') === '--tous') {
    // Les demandes les plus récentes d'abord : ce sont elles que l'atelier ouvre.
    foreach ($db->query('SELECT id FROM demande_prefabrication ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
        foreach (planIndexDocumentsDemande((int) $requestId) as $cle) $documents[] = [(int) $requestId, $cle];
    }
} else {
    $elementId = (int) ($argv[1] ?? 0);
    if ($elementId < 1) exit(1);
    $demande = $db->prepare('SELECT id_demande FROM element_atelier WHERE id = ?');
    $demande->execute([$elementId]);
    $requestId = (int) $demande->fetchColumn();
    if (!$requestId) exit(0);
    foreach (planDocuments($db, $elementId) as $document) $documents[] = [$requestId, $document['cle_document']];
}

// Un compte rendu, pour qui le lance à la main : une ligne par plan, puis le total. Lancé
// par l'application, il part dans le vide.
$bilan = [];
foreach ($documents as [$requestId, $cle]) {
    $debut = microtime(true);
    $etat = planIndexerDocument($requestId, $cle);
    $bilan[$etat] = ($bilan[$etat] ?? 0) + 1;
    echo 'Demande ', $requestId, ' · ', preg_replace('/^[a-f0-9]{32}-/', '', $cle), ' : ', $etat,
        $etat === 'lu' ? sprintf(' en %.0f s', microtime(true) - $debut) : '', PHP_EOL;
}
echo count($documents), count($documents) > 1 ? ' plans' : ' plan',
    $bilan ? ' — ' . implode(', ', array_map(static fn (string $etat, int $nombre): string => $nombre . ' ' . $etat,
        array_keys($bilan), $bilan)) : '', '.', PHP_EOL;
