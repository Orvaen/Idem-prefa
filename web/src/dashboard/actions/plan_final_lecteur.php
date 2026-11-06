<?php
// La fenêtre du plan, en consultation, pour une feuille : c'est là que se regarde le plan
// final depuis la liste des demandes.
//
// Le plan final est ce que cette fenêtre montre déjà — le dessin, et par-dessus les
// coulées, la liste soudure validée, ses croix et ses visas, rangés par le même code que le
// PDF (plan_lignes.php). On ne l'ouvrait que dans le lecteur PDF du navigateur, étranger à
// l'application ; il s'ouvre maintenant dans le nôtre, d'où le PDF se télécharge.
//
// Rendue à la demande et non dans la liste : une demande porte vite plusieurs plans de
// quinze feuilles, et la liste n'a pas à construire des fenêtres qu'on n'ouvrira pas.
//
// Rend du JSON : le balisage, et la feuille de style et le script de la fenêtre, que la
// page n'a pas quand le rôle n'a pas d'écran du plan ISO — le chargé d'affaire.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/plan_document.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';
require_once __DIR__ . '/../includes/atelier/soudure.php';
require_once __DIR__ . '/../includes/vue/plan_viewer.php';

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

$choisi = planDocumentChoisi($db, $elementId, planDocumentDemande($_GET));
if (!$choisi) prefaError(404, 'Aucun plan rattaché à cet élément.');

// Mêmes droits que l'image du plan et le plan final : l'atelier voit les plans sur
// lesquels il est affecté, l'encadrement voit tout, le demandeur ses demandes.
if ($isWorkshopPersonnel) {
    if (!atelierDocumentAllowed($db, (int) $actor['id'], (int) $choisi['id_demande'], 'plan', (string) $choisi['cle_document'])) {
        prefaError(404, 'Plan introuvable ou inaccessible.');
    }
} elseif (!$canViewAllPrefa) {
    $acces = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
    $acces->execute([$choisi['id_demande'], $actor['id']]);
    if (!$acces->fetchColumn()) prefaError(404, 'Plan introuvable ou inaccessible.');
}

$document = null;
foreach (planDocuments($db, $elementId) as $candidat) {
    if ($candidat['id'] === (int) $choisi['id']) $document = $candidat;
}
if (!$document) prefaError(404, 'Plan introuvable.');

// La feuille telle que la fenêtre la veut, page par page : ses cases de coulée, sa liste
// soudure en lecture. Rien n'y est modifiable — la fenêtre reçoit le jeton de lecture, pas
// celui d'écriture (planViewerRendu).
$reperes = [];
$listes = [];
for ($page = 1; $page <= $document['pages']; $page++) {
    $reperes[$page] = nomenclatureLignes($db, $elementId, $document['id'], $page);
    $listes[$page] = soudureRendu(soudureLignes($db, $elementId, $document['id'], $page), [],
        $elementId, $document['id'], false, '', $page);
}
$fenetre = planViewerRendu($elementId, [$document + [
    'reperes' => $reperes,
    'empreintes' => [],
    'soudures' => $listes,
    'cordons' => soudureCompte($db, $elementId, $document['id']),
]], [], false, false);

// Une soudure à montrer — depuis sa validation, « N° 1 » ne disait pas où elle était
// (6 novembre 2026) : la page, et où son numéro est écrit sur le dessin — le long du tube, et
// sa ligne dans la liste imprimée —, en fractions de la page, comme le soudeur les touche
// (soudureAtelierDessin()). La fenêtre l'y cercle.
$cible = null;
$pageCible = filter_var($_GET['page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => max(1, (int) $document['pages'])]]);
if ($pageCible) {
    $numeroCible = trim((string) ($_GET['numero'] ?? ''));
    $cible = ['page' => $pageCible, 'numero' => $numeroCible, 'repere' => null, 'ligne' => null];
    if ($numeroCible !== '' && is_file($choisi['chemin'])) {
        require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
        $dessin = soudureAtelierDessin($choisi, $pageCible);
        foreach ($dessin['reperes'] as $repere) {
            if ($repere['numero'] === $numeroCible) {
                $cible['repere'] = $repere;
                break;
            }
        }
        // Sa ligne dans la liste imprimée : la n-ième, d'un bord du tableau à l'autre.
        $tableau = $dessin['tableau'];
        $rang = ctype_digit($numeroCible) ? (int) $numeroCible - 1 : -1;
        if ($rang >= 0 && isset($tableau['lignes'][$rang], $tableau['bords'], $tableau['pas']) && count($tableau['bords']) >= 2) {
            $gauche = (float) $tableau['bords'][0];
            $ligne = $tableau['lignes'][$rang];
            $cible['ligne'] = [
                'x' => $gauche,
                'y' => (float) $ligne['y'] - ((float) $tableau['pas'] - (float) $ligne['h']) / 2,
                'w' => (float) end($tableau['bords']) - $gauche,
                'h' => (float) $tableau['pas'],
            ];
        }
    }
}

// Les ressources avec la même empreinte que celles du tableau de bord : un navigateur qui
// les a déjà ne les recharge pas.
$racine = __DIR__ . '/../..';
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'html' => $fenetre,
    'cible' => $cible,
    'styles' => ['../css/dashboard/plan-viewer.css?v=' . filemtime($racine . '/css/dashboard/plan-viewer.css')],
    'scripts' => ['../js/ecrans/plan-viewer.js?v=' . hash_file('sha256', $racine . '/js/ecrans/plan-viewer.js')],
]);
