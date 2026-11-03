<?php

// Ouvrir, modifier et fermer une affaire. Réservé au chargé de production : c'est lui qui
// décide qu'un chantier existe et qui en a la charge.
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();

if (!$isAdmin) prefaError(403, 'Seul le chargé de production gère les affaires.');

$nom = trim((string) ($_POST['nom'] ?? ''));
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

// « aucun » est un choix : une affaire peut être ouverte avant de savoir qui la suivra.
$charge = trim((string) ($_POST['charge'] ?? ''));
$charge = $charge === '' ? null : filter_var($charge, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($charge === false) prefaError(400, 'Chargé d’affaire invalide.');
if ($charge !== null) {
    $verifier = $db->prepare('SELECT 1 FROM Utilisateur WHERE id = ? AND id_role = ?');
    $verifier->execute([$charge, Role::ChargeDAffaire->value]);
    if (!$verifier->fetchColumn()) prefaError(400, 'Ce compte n’est pas un chargé d’affaire.');
}

$operation = (string) ($_POST['operation'] ?? 'ouvrir');

// Le chargé d'affaire à prévenir, s'il y en a un : il apprend sans rafraîchir qu'une
// affaire lui revient. L'annonce part après la transaction, plus bas.
$prevenir = null;

try {
    switch ($operation) {
        case 'ouvrir':
            if ($nom === '' || mb_strlen($nom) > 255) prefaError(400, 'Saisissez un nom d’affaire de 255 caractères maximum.');
            $id = Affaire::ouvrir($db, $nom, $charge);
            $message = 'Affaire ouverte.';
            if ($charge !== null) $prevenir = $charge;
            break;

        case 'modifier':
            if (!$id) prefaError(400, 'Affaire invalide.');
            if ($nom === '' || mb_strlen($nom) > 255) prefaError(400, 'Saisissez un nom d’affaire de 255 caractères maximum.');
            // Qui l'avait avant : on ne prévient que si la charge change vraiment. Enregistrer
            // une affaire pour en corriger le nom ne doit pas sonner chez son chargé d'affaire.
            $avant = Affaire::parId($db, $id);
            Affaire::modifier($db, $id, $nom, $charge);
            $message = 'Affaire modifiée.';
            if ($charge !== null && $avant && $avant->idChargeAffaire !== $charge) $prevenir = $charge;
            break;

        case 'fermer':
            if (!$id) prefaError(400, 'Affaire invalide.');
            Affaire::fermer($db, $id);
            $message = 'Affaire supprimée.';
            break;

        default:
            prefaError(400, 'Action invalide.');
    }
} catch (DomainException $refus) {
    // Un refus métier n'est pas une panne : il se lit, et on corrige la saisie.
    prefaError(409, $refus->getMessage());
} catch (PDOException $erreur) {
    prefaError(500, 'Impossible d’enregistrer cette affaire. Réessayez.');
}

$affaire = $operation === 'fermer' ? null : Affaire::parId($db, $id);

// Après l'enregistrement, jamais avant : une annonce pour une affaire qui n'a pas été
// écrite vaut moins que pas d'annonce du tout.
if ($prevenir !== null && $affaire) {
    Evenement::noter($db, Evenement::AFFAIRE_CONFIEE, null, $utilisateur->id,
        ['affaire' => $affaire->nom], $prevenir);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => $message,
    'affaire' => $affaire ? [
        'id' => $affaire->id,
        'nom' => $affaire->nom,
        'charge' => $affaire->chargeAffaire,
        'id_charge' => $affaire->idChargeAffaire,
        'demandes' => $affaire->demandes,
    ] : null,
]);
