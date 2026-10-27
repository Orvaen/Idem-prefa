<?php
// Enregistre un PV : état, date, commentaire, et dépôt ou retrait de documents.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/pv.php';
require_once __DIR__ . '/../includes/materiel/commandes.php';
prefaPost();
if (!$canFillPv) prefaError(403, 'Les PV sont renseignés par le chef d’atelier.');

$idDemande = filter_var($_POST['demande'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string) ($_POST['type'] ?? '');
$operation = (string) ($_POST['operation'] ?? 'save');
if (!$idDemande || !array_key_exists($type, pvTypes())) prefaError(400, 'PV invalide.');
if (!in_array($operation, ['save', 'delete_document'], true)) prefaError(400, 'Action invalide.');

$stmt = $db->prepare('SELECT id, id_passivation FROM demande_prefabrication WHERE id = ? AND id_statut = 2');
$stmt->execute([$idDemande]);
$demande = $stmt->fetch();
if (!$demande) prefaError(404, 'Demande introuvable ou non validée.');
// Sans passivation demandée, le PV est « sans objet » d'office (voir pvParDemande) : il
// n'y a rien à y écrire.
if ($type === 'passivation' && $demande['id_passivation'] === null) {
    prefaError(409, 'Aucune passivation n’est demandée : ce PV est sans objet.');
}

/** Crée la ligne du PV à la première écriture, puis la verrouille. */
function pvVerrouiller(PDO $db, int $idDemande, string $type): array
{
    $db->prepare('INSERT IGNORE INTO pv_prefabrication (id_demande, type) VALUES (?, ?)')->execute([$idDemande, $type]);
    $stmt = $db->prepare('SELECT * FROM pv_prefabrication WHERE id_demande = ? AND type = ? FOR UPDATE');
    $stmt->execute([$idDemande, $type]);
    return $stmt->fetch();
}

try {
    if ($operation === 'delete_document') {
        $idDocument = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$idDocument) prefaError(400, 'Document invalide.');

        $db->beginTransaction();
        $pv = pvVerrouiller($db, $idDemande, $type);
        $stmt = $db->prepare('SELECT * FROM pv_document WHERE id = ? AND id_pv = ?');
        $stmt->execute([$idDocument, $pv['id']]);
        $document = $stmt->fetch();
        if (!$document) { $db->rollBack(); prefaError(404, 'Document introuvable.'); }

        $db->prepare('DELETE FROM pv_document WHERE id = ?')->execute([$idDocument]);
        $db->commit();
        // Le fichier part après la transaction : une suppression disque ne se défait pas.
        @unlink(pvDocumentDirectory((int) $pv['id']) . '/' . $document['cle']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Document retiré.']);
        return;
    }

    // ===== Enregistrement du PV =====
    $statut = (string) ($_POST['statut'] ?? '');
    $datePv = trim((string) ($_POST['date_pv'] ?? ''));
    $commentaire = trim((string) ($_POST['commentaire'] ?? ''));
    $revision = filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    if (!array_key_exists($statut, pvStatuts())) prefaError(400, 'État invalide.');
    if ($revision === false) prefaError(400, 'Révision invalide.');
    if (mb_strlen($commentaire) > 1000) prefaError(400, 'Commentaire trop long (1 000 caractères maximum).');
    if ($datePv !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $datePv);
        if (!$date || $date->format('Y-m-d') !== $datePv) prefaError(400, 'Date du PV invalide.');
    }
    // Un PV « fait » sans date laisserait un document non datable : on exige la date.
    if ($statut === 'fait' && $datePv === '') prefaError(400, 'Indiquez la date du PV.');

    // Les fichiers sont validés avant d'ouvrir la transaction : rien à défaire en cas de refus.
    // Deux champs : les documents (PDF ou image) et, sur la fin de fabrication, les photos
    // de la préfa — des images seulement, rangées à part pour s'afficher en vignettes.
    $autorises = ['pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg']];
    $champs = ['documents' => ['categorie' => 'document', 'extensions' => ['pdf', 'png', 'jpg', 'jpeg']]];
    if ($type === 'fin_fabrication') $champs['photos'] = ['categorie' => 'photo', 'extensions' => ['png', 'jpg', 'jpeg']];
    $documents = [];
    foreach ($champs as $champ => $regle) {
        $uploads = $_FILES[$champ] ?? null;
        if (!$uploads || !is_array($uploads['name'])) continue;
        foreach ($uploads['name'] as $index => $brut) {
            if ($uploads['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
            if ($uploads['error'][$index] !== UPLOAD_ERR_OK || !is_uploaded_file($uploads['tmp_name'][$index])) prefaError(400, 'Dépôt impossible. Réessayez.');
            if ($uploads['size'][$index] > 50 * 1024 * 1024) prefaError(400, 'Chaque fichier doit faire au maximum 50 Mo.');
            $nom = basename(str_replace('\\', '/', (string) $brut));
            $nom = substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $nom), 0, 180);
            $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
            if (!in_array($extension, $regle['extensions'], true)) {
                prefaError(400, $regle['categorie'] === 'photo' ? 'Les photos se déposent en PNG ou JPEG.' : 'Formats acceptés : PDF, PNG et JPEG.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploads['tmp_name'][$index]);
            if (!in_array($mime, $autorises[$extension], true)) prefaError(400, 'Le type du fichier ne correspond pas à son extension.');
            $documents[] = ['tmp' => $uploads['tmp_name'][$index], 'nom' => $nom, 'taille' => (int) $uploads['size'][$index], 'categorie' => $regle['categorie']];
        }
    }
    $deposes = [];
    $db->beginTransaction();
    try {
        $pv = pvVerrouiller($db, $idDemande, $type);
        if ((int) $pv['revision'] !== $revision) {
            $db->rollBack();
            prefaError(409, 'Ce PV a été modifié entre-temps. Actualisez avant de réessayer.');
        }

        $db->prepare('UPDATE pv_prefabrication SET statut = ?, date_pv = ?, commentaire = ?, maj_par = ?, date_maj = NOW(), revision = revision + 1 WHERE id = ?')
            ->execute([$statut, $datePv ?: null, $commentaire ?: null, $actor['id'], $pv['id']]);

        // Le tableau des commandes voyage avec le PV de réception : une seule sauvegarde
        // pour le formulaire entier, et la même transaction — on ne veut pas d'un PV
        // enregistré dont les commandes se seraient perdues.
        if ($type === 'reception_materiel' && isset($_POST['commande']) && is_array($_POST['commande'])) {
            commandesEnregistrer($db, $idDemande, $_POST['commande'], (int) $actor['id']);
        }

        if ($documents) {
            $dossier = pvDocumentDirectory((int) $pv['id']);
            if (!is_dir($dossier) && !@mkdir($dossier, 0775, true)) throw new RuntimeException('Dossier de dépôt indisponible.');
            $inserer = $db->prepare('INSERT INTO pv_document (id_pv, cle, nom, categorie, taille, depose_par) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($documents as $document) {
                $cle = bin2hex(random_bytes(16)) . '-' . $document['nom'];
                $chemin = $dossier . '/' . $cle;
                if (!@move_uploaded_file($document['tmp'], $chemin)) throw new RuntimeException('Impossible de déposer le document.');
                $deposes[] = $chemin;
                $inserer->execute([$pv['id'], $cle, $document['nom'], $document['categorie'], $document['taille'], $actor['id']]);
            }
        }

        $db->commit();
    } catch (Throwable $erreur) {
        if ($db->inTransaction()) $db->rollBack();
        // Les fichiers déjà posés n'ont plus de ligne : on les retire.
        foreach ($deposes as $chemin) @unlink($chemin);
        throw $erreur;
    }

    header('Content-Type: application/json; charset=utf-8');
    $n = count($documents);
    echo json_encode(['success' => true, 'message' => 'PV enregistré' . ($n ? ' avec ' . $n . ($n === 1 ? ' fichier.' : ' fichiers.') : '.')]);
} catch (DomainException $refus) {
    prefaError(409, $refus->getMessage());
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    prefaError(500, 'Impossible d’enregistrer le PV. Réessayez.');
} catch (RuntimeException $e) {
    prefaError(507, $e->getMessage());
}
