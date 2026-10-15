<?php
// Dépôt d'un plan par le chef d'atelier, directement sur un élément.
//
// Jusqu'ici le chef ne pouvait que cocher un fichier déjà joint à la demande par le
// demandeur. Or c'est lui qui reçoit les plans d'exécution, souvent après la demande,
// et c'est sur eux qu'il pose les numéros de coulée. Il les dépose donc lui-même.
//
// Le fichier rejoint les pièces de la demande, au même endroit et sous la même forme :
// il reste téléchargeable par les voies habituelles, et le lecteur d'aperçu le trouve
// sans traitement particulier.
//
// Le même point d'entrée remplace une feuille : quand la requête en désigne une, le PDF
// prend sa place au lieu de s'ajouter. Tout ce qu'on vérifie du fichier — type, taille,
// PDF réel — vaut dans les deux cas, et il n'y a qu'un chemin à relire.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/lecture/plan_numeros.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';

prefaPost();
if (!$canManageWorkshop) prefaError(403, 'Seul l’encadrement peut déposer un plan.');

$elementId = filter_var($_POST['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Élément invalide.');

$stmt = $db->prepare('SELECT e.id_demande FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE e.id = ? AND d.id_statut = 2');
$stmt->execute([$elementId]);
$requestId = (int) $stmt->fetchColumn();
if (!$requestId) prefaError(404, 'Élément introuvable ou demande non validée.');

// La feuille que ce dépôt remplace, s'il en remplace une.
$remplace = filter_var($_POST['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($remplace) {
    $stmt = $db->prepare("SELECT id FROM element_atelier_document
        WHERE id = ? AND id_element = ? AND type_document = 'plan'");
    $stmt->execute([$remplace, $elementId]);
    if (!$stmt->fetchColumn()) prefaError(404, 'Feuille à remplacer introuvable sur cet élément.');
}

$depot = $_FILES['fichier'] ?? null;
if (!$depot || !is_array($depot) || ($depot['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    prefaError(400, 'Choisissez un plan à déposer.');
}
if ($depot['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($depot['tmp_name'])) {
    prefaError(400, 'Dépôt interrompu. Réessayez.');
}
if ($depot['size'] > 50 * 1024 * 1024) prefaError(400, 'Le plan doit faire au maximum 50 Mo.');

// PDF seulement : c'est ce que l'aperçu sait rasteriser pour y poser les coulées.
$nom = basename(str_replace('\\', '/', (string) $depot['name']));
$nom = substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $nom), 0, 180);
if (strtolower(pathinfo($nom, PATHINFO_EXTENSION)) !== 'pdf') prefaError(400, 'Le plan doit être un PDF.');
if ((new finfo(FILEINFO_MIME_TYPE))->file($depot['tmp_name']) !== 'application/pdf'
    || file_get_contents($depot['tmp_name'], false, null, 0, 5) !== '%PDF-') {
    prefaError(400, 'Ce fichier n’est pas un PDF.');
}

$dossier = prefaAttachmentDirectory($requestId);
if (!is_dir($dossier) && !@mkdir($dossier, 0770, true) && !is_dir($dossier)) {
    prefaError(507, 'Impossible de créer le dossier des pièces jointes.');
}

$cle = bin2hex(random_bytes(16)) . '-' . $nom;
$chemin = $dossier . '/' . $cle;
if (!@move_uploaded_file($depot['tmp_name'], $chemin)) prefaError(507, 'Impossible de déposer le plan.');

// Le nombre de pages est relevé ici, une fois pour toutes : un cahier porte une liste
// soudure par page, et le volet doit pouvoir les afficher sans relire le PDF.
$pages = 0;
exec('pdfinfo ' . escapeshellarg($chemin) . ' 2>/dev/null', $infos);
if (preg_match('/^Pages:\s+(\d+)/m', implode("\n", $infos), $trouve)) $pages = (int) $trouve[1];

// Le numéro de ligne de chaque feuille, au même moment et pour la même raison : c'est
// sous ce nom que l'atelier reconnaît une feuille, et c'est lui que le sommaire affiche.
$numeros = planNumerosDeLigne($chemin);

try {
    // Sauf remplacement demandé, le plan déposé S'AJOUTE à ceux de l'élément.
    //
    // Un élément porte une ligne de tuyauterie, et une ligne tient rarement sur un seul
    // ISO : le chef en dépose un par feuille. Remplacer d'office effaçait le travail fait
    // sur le précédent dès qu'il en déposait un deuxième. Chaque PDF garde maintenant sa
    // nomenclature et sa liste soudure, puisqu'elles sont rattachées à lui.
    $db->beginTransaction();
    // La feuille remplacée s'en va d'abord, et ses lignes de nomenclature et de soudure
    // la suivent (ON DELETE CASCADE) : elles ont été relevées sur ce dessin-là, et leurs
    // positions ne veulent rien dire sur le suivant.
    if ($remplace) $db->prepare('DELETE FROM element_atelier_document WHERE id = ?')->execute([$remplace]);
    $db->prepare("INSERT INTO element_atelier_document (id_element, type_document, cle_document, pages, numeros_ligne)
        VALUES (?, 'plan', ?, ?, ?)")
        ->execute([$elementId, $cle, max(1, $pages), $numeros ? json_encode($numeros) : null]);
    $documentId = (int) $db->lastInsertId();
    $db->commit();
} catch (Throwable $erreur) {
    $db->rollBack();
    @unlink($chemin);
    prefaError(500, $remplace ? 'Remplacement de la feuille impossible.' : 'Enregistrement du plan impossible.');
}

// Un cahier n'est pas un plan comme un autre : les coulées se posent sur sa première
// page, mais chacune de ses pages a sa liste soudure et ressort dans le plan final. Le
// dire, sinon on ne sait pas ce que l'application a compris du fichier.
$message = $remplace ? 'Feuille remplacée par ' . $nom . '.' : 'Plan déposé : ' . $nom . '.';
if ($pages > 1) {
    $message .= ' Ce PDF compte ' . $pages . ' pages : une liste de soudures par page,'
        . ' et les numéros de coulée sur la première.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    // Le lecteur bascule sur le plan qui vient d'arriver : c'est celui qu'on veut voir.
    'document' => $documentId,
    'nom' => $nom,
    'pages' => $pages,
    'message' => $message,
]);
