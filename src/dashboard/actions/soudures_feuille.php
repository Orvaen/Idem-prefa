<?php
// La liste soudure d'une feuille ISO, pour le soudeur qui la travaille.
//
// La reconnaissance du tableau imprimé coûte quelques secondes la première fois qu'une
// page est ouverte, puis elle est gardée à côté du plan. C'est la raison d'être de cette
// action : l'écran « Mes soudures » ne lit qu'une page à la fois, celle qu'on regarde,
// au lieu de relire les quinze d'un cahier avant d'afficher quoi que ce soit.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/soudure_atelier.php';

prefaPost();
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// Le plan doit vous être confié : c'est la seule porte, et elle est ici plutôt que dans
// l'écran — une adresse se tape à la main.
if (!soudureAtelierAffectation($db, $elementId, (int) $actor['id'])) {
    prefaError(403, 'Ce plan ne vous est pas affecté.');
}

$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucune feuille ISO rattachée à ce plan.');
if (!is_file($plan['chemin'])) prefaError(404, 'Feuille introuvable.');

$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > max(1, (int) $plan['pages'])) prefaError(400, 'Page inexistante dans cette feuille.');

$feuille = soudureAtelierFeuille($db, $elementId, $plan, $page, (int) $actor['id']);

// L'état du plan entier, pour les deux autres chiffres de l'écran : la feuille ouverte ne
// dit pas où en est le cahier, et c'est pourtant ce qui part en validation.
require_once __DIR__ . '/../includes/avancement.php';
$prevu = avancementSouduresPrevues($db, $elementId);
$avancement = $db->prepare('SELECT avancement FROM affectation_atelier
    WHERE id_element = ? AND id_utilisateur = ? AND actif = 1 LIMIT 1');
$avancement->execute([$elementId, (int) $actor['id']]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'lignes' => $feuille['lignes'],
    'imprimees' => $feuille['imprimees'],
    'faites' => $feuille['faites'],
    'miennes' => $feuille['miennes'],
    // De quoi remettre à jour la ligne de cette feuille dans le tableau : son compte et
    // son état. Ils viennent d'ici parce que l'écran ne les connaît pas encore — une
    // feuille jamais ouverte ignorait son total, et elle vient de l'apprendre.
    'feuille' => [
        'compte' => soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees']),
        'etat' => soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']),
    ],
    'plan' => [
        'prevues' => $prevu['prevues'],
        'faites' => avancementSouduresFaites($db, $elementId),
        'feuilles' => avancementFeuillesPhrase($prevu),
    ],
    'valide' => (int) $avancement->fetchColumn(),
    // Dit à l'écran pourquoi le tableau est vide : un plan scanné, ou un gabarit que la
    // reconnaissance ne sait pas lire, n'a aucune ligne à proposer.
    'message' => $feuille['imprimees'] === 0 && !$feuille['lignes']
        ? 'Aucune liste soudure n’a été reconnue sur cette page : prévenez le chef d’atelier.'
        : '',
]);
