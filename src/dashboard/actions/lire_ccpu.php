<?php
// Lit (ou relit) les coulées d'un certificat matière et renvoie le bloc à afficher.
// Appelé à l'ouverture de la fiche de détail, pour chaque certificat pas encore lu.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/ccpu_demande.php';
prefaPost();
$csrf = Session::jeton();
// La lecture d'un scan dure plusieurs secondes. On rend la session avant : PHP la
// verrouille le temps de la requête, et toutes les autres pages du même utilisateur
// attendraient derrière celle-ci.
session_write_close();

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Certificat invalide.');

// Même règle que le téléchargement : l'encadrement voit tout, le demandeur ses demandes.
$stmt = $db->prepare('SELECT doc.id_demande FROM demande_ccpu_documents doc
    JOIN demande_prefabrication d ON d.id = doc.id_demande
    WHERE doc.id = ?' . ($canViewAllPrefa ? '' : ' AND d.idUsers = ?'));
$stmt->execute($canViewAllPrefa ? [$idDocument] : [$idDocument, $actor['id']]);
$idDemande = $stmt->fetchColumn();
if (!$idDemande) prefaError(404, 'Certificat introuvable ou inaccessible.');

// Outils absents : c'est le serveur qui n'est pas prêt, pas le certificat qui est
// illisible. On n'enregistre rien, pour que la lecture se fasse d'elle-même une fois
// le serveur mis à jour.
if ($manquants = ccpuOutilsManquants()) {
    prefaError(503, 'Lecture automatique indisponible sur ce serveur : ' . implode(', ', $manquants) . ' à installer.');
}

try {
    ccpuLireDocument($db, (int) $idDocument);
} catch (Throwable $exception) {
    prefaError(500, 'Lecture impossible. Réessayez.');
}

foreach (ccpuParDemandes($db, [(int) $idDemande])[(int) $idDemande] ?? [] as $document) {
    if ((int) $document['id'] !== (int) $idDocument) continue;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'html' => ccpuRendu($document, $csrf)]);
    return;
}
prefaError(404, 'Certificat introuvable.');
