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
    // Deux crans : le fichier vit dans includes/demande/, le dossier des dépôts est
    // à côté de includes/, pas dedans.
    return dirname(__DIR__, 2) . '/uploads/prefa/' . $id;
}

/**
 * Le nom sous lequel un fichier se range : des lettres sans accent, des chiffres, « . », « _ »
 * et « - ». C'est ce que vérifient tous ceux qui le servent ou le rattachent (le
 * téléchargement, le rattachement à un plan, la fenêtre du plan) : un nom qui en sort ne
 * passe nulle part. Les accents deviennent leur lettre, le reste un « _ » — « CAHIER ISO.pdf »
 * devient « CAHIER_ISO.pdf ».
 */
function prefaNomDeFichier(string $nom): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom) : false;
    $nom = preg_replace('/[^A-Za-z0-9._-]+/', '_', is_string($ascii) && $ascii !== '' ? $ascii : $nom);
    $nom = trim((string) $nom, '_');
    return substr($nom !== '' && $nom !== '.' && $nom !== '..' ? $nom : 'document.pdf', 0, 180);
}

/**
 * Les fichiers de la demande.
 *
 * Un fichier dont le nom sort des caractères permis — déposé depuis la fiche avant le
 * 6 novembre 2026, qui gardait le nom tel quel : « CAHIER ISO.pdf », avec son espace — se
 * listait, mais ne se rattachait à aucun plan (« Ce document n'appartient pas à la
 * demande ») et ne s'ouvrait pas. Il est renommé ici, une fois, sous son nom permis. Rien ne
 * le désignait encore : son ancien nom ne passait nulle part.
 */
function prefaAttachments(int $id): array
{
    $directory = prefaAttachmentDirectory($id);
    if (!is_dir($directory)) return [];
    $files = [];
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || !preg_match('/^([a-f0-9]{32})-(.+)$/', $file->getFilename(), $match)) continue;
        $key = $file->getFilename();
        $name = $match[2];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            $permis = prefaNomDeFichier($name);
            $nouvelle = $match[1] . '-' . $permis;
            if (!file_exists($directory . '/' . $nouvelle) && @rename($directory . '/' . $key, $directory . '/' . $nouvelle)) {
                [$key, $name] = [$nouvelle, $permis];
            }
        }
        $files[] = ['key' => $key, 'name' => $name];
    }
    return $files;
}
