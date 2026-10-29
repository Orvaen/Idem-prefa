<?php
// Sa propre signature, tracée dans les paramètres (rubrique Compte) : la ranger, ou la
// retirer. Chacun ne touche qu'à la sienne — la personne vient de la session, jamais du
// formulaire. La réponse rend la commande de la ligne, que la fenêtre remplace.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/signature.php';
prefaPost();

if (!signaturesEnBase($db)) {
    prefaError(409, 'Les signatures n’existent pas encore sur ce serveur : passez la migration 2026-10-29_signatures.');
}

$operation = (string) ($_POST['operation'] ?? '');
if (!in_array($operation, ['enregistrer', 'supprimer'], true)) prefaError(400, 'Action inconnue.');

try {
    if ($operation === 'enregistrer') {
        // Les cordons qu'on avait visés au nom la prennent aussitôt : on le dit, sinon on la
        // découvre sur le plan sans savoir d'où elle vient.
        $signes = signatureEnregistrer($db, $utilisateur->id, (string) ($_POST['dessin'] ?? ''));
        $message = match (true) {
            $signes === 0 => 'Signature enregistrée : elle visera les cordons que vous validez.',
            $signes === 1 => 'Signature enregistrée : elle remplace votre nom sur le cordon que vous aviez visé, et visera les suivants.',
            default => 'Signature enregistrée : elle remplace votre nom sur les ' . $signes
                . ' cordons que vous aviez visés, et visera les suivants.',
        };
    } else {
        signatureSupprimer($db, $utilisateur->id);
        $message = 'Signature supprimée : vos prochains visas porteront votre nom.';
    }
} catch (DomainException $refus) {
    prefaError(422, $refus->getMessage());
} catch (PDOException $erreur) {
    Journal::erreur('Signature', $erreur);
    prefaError(500, 'Enregistrement impossible. Réessayez.');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => $message,
    'html' => signatureParametreRendu(signatureEnCours($db, $utilisateur->id), $utilisateur->nomComplet()),
]);
