<?php
// L'encadrement fait avancer un manque signalé par l'atelier : vu, puis traité.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/manques.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Les manques se traitent par le chargé de production ou le chef d’atelier.');

$id = filter_var($_POST['manque'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$statut = (string) ($_POST['statut'] ?? '');
if (!$id) prefaError(400, 'Signalement invalide.');

try {
    $manque = manqueTraiter($db, $id, $statut, (int) $actor['id']);
} catch (DomainException $refus) {
    prefaError(409, $refus->getMessage());
} catch (PDOException $erreur) {
    Journal::erreur('Traitement du manque ' . $id, $erreur);
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

// Traité : celui qui l'a signalé l'apprend. « Vu » ne le dérange pas — la puce de son
// écran suffit à le dire.
if ($statut === 'traitee') {
    Evenement::noter($db, Evenement::MANQUE_TRAITE, (int) $manque['id_demande'], (int) $actor['id'], [
        'article' => $manque['designation'] . ' × ' . manqueQuantite($manque['quantite']) . ' ' . $manque['unite'],
    ], (int) $manque['demande_par']);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'statut' => $statut,
    'etat' => manquesEtats()[$statut],
    'message' => $statut === 'traitee' ? 'Manque traité : l’atelier est prévenu.' : 'Manque marqué vu.',
]);
