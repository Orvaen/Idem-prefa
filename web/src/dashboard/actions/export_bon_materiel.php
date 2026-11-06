<?php
// La commande d'un bon, en fichier Excel aux couleurs de la maison (materiel_export.php) :
// « Excel », dans l'en-tête de son volet (6 novembre 2026).
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_export.php';

// Même porte que l'écran « Commande matériel ».
if (!$canViewAllPrefa) prefaError(403, 'Les bons de commande sont réservés au chargé de production et au chef d’atelier.');

$idDocument = filter_var($_GET['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

$idDemande = (int) $document['id_demande'];
try {
    $classeur = materielClasseur($document, prefaReferenceComplete($idDemande), $utilisateur->nomComplet());
} catch (Throwable $exception) {
    Journal::erreur('Export Excel du bon ' . $idDocument, $exception);
    prefaError(500, 'Le fichier Excel n’a pas pu être préparé. Réessayez.');
}

$nom = materielNomClasseur($document, prefaReference($idDemande));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $nom . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
header('Content-Length: ' . strlen($classeur));
header('Cache-Control: private, no-store');
echo $classeur;
