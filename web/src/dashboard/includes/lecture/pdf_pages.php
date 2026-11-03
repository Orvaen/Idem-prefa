<?php

require_once __DIR__ . '/commandes_lignes.php';

/**
 * Les pages d'un PDF en images, pour le lecteur de l'application.
 *
 * Un PDF s'ouvrait dans le lecteur du navigateur, posé dans une iframe : une autre barre,
 * d'autres outils — « Dessiner », « Surligner » — que personne ici n'emploie, et qui ne
 * ressemblent à rien d'autre dans l'application. Le serveur rend donc les pages lui-même,
 * comme pour la fenêtre du plan, et le visualiseur les montre (prefa-interactions.js).
 *
 * Trois questions, posées à l'adresse même du document — c'est elle qui vérifie le droit
 * de le lire : `?pages` (combien), `?page=N` (une page à lire), `?vignette=N` (la même, pour
 * le sommaire). Les deux dernières portent les noms du sommaire des documents de PV.
 *
 * Une image est rendue une fois, puis gardée à côté du PDF.
 */

/**
 * La page à lire. 200 dpi tiennent le zoom à 150 % sur un écran réglé à 150 %, l'échelle
 * de la plupart des portables, sans que le texte d'un scan ne bave.
 */
const PDF_PAGES_DPI = 200;

/** La vignette du sommaire : on y reconnaît une page, on n'y lit rien. */
const PDF_PAGES_DPI_VIGNETTE = 30;

/** L'adresse pose-t-elle une des trois questions ? */
function pdfPagesDemandees(array $requete): bool
{
    return isset($requete['pages']) || isset($requete['page']) || isset($requete['vignette']);
}

/**
 * Un document rangé en base, posé sur le disque : Poppler lit un fichier, pas un blob.
 *
 * Le dossier porte l'empreinte du contenu. Ses images ne peuvent donc jamais être celles
 * d'un autre document, et deux dépôts du même PDF les partagent. Le dossier temporaire
 * suffit : c'est un cache, qui se refait si le conteneur repart de zéro.
 */
function pdfPagesDepuisContenu(string $contenu): string
{
    $dossier = sys_get_temp_dir() . '/apercus/' . hash('sha256', $contenu);
    $pdf = $dossier . '/document.pdf';
    if (!is_file($pdf)) {
        if (!is_dir($dossier)) @mkdir($dossier, 0700, true);
        // Écrit à côté puis renommé : une vignette demandée en même temps ne lit jamais un
        // fichier à moitié écrit.
        $provisoire = $pdf . '.' . bin2hex(random_bytes(4));
        file_put_contents($provisoire, $contenu);
        rename($provisoire, $pdf);
    }
    return $pdf;
}

/**
 * L'image d'une page, rendue au premier appel ; null si la page n'existe pas.
 */
function pdfPagesImage(string $pdf, int $page, bool $vignette): ?string
{
    $image = $pdf . ($vignette ? '.vignette-' : '.page-') . $page . '.jpg';
    if (is_file($image)) return $image;

    // Même précaution que pour le PDF : rendue sous un autre nom, puis renommée.
    $provisoire = $image . '.' . bin2hex(random_bytes(4));
    $rendue = ccpuExecuter(['pdftoppm', '-r', (string) ($vignette ? PDF_PAGES_DPI_VIGNETTE : PDF_PAGES_DPI),
        '-jpeg', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $provisoire]);
    if (!$rendue || !is_file($provisoire . '.jpg')) return null;
    rename($provisoire . '.jpg', $image);
    return $image;
}

/**
 * Répond à la question de l'adresse, puis s'arrête : le nombre de pages en JSON, ou
 * l'image d'une page.
 */
function pdfPagesRepondre(string $pdf, array $requete): never
{
    // Le sommaire demande ses vignettes toutes ensemble : la session, verrouillée tant que
    // la réponse n'est pas partie, les ferait passer l'une après l'autre.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if (isset($requete['pages'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['pages' => commandeLignesNombreDePages($pdf)]);
        exit;
    }

    $vignette = isset($requete['vignette']);
    $page = filter_var($vignette ? $requete['vignette'] : $requete['page'], FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 500]]);
    $image = $page ? pdfPagesImage($pdf, $page, $vignette) : null;
    if (!$image) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($image));
    readfile($image);
    exit;
}
