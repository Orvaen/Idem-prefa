<?php
require_once __DIR__ . '/../includes/prefa_context.php';
if (!$canEditPrefa) prefaError(403, 'Création et modification réservées aux demandeurs.');
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
require_once __DIR__ . '/../includes/prefa_pdf.php';
// Vérifier le type de conexion et la méthode POST reçus.
prefaPost();

// Récupérer un id si null alors erreure
$editing = isset($_POST['id']);

// Upload images

// Vérifier si c une demande de édition
if ($editing) {

    $requestId = filter_var(
        $_POST['id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    ); // Vérifier l'entrée de request id


    // Si le chiffre et pas un vrai numéro 
    if (!$requestId) prefaError(400, 'Demande invalide.');

    // Vérifier si la demande existe et si c un admin.
    $check = $db->prepare('SELECT id FROM demande_prefabrication WHERE id = ?' . ($isAdmin ? '' : ' AND idUsers = ?'));
    $check->execute($isAdmin ? [$requestId] : [$requestId, $actor['id']]);

    // Sinon retourner une erreure
    if (!$check->fetchColumn()) prefaError(404, 'Demande introuvable ou inaccessible.');

} elseif ($isAdmin) {
    prefaError(403, 'Un administrateur ne peut pas créer de demande de préfabrication.');
}

// Champs à donner aux code sql aprés
$values = [];
// La demande se rattache à une affaire déjà ouverte : c'est le chargé de production qui
// ouvre le chantier et désigne qui le suit. On vérifie que celle-ci est bien accessible à
// qui dépose — un identifiant se change dans le formulaire, et rien n'empêcherait sinon de
// déposer sur le chantier d'un collègue.
$idAffaire = filter_var($_POST['id_affaire'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$idAffaire) prefaError(400, 'Choisissez l’affaire à laquelle rattacher cette demande.');

$affaire = Affaire::accessible($db, $utilisateur, $idAffaire);
if (!$affaire) prefaError(403, 'Cette affaire ne vous est pas confiée.');

$values['id_affaire'] = $affaire->id;
// Le nom reste recopié sur la demande : c'est lui qui s'affiche dans les listes et dans les
// références, sans jointure. Renommer l'affaire met ses demandes à jour.
$values['nom_affaire'] = $affaire->nom;

$availability = $_POST['matiere_disponibilite'] ?? '';
$material = $_POST['id_matiere'] ?? '';
if (!is_string($material) || !is_string($availability) || !in_array($availability, ['', 'stock', 'commande'], true)) {
    prefaError(400, 'Choisissez une matière et une disponibilité valides.');
}
if ($material === '' && $availability !== '') prefaError(400, 'Choisissez une matière avant sa disponibilité.');
if ($material !== '' && $availability === '') prefaError(400, 'Précisez si la matière est en stock ou à commander.');
if (isset($_POST['matiere_autre']) && !is_string($_POST['matiere_autre'])) prefaError(400, 'Veuillez préciser une matière valide.');
$values['matiere_disponibilite'] = $availability === '' ? null : $availability;

$values['plan_bpe_iso'] = $editing ? (string) $db->query('SELECT plan_bpe_iso FROM demande_prefabrication WHERE id = ' . (int) $requestId)->fetchColumn() : '';

$endDate = (string) ($_POST['date_fin_prevue'] ?? '');
$parsedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
if (!$parsedEnd || $parsedEnd->format('Y-m-d') !== $endDate) prefaError(400, 'Choisissez une date de fin valide.');
$values['date_fin_prevue'] = $endDate;

$deliveryDate = (string) ($_POST['date_livraison_prevue'] ?? '');
$parsedDelivery = DateTimeImmutable::createFromFormat('!Y-m-d', $deliveryDate);
if (!$parsedDelivery || $parsedDelivery->format('Y-m-d') !== $deliveryDate) prefaError(400, 'Choisissez une date de livraison valide.');
$values['date_livraison_prevue'] = $deliveryDate;

if ($endDate > $deliveryDate) {
    prefaError(400, 'La date de fin doit être avant ou le jour de la livraison.');
}

$uploads = $_FILES['plan_documents'] ?? null;
$documents = [];
if ($uploads && is_array($uploads['name'])) {
    $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx', 'xls', 'xlsx'];
    foreach ($uploads['name'] as $index => $rawName) {
        if ($uploads['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
        if ($uploads['error'][$index] !== UPLOAD_ERR_OK || $uploads['size'][$index] > 50 * 1024 * 1024 || !is_uploaded_file($uploads['tmp_name'][$index])) prefaError(400, 'Chaque document doit faire au maximum 50 Mo.');
        $name = basename(str_replace('\\', '/', (string) $rawName));
        $name = substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $name), 0, 180);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) prefaError(400, 'Formats acceptés : PDF, images, Word et Excel.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploads['tmp_name'][$index]);
        $validMime = [
            'pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ];
        if (!in_array($mime, $validMime[$extension], true)) prefaError(400, 'Le type du document ne correspond pas à son extension.');
        $documents[] = ['tmp' => $uploads['tmp_name'][$index], 'name' => $name];
    }
}
if (!$editing && !$documents) prefaError(400, 'Ajoutez au moins un document du plan BPE / ISO.');

$removeKeys = $_POST['remove_documents'] ?? [];
if (!is_array($removeKeys) || (!$editing && $removeKeys)) prefaError(400, 'Liste des documents invalide.');
$savedAttachments = $editing ? prefaAttachments($requestId) : [];
$availableKeys = array_column($savedAttachments, 'key');
foreach ($removeKeys as $key) {
    if (!is_string($key) || !in_array($key, $availableKeys, true)) prefaError(400, 'Document à retirer introuvable.');
}
$removeKeys = array_unique($removeKeys);

// Vérifier si pouces_total_iso et position ou null
$value = filter_var(
    $_POST['pouces_total_iso'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 2147483647]]
);

if ($value === false || $value === null) {
    prefaError(400, 'Le total des pouces doit être un nombre entier positif ou nul.');
}

$values['pouces_total_iso'] = $value;

$values['heures_chiffrees'] = prefaCalculatedHours($values['pouces_total_iso'], prefaHoursPerInch($db));

$values['CDN']    = !empty($_POST['CDN']) ? 1 : 0;
foreach (['RT', 'PT'] as $method) {
    $values[$method] = !empty($_POST[$method]) ? 1 : 0;
    $key = 'controles_' . strtolower($method);
    $values[$key] = null;
    if ($values[$method]) {
        $percentage = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($percentage === false || $percentage === null) prefaError(400, 'Le contrôle ' . $method . ' doit être compris entre 1 et 100 %.');
        $values[$key] = (string) $percentage;
    }
}
$values['urgent'] = !empty($_POST['urgent']) ? 1 : 0;
$revetementType = trim((string) ($_POST['revetement_type'] ?? ''));
$revetementLabels = [
    'exterieur' => 'Revêtement extérieur',
    'interieur' => 'Revêtement intérieur',
    'les_deux' => 'Revêtement intérieur et extérieur',
];

if (isset($revetementLabels[$revetementType])) {
    $values['revetement'] = 1;
    $precision = trim((string) ($_POST['revetement_precision'] ?? ''));
    if (mb_strlen($precision) > 900) {
        prefaError(400, 'La précision du revêtement ne peut pas dépasser 900 caractères.');
    }
    $values['commentaire_revetement'] = $precision !== ''
        ? $revetementLabels[$revetementType] . ' — ' . $precision
        : $revetementLabels[$revetementType];
} elseif (!empty($_POST['revetement'])) {
    // Backwards compatibility with previous checkbox
    $values['revetement'] = 1;
    $comm = trim((string) ($_POST['commentaire_revetement'] ?? ''));
    $values['commentaire_revetement'] = $comm !== '' ? $comm : 'Revêtement demandé';
} else {
    $values['revetement'] = 0;
    $values['commentaire_revetement'] = null;
}
if ($values['CDN']) {
    $percentage = filter_var($_POST['controles_cdn'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($percentage === false || $percentage === null) prefaError(400, 'Le contrôle CND doit être compris entre 1 et 100 %.');
    $values['controles_cdn'] = (string) $percentage;
} else {
    $values['controles_cdn'] = null;
}

// On récupérer le id
$values['idUsers'] = $actor['id'];
$values['QMOS'] = ''; // Initialiser le chemin du QMOS

// Lors de l'edition on récupére les informations du qmos
if ($editing) {
    $legacy = $db->prepare('SELECT ` QMOS` FROM demande_prefabrication WHERE id = ?');
    $legacy->execute([$requestId]);
    $values['QMOS'] = (string) $legacy->fetchColumn();
}

$qmosPdfs = prefaPdfUploads($_FILES['qmos_pdf'] ?? null, 'QMOS');
$dmosPdfs = prefaPdfUploads($_FILES['dmos_pdf'] ?? null, 'DMOS');
$materielPdfs = prefaPdfUploads($_FILES['materiel_pdf'] ?? null, 'fiche matériel');
$ccpuPdfs = prefaPdfUploads($_FILES['ccpu_pdf'] ?? null, 'CCPU');

$removeQmosIds = $_POST['remove_qmos'] ?? [];
if (!is_array($removeQmosIds) || (!$editing && $removeQmosIds)) prefaError(400, 'Liste des QMOS invalide.');
$existingQmos = [];
if ($editing) {
    $qmosQuery = $db->prepare('SELECT id FROM demande_qmos_documents WHERE id_demande = ?');
    $qmosQuery->execute([$requestId]);
    $existingQmos = $qmosQuery->fetchAll();
}
$existingQmosById = array_column($existingQmos, 'id', 'id');
foreach ($removeQmosIds as $id) {
    if (!is_string($id) || !ctype_digit($id) || !array_key_exists((int) $id, $existingQmosById)) prefaError(400, 'QMOS à retirer introuvable.');
}
$removeQmosIds = array_unique(array_map('intval', $removeQmosIds));
$removeDmosIds = $_POST['remove_dmos'] ?? [];
if (!is_array($removeDmosIds) || (!$editing && $removeDmosIds)) prefaError(400, 'Liste des DMOS invalide.');
$existingDmos = [];
if ($editing) {
    $dmosQuery = $db->prepare('SELECT id FROM demande_dmos_documents WHERE id_demande = ?');
    $dmosQuery->execute([$requestId]);
    $existingDmos = $dmosQuery->fetchAll();
}
$existingDmosById = array_column($existingDmos, 'id', 'id');
foreach ($removeDmosIds as $id) {
    if (!is_string($id) || !ctype_digit($id) || !array_key_exists((int) $id, $existingDmosById)) prefaError(400, 'DMOS à retirer introuvable.');
}
$removeDmosIds = array_unique(array_map('intval', $removeDmosIds));
$removeMaterielIds = $_POST['remove_materiel'] ?? [];
if (!is_array($removeMaterielIds) || (!$editing && $removeMaterielIds)) prefaError(400, 'Liste des fiches matériel invalide.');
$existingMateriel = [];
if ($editing) {
    $materielQuery = $db->prepare('SELECT id FROM demande_materiel_documents WHERE id_demande = ?');
    $materielQuery->execute([$requestId]);
    $existingMateriel = $materielQuery->fetchAll();
}
$existingMaterielById = array_column($existingMateriel, 'id', 'id');
foreach ($removeMaterielIds as $id) {
    if (!is_string($id) || !ctype_digit($id) || !array_key_exists((int) $id, $existingMaterielById)) prefaError(400, 'Fiche matériel à retirer introuvable.');
}
$removeMaterielIds = array_unique(array_map('intval', $removeMaterielIds));
$removeCcpuIds = $_POST['remove_ccpu'] ?? [];
if (!is_array($removeCcpuIds) || (!$editing && $removeCcpuIds)) prefaError(400, 'Liste des CCPU invalide.');
$existingCcpu = [];
if ($editing) {
    $ccpuQuery = $db->prepare('SELECT id FROM demande_ccpu_documents WHERE id_demande = ?');
    $ccpuQuery->execute([$requestId]);
    $existingCcpu = $ccpuQuery->fetchAll();
}
$existingCcpuById = array_column($existingCcpu, 'id', 'id');
foreach ($removeCcpuIds as $id) {
    if (!is_string($id) || !ctype_digit($id) || !array_key_exists((int) $id, $existingCcpuById)) prefaError(400, 'CCPU à retirer introuvable.');
}
$removeCcpuIds = array_unique(array_map('intval', $removeCcpuIds));
$savedFiles = [];
$pendingRemoval = [];
try {
    $passivation = $_POST['id_passivation'] ?? '';
    $values['id_passivation'] = null;

    if ($passivation !== '') {
        if ($passivation === 'autre') {
            $autreLabel = trim((string) ($_POST['passivation_autre'] ?? ''));
            if ($autreLabel === '' || mb_strlen($autreLabel) > 100) {
                prefaError(400, 'Veuillez préciser le type de passivation (1 à 100 caractères).');
            }
            $stmt = $db->prepare('SELECT id FROM type_passivation WHERE LOWER(libelle) = LOWER(?) LIMIT 1');
            $stmt->execute([$autreLabel]);
            $existingId = $stmt->fetchColumn();
            if ($existingId !== false) {
                $values['id_passivation'] = (int) $existingId;
            } else {
                $ins = $db->prepare('INSERT INTO type_passivation (libelle) VALUES (?)');
                $ins->execute([$autreLabel]);
                $values['id_passivation'] = (int) $db->lastInsertId();
            }
        } else {
            $id = filter_var($passivation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) prefaError(400, 'Choisissez un type de passivation valide.');
            $check = $db->prepare('SELECT id FROM type_passivation WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) prefaError(400, 'Ce type de passivation n’existe plus. Rechargez le formulaire.');
            $values['id_passivation'] = $id;
        }
    }

    $matiere = $_POST['id_matiere'] ?? '';
    $values['id_matiere'] = null;

    if ($matiere !== '') {
        if ($matiere === 'autre') {
            $autreLabel = trim((string) ($_POST['matiere_autre'] ?? ''));
            if ($autreLabel === '' || mb_strlen($autreLabel) > 100) {
                prefaError(400, 'Veuillez préciser la matière (1 à 100 caractères).');
            }
            $stmt = $db->prepare('SELECT id FROM type_matiere WHERE LOWER(libelle) = LOWER(?) LIMIT 1');
            $stmt->execute([$autreLabel]);
            $existingId = $stmt->fetchColumn();
            if ($existingId !== false) {
                $values['id_matiere'] = (int) $existingId;
            } else {
                $ins = $db->prepare('INSERT INTO type_matiere (libelle) VALUES (?)');
                $ins->execute([$autreLabel]);
                $values['id_matiere'] = (int) $db->lastInsertId();
            }
        } else {
            $id = filter_var($matiere, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) prefaError(400, 'Choisissez une matière valide.');
            $check = $db->prepare('SELECT id FROM type_matiere WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) prefaError(400, 'Cette matière n’existe plus. Rechargez le formulaire.');
            $values['id_matiere'] = $id;
        }
    }

    if ($editing) {
        unset($values['idUsers']);
        $values['id'] = $requestId;
        if (!$isAdmin) $values['owner'] = $actor['id'];
        

        $stmt = $db->prepare('UPDATE demande_prefabrication SET
            nom_affaire = :nom_affaire, id_affaire = :id_affaire, plan_bpe_iso = :plan_bpe_iso, date_fin_prevue = :date_fin_prevue, date_livraison_prevue = :date_livraison_prevue,
            pouces_total_iso = :pouces_total_iso, heures_chiffrees = :heures_chiffrees,
            CDN = :CDN, ` QMOS` = :QMOS, urgent = :urgent, controles_cdn = :controles_cdn,
            revetement = :revetement, commentaire_revetement = :commentaire_revetement,
            RT = :RT, PT = :PT, controles_rt = :controles_rt, controles_pt = :controles_pt, id_matiere = :id_matiere, matiere_disponibilite = :matiere_disponibilite, id_passivation = :id_passivation, DateUpdate = NOW(), id_statut = 1,
            commentaire_validation = NULL, valide_par = NULL, date_validation = NULL,
            date_debut_planifiee = NULL, date_fin_planifiee = NULL, planning_revision = planning_revision + 1
            WHERE id = :id' . ($isAdmin ? '' : ' AND idUsers = :owner'));

    } else {
        $stmt = $db->prepare('INSERT INTO demande_prefabrication
        (idUsers, nom_affaire, id_affaire, DateUpdate, date_creation, date_fin_prevue, date_livraison_prevue, plan_bpe_iso, pouces_total_iso, heures_chiffrees, CDN, ` QMOS`, urgent, controles_cdn, revetement, commentaire_revetement, RT, PT, controles_rt, controles_pt, id_statut, id_passivation, id_matiere, matiere_disponibilite)
        VALUES (:idUsers, :nom_affaire, :id_affaire, NOW(), NOW(), :date_fin_prevue, :date_livraison_prevue, :plan_bpe_iso, :pouces_total_iso, :heures_chiffrees, :CDN, :QMOS, :urgent, :controles_cdn, :revetement, :commentaire_revetement, :RT, :PT, :controles_rt, :controles_pt, 1, :id_passivation, :id_matiere, :matiere_disponibilite)');
    }

    $db->beginTransaction();
    $stmt->execute($values);
    $savedId = $editing ? $requestId : (int) $db->lastInsertId();
    if (!$editing) {
        $created = $db->prepare('SELECT date_creation FROM demande_prefabrication WHERE id = ?');
        $created->execute([$savedId]);
        $date = new DateTimeImmutable($created->fetchColumn(), new DateTimeZone('UTC'));
        prefaAssignReference($db, $savedId, (int) $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y'));
    }

    if ($documents) {
        $directory = prefaAttachmentDirectory($savedId);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Impossible de créer le dossier uploads.', 507);
        foreach ($documents as $document) {
            $path = $directory . '/' . bin2hex(random_bytes(16)) . '-' . $document['name'];
            if (!@move_uploaded_file($document['tmp'], $path)) throw new RuntimeException('Impossible de déposer le document.', 507);
            $savedFiles[] = $path;
        }
    }

    foreach ($removeKeys as $key) {
        $path = prefaAttachmentDirectory($savedId) . '/' . $key;
        $pendingPath = prefaAttachmentDirectory($savedId) . '/.delete-' . bin2hex(random_bytes(16));
        if (!rename($path, $pendingPath)) throw new RuntimeException('Impossible de retirer le document.');
        $pendingRemoval[$path] = $pendingPath;
    }

    if ($removeQmosIds) {
        $file = $db->prepare('DELETE FROM demande_qmos_documents WHERE id_demande = ? AND id = ?');
        foreach ($removeQmosIds as $id) $file->execute([$savedId, $id]);
    }
    if ($qmosPdfs) {
        $file = $db->prepare('INSERT INTO demande_qmos_documents (id_demande, nom, contenu, sha256) VALUES (?, ?, ?, UNHEX(?))');
        foreach ($qmosPdfs as $document) $file->execute([$savedId, $document['name'], $document['content'], $document['digest']]);
    }
    if ($removeDmosIds) {
        $file = $db->prepare('DELETE FROM demande_dmos_documents WHERE id_demande = ? AND id = ?');
        foreach ($removeDmosIds as $id) $file->execute([$savedId, $id]);
    }
    if ($dmosPdfs) {
        $file = $db->prepare('INSERT INTO demande_dmos_documents (id_demande, nom, contenu, sha256) VALUES (?, ?, ?, UNHEX(?))');
        foreach ($dmosPdfs as $document) $file->execute([$savedId, $document['name'], $document['content'], $document['digest']]);
    }
    if ($removeMaterielIds) {
        $file = $db->prepare('DELETE FROM demande_materiel_documents WHERE id_demande = ? AND id = ?');
        foreach ($removeMaterielIds as $id) $file->execute([$savedId, $id]);
    }
    if ($materielPdfs) {
        $file = $db->prepare('INSERT INTO demande_materiel_documents (id_demande, nom, contenu, sha256) VALUES (?, ?, ?, UNHEX(?))');
        foreach ($materielPdfs as $document) $file->execute([$savedId, $document['name'], $document['content'], $document['digest']]);
    }
    // Les coulées lues sur un certificat retiré partent avec lui, par cascade.
    if ($removeCcpuIds) {
        $file = $db->prepare('DELETE FROM demande_ccpu_documents WHERE id_demande = ? AND id = ?');
        foreach ($removeCcpuIds as $id) $file->execute([$savedId, $id]);
    }
    // Le certificat est seulement rangé ici : sa lecture dure plusieurs secondes par
    // page scannée et se fait à l'ouverture de la fiche, pas pendant l'enregistrement.
    if ($ccpuPdfs) {
        $file = $db->prepare('INSERT INTO demande_ccpu_documents (id_demande, nom, contenu, sha256) VALUES (?, ?, ?, UNHEX(?))');
        foreach ($ccpuPdfs as $document) $file->execute([$savedId, $document['name'], $document['content'], $document['digest']]);
    }

    $db->commit();

    // Après le commit, jamais avant : une annonce pour une demande que la transaction
    // aurait annulée vaut moins que pas d'annonce du tout. Une modification n'en est pas
    // une nouvelle — on ne prévient que du dépôt.
    if (!$editing) {
        Evenement::noter($db, Evenement::DEMANDE_DEPOSEE, $savedId, $utilisateur->id, [
            'affaire' => (string) ($values['nom_affaire'] ?? ''),
            'urgente' => (bool) ($values['urgent'] ?? false),
        ]);
    }

    foreach ($pendingRemoval as $pendingPath) unlink($pendingPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $editing ? 'Demande modifiée. Elle est en attente de validation.' : 'Demande envoyée. Elle est en attente de validation.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($savedFiles as $path) unlink($path);
    foreach ($pendingRemoval as $path => $pendingPath) rename($pendingPath, $path);
    error_log('Création demande de préfabrication : ' . $e->getMessage());
    $message = 'Impossible d’enregistrer la demande. Vérifiez les champs et réessayez.';
    if ($e instanceof RuntimeException && $e->getCode() === 507) {
        $message = 'Le serveur ne peut pas stocker les documents joints. Veuillez réessayer après rétablissement du stockage.';
    }
    if ($e instanceof PDOException && str_contains($e->getMessage(), 'Unknown column')) {
        $message = 'La base de données n’est pas à jour. Appliquez la migration Revêtement puis réessayez.';
    }
    prefaError(500, $message);
}
