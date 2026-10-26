<?php
// Valider ou refuser une demande, depuis la liste.
//
// La décision elle-même — qui a le droit, quel état elle peut quitter, où elle se pose
// dans le planning, ce qu'on annonce aux écrans ouverts — vit dans Demande. L'API tranche
// par les mêmes méthodes : une règle, deux portes d'entrée. Ce fichier ne fait que lire
// le formulaire et traduire le résultat pour le JavaScript de la liste.
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$statut = (string) ($_POST['statut'] ?? '');
$commentaire = trim((string) ($_POST['commentaire'] ?? ''));
if (!$id || !in_array($statut, ['2', '3'], true)) prefaError(400, 'Décision invalide.');
if (mb_strlen($commentaire) > 1000) prefaError(400, 'Le motif tient en 1 000 caractères.');

$demande = Demande::visible($db, $utilisateur, $id);
if (!$demande) prefaError(404, 'Demande introuvable.');

try {
    $tranchee = $statut === '2'
        ? $demande->valider($db, $utilisateur, $commentaire)
        : $demande->refuser($db, $utilisateur, $commentaire);
} catch (DomainException $refus) {
    // Le motif manquant est une saisie à corriger ; le reste est un droit absent.
    prefaError(str_contains($refus->getMessage(), 'motif') ? 400 : 403, $refus->getMessage());
} catch (InvalidArgumentException $horsLimite) {
    /*
     * planningPeriod refuse une durée qu'il ne sait pas poser. Ce n'est pas une panne :
     * sans ce rattrapage, l'exception remontait jusqu'à PHP, qui affichait sa trace —
     * chemins du serveur compris.
     *
     * Le message nomme le chiffre en cause et les deux façons de le corriger. La durée est
     * figée sur la demande au moment où elle est déposée : changer la cadence dans les
     * réglages ne la recalcule pas, il faut rouvrir la demande — ce que son enregistrement
     * fait. Dire seulement « vérifiez la cadence » envoyait au mauvais endroit.
     */
    $jours = (int) ceil($demande->heuresRetenues($db) / 24);
    prefaError(409, $jours > 3650
        ? 'Cette demande porte une durée estimée de ' . number_format($jours, 0, ',', ' ')
            . ' jours, et le planning n’en pose pas plus de 3 650. Ouvrez-la avec « Modifier »'
            . ' puis enregistrez-la : la durée sera recalculée à la cadence du moment.'
            . ' Si elle reste hors limite, corrigez la cadence dans Paramètres › Jours ISO.'
        : $horsLimite->getMessage() . ' La cadence se règle dans Paramètres › Jours ISO.');
} catch (PDOException $erreur) {
    Journal::erreur('Décision sur la demande ' . $id, $erreur);
    prefaError(500, 'Impossible d’enregistrer la décision. Réessayez.');
}

if (!$tranchee) prefaError(409, 'Cette demande a déjà été traitée ou n’existe plus. Actualisez la liste.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => $statut === '2' ? 'Demande validée.' : 'Demande refusée.']);
