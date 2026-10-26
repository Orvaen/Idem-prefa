<?php
// Lit (ou relit) les articles d'un bon de commande déposé sur une demande.
//
// La lecture se demande : elle ne part pas d'elle-même comme celle des certificats. Un
// bon scanné de quatre pages occupe le serveur plusieurs secondes, et chaque demande en
// porte un.
//
// Ce qui est lu ne vaut pas saisie : une relecture efface la vérification précédente, et
// c'est l'encadrement qui corrige ensuite, dans l'écran « Commande matériel ».
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
prefaPost();
// La lecture dure plusieurs secondes. On rend la session avant : PHP la verrouille le
// temps de la requête, et les autres pages de la même personne attendraient derrière.
session_write_close();

// Le bon décrit la demande entière, pas un plan : c'est l'encadrement qui le fait lire.
if (!$canViewAllPrefa) prefaError(403, 'Les bons de commande sont lus par le chargé de production ou le chef d’atelier.');

$idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idDocument) prefaError(400, 'Bon invalide.');

$stmt = $db->prepare('SELECT doc.id FROM demande_materiel_documents doc
    JOIN demande_prefabrication d ON d.id = doc.id_demande
    WHERE doc.id = ?');
$stmt->execute([$idDocument]);
if (!$stmt->fetchColumn()) prefaError(404, 'Bon introuvable ou inaccessible.');

// Outils absents : c'est le serveur qui n'est pas prêt, pas le bon qui est illisible. On
// n'enregistre rien, pour que la lecture reste possible une fois le serveur mis à jour.
if ($manquants = ccpuOutilsManquants()) {
    prefaError(503, 'Lecture automatique indisponible sur ce serveur : ' . implode(', ', $manquants) . ' à installer.');
}

try {
    materielLireDocument($db, $idDocument);
} catch (DomainException $exception) {
    prefaError(409, $exception->getMessage());
} catch (Throwable $exception) {
    Journal::erreur('Lecture du bon ' . $idDocument, $exception);
    prefaError(500, 'Lecture impossible. Réessayez.');
}

$document = materielDocument($db, $idDocument);
if (!$document) prefaError(404, 'Bon introuvable.');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'document' => materielPayload($document),
    // Ce que l'écran doit dire avant qu'on n'enregistre : une lecture par reconnaissance
    // d'image se trompe sur des caractères qui se ressemblent.
    'message' => $document['lignes']
        ? count($document['lignes']) . ' article(s) lus'
            . ($document['lecture'] === 'ocr' ? ' par reconnaissance d’image — relisez les références et les quantités.' : '.')
        : 'Aucun article reconnu dans ce bon. Saisissez-les à la main.',
]);
