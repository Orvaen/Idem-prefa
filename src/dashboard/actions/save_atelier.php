<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_workshop.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier.php';
prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Action réservée au chef d’atelier ou à l’administrateur.');
$requestId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$operation = $_POST['operation'] ?? '';
// « take » a disparu : une demande validée est ouverte à l'atelier, et plusieurs chefs y
// travaillent. L'opération reste refusée explicitement, le temps que les pages ouvertes
// avant la mise à jour se rechargent.
if ($operation === 'take') prefaError(410, 'Une demande validée est désormais ouverte à l’atelier sans prise en charge. Rechargez la page.');
if (!$requestId || !in_array($operation, ['element', 'update_element', 'delete_element', 'documents', 'assign', 'revoke'], true)) prefaError(400, 'Action atelier invalide.');
try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT * FROM demande_prefabrication WHERE id = ? AND id_statut = 2 FOR UPDATE');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) { $db->rollBack(); prefaError(404, 'Demande validée introuvable.'); }
    if (in_array($operation, ['update_element', 'delete_element'], true)) {
        $elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$elementId) { $db->rollBack(); prefaError(400, 'Plan invalide.'); }
        atelierChangeElement($db, $requestId, $elementId, $operation, $_POST['reference'] ?? '', $_POST['libelle'] ?? '');
    } elseif ($operation === 'element') {
        // Création d'un plan : c'est le seul moment où l'on choisit ses fichiers d'un bloc,
        // puisqu'il n'en a encore aucun.
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $label = trim((string) ($_POST['libelle'] ?? ''));
        $documents = $_POST['documents'] ?? [];
        if ($reference === '' || mb_strlen($reference) > 80 || $label === '' || mb_strlen($label) > 180
            || !is_array($documents) || count($documents) > 100) {
            $db->rollBack(); prefaError(400, 'Indiquez une référence et un libellé valides.');
        }
        $links = [];
        foreach ($documents as $token) {
            if (!is_string($token)) { $db->rollBack(); prefaError(400, 'Document invalide.'); }
            [$kind, $key] = array_pad(explode(':', $token, 2), 2, '');
            if (!atelierDocumentName($db, $requestId, $kind, $key)) { $db->rollBack(); prefaError(400, 'Ce document n’appartient pas à la demande.'); }
            $links[$token] = [$kind, $key];
        }
        if (!array_filter($links, fn($link) => $link[0] === 'plan')) {
            $db->rollBack(); prefaError(400, 'Sélectionnez au moins un fichier de plan / ISO.');
        }
        $db->prepare('INSERT INTO element_atelier (id_demande, reference, libelle, cree_par) VALUES (?, ?, ?, ?)')->execute([$requestId, $reference, $label, $actor['id']]);
        $elementId = (int) $db->lastInsertId();
        $link = $db->prepare('INSERT INTO element_atelier_document (id_element, type_document, cle_document) VALUES (?, ?, ?)');
        foreach ($links as [$kind, $key]) $link->execute([$elementId, $kind, $key]);
    } elseif ($operation === 'documents') {
        // Les documents annexes d'un plan : QMOS, DMOS, matériel. Les feuilles ISO ne
        // passent plus par ici — elles se déposent et se retirent une par une dans le
        // volet, et chacune porte ses coulées et sa liste soudure.
        //
        // Ce formulaire ne réécrit plus tous les rattachements de l'élément. Il le faisait,
        // et chaque enregistrement donnait de nouveaux identifiants aux feuilles : la base
        // suivant en cascade, les coulées posées et les listes soudure de TOUTES les
        // feuilles disparaissaient sans un mot. On ne touche donc qu'à ce qui change.
        $documents = $_POST['documents'] ?? [];
        $elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$elementId || !is_array($documents) || count($documents) > 100) {
            $db->rollBack(); prefaError(400, 'Documents invalides.');
        }
        $stmt = $db->prepare('SELECT id FROM element_atelier WHERE id = ? AND id_demande = ? FOR UPDATE');
        $stmt->execute([$elementId, $requestId]);
        if (!$stmt->fetchColumn()) { $db->rollBack(); prefaError(400, 'Plan introuvable dans cette demande.'); }

        $souhaites = [];
        foreach ($documents as $token) {
            if (!is_string($token)) { $db->rollBack(); prefaError(400, 'Document invalide.'); }
            [$kind, $key] = array_pad(explode(':', $token, 2), 2, '');
            // Une page ouverte avant ce changement peut encore cocher des plans : on les
            // laisse où ils sont plutôt que de refuser l'enregistrement.
            if ($kind === 'plan') continue;
            if (!atelierDocumentName($db, $requestId, $kind, $key)) { $db->rollBack(); prefaError(400, 'Ce document n’appartient pas à la demande.'); }
            $souhaites[$kind . ':' . $key] = [$kind, $key];
        }

        $actuels = $db->prepare("SELECT id, type_document, cle_document FROM element_atelier_document
            WHERE id_element = ? AND type_document <> 'plan'");
        $actuels->execute([$elementId]);
        $retirer = $db->prepare('DELETE FROM element_atelier_document WHERE id = ?');
        $gardes = [];
        foreach ($actuels->fetchAll() as $lien) {
            $token = $lien['type_document'] . ':' . $lien['cle_document'];
            if (isset($souhaites[$token])) { $gardes[$token] = true; continue; }
            $retirer->execute([(int) $lien['id']]);
        }
        $ajouter = $db->prepare('INSERT INTO element_atelier_document (id_element, type_document, cle_document) VALUES (?, ?, ?)');
        foreach ($souhaites as $token => [$kind, $key]) {
            if (!isset($gardes[$token])) $ajouter->execute([$elementId, $kind, $key]);
        }
    } else {
        $elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = $_POST['type'] ?? '';
        $stmt = $db->prepare('SELECT id FROM element_atelier WHERE id = ? AND id_demande = ?');
        $stmt->execute([$elementId ?: 0, $requestId]);
        if (!$stmt->fetchColumn() || !in_array($type, ['plan', 'montage', 'soudage'], true)) { $db->rollBack(); prefaError(400, 'Élément ou travail invalide.'); }
        if ($operation === 'assign' && $type === 'plan') {
            $stmt = $db->prepare("SELECT cle_document FROM element_atelier_document WHERE id_element = ? AND type_document = 'plan'");
            $stmt->execute([$elementId]);
            $validFiles = array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($key) => atelierDocumentName($db, $requestId, 'plan', $key) !== null);
            if (!$validFiles) { $db->rollBack(); prefaError(400, 'Associez un fichier au plan avant de l’affecter.'); }
        }
        $stmt = $db->prepare('SELECT id, id_utilisateur FROM affectation_atelier WHERE id_element = ? AND type_affectation = ? AND actif = 1 FOR UPDATE');
        $stmt->execute([$elementId, $type]);
        $previous = $stmt->fetch();
        $personnelId = $operation === 'assign' ? prefaWorkshopPersonnelId($db, $_POST['utilisateur'] ?? '') : null;
        if ($operation === 'assign' && !$personnelId) { $db->rollBack(); prefaError(400, 'Sélectionnez un utilisateur atelier.'); }
        if ($operation === 'revoke' || !$previous || (int) $previous['id_utilisateur'] !== $personnelId) {
            $db->prepare('UPDATE affectation_atelier SET actif = NULL, date_fin_affectation = NOW() WHERE id_element = ? AND type_affectation = ? AND actif = 1')->execute([$elementId, $type]);
            if ($operation === 'assign') $db->prepare('INSERT INTO affectation_atelier (id_element, id_utilisateur, type_affectation, affecte_par) VALUES (?, ?, ?, ?)')->execute([$elementId, $personnelId, $type, $actor['id']]);
        }
    }
    $db->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Atelier mis à jour.']);
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) $db->rollBack();
    prefaError(400, $e->getMessage());
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($e->getCode() === '23000') prefaError(409, 'Cette référence ou affectation existe déjà. Actualisez la liste.');
    error_log('Atelier: ' . $e->getMessage());
    prefaError(500, 'Impossible d’enregistrer. Réessayez.');
}
