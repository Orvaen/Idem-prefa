<?php
/**
 * Un PDF déposé par un formulaire, vérifié avant d'entrer en base.
 *
 * Trois contrôles, et il faut les trois : la taille, le type réel du fichier lu par finfo,
 * et les cinq premiers octets — « %PDF- ». L'extension et le type annoncé par le navigateur
 * ne sont pas des preuves : ils viennent de celui qui dépose.
 *
 * Le nom est ramené à son dernier segment : un « ../ » dans un nom de fichier ne doit pas
 * désigner un dossier voisin.
 */

function prefaPdfUpload(?array $upload): ?array
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 50 * 1024 * 1024) prefaError(400, 'Chaque PDF QMOS ou DMOS doit faire au maximum 50 Mo.');
    if (!is_uploaded_file($upload['tmp_name']) || (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) !== 'application/pdf') prefaError(400, 'Choisissez un fichier PDF valide.');
    $content = file_get_contents($upload['tmp_name']);
    if ($content === false || !str_starts_with($content, '%PDF-')) prefaError(400, 'Choisissez un fichier PDF valide.');
    $name = mb_substr(basename(str_replace('\\', '/', (string) $upload['name'])), 0, 255);
    return ['name' => $name, 'content' => $content, 'digest' => hash('sha256', $content)];
}

function prefaPdfUploads(?array $uploads, string $kind = 'QMOS', ?int $maxFiles = null): array
{
    if (!$uploads) return [];
    if (!isset($uploads['name']) || !is_array($uploads['name'])) prefaError(400, 'Liste de PDF ' . $kind . ' invalide.');
    if ($maxFiles !== null && count($uploads['name']) > $maxFiles) prefaError(400, 'Ajoutez au maximum ' . $maxFiles . ' PDF ' . $kind . ' à la fois.');
    $documents = [];
    foreach ($uploads['name'] as $index => $name) {
        $document = prefaPdfUpload([
            'name' => $name,
            'tmp_name' => $uploads['tmp_name'][$index] ?? '',
            'size' => $uploads['size'][$index] ?? 0,
            'error' => $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        ]);
        if ($document !== null) $documents[] = $document;
    }
    return $documents;
}
