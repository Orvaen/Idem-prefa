<?php
/**
 * Les plans et pièces jointes d'une demande, rangés sur le disque.
 *
 * Eux seuls ne sont pas en base : ils sont gros, et servis tels quels. Un fichier s'appelle
 * « <32 caractères au hasard>-<son nom> » — le préfixe évite qu'un second dépôt du même nom
 * écrase le premier, et le nom reste lisible dans le dossier.
 *
 * Ces fichiers vivent sous la racine web mais ne sont jamais servis directement : Apache
 * refuse le dossier, et c'est actions/download_prefa_attachment.php qui les rend, après
 * avoir vérifié la session et l'appartenance de la demande.
 */

function prefaAttachmentDirectory(int $id): string
{
    return dirname(__DIR__) . '/uploads/prefa/' . $id;
}

function prefaAttachments(int $id): array
{
    $directory = prefaAttachmentDirectory($id);
    if (!is_dir($directory)) return [];
    $files = [];
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || !preg_match('/^[a-f0-9]{32}-(.+)$/', $file->getFilename(), $match)) continue;
        $files[] = ['key' => $file->getFilename(), 'name' => $match[1]];
    }
    return $files;
}
