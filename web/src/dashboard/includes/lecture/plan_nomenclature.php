<?php

require_once __DIR__ . '/../demande/prefa_attachments.php';
require_once __DIR__ . '/plan_table.php';
require_once __DIR__ . '/plan_reperes.php';

/**
 * La nomenclature de matériel d'une feuille, lue sur le dessin : où est chaque ligne, et ce
 * qu'elle dit — repère, quantité, DN, épaisseur, désignation.
 *
 * Le soudeur signale un manque en cliquant, sur le dessin, la ligne du matériel qui lui
 * manque : celle qu'il lit sur son tirage. Il faut donc savoir où sont les lignes —
 * plan_table.php trouve déjà la grille, pour poser les coulées — et ce qu'elles disent.
 * AutoCAD Plant 3D trace ce texte en courbes, pdftotext n'y trouve rien : on le lit à l'OCR,
 * en local (Tesseract, dans l'image), et seulement dans les cases du tableau.
 *
 * Trois précautions font la lecture. Le quadrillage est effacé : Tesseract prenait chaque
 * trait pour un « | », un « [ » ou un « J » collé au mot voisin — « JCOLLERETTE ». Les
 * colonnes sont écartées d'un blanc : sans trait entre elles, le repère « 1 » et la quantité
 * « 2.0M » ne faisaient plus qu'un mot, « 12.0M ». Et les chiffres se lisent à part, sans
 * lettres possibles (PLAN_NOMENCLATURE_LECTURES).
 *
 * Mesuré sur un cahier de quinze feuilles : toutes les cases justes, une seconde par page.
 * Ce qui est lu reste une proposition : la fenêtre « Signaler un manque » la montre, et le
 * soudeur la corrige au besoin. Rien ne part sans lui.
 */

/**
 * À incrémenter dès que la lecture rend autre chose : le cache de l'ancienne serait resservi.
 * 2 : la lecture rend aussi les bords de la colonne « N° COULÉE » (6 novembre 2026).
 */
const PLAN_NOMENCLATURE_VERSION = 2;

/** La résolution de la lecture : assez pour des capitales de deux millimètres. */
const PLAN_NOMENCLATURE_DPI = 400;

/** Le blanc posé entre deux colonnes recomposées, en pixels de lecture. */
const PLAN_NOMENCLATURE_ECART = 60;

/**
 * Les colonnes lues, dans l'ordre du tableau. « N° COULÉE », la dernière, ne se lit pas : on
 * y écrit — l'encadrement dans sa visionneuse, le soudeur en touchant la case (ses bords
 * sont rendus dans « coulee »).
 */
const PLAN_NOMENCLATURE_COLONNES = ['repere', 'quantite', 'dn', 'ep', 'description'];

/**
 * Les deux lectures d'une nomenclature, chacune sur ses colonnes et avec ses caractères.
 *
 * Les chiffres à part, avec pour seules lettres le X d'une dimension et le M d'une longueur :
 * lue d'un bloc avec le texte, la police du plan faisait de « 3.3M » « 315M », et de « 5D »
 * « SD ». La désignation ensuite, en capitales, avec le degré d'un coude (« 90° »).
 */
const PLAN_NOMENCLATURE_LECTURES = [
    ['colonnes' => ['repere', 'quantite', 'dn', 'ep'], 'signes' => '0123456789.,XM'],
    ['colonnes' => ['description'], 'signes' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-.,/()° '],
];

/** Le cache de la nomenclature d'une page, à côté de celui des repères (planReperesCache()). */
function planNomenclatureCache(int $requestId, string $cle, int $page): string
{
    return prefaAttachmentDirectory($requestId) . '/nomenclature-' . substr($cle, 0, 32) . '-p' . $page . '.json';
}

/**
 * La nomenclature d'une page, lue une fois puis gardée. Refaite si le plan a changé.
 *
 * @return array{gauche:float, droite:float, coulee: ?array{gauche:float, droite:float},
 *               lignes: list<array{y:float, h:float, repere:string,
 *               quantite:string, dn:string, ep:string, description:string}>}|null
 *         en fractions de l'image, comme les cases de plan_table.php ; null si la page n'a
 *         pas de nomenclature reconnue
 */
function planNomenclatureAnalyse(string $source, string $cache, int $page): ?array
{
    if (is_file($cache) && is_file($source) && filemtime($cache) >= filemtime($source)) {
        $garde = json_decode((string) file_get_contents($cache), true);
        if (is_array($garde) && ($garde['version'] ?? 0) === PLAN_NOMENCLATURE_VERSION) return $garde['nomenclature'];
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $nomenclature = planNomenclatureLire($source, $page, $complete);
    // Une lecture interrompue sert à cette fois, mais n'est pas gardée : elle serait
    // resservie telle quelle jusqu'au prochain dépôt du plan.
    if ($complete) {
        @file_put_contents($cache, json_encode(['version' => PLAN_NOMENCLATURE_VERSION, 'nomenclature' => $nomenclature]));
    }
    return $nomenclature;
}

/**
 * La nomenclature d'une page si elle est déjà lue, sans jamais la lire : pour ce qui
 * parcourt toutes les pages d'un plan (atelier/plan_resume.php), où un OCR par page ferait
 * attendre une minute.
 *
 * @return array{nomenclature: ?array}|null null si la page n'est pas encore lue ; sinon sa
 *         nomenclature, elle-même null quand aucune n'a été reconnue
 */
function planNomenclatureGardee(string $source, string $cache): ?array
{
    if (!is_file($cache) || !is_file($source) || filemtime($cache) < filemtime($source)) return null;
    $garde = json_decode((string) file_get_contents($cache), true);
    // Une lecture d'une version précédente vaut encore ici : ses lignes sont les mêmes, seuls
    // les bords de la colonne des coulées lui manquent, et ce résumé ne s'en sert pas.
    return is_array($garde) && ($garde['version'] ?? 0) >= 1
        ? ['nomenclature' => $garde['nomenclature'] ?? null] : null;
}

/**
 * Trouve les lignes de la nomenclature, puis lit leurs cases.
 *
 * Sans texte — l'OCR a échoué —, les lignes restent : le soudeur les clique et écrit
 * l'article lui-même.
 *
 * @param bool|null $complete faux quand un rendu ou l'OCR a échoué en route
 */
function planNomenclatureLire(string $pdf, int $page, ?bool &$complete = null): ?array
{
    $complete = false;
    $image = planReperesImage($pdf, $page, PLAN_TABLE_DPI);
    if ($image === null) return null;
    $complete = true;
    $grille = planTableNomenclatureGrille($image);
    if ($grille === null) return null;

    // Les traits des lignes de matériel : de la rangée 2, sous l'en-tête, jusqu'au bas.
    $traits = array_slice($grille['rangees'], 2);
    if (count($traits) < 2) return null;
    $textes = planNomenclatureTextes($pdf, $page, $grille);
    if ($textes === null) $complete = false;

    $lignes = [];
    for ($rang = 0; $rang < count($traits) - 1; $rang++) {
        $cellules = $textes[$rang] ?? [];
        $repere = $cellules['repere'] ?? '';
        $y1 = $traits[$rang]['y'];
        $y2 = $traits[$rang + 1]['y'];
        $lignes[] = [
            'y' => $y1 / $image['h'],
            'h' => ($y2 - $y1) / $image['h'],
            // Le repère imprimé s'il se lit ; sinon son rang, que la nomenclature suit.
            'repere' => ctype_digit($repere) ? $repere : (string) ($rang + 1),
            'quantite' => $cellules['quantite'] ?? '',
            'dn' => $cellules['dn'] ?? '',
            'ep' => $cellules['ep'] ?? '',
            'description' => $cellules['description'] ?? '',
        ];
    }
    // « N° COULÉE », la dernière colonne : entre l'avant-dernier séparateur et le bord droit,
    // là même où se posent les cases de coulée (plan_table.php). Le soudeur la touche pour
    // choisir la coulée de la ligne ; le reste de la ligne signale un manque. Trop étroite
    // pour être une vraie colonne, elle n'est pas rendue : la ligne entière signale.
    $colonnes = $grille['colonnes'];
    $xCoulee = $colonnes[count($colonnes) - 2] ?? null;
    $coulee = $xCoulee !== null && $grille['droite'] - $xCoulee >= $image['l'] * 0.02
        ? ['gauche' => $xCoulee / $image['l'], 'droite' => $grille['droite'] / $image['l']]
        : null;

    return [
        'gauche' => $grille['gauche'] / $image['l'],
        'droite' => $grille['droite'] / $image['l'],
        'coulee' => $coulee,
        'lignes' => $lignes,
    ];
}

/**
 * Le texte de chaque case, ligne par ligne : le corps du tableau rendu en gris une fois,
 * puis, pour chaque lecture, recomposé sans quadrillage et colonnes écartées.
 *
 * @param array{rangees: list<array>, gauche:int, droite:int, colonnes: list<float>} $grille
 *        celle de planTableNomenclatureGrille(), en pixels à PLAN_TABLE_DPI
 * @return list<array<string, string>>|null une entrée par ligne de matériel, les cases par
 *         nom de colonne ; null si le rendu ou l'OCR a échoué
 */
function planNomenclatureTextes(string $pdf, int $page, array $grille): ?array
{
    $echelle = PLAN_NOMENCLATURE_DPI / PLAN_TABLE_DPI;
    $traits = array_slice($grille['rangees'], 2);
    $x0 = (int) floor($grille['gauche'] * $echelle);
    $y0 = (int) floor($traits[0]['y'] * $echelle);
    $morceau = planNomenclatureMorceau($pdf, $page, $x0, $y0,
        (int) ceil(($grille['droite'] - $grille['gauche']) * $echelle),
        (int) ceil((end($traits)['y'] - $traits[0]['y']) * $echelle));
    if ($morceau === null) return null;

    // Chaque case rognée un peu à l'intérieur de ses traits : un trait gardé, même d'un
    // pixel, redevient une lettre.
    $marge = (int) ceil(3 * $echelle);
    $colonnes = [];
    $nombre = min(count(PLAN_NOMENCLATURE_COLONNES), count($grille['colonnes']) - 1);
    for ($i = 0; $i < $nombre; $i++) {
        $debut = (int) round($grille['colonnes'][$i] * $echelle) - $x0 + $marge;
        $fin = (int) round($grille['colonnes'][$i + 1] * $echelle) - $x0 - $marge;
        if ($fin > $debut && $fin <= $morceau['l']) $colonnes[PLAN_NOMENCLATURE_COLONNES[$i]] = [$debut, $fin - $debut];
    }
    $lignesY = array_map(static fn (array $trait): int => (int) round($trait['y'] * $echelle) - $y0, $traits);

    $cases = array_fill(0, count($lignesY) - 1, []);
    foreach (PLAN_NOMENCLATURE_LECTURES as $lecture) {
        $lues = array_intersect_key($colonnes, array_flip($lecture['colonnes']));
        if (!$lues) continue;
        $mots = planNomenclatureLecture($morceau, $lues, $lignesY, $marge, $lecture['signes']);
        if ($mots === null) return null;
        foreach ($mots as $mot) $cases[$mot['rang']][$mot['colonne']][] = $mot['texte'];
    }
    return array_map(static fn (array $ligne): array => array_map(
        static fn (array $morceaux): string => planNomenclatureNettoyer(implode(' ', $morceaux)),
        $ligne
    ), $cases);
}

/**
 * Une lecture : les colonnes demandées côte à côte, un blanc avant, entre et après chacune,
 * les traits des lignes repeints en blanc ; puis Tesseract, et chaque mot rendu à sa case.
 *
 * @param array{l:int, h:int, pixels:string} $morceau le corps du tableau, en gris
 * @param array<string, array{0:int, 1:int}> $colonnes nom => début et largeur dans le morceau
 * @param list<int> $lignesY les traits des lignes, du haut de la première au bas
 * @return list<array{rang:int, colonne:string, texte:string}>|null
 */
function planNomenclatureLecture(array $morceau, array $colonnes, array $lignesY, int $marge, string $signes): ?array
{
    ['l' => $largeur, 'h' => $hauteur, 'pixels' => $pixels] = $morceau;
    $blanc = str_repeat("\xFF", PLAN_NOMENCLATURE_ECART);
    $debuts = [];
    $total = PLAN_NOMENCLATURE_ECART;
    foreach ($colonnes as $nom => [, $etendue]) {
        $debuts[$nom] = $total;
        $total += $etendue + PLAN_NOMENCLATURE_ECART;
    }
    $recomposee = '';
    for ($y = 0; $y < $hauteur; $y++) {
        $surUnTrait = false;
        foreach ($lignesY as $trait) {
            if (abs($y - $trait) <= $marge) { $surUnTrait = true; break; }
        }
        $rangee = $blanc;
        foreach ($colonnes as [$debut, $etendue]) {
            $rangee .= ($surUnTrait ? str_repeat("\xFF", $etendue) : substr($pixels, $y * $largeur + $debut, $etendue)) . $blanc;
        }
        $recomposee .= $rangee;
    }

    $mots = planNomenclatureOcr("P5\n{$total} {$hauteur}\n255\n" . $recomposee, $signes);
    return $mots === null ? null : planNomenclatureRanger($mots, $debuts, $lignesY);
}

/**
 * Rend chaque mot lu à sa case : à la ligne dont il est sous le trait du haut, à la colonne
 * dont il a passé le début — moins la moitié du blanc qui la précède. Un mot au-dessus du
 * premier trait ou sous le dernier n'est d'aucune ligne.
 *
 * @param list<array{texte:string, cx:float, cy:float}> $mots
 * @param array<string, int> $debuts colonne => son début dans l'image recomposée
 * @param list<int> $lignesY les traits des lignes, du haut de la première au bas
 * @return list<array{rang:int, colonne:string, texte:string}>
 */
function planNomenclatureRanger(array $mots, array $debuts, array $lignesY): array
{
    $places = [];
    foreach ($mots as $mot) {
        $rang = -1;
        foreach ($lignesY as $i => $trait) {
            if ($mot['cy'] > $trait) $rang = $i;
        }
        if ($rang < 0 || $rang >= count($lignesY) - 1) continue;
        $colonne = array_key_first($debuts);
        foreach ($debuts as $nom => $debut) {
            if ($mot['cx'] >= $debut - PLAN_NOMENCLATURE_ECART / 2) $colonne = $nom;
        }
        $places[] = ['rang' => $rang, 'colonne' => $colonne, 'texte' => $mot['texte']];
    }
    return $places;
}

/**
 * Un morceau de page en gris, à la résolution de lecture.
 *
 * @return array{l:int, h:int, pixels:string}|null un octet par pixel, 255 pour le blanc
 */
function planNomenclatureMorceau(string $pdf, int $page, int $x, int $y, int $largeur, int $hauteur): ?array
{
    $base = tempnam(sys_get_temp_dir(), 'nomenclature');
    if ($base === false) return null;
    @unlink($base);
    try {
        exec(sprintf('pdftoppm -gray -r %d -f %d -l %d -x %d -y %d -W %d -H %d -singlefile %s %s 2>/dev/null',
            PLAN_NOMENCLATURE_DPI, $page, $page, $x, $y, $largeur, $hauteur,
            escapeshellarg($pdf), escapeshellarg($base)), $sortie, $code);
        if ($code !== 0 || !is_file($base . '.pgm')) return null;
        $brut = (string) file_get_contents($base . '.pgm');
    } finally {
        @unlink($base . '.pgm');
    }
    // L'en-tête d'un PGM binaire : « P5 », la largeur, la hauteur, la valeur maximale.
    if (!preg_match('/^P5\s+(\d+)\s+(\d+)\s+(\d+)\s/', $brut, $entete) || (int) $entete[3] !== 255) return null;
    $l = (int) $entete[1];
    $h = (int) $entete[2];
    $pixels = substr($brut, strlen($entete[0]), $l * $h);
    return strlen($pixels) === $l * $h ? ['l' => $l, 'h' => $h, 'pixels' => $pixels] : null;
}

/**
 * Lit une image PGM à Tesseract, et rend ses mots avec leur centre.
 *
 * Les caractères sont bornés à ceux que la colonne peut porter : sans cela, un 0 devenait
 * un O, et un trait oublié une parenthèse.
 *
 * @return list<array{texte:string, cx:float, cy:float}>|null
 */
function planNomenclatureOcr(string $pgm, string $signes): ?array
{
    $base = tempnam(sys_get_temp_dir(), 'nomenclature');
    if ($base === false) return null;
    try {
        if (file_put_contents($base . '.pgm', $pgm) === false) return null;
        exec(sprintf('tesseract %s stdout -l eng --psm 6 -c tessedit_char_whitelist=%s tsv 2>/dev/null',
            escapeshellarg($base . '.pgm'), escapeshellarg($signes)), $sortie, $code);
        if ($code !== 0) return null;
    } finally {
        @unlink($base . '.pgm');
        @unlink($base);
    }

    $mots = [];
    foreach ($sortie as $ligne) {
        $champs = explode("\t", $ligne);
        // Le niveau 5 est le mot ; les autres décrivent les blocs, paragraphes et lignes.
        if (count($champs) < 12 || $champs[0] !== '5' || trim($champs[11]) === '') continue;
        $mots[] = [
            'texte' => trim($champs[11]),
            'cx' => (int) $champs[6] + (int) $champs[8] / 2,
            'cy' => (int) $champs[7] + (int) $champs[9] / 2,
        ];
    }
    return $mots;
}

/** Une case lue : les blancs ramenés à un seul, les signes égarés aux deux bouts retirés. */
function planNomenclatureNettoyer(string $texte): string
{
    return trim((string) preg_replace('/\s+/', ' ', $texte), " -.,/");
}

/**
 * L'article d'une ligne, tel que le manque le nomme : la désignation, puis le DN et
 * l'épaisseur — « BRIDE TOURNANTE EMBOUTIE - 316L DN200 ». Sans eux, le chef ne saurait
 * pas quoi commander : la même bride existe dans toutes les tailles.
 *
 * Vide si la désignation n'a pas été lue : un « DN200 » seul ne nomme rien.
 */
function planNomenclatureDesignation(array $ligne): string
{
    $description = trim((string) ($ligne['description'] ?? ''));
    if ($description === '') return '';
    $morceaux = [$description];
    $dn = trim((string) ($ligne['dn'] ?? ''));
    // Un nombre seul est un diamètre nominal ; « 20X100 » est déjà une dimension — celle
    // de la boulonnerie.
    if ($dn !== '') $morceaux[] = ctype_digit($dn) ? 'DN' . $dn : $dn;
    $ep = trim((string) ($ligne['ep'] ?? ''));
    if ($ep !== '') $morceaux[] = 'EP' . $ep;
    return implode(' ', $morceaux);
}
