<?php
// Le soudeur enregistre ses soudures sur une page de feuille ISO.
//
// Il ne pose ni numéro ni nom : le repère est imprimé sur le plan, et le soudeur est
// celui qui enregistre. Les contrôles RT/VI/PT et le visa ne passent pas par ici — ils
// engagent l'encadrement, et soudureAtelierEnregistrer() les laisse intacts.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/soudure_atelier.php';

prefaPost();
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');
if (!soudureAtelierAffectation($db, $elementId, (int) $actor['id'])) {
    prefaError(403, 'Ce plan ne vous est pas affecté.');
}

$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucune feuille ISO rattachée à ce plan.');
if (!is_file($plan['chemin'])) prefaError(404, 'Feuille introuvable.');

$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > max(1, (int) $plan['pages'])) prefaError(400, 'Page inexistante dans cette feuille.');

$saisies = $_POST['soudures'] ?? [];
if (!is_array($saisies)) prefaError(400, 'Saisie invalide.');
if (count($saisies) > 500) prefaError(400, 'Trop de lignes envoyées.');

try {
    $db->beginTransaction();
    $bilan = soudureAtelierEnregistrer($db, $elementId, $plan, $page, $saisies, (int) $actor['id']);
    $db->commit();
} catch (PDOException $erreur) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Soudures atelier: ' . $erreur->getMessage());
    prefaError(500, 'Impossible d’enregistrer. Réessayez.');
}

$feuille = soudureAtelierFeuille($db, $elementId, $plan, $page, (int) $actor['id']);

// Les mêmes chiffres qu'à la lecture : l'écran se remet à jour d'un bloc après
// l'enregistrement, et le total du plan vient de bouger.
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
    'message' => $bilan['ecrites'] === 0 && $bilan['retirees'] === 0
        ? 'Rien à enregistrer.'
        : trim(($bilan['ecrites'] ? $bilan['ecrites'] . ' soudure' . ($bilan['ecrites'] > 1 ? 's' : '') . ' enregistrée' . ($bilan['ecrites'] > 1 ? 's' : '') . '.' : '')
            . ($bilan['retirees'] ? ' ' . $bilan['retirees'] . ' ligne' . ($bilan['retirees'] > 1 ? 's' : '') . ' remise' . ($bilan['retirees'] > 1 ? 's' : '') . ' à faire.' : '')),
]);
