<?php
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut vérifier une demande.');
$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = (string) ($_POST['statut'] ?? '');
$comment = trim((string) ($_POST['commentaire'] ?? ''));
if (!$id || !in_array($status, ['2', '3'], true)) prefaError(400, 'Décision invalide.');
if (mb_strlen($comment) > 1000 || ($status === '3' && $comment === '')) prefaError(400, 'Indiquez un motif de refus (1 000 caractères maximum).');

try {
    if ($status === '2') {
        require_once __DIR__ . '/../includes/planning.php';
        require_once __DIR__ . '/../includes/prefa_hours.php';

        $reqStmt = $db->prepare('SELECT pouces_total_iso, heures_chiffrees FROM demande_prefabrication WHERE id = ?');
        $reqStmt->execute([$id]);
        $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);

        $hours = (float) ($reqData['heures_chiffrees'] ?? 0);
        if ($hours <= 0 && !empty($reqData['pouces_total_iso'])) {
            $hours = (float) prefaCalculatedHours((int) $reqData['pouces_total_iso'], prefaHoursPerInch($db));
        }
        if ($hours <= 0) {
            $rate = (float) prefaHoursPerInch($db);
            $hours = $rate > 0 ? $rate : 24.0;
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
        $period = planningPeriod($today, $hours, true);

        $stmt = $db->prepare('UPDATE demande_prefabrication SET id_statut = ?, commentaire_validation = ?, valide_par = ?, date_validation = NOW(), date_debut_planifiee = ?, date_fin_planifiee = ?, planning_jours_ouvres = 1, DateUpdate = NOW() WHERE id = ? AND id_statut = 1');
        $stmt->execute([$status, $comment ?: null, $actor['id'], $period['start'], $period['end'], $id]);
    } else {
        $stmt = $db->prepare('UPDATE demande_prefabrication SET id_statut = ?, commentaire_validation = ?, valide_par = ?, date_validation = NOW(), DateUpdate = NOW() WHERE id = ? AND id_statut = 1');
        $stmt->execute([$status, $comment ?: null, $actor['id'], $id]);
    }
    if ($stmt->rowCount() !== 1) prefaError(409, 'Cette demande a déjà été traitée ou n’existe plus. Actualisez la liste.');

    // Le demandeur apprend la décision sans rafraîchir : c'est ce qu'il attend, et il n'a
    // aucun moyen de savoir quand elle tombe.
    $affaire = $db->prepare('SELECT nom_affaire FROM demande_prefabrication WHERE id = ?');
    $affaire->execute([$id]);
    Evenement::noter(
        $db,
        $status === '2' ? Evenement::DEMANDE_VALIDEE : Evenement::DEMANDE_REFUSEE,
        $id,
        $utilisateur->id,
        ['affaire' => (string) $affaire->fetchColumn(), 'motif' => $comment]
    );

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $status === '2' ? 'Demande validée.' : 'Demande refusée.']);
} catch (InvalidArgumentException $refus) {
    /*
     * planningPeriod refuse une durée qu'il ne sait pas poser. Ce n'est pas une panne : sans
     * ce rattrapage, l'exception remontait jusqu'à PHP, qui affichait sa trace — chemins du
     * serveur compris.
     *
     * Le message nomme le chiffre en cause et les deux façons de le corriger. La durée est
     * figée sur la demande au moment où elle est déposée : changer la cadence dans les
     * réglages ne la recalcule pas, il faut rouvrir la demande — ce que son enregistrement
     * fait. Dire seulement « vérifiez la cadence » envoyait au mauvais endroit.
     */
    $jours = isset($hours) && $hours > 0 ? (int) ceil($hours / 24) : 0;
    prefaError(409, $jours > 3650
        ? 'Cette demande porte une durée estimée de ' . number_format($jours, 0, ',', ' ')
            . ' jours, et le planning n’en pose pas plus de 3 650. Ouvrez-la avec « Modifier »'
            . ' puis enregistrez-la : la durée sera recalculée à la cadence du moment.'
            . ' Si elle reste hors limite, corrigez la cadence dans Paramètres › Jours ISO.'
        : $refus->getMessage() . ' La cadence se règle dans Paramètres › Jours ISO.');
} catch (PDOException $e) {
    prefaError(500, 'Impossible d’enregistrer la décision. Réessayez.');
}
