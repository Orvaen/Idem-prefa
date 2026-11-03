<?php
// La liste soudure d'une feuille ISO, pour le soudeur qui la travaille.
//
// La reconnaissance du tableau imprimé coûte quelques secondes la première fois qu'une
// page est ouverte, puis elle est gardée à côté du plan. C'est la raison d'être de cette
// action : l'écran « Mes soudures » ne lit qu'une page à la fois, celle qu'on regarde,
// au lieu de relire les quinze d'un cahier avant d'afficher quoi que ce soit.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
require_once __DIR__ . '/../includes/materiel/manques.php';

prefaPost();
// Le jeton est vérifié, et rien d'autre ne s'écrit dans la session : la feuille, son image et
// ses vignettes se servent en même temps (Session::liberer()).
Session::liberer();
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// Le plan doit vous être confié : c'est la seule porte, et elle est ici plutôt que dans
// l'écran — une adresse se tape à la main.
$affectation = soudureAtelierAffectation($db, $elementId, (int) $actor['id']);
if (!$affectation) prefaError(403, 'Ce plan ne vous est pas affecté.');

$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucune feuille ISO rattachée à ce plan.');
if (!is_file($plan['chemin'])) prefaError(404, 'Feuille introuvable.');

$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > max(1, (int) $plan['pages'])) prefaError(400, 'Page inexistante dans cette feuille.');

// Un plan déposé avant qu'une lecture existe, ou lu par une version plus ancienne : ses
// feuilles partent en lecture, en fond — avant de lire celle-ci, pour que les deux avancent
// ensemble. Elles sont d'ordinaire déjà prêtes, lues au dépôt (plan_index.php).
require_once __DIR__ . '/../includes/lecture/plan_index.php';
planIndexerElementSiBesoin($db, $elementId, (int) $plan['id_demande']);

$feuille = soudureAtelierFeuille($db, $elementId, $plan, $page, (int) $actor['id']);
// Ce que le soudeur clique sur le dessin : les lignes de la liste soudure, les numéros le
// long du tube, et les lignes de la nomenclature. Lu au dépôt du plan ; près de trois
// secondes sinon, la première fois que la page est ouverte, puis c'est gardé.
$dessin = soudureAtelierDessin($plan, $page);

// Chaque ligne de la nomenclature reçoit l'article qu'un manque signalé d'elle portera, et
// la date du manque encore ouvert pour cet article, s'il y en a un : la ligne le montre.
$nomenclature = $dessin['nomenclature'];
if ($nomenclature) {
    $ouverts = manquesOuvertsParArticle($db, (int) $plan['id_demande']);
    foreach ($nomenclature['lignes'] as &$ligne) {
        $ligne['designation'] = planNomenclatureDesignation($ligne);
        $signale = $ligne['designation'] !== '' ? ($ouverts[mb_strtolower($ligne['designation'])] ?? '') : '';
        $ligne['signale'] = $signale !== '' ? prefaFormatDate($signale) : '';
    }
    unset($ligne);
}

// L'état du plan entier, pour les deux autres chiffres de l'écran : la feuille ouverte ne
// dit pas où en est le cahier, et c'est pourtant ce qui part en validation.
require_once __DIR__ . '/../includes/atelier/avancement.php';
$prevu = avancementSouduresPrevues($db, $elementId);
// L'avancement que les cordons font bouger : celui du suivi soudage du plan.
$cordons = avancementAffectationCordons($db, $elementId);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'lignes' => $feuille['lignes'],
    'tableau' => $dessin['tableau'],
    'reperes' => $dessin['reperes'],
    'nomenclature' => $nomenclature,
    'imprimees' => $feuille['imprimees'],
    'faites' => $feuille['faites'],
    'miennes' => $feuille['miennes'],
    // Ce qui attend le chef sur cette feuille, et depuis quand il l'a sous les yeux :
    // l'écran le dit au-dessus du tableau, et ligne par ligne.
    'attente' => [
        'cordons' => $feuille['enAttente'],
        'depuis' => $cordons && ($declaration = avancementEnAttente($db, $cordons['id']))
            ? prefaFormatDate($declaration['date_saisie'], true) : '',
    ],
    // De quoi remettre à jour la ligne de cette feuille dans le tableau : son compte et
    // son état. Ils viennent d'ici parce que l'écran ne les connaît pas encore — une
    // feuille jamais ouverte ignorait son total, et elle vient de l'apprendre.
    'feuille' => [
        'compte' => soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees']),
        'etat' => soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']),
        'part' => soudureAtelierPartFeuille($feuille['faites'], $feuille['imprimees']),
        'imprimees' => $feuille['imprimees'],
    ],
    'plan' => [
        'prevues' => $prevu['prevues'],
        'faites' => avancementSouduresFaites($db, $elementId),
        'feuilles' => avancementFeuillesPhrase($prevu),
    ],
    'valide' => $cordons['avancement'] ?? 0,
    // Dit à l'écran pourquoi le tableau est vide : un plan scanné, ou un gabarit que la
    // reconnaissance ne sait pas lire, n'a aucune ligne à proposer.
    'message' => $feuille['imprimees'] === 0 && !$feuille['lignes']
        ? 'Aucune liste soudure n’a été reconnue sur cette page : prévenez le chef d’atelier.'
        : '',
]);
