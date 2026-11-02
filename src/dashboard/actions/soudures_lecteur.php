<?php
// La fenêtre du plan d'un élément, pour « Mes soudures » : rendue à la première feuille qu'on
// ouvre de ce plan, et non avec la liste (vue/soudures_lecteur.php). soudures.js la demande
// dès que le pointeur passe sur une feuille de ce plan : elle est d'ordinaire là au clic.
//
// Rend du JSON, comme la fenêtre du plan final (plan_final_lecteur.php). La feuille de style
// et le script de la fenêtre sont déjà dans la page : l'écran les charge (menu.php).
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/vue/soudures_lecteur.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// Le plan doit vous être confié : la même porte que la lecture d'une feuille
// (soudures_feuille.php).
if (!soudureAtelierAffectation($db, $elementId, (int) $actor['id'])) prefaError(403, 'Ce plan ne vous est pas affecté.');

$fenetre = souduresLecteurRendu($db, $elementId);
if ($fenetre === '') prefaError(404, 'Aucune feuille ISO rattachée à ce plan.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'html' => $fenetre]);
