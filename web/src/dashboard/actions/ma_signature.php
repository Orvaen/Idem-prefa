<?php
// Sa propre signature, tracée dans sa grande fenêtre (signatureFenetreRendu()) : la valider,
// ou la retirer. Chacun ne touche qu'à la sienne — la personne vient de la session, jamais du
// formulaire. La réponse rend le bloc « Signature » des paramètres, qui le remplacent.
//
// Validée, elle ne se change plus de soi-même : seul un administrateur la réinitialise, depuis
// « Utilisateurs » (reinitialiser_signature.php), et garde la main sur la sienne. Les
// paramètres ne proposent plus de la refaire ; le serveur le refuse quand même.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/signature.php';
prefaPost();

if (!signaturesEnBase($db)) {
    prefaError(409, 'Les signatures n’existent pas encore sur ce serveur : passez la migration 2026-10-29_signatures.');
}

$operation = (string) ($_POST['operation'] ?? '');
if (!in_array($operation, ['enregistrer', 'supprimer'], true)) prefaError(400, 'Action inconnue.');

$administrateur = $utilisateur->est(Role::ChargeDeProduction);
if (!signatureModifiable($administrateur, signatureEnCours($db, $utilisateur->id))) {
    prefaError(403, 'Votre signature est validée : seul un administrateur peut la changer.');
}

try {
    if ($operation === 'enregistrer') {
        // Les cordons qu'on avait visés au nom la prennent aussitôt : on le dit, sinon on la
        // découvre sur le plan sans savoir d'où elle vient.
        $signes = signatureEnregistrer($db, $utilisateur->id, (string) ($_POST['dessin'] ?? ''));
        $suite = $administrateur ? '' : ' Seul un administrateur pourra la changer.';
        $message = match (true) {
            $signes === 0 => 'Signature validée : elle visera les soudures que vous validez.',
            $signes === 1 => 'Signature validée : elle remplace votre nom sur la soudure que vous aviez visée, et visera les suivantes.',
            default => 'Signature validée : elle remplace votre nom sur les ' . $signes
                . ' soudures que vous aviez visées, et visera les suivantes.',
        } . $suite;
    } else {
        signatureSupprimer($db, $utilisateur->id);
        $message = 'Signature supprimée : tracez-en une nouvelle pour valider des soudures.';
    }
} catch (DomainException $refus) {
    prefaError(422, $refus->getMessage());
} catch (PDOException $erreur) {
    Journal::erreur('Signature', $erreur);
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

header('Content-Type: application/json; charset=utf-8');
$signature = signatureEnCours($db, $utilisateur->id);
echo json_encode([
    'success' => true,
    'message' => $message,
    'html' => signatureParametreRendu($signature, $utilisateur->nomComplet(), $administrateur),
]);
