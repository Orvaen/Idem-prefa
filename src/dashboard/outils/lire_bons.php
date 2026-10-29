<?php
/**
 * Lit, en tâche de fond, les bons de commande qu'on vient de déposer.
 *
 * La lecture d'un bon scanné coûte plusieurs secondes par page : le dépôt ne peut pas
 * l'attendre, et il fallait la demander à la main, bon par bon, depuis l'écran « Commande
 * matériel ». Ce script la fait dès le dépôt ; l'écran n'a plus qu'à montrer les articles.
 *
 * Lancé par materielLireEnFond(), jamais par le web : il vit sous la racine du site, d'où
 * la première ligne. Un bon déjà lu — par l'écran, entre-temps — ou validé n'est pas relu,
 * et un verrou par bon évite deux lectures en même temps (materielVerrou()).
 *
 * Usage : php lire_bons.php <identifiant du bon> [<identifiant> …]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
if (!$ids) exit(1);

$racine = dirname(__DIR__, 2);
require_once $racine . '/db.php';
require_once $racine . '/modeles/autoload.php';
require_once $racine . '/dashboard/includes/materiel/materiel_bon.php';

// Sans les outils de lecture, il n'y a rien à tenter : l'écran le dira à qui ouvrira le bon.
if (ccpuOutilsManquants()) exit(0);

$db = new MyPDO($racine . '/my_setting.ini');
foreach ($ids as $id) {
    $verrou = materielVerrou($id, false);
    if (!$verrou) continue;
    try {
        $document = materielDocument($db, $id);
        if (!$document || !empty($document['date_lecture']) || materielEstFige($document)) continue;
        materielLireDocument($db, $id);
    } catch (Throwable $erreur) {
        // Un bon qui ne se lit pas n'empêche pas les suivants ; il pourra être relu à
        // l'écran. L'échec ordinaire — un scan illisible — est déjà rangé avec le bon.
        Journal::erreur('Lecture de fond du bon ' . $id, $erreur);
    } finally {
        fclose($verrou);
    }
}
