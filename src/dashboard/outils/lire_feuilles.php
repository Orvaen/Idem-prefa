<?php
/**
 * Lit, en tâche de fond, la liste soudure de toutes les feuilles d'un plan.
 *
 * La lecture d'une page coûte quelques secondes : aucun écran ne peut la faire pour quinze
 * feuilles sans laisser la personne devant du vide. Sans elle, une page n'était lue que
 * lorsqu'on l'ouvrait, et le total des cordons prévus ne comptait que les feuilles déjà
 * vues — quatre cordons pour un cahier de quinze pages. Ce script fait le tour une fois,
 * dès que le plan est rattaché ; les écrans n'ont plus qu'à lire ce qui est gardé.
 *
 * Lancé par planDocumentLireEnFond(), jamais par le web : il vit sous la racine du site,
 * d'où la première ligne. Un verrou par élément évite deux tours en même temps.
 *
 * Usage : php lire_feuilles.php <identifiant de l'élément>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$elementId = (int) ($argv[1] ?? 0);
if ($elementId < 1) exit(1);

$racine = dirname(__DIR__, 2);
require_once $racine . '/db.php';
require_once $racine . '/dashboard/includes/atelier/plan_document.php';
require_once $racine . '/dashboard/includes/lecture/plan_lignes.php';

$verrou = fopen(sys_get_temp_dir() . '/idem-lire-feuilles-' . $elementId . '.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) exit(0);

$db = new MyPDO($racine . '/my_setting.ini');
$demande = $db->prepare('SELECT id_demande FROM element_atelier WHERE id = ?');
$demande->execute([$elementId]);
$requestId = (int) $demande->fetchColumn();
if (!$requestId) exit(0);

foreach (planDocuments($db, $elementId) as $document) {
    $chemin = prefaAttachmentDirectory($requestId) . '/' . $document['cle_document'];
    if (!is_file($chemin)) continue;
    for ($page = 1; $page <= $document['pages']; $page++) {
        try {
            planLignesAnalyse($chemin, planLignesCache($requestId, $document['cle_document'], $page), $page);
        } catch (Throwable $erreur) {
            // Une feuille illisible n'empêche pas les suivantes : elle sera relue, ou non,
            // quand quelqu'un l'ouvrira.
            continue;
        }
    }
}
