<?php
// Sert le plan d'un élément d'atelier sous forme d'image, pour l'afficher dans le
// visualiseur où les coulées se posent directement sur les lignes du plan.
//
// Le PDF est rasterisé une fois puis gardé à côté de l'original : un scan A4 pèse
// environ 200 Ko en JPEG, et le refaire à chaque ouverture coûterait une seconde.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/atelier.php';

const PLAN_IMAGE_DPI = 150;

/**
 * La définition d'une vignette de sommaire. On y reconnaît la feuille à son tracé, on n'y
 * lit rien : quinze pages en pleine définition feraient trois méga-octets pour une colonne
 * large comme le pouce.
 */
const PLAN_IMAGE_DPI_VIGNETTE = 25;

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// Un élément porte plusieurs ISO : « document » dit lequel, et à défaut c'est le premier.
$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_GET));
if (!$plan) prefaError(404, 'Aucun plan rattaché à cet élément.');

$requestId = $plan['id_demande'];
$cle = $plan['cle_document'];

// Mêmes droits que le téléchargement du document : l'atelier voit les plans sur
// lesquels il est affecté, l'encadrement voit tout, le demandeur ses demandes.
if ($isWorkshopPersonnel) {
    if (!atelierDocumentAllowed($db, (int) $actor['id'], $requestId, 'plan', $cle)) {
        prefaError(404, 'Plan introuvable ou inaccessible.');
    }
} elseif (!$canViewAllPrefa) {
    $acces = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
    $acces->execute([$requestId, $actor['id']]);
    if (!$acces->fetchColumn()) prefaError(404, 'Plan introuvable ou inaccessible.');
}

$source = $plan['chemin'];
if (!is_file($source)) prefaError(404, 'Plan introuvable.');

// La page demandée : un cahier ISO en porte plusieurs et la fenêtre les parcourt, chacune
// avec sa liste soudure. La première garde le nom de cache d'origine, pour que les images
// déjà rastérisées resservent.
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > (int) $plan['pages']) prefaError(404, 'Page du plan introuvable.');

// Le sommaire demande les mêmes pages en tout petit. La vignette a son propre fichier de
// cache : elle ne remplace pas le plan, elle l'annonce.
$vignette = !empty($_GET['vignette']);

// L'image n'est refaite que si le plan a changé depuis la dernière fois.
$cache = prefaAttachmentDirectory($requestId) . '/apercu-' . substr($cle, 0, 32)
    . ($page > 1 ? '-p' . $page : '') . ($vignette ? '-v' : '') . '.jpg';
if (!is_file($cache) || filemtime($cache) < filemtime($source)) {
    $base = substr($cache, 0, -4);
    exec(sprintf(
        'pdftoppm -r %d -jpeg -f %d -l %d -singlefile %s %s 2>/dev/null',
        $vignette ? PLAN_IMAGE_DPI_VIGNETTE : PLAN_IMAGE_DPI,
        $page,
        $page,
        escapeshellarg($source),
        escapeshellarg($base)
    ), $sortie, $code);
    if ($code !== 0 || !is_file($cache)) prefaError(500, 'Aperçu du plan indisponible.');
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($cache));
readfile($cache);
