<?php
// Détecte le tableau « NOMENCLATURE DE MATÉRIEL » du plan et renvoie une case par
// ligne, prête à être posée dans la colonne « N° COULÉE ».
//
// La détection n'écrit rien : elle propose. Les cases n'existent qu'à l'écran tant que
// personne n'a enregistré, et un plan dont le tableau n'est pas reconnu — un scan, un
// autre gabarit — rend simplement une liste vide, le placement manuel prenant le relais.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/plan_lignes.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Seul l’encadrement peut détecter les lignes du plan.');

$elementId = filter_var($_POST['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// La demande doit être validée : on ne travaille pas sur un plan qui peut encore bouger.
$valide = $db->prepare('SELECT 1 FROM element_atelier e JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE e.id = ? AND d.id_statut = 2');
$valide->execute([$elementId]);
if (!$valide->fetchColumn()) prefaError(404, 'Aucun plan rattaché à cet élément.');

// Un élément porte plusieurs ISO : « document » dit lequel, et à défaut c'est le premier.
$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucun plan rattaché à cet élément.');
$source = $plan['chemin'];
if (!is_file($source)) prefaError(404, 'Plan introuvable.');

// La page regardée dans la fenêtre : chaque page d'un cahier a sa liste soudure imprimée,
// donc son propre tableau à reconnaître. Les cases de coulée, elles, ne se cherchent que
// sur la première.
$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > (int) $plan['pages']) prefaError(400, 'Page du plan invalide.');

// La lecture des tableaux et le rangement de la liste soudure vivent dans plan_lignes.php :
// le plan final s'en sert aussi, et doit montrer exactement ce que montre le lecteur.
$analyse = planLignesAnalyse($source, planLignesCache($plan['id_demande'], $plan['cle_document'], $page), $page);
$cases = $analyse['cases'] ?? [];
$soudures = $analyse['soudures'] ?? null;

$liste = planLignesSoudures($db, $elementId, $plan['id'], $soudures, $page);
$valeurs = $liste['valeurs'];
$horsTableau = $liste['horsTableau'];

// Ce que la détection a trouvé ne se raconte pas : le plan le montre. On ne garde que ce
// qui ne se voit PAS dessus — une soudure qu'aucune ligne ne peut accueillir disparaîtrait
// sans bruit, et c'est la seule chose qu'il faille dire.
$avertissements = [];
// Une case que le plan porte déjà n'est pas recouverte : le dire, sinon on cherche en
// vain ce qu'on vient de saisir sur la feuille finale.
if (!empty($liste['occupees'])) {
    $avertissements[] = $liste['occupees'] === 1
        ? 'Une case de la liste soudure porte déjà du texte sur le plan : elle est laissée telle quelle.'
        : $liste['occupees'] . ' cases de la liste soudure portent déjà du texte sur le plan :'
            . ' elles sont laissées telles quelles.';
}
if ($soudures && $horsTableau) {
    $avertissements[] = count($horsTableau) === 1
        ? 'La soudure n° ' . $horsTableau[0] . ' n’a pas de ligne sur ce plan : elle n’y figure pas.'
        : 'Les soudures n° ' . implode(', ', $horsTableau) . ' n’ont pas de ligne sur ce plan : elles n’y figurent pas.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'cases' => $cases,
    'soudures' => $soudures,
    'valeurs' => $valeurs,
    // La place de l'intitulé « Numéro de ligne » : le lecteur y montre le numéro de page
    // que le plan final écrira, pour que l'écran dise la même chose que le PDF.
    // Le volet s'en sert pour prévenir qu'on travaille sur la première page d'un cahier.
    'pages' => (int) ($analyse['pages'] ?? 0),
    'avertissement' => implode(' ', $avertissements),
    'message' => $cases || $soudures ? '' : 'Tableaux non reconnus sur ce plan : placez les cases à la main.',
]);
