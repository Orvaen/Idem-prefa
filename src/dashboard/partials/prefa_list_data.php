<?php
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/demande/prefa_hours.php';
require_once __DIR__ . '/../includes/materiel/ccpu_demande.php';
require_once __DIR__ . '/../includes/atelier/prefa_workshop.php';
require_once __DIR__ . '/../includes/demande/prefa_visibilite.php';
require_once __DIR__ . '/../includes/materiel/commandes.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
require_once __DIR__ . '/../includes/demande/prefa_documents.php';
$workshopUsers = $canManageWorkshop ? prefaWorkshopPersonnel($db) : [];

$q = trim((string) ($_GET['q'] ?? ''));

/**
 * Les valeurs retenues pour un filtre.
 *
 * Un filtre accepte désormais plusieurs choix — « en attente OU validée » — et arrive donc
 * sous forme de tableau. On ne garde que ce qui figure dans la liste des choix possibles :
 * ce qui vient de l'adresse n'est jamais une donnée de confiance, et ces valeurs partent
 * dans la requête.
 *
 * L'ancienne forme à valeur unique reste acceptée : les liens déjà envoyés fonctionnent.
 *
 * @param list<string> $permis
 * @return list<string>
 */
$filtreRetenu = static function (string $nom, array $permis) : array {
    $brut = $_GET[$nom] ?? null;
    if ($brut === null || $brut === '') return [];
    $valeurs = array_map('strval', is_array($brut) ? $brut : [$brut]);
    return array_values(array_unique(array_intersect($valeurs, $permis)));
};

$statuses = $db->query('SELECT id, libelle FROM statut_demande ORDER BY id')->fetchAll();
$status = $filtreRetenu('status', array_map(static fn ($item) => (string) $item['id'], $statuses));

$priority = $filtreRetenu('priority', ['0', '1']);
$urgentView = $canViewAllPrefa && ($_GET['view'] ?? '') === 'urgent';
if ($urgentView) $priority = ['1'];
$users = $canViewAllPrefa
    ? $db->query('SELECT DISTINCT u.id, u.prenom, u.name FROM Utilisateur u JOIN demande_prefabrication d ON d.idUsers = u.id ORDER BY u.prenom, u.name')->fetchAll()
    : [];
$userId = $canViewAllPrefa
    ? $filtreRetenu('user', array_map(static fn ($u) => (string) $u['id'], $users))
    : [];


$joins = ' FROM demande_prefabrication d
    JOIN statut_demande s ON s.id = d.id_statut
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    LEFT JOIN Utilisateur val ON val.id = d.valide_par
    LEFT JOIN Utilisateur taker ON taker.id = d.pris_en_charge_par
    LEFT JOIN Utilisateur staff ON staff.id = d.id_personnel_atelier
    LEFT JOIN type_passivation p ON p.id = d.id_passivation
    LEFT JOIN type_matiere m ON m.id = d.id_matiere ';
// Qui voit quoi. La règle est partagée avec l'API : deux copies d'une règle d'accès
// finissent par diverger, et c'est toujours celle qu'on a oubliée qui laisse passer.
['where' => $where, 'params' => $params] = prefaDemandesVisibles($utilisateur);

/**
 * Colle un filtre à choix multiples : « IN (…) » plutôt que « = ».
 *
 * Les marqueurs sont numérotés — :f_user_0, :f_user_1 — parce que l'émulation des requêtes
 * préparées est coupée : un marqueur ne peut être lié qu'une fois.
 */
$filtreEnSql = static function (string $colonne, string $nom, array $valeurs) use (&$where, &$params): void {
    if (!$valeurs) return;
    $marqueurs = [];
    foreach (array_values($valeurs) as $rang => $valeur) {
        $marqueur = 'f_' . $nom . '_' . $rang;
        $marqueurs[] = ':' . $marqueur;
        $params[$marqueur] = (int) $valeur;
    }
    $where .= ' AND ' . $colonne . ' IN (' . implode(', ', $marqueurs) . ')';
};

if ($canViewAllPrefa) $filtreEnSql('d.idUsers', 'user', $userId);
$filtreEnSql('d.id_statut', 'status', $status);
$filtreEnSql('d.urgent', 'priority', $priority);
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

// L'écran « Mes demandes » : celui de qui ne voit ni toutes les demandes ni ses seules
// affectations. C'est le même critère qui donne son titre à la page et qui range la liste,
// pour qu'ils ne puissent pas se contredire.
$vueParAffaire = !$canViewAllPrefa && !$isWorkshopPersonnel;

$stmt = $db->prepare(
    'SELECT d.*, s.libelle, u.name, u.prenom, val.name AS valideur_name, val.prenom AS valideur_prenom, TRIM(CONCAT(taker.prenom, \' \', taker.name)) AS prise_en_charge_nom, TRIM(CONCAT(staff.prenom, \' \', staff.name)) AS personnel_atelier_nom, p.libelle AS passivation_libelle, m.libelle AS matiere_libelle' . $joins . $where
    // Celui qui ne voit que ses propres demandes les lit par affaire : elles se suivent
    // sous leur chantier, la plus récente en tête. L'encadrement et l'atelier les lisent par
    // statut — en attente, validées, refusées, dans l'ordre des identifiants — et, dans
    // chaque groupe, de la plus récente à la plus ancienne : ce qui demande une décision
    // vient d'abord, et ce qui vient d'arriver reste en tête de son groupe.
    . ($vueParAffaire
        ? ' ORDER BY d.nom_affaire, d.date_creation DESC, d.id DESC'
        : ' ORDER BY d.id_statut, d.date_creation DESC, d.id DESC')
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
$commandesByRequest = [];
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

    $materielByRequest = materielParDemandes($db, $requestIds);

    $ccpuByRequest = ccpuParDemandes($db, $requestIds);

    // Le matériel commandé ne s'affiche que dans la fiche de ceux qui tiennent les PV :
    // inutile d'aller le chercher pour les autres.
    if ($canFillPv) $commandesByRequest = commandesParDemandes($db, $requestIds);
}

$urgentPending = $canViewAllPrefa
    ? (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE urgent = 1 AND id_statut = 1')->fetchColumn()
    : 0;
$pendingTotal = $canViewAllPrefa
    ? (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE id_statut = 1')->fetchColumn()
    : 0;
$partial = isset($_GET['results']);
