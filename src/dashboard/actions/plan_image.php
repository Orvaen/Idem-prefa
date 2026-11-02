<?php
// Sert le plan d'un élément d'atelier sous forme d'image, pour l'afficher dans le
// visualiseur où les coulées se posent directement sur les lignes du plan.
//
// Le PDF est rasterisé une fois puis gardé à côté de l'original : un scan A4 pèse
// environ 200 Ko en JPEG, et le refaire à chaque ouverture coûterait une seconde. Il l'est
// d'ordinaire dès le dépôt du plan, en tâche de fond (planApercu(), plan_index.php) : cette
// action ne le fait elle-même que pour une page que la lecture n'a pas encore atteinte.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/plan_document.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/lecture/plan_index.php';

// Rien ne s'écrit dans la session d'ici : la page et ses quinze vignettes se servent en même
// temps au lieu de s'attendre (Session::liberer()).
Session::liberer();

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
$cache = planApercu($requestId, $cle, $page, $vignette);
if ($cache === null) prefaError(500, 'Aperçu du plan indisponible.');

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($cache));
readfile($cache);
