<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
prefaPost();
if (!$isWorkshopPersonnel && !$canManageWorkshop) prefaError(403, 'Action réservée au personnel atelier et au chef d’atelier.');
$id = filter_var($_POST['affectation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$operation = $_POST['operation'] ?? '';
if (!$id || !in_array($operation, ['progress', 'steps', 'valider'], true)) prefaError(400, 'Action invalide.');
// Trancher une déclaration engage celui qui le fait : le chiffre devient celui de
// l’affectation, et son nom reste dessus. La personne affectée déclare, elle ne valide pas.
if ($operation === 'valider' && !$canManageWorkshop) prefaError(403, 'Seul le chef d’atelier valide un avancement déclaré.');
// Le chef d’atelier et l’administrateur suivent tous les plans ; la personne affectée
// n’agit que sur les siens.
$toutesAffectations = $canManageWorkshop;
$reponse = 'Enregistrement effectué.';
try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT a.* FROM affectation_atelier a JOIN element_atelier e ON e.id = a.id_element JOIN demande_prefabrication d ON d.id = e.id_demande WHERE a.id = ?' . ($toutesAffectations ? '' : ' AND a.id_utilisateur = ?') . ' AND a.actif = 1 AND d.id_statut = 2 FOR UPDATE');
    $stmt->execute($toutesAffectations ? [$id] : [$id, $actor['id']]);
    $assignment = $stmt->fetch();
    if (!$assignment) { $db->rollBack(); prefaError(404, 'Affectation introuvable ou retirée.'); }
    $comment = trim((string) ($_POST['commentaire'] ?? ''));
    if (mb_strlen($comment) > 2000) { $db->rollBack(); prefaError(400, 'Commentaire trop long.'); }
    if ($operation === 'steps') {
        $revision = filter_var($_POST['etapes_revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $values = $_POST['etapes'] ?? null;
        if ($revision === false || !is_array($values) || count($values) !== count(atelierSteps())) {
            $db->rollBack(); prefaError(400, 'Renseignez les ' . count(atelierSteps()) . ' étapes.');
        }
        $statuses = [];
        foreach (atelierSteps() as $key => $label) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || !in_array($value, ['', 'OK', 'Non OK', 'RAS'], true)) {
                $db->rollBack(); prefaError(400, 'Statut d’étape invalide.');
            }
            $statuses[$key] = $value;
        }
        $stmt = $db->prepare('SELECT revision FROM element_atelier_etapes WHERE id_element = ? FOR UPDATE');
        $stmt->execute([$assignment['id_element']]);
        $currentRevision = (int) ($stmt->fetchColumn() ?: 0);
        if ($currentRevision !== $revision) {
            $db->rollBack(); prefaError(409, 'Les étapes ont été modifiées. Actualisez avant de réessayer.');
        }
        $db->prepare('INSERT INTO element_atelier_etapes (id_element, statuts, revision, modifie_par) VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE statuts = VALUES(statuts), revision = revision + 1, modifie_par = VALUES(modifie_par), date_modification = NOW()')
            ->execute([$assignment['id_element'], json_encode($statuses, JSON_UNESCAPED_UNICODE), $actor['id']]);
        $db->prepare('INSERT INTO element_atelier_etapes_historique (id_element, statuts, revision, modifie_par) VALUES (?, ?, ?, ?)')
            ->execute([$assignment['id_element'], json_encode($statuses, JSON_UNESCAPED_UNICODE), $currentRevision + 1, $actor['id']]);
        // Les étapes font l'avancement : chaque travail du plan qui s'y mesure reçoit le
        // nouveau chiffre — en déclaration pour le chef, posé d'office si c'est lui.
        $calcul = avancementSelonEtapes($db, (int) $assignment['id_element'], (int) $actor['id'], $canManageWorkshop);
        $reponse = 'Étapes enregistrées.';
        if ($calcul && $calcul['travaux'] > 0) {
            $reponse .= ' Avancement calculé : ' . $calcul['pourcentage'] . ' %'
                . ($canManageWorkshop ? '.' : ', envoyé au chef d’atelier.');
        }
    } elseif ($operation === 'valider') {
        $declaration = filter_var($_POST['declaration'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decision = $_POST['decision'] ?? '';
        if (!$declaration || !in_array($decision, ['valider', 'refuser'], true)) { $db->rollBack(); prefaError(400, 'Décision invalide.'); }
        try {
            $tranchee = avancementTrancher($db, $id, $declaration, $actor['id'], $decision === 'valider');
        } catch (DomainException $erreur) {
            // Quelqu’un d’autre l’a déjà tranchée, ou l’ouvrier l’a remplacée entre-temps.
            $db->rollBack();
            prefaError(409, $erreur->getMessage());
        }
        $reponse = $decision === 'valider'
            ? 'Avancement validé : ' . avancementPhrase($tranchee) . '.'
            : 'Déclaration refusée. L’avancement n’a pas changé.';
    } elseif ($operation === 'progress') {
        // L'atelier ne tape plus de pourcentage : il se calcule sur les étapes et les
        // cordons (avancement.php). Seul le chef garde la main, comme autorité.
        if (!$canManageWorkshop) {
            $db->rollBack();
            prefaError(403, 'L’avancement se calcule tout seul, sur les étapes et les cordons : il ne se déclare plus à la main.');
        }
        $revision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($revision === false) { $db->rollBack(); prefaError(400, 'Formulaire incomplet. Actualisez avant de réessayer.'); }
        if ((int) $assignment['revision'] !== $revision) { $db->rollBack(); prefaError(409, 'Cet avancement a changé. Actualisez avant de réessayer.'); }

        // Quand le plan porte une liste de soudures, on compte des cordons et non des
        // pour cent : c’est ce que l’atelier sait dire — « j’en ai fait sept sur sept ».
        // Le dénominateur est ce que le bureau d’études a imprimé sur les feuilles, pas ce
        // que l’atelier a saisi : sinon le rapport serait de soi à soi, toujours 100 %.
        // Le quotient se calcule ici et nulle part ailleurs ; un pourcentage envoyé par le
        // formulaire ne serait pas écouté, sans quoi les deux chiffres pourraient se
        // contredire. Le chef, lui, garde la main sur le pourcentage direct : sa fenêtre
        // n’envoie pas de soudures, et c’est la branche du dessous qui la sert.
        $total = avancementSouduresPrevues($db, (int) $assignment['id_element'])['prevues'];
        $soudures = null;
        if ($total > 0 && trim((string) ($_POST['soudures'] ?? '')) !== '') {
            $soudures = filter_var($_POST['soudures'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $total]]);
            if ($soudures === false) { $db->rollBack(); prefaError(400, 'Indiquez un nombre de soudures entre 0 et ' . $total . '.'); }
            $progress = avancementPourcentage($soudures, $total);
        } elseif (trim((string) ($_POST['soudures'] ?? '')) !== '') {
            // L’écran comptait des cordons, la liste n’en a plus : elle a été vidée entre
            // l’affichage et l’envoi. Le dire, plutôt que de parler d’un pourcentage que ce
            // formulaire-là n’a jamais proposé.
            $db->rollBack();
            prefaError(409, 'La liste des soudures de ce plan a été vidée. Actualisez avant de réessayer.');
        } else {
            $progress = filter_var($_POST['avancement'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
            if ($progress === false) { $db->rollBack(); prefaError(400, 'Avancement invalide (0 à 100 %).'); }
        }
        // Le total est rangé avec la déclaration, pas relu à l’affichage : la liste des
        // soudures grandit, et « sept sur sept » doit rester vrai quand le plan en compte douze.
        $totalRetenu = $soudures === null ? null : $total;

        // Seul le chef arrive ici (refus plus haut) : son chiffre est posé d'office.
        avancementPoser($db, $id, $actor['id'], $progress, $soudures, $totalRetenu, $comment ?: null);
    }
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $reponse]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    Journal::erreur('Avancement atelier', $e);
    prefaError(500, 'Impossible d’enregistrer. Réessayez.');
}
