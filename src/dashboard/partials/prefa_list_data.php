<?php
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
require_once __DIR__ . '/../includes/ccpu_demande.php';
require_once __DIR__ . '/../includes/prefa_workshop.php';
$workshopUsers = $canManageWorkshop ? prefaWorkshopPersonnel($db) : [];

$q = trim((string) ($_GET['q'] ?? ''));
$statuses = $db->query('SELECT id, libelle FROM statut_demande ORDER BY id')->fetchAll();
$status = (string) ($_GET['status'] ?? '');
$validStatuses = array_map(static fn($item) => (string) $item['id'], $statuses);
if (!in_array($status, $validStatuses, true)) $status = '';

$priority = (string) ($_GET['priority'] ?? '');
if (!in_array($priority, ['0', '1'], true)) $priority = '';
$urgentView = $canViewAllPrefa && ($_GET['view'] ?? '') === 'urgent';
if ($urgentView) $priority = '1';

$userId = $canViewAllPrefa
    ? filter_var($_GET['user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$users = $canViewAllPrefa
    ? $db->query('SELECT DISTINCT u.id, u.prenom, u.name FROM Utilisateur u JOIN demande_prefabrication d ON d.idUsers = u.id ORDER BY u.prenom, u.name')->fetchAll()
    : [];


$joins = ' FROM demande_prefabrication d
    JOIN statut_demande s ON s.id = d.id_statut
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    LEFT JOIN Utilisateur val ON val.id = d.valide_par
    LEFT JOIN Utilisateur taker ON taker.id = d.pris_en_charge_par
    LEFT JOIN Utilisateur staff ON staff.id = d.id_personnel_atelier
    LEFT JOIN type_passivation p ON p.id = d.id_passivation
    LEFT JOIN type_matiere m ON m.id = d.id_matiere ';
$where = $canViewAllPrefa ? ' WHERE 1=1' : ' WHERE d.idUsers = :user';
$params = $canViewAllPrefa ? [] : ['user' => $actor['id']];
if ($isWorkshopPersonnel) $where .= ' AND d.id_statut = 2';
if ($isWorkshopChief) {
    $where .= ' AND (d.id_statut = 2 OR d.idUsers = :chief_creator)';
    $params['chief_creator'] = $actor['id'];
}

if ($canViewAllPrefa && $userId) {
    $where .= ' AND d.idUsers = :filter_user';
    $params['filter_user'] = $userId;
}
if ($status !== '') {
    $where .= ' AND d.id_statut = :status';
    $params['status'] = (int) $status;
}
if ($priority !== '') {
    $where .= ' AND d.urgent = :priority';
    $params['priority'] = (int) $priority;
}
if ($q !== '') {
    $columns = [
        'CAST(d.id AS CHAR)',
        'd.nom_affaire',
        '(SELECT reference FROM prefa_reference WHERE id_demande = d.id)',
        's.libelle',
        'p.libelle',
        'CAST(d.date_fin_prevue AS CHAR)',
        'CAST(d.date_livraison_prevue AS CHAR)',
        "CASE WHEN d.urgent = 1 THEN 'Urgente' ELSE 'Normale' END",
    ];
    if ($canViewAllPrefa) $columns[] = "CONCAT(u.prenom, ' ', u.name)";

    $conditions = [];
    foreach ($columns as $index => $column) {
        $conditions[] = $column . ' LIKE :q' . $index;
        $params['q' . $index] = '%' . $q . '%';
    }
    $where .= ' AND (' . implode(' OR ', $conditions) . ')';
}

$stmt = $db->prepare(
    'SELECT d.*, s.libelle, u.name, u.prenom, val.name AS valideur_name, val.prenom AS valideur_prenom, TRIM(CONCAT(taker.prenom, \' \', taker.name)) AS prise_en_charge_nom, TRIM(CONCAT(staff.prenom, \' \', staff.name)) AS personnel_atelier_nom, p.libelle AS passivation_libelle, m.libelle AS matiere_libelle' . $joins . $where
    . ' ORDER BY d.date_creation DESC, d.id DESC'
);
foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
$stmt->execute();
$requests = $stmt->fetchAll();

$total = count($requests);
$attachmentsByRequest = [];
foreach ($requests as $request) {
    $attachmentsByRequest[$request['id']] = prefaAttachments((int) $request['id']);
}

$qmosByRequest = [];
$dmosByRequest = [];
$materielByRequest = [];
$ccpuByRequest = [];
if ($requests) {
    $requestIds = array_column($requests, 'id');
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    $qmosQuery = $db->prepare(
        'SELECT id, id_demande, nom FROM demande_qmos_documents WHERE id_demande IN (' . $placeholders . ') ORDER BY id'
    );
    $qmosQuery->execute($requestIds);
    foreach ($qmosQuery->fetchAll() as $document) {
        $qmosByRequest[$document['id_demande']][] = $document;
    }

    $dmosQuery = $db->prepare(
        'SELECT id, id_demande, nom FROM demande_dmos_documents WHERE id_demande IN (' . $placeholders . ') ORDER BY id'
    );
    $dmosQuery->execute($requestIds);
    foreach ($dmosQuery->fetchAll() as $document) {
        $dmosByRequest[$document['id_demande']][] = $document;
    }

    $materielQuery = $db->prepare(
        'SELECT id, id_demande, nom FROM demande_materiel_documents WHERE id_demande IN (' . $placeholders . ') ORDER BY id'
    );
    $materielQuery->execute($requestIds);
    foreach ($materielQuery->fetchAll() as $document) {
        $materielByRequest[$document['id_demande']][] = $document;
    }

    $ccpuByRequest = ccpuParDemandes($db, $requestIds);
}

$urgentPending = $canViewAllPrefa
    ? (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE urgent = 1 AND id_statut = 1')->fetchColumn()
    : 0;
$pendingTotal = $canViewAllPrefa
    ? (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE id_statut = 1')->fetchColumn()
    : 0;
$partial = isset($_GET['results']);
