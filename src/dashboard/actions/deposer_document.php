<?php
// Dépose un ou plusieurs PDF sur une demande, depuis sa fiche.
//
// Le même dépôt existe dans le formulaire de modification, mais y passer renvoie la
// demande en attente de validation : c'est voulu pour une date ou un métrage, pas pour un
// certificat matière qui arrive trois semaines après la validation. Ici, rien d'autre que
// le document ne bouge.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/demande/prefa_pdf.php';
require_once __DIR__ . '/../includes/demande/prefa_documents.php';
prefaPost();

$familles = prefaDocumentFamilles();

$idDemande = filter_var($_POST['demande'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string) ($_POST['type'] ?? '');
if (!$idDemande) prefaError(400, 'Demande invalide.');
if (!isset($familles[$type])) prefaError(400, 'Type de document inconnu.');

$stmt = $db->prepare('SELECT id, idUsers FROM demande_prefabrication WHERE id = ?');
$stmt->execute([$idDemande]);
$demande = $stmt->fetch();
if (!$demande) prefaError(404, 'Demande introuvable.');

if (!prefaPeutDeposer($demande, $canEditPrefa, $isAdmin, (int) $actor['id'])) {
    prefaError(403, 'Vous ne pouvez pas déposer de document sur cette demande.');
}

$famille = $familles[$type] + ['cle' => $type];
$documents = prefaPdfUploads($_FILES['documents'] ?? null, $famille['titre'], 20);
if (!$documents) prefaError(400, 'Choisissez au moins un fichier PDF.');

$ajoutes = [];
try {
    if ($famille['table'] === null) {
        // Les plans vivent sur le disque : ils sont gros, et servis tels quels.
        $dossier = prefaAttachmentDirectory($idDemande);
        if (!is_dir($dossier) && !@mkdir($dossier, 0770, true) && !is_dir($dossier)) {
            throw new RuntimeException('Impossible de créer le dossier des plans.');
        }
        foreach ($documents as $document) {
            $cle = bin2hex(random_bytes(16)) . '-' . $document['name'];
            if (@file_put_contents($dossier . '/' . $cle, $document['content']) === false) {
                throw new RuntimeException('Impossible de déposer le plan.');
            }
            $ajoutes[] = ['key' => $cle, 'name' => $document['name']];
        }
    } else {
        $db->beginTransaction();
        $inserer = $db->prepare('INSERT INTO ' . $famille['table']
            . ' (id_demande, nom, contenu, sha256) VALUES (?, ?, ?, UNHEX(?))');
        foreach ($documents as $document) {
            $inserer->execute([$idDemande, $document['name'], $document['content'], $document['digest']]);
            // Relu plutôt que deviné : la fiche a besoin de l'identifiant pour construire
            // le lien de téléchargement, et d'un enregistrement complet pour les familles
            // qui affichent une lecture sous le document.
            $relire = $db->prepare('SELECT * FROM ' . $famille['table'] . ' WHERE id = ?');
            $relire->execute([(int) $db->lastInsertId()]);
            $ajoute = $relire->fetch(PDO::FETCH_ASSOC);
            unset($ajoute['contenu']);
            $ajoutes[] = $ajoute + ['lignes' => []];
        }
        $db->commit();
    }

    // La demande a bougé, même si son contenu n'a pas changé : la fiche affiche cette date
    // et c'est elle qui dit qu'il s'est passé quelque chose.
    $db->prepare('UPDATE demande_prefabrication SET DateUpdate = NOW() WHERE id = ?')->execute([$idDemande]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    Journal::erreur('Dépôt sur la demande ' . $idDemande, $exception);
    prefaError(500, 'Dépôt impossible. Réessayez.');
}

// Un bon de commande se lit tout seul, en tâche de fond : la réponse n'attend pas, et
// l'encadrement trouve ses articles en ouvrant le bon.
if ($type === 'materiel') {
    require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
    materielLireEnFond(array_column($ajoutes, 'id'));
}

$html = '';
foreach ($ajoutes as $document) {
    $html .= prefaDocumentLigne($idDemande, $famille, $document, $canViewAllPrefa);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'html' => $html,
    'ajoutes' => count($ajoutes),
    'message' => count($ajoutes) . ' document' . (count($ajoutes) > 1 ? 's déposés' : ' déposé') . '.',
]);
