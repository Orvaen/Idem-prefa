<?php
// Le résumé d'un plan, pour le volet de « Plans / ISO & Affectations » : ce qui lui manque,
// feuille ISO par feuille ISO (atelier/plan_resume.php). Rendu au clic sur « Résumé du
// plan », pas avec le volet : il parcourt toutes les pages des cahiers du plan.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/plan_resume.php';

// Le volet du plan est celui de l'encadrement : la même porte que l'écran.
if (!$canManageWorkshop && !$canViewAllPrefa) prefaError(403, 'Le résumé d’un plan est réservé à l’encadrement de l’atelier.');
// Rien ne s'écrit dans la session d'ici (Session::liberer()).
Session::liberer();

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

$plan = $db->prepare('SELECT e.reference, e.id_demande, d.nom_affaire FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande WHERE e.id = ?');
$plan->execute([$elementId]);
$plan = $plan->fetch();
if (!$plan) prefaError(404, 'Plan introuvable.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'titre' => 'Résumé du plan ' . $plan['reference'],
    'sous_titre' => prefaReference((int) $plan['id_demande'])
        . (trim((string) $plan['nom_affaire']) !== '' ? ' · ' . trim((string) $plan['nom_affaire']) : ''),
    'html' => planResumeRendu(planResume($db, $elementId)),
]);
