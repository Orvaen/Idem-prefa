<?php
// Le numéro de coulée d'une ligne de la nomenclature, choisi par le soudeur dans « Mes
// soudures » : il touche la case « N° COULÉE » de la ligne, sur le dessin, et choisit parmi
// les coulées lues sur les certificats de la demande — ou tape le numéro.
//
// Les mêmes portes que la feuille qu'il travaille (soudures_feuille.php) : le personnel
// d'atelier, sur un plan qui lui est affecté. L'encadrement garde sa saisie à lui, la page
// entière (save_nomenclature.php), et voit ce que le soudeur a posé.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/atelier/plan_document.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';

prefaPost();
if (!$isWorkshopPersonnel) prefaError(403, 'Cette action est réservée au personnel atelier.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');
if (!soudureAtelierAffectation($db, $elementId, (int) $actor['id'])) prefaError(403, 'Ce plan ne vous est pas affecté.');

$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_POST));
if (!$plan) prefaError(404, 'Aucune feuille ISO rattachée à ce plan.');

$page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$page || $page > max(1, (int) $plan['pages'])) prefaError(400, 'Page inexistante dans cette feuille.');

// La case, en fractions de l'image, bornée : une case hors du plan serait invisible.
$cellule = [];
foreach (['x', 'y', 'w', 'h'] as $cote) {
    $valeur = filter_var($_POST[$cote] ?? '', FILTER_VALIDATE_FLOAT);
    if ($valeur === false) prefaError(400, 'Case de coulée invalide.');
    $cellule[$cote] = max(0.0, min(1.0, $valeur));
}
if ($cellule['w'] <= 0 || $cellule['h'] <= 0) prefaError(400, 'Case de coulée invalide.');

$coulee = trim((string) ($_POST['coulee'] ?? ''));
if (mb_strlen($coulee) > 20) prefaError(400, 'Un numéro de coulée tient en 20 caractères.');

// Toutes les lignes de la page, pour poser une case à chacune si la page n'en a encore aucune
// (nomenclatureCouleeDeLigne()). Bornées comme la case : ce qui vient du navigateur se vérifie.
$toutes = [];
foreach (array_slice((array) json_decode((string) ($_POST['lignes'] ?? '[]'), true), 0, 200) as $ligne) {
    $y = filter_var($ligne['y'] ?? null, FILTER_VALIDATE_FLOAT);
    $h = filter_var($ligne['h'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($y === false || $h === false || $h <= 0) continue;
    $toutes[] = ['y' => max(0.0, min(1.0, $y)), 'h' => max(0.0, min(1.0, $h))];
}

try {
    nomenclatureCouleeDeLigne($db, $elementId, (int) $plan['id'], $page, $cellule, $coulee,
        (string) ($_POST['description'] ?? ''), (int) $actor['id'], $toutes);
} catch (PDOException $erreur) {
    Journal::erreur('Coulée d’une ligne de nomenclature', $erreur);
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'coulee' => $coulee,
    'message' => $coulee === '' ? 'Coulée retirée.' : 'Coulée ' . $coulee . ' enregistrée.',
]);
