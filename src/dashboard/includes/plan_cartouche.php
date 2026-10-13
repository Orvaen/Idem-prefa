<?php

/**
 * Où le cartouche du plan porte son intitulé « Numéro de ligne ».
 *
 * Un cahier ISO ne numérote pas ses pages : chaque feuille porte son numéro de ligne —
 * YU-PRE-101, YU-PRE-107A… — et rien ne dit de quelle feuille du cahier elle sort. Le
 * tirage papier se mélange, et on ne sait plus quelle page rouvrir à l'écran. On écrit
 * donc le numéro de page à la place de cet intitulé : la valeur au-dessus dit déjà que
 * c'est un numéro de ligne, et ce mot est le seul endroit perdu du cartouche.
 *
 * Reconnaissance d'image et non lecture du texte : ce mot-là n'est pas du texte dans le
 * PDF. AutoCAD trace les intitulés du cartouche en courbes — pdftotext n'en rend que les
 * valeurs. Seul l'œil peut donc les retrouver, et c'est tesseract qui le remplace.
 *
 * La lecture ne porte que sur la bande du bas : le cartouche d'un isométrique s'y tient
 * par usage, et faire lire une A3 entière coûterait dix fois plus pour reconnaître dix
 * fois plus de bruit.
 */

/** Assez fin pour un intitulé de cartouche, qui fait trois millimètres de haut. */
const PLAN_CARTOUCHE_DPI = 300;

/** La part basse de la feuille où le cartouche se tient. */
const PLAN_CARTOUCHE_BANDE = 0.12;

/** En deçà, la lecture est un hasard : mieux vaut ne rien écrire que d'écrire à côté. */
const PLAN_CARTOUCHE_CONFIANCE = 60.0;

/** L'intitulé cherché, sans accent ni espace — c'est sous cette forme qu'on compare. */
const PLAN_CARTOUCHE_INTITULE = 'numerodeligne';

/**
 * Les fautes tolérées sur les treize lettres de l'intitulé.
 *
 * L'OCR en perd parfois une, ou coupe le mot en deux. Trois reste loin de ce qu'il
 * faudrait pour confondre l'intitulé avec « Nom du projet » ou « Approuvé par ».
 */
const PLAN_CARTOUCHE_FAUTES = 3;

/**
 * Ce qu'il faut blanchir autour du mot, en part de sa hauteur.
 *
 * Une lettre ne s'arrête pas net : elle finit sur un ou deux pixels de gris que la boîte
 * de l'OCR ne compte pas, et qui resteraient en liseré au-dessus du cache. La marge est
 * dix fois plus petite que le blanc qui sépare l'intitulé des traits de sa cellule.
 */
const PLAN_CARTOUCHE_MARGE = 0.12;

/**
 * La place de l'intitulé « Numéro de ligne », en fractions de la page affichée, et le
 * rectangle à blanchir pour l'effacer.
 *
 * @return array{x:float, y:float, w:float, h:float, cache:array{x:float, y:float, w:float, h:float}}|null
 *         null si on ne l'a pas reconnu — un plan d'un autre gabarit, ou sans cartouche,
 *         n'est pas une anomalie.
 */
function planCartoucheNumeroDeLigne(string $cheminPdf, int $page = 1): ?array
{
    if (!is_file($cheminPdf)) return null;
    $taille = planCartoucheTaillePage($cheminPdf, $page);
    if (!$taille) return null;

    $echelle = PLAN_CARTOUCHE_DPI / 72;
    $largeurPx = (int) round($taille['largeur'] * $echelle);
    $hauteurPx = (int) round($taille['hauteur'] * $echelle);
    $bandeHaut = (int) round($hauteurPx * (1 - PLAN_CARTOUCHE_BANDE));
    $bandeHauteur = $hauteurPx - $bandeHaut;
    if ($largeurPx < 1 || $bandeHauteur < 1) return null;

    $base = tempnam(sys_get_temp_dir(), 'cartouche');
    if ($base === false) return null;
    @unlink($base);

    try {
        exec(sprintf(
            'pdftoppm -gray -r %d -f %d -l %d -x 0 -y %d -W %d -H %d -singlefile %s %s 2>/dev/null',
            PLAN_CARTOUCHE_DPI, $page, $page,
            $bandeHaut, $largeurPx, $bandeHauteur,
            escapeshellarg($cheminPdf),
            escapeshellarg($base)
        ), $sortie, $code);
        if ($code !== 0 || !is_file($base . '.pgm')) return null;

        $boite = planCartoucheChercher(planCartoucheLire($base . '.pgm'));
        if (!$boite) return null;

        // Recalée sur l'encre : l'OCR rend la boîte des lettres qu'il a reconnues, et il
        // lui arrive d'en perdre une au bord. Le cache qu'on posera dessus doit couvrir
        // le mot entier, sinon il en reste un bout qui dépasse.
        $image = planCartoucheLireImage($base . '.pgm');
        if ($image) $boite = planCartoucheEtendre($image, $boite);

        // Des pixels de la bande aux fractions de la page entière : c'est dans ce repère
        // que travaillent le lecteur et le calque du plan final. Le cache se calcule ici,
        // une fois pour les deux : l'écran doit blanchir exactement ce que le PDF blanchit.
        $mot = [
            'x' => $boite['gauche'] / $largeurPx,
            'y' => ($bandeHaut + $boite['haut']) / $hauteurPx,
            'w' => ($boite['droite'] - $boite['gauche']) / $largeurPx,
            'h' => ($boite['bas'] - $boite['haut']) / $hauteurPx,
        ];
        $marge = $mot['h'] * PLAN_CARTOUCHE_MARGE;
        $mot['cache'] = [
            'x' => $mot['x'] - $marge,
            'y' => $mot['y'] - $marge,
            'w' => $mot['w'] + 2 * $marge,
            'h' => $mot['h'] + 2 * $marge,
        ];
        return $mot;
    } finally {
        @unlink($base . '.pgm');
    }
}

/**
 * La taille de la page telle qu'on la regarde, en points.
 *
 * La rotation est une consigne d'affichage : une A3 couchée est souvent stockée debout
 * avec « rot: 90 ». pdftoppm rend la page redressée, et c'est donc dans ce sens-là qu'il
 * faut découper la bande.
 *
 * @return array{largeur:float, hauteur:float}|null
 */
function planCartoucheTaillePage(string $cheminPdf, int $page): ?array
{
    $sortie = [];
    exec(sprintf('pdfinfo -f %d -l %d %s 2>/dev/null', $page, $page, escapeshellarg($cheminPdf)), $sortie);
    $infos = implode("\n", $sortie);
    if (!preg_match('/^Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $infos, $taille)
        && !preg_match('/^Page\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $infos, $taille)) {
        return null;
    }
    preg_match('/^Page\s+(?:\d+\s+)?rot:\s+(-?\d+)/m', $infos, $tourne);
    $rotation = ((int) ($tourne[1] ?? 0) % 360 + 360) % 360;
    $largeur = (float) $taille[1];
    $hauteur = (float) $taille[2];
    return in_array($rotation, [90, 270], true)
        ? ['largeur' => $hauteur, 'hauteur' => $largeur]
        : ['largeur' => $largeur, 'hauteur' => $hauteur];
}

/**
 * Les mots reconnus sur l'image, regroupés par ligne de texte telle qu'on la voit.
 *
 * On regroupe sur la hauteur plutôt que de suivre le découpage de tesseract : il lui
 * arrive de couper un intitulé en trois morceaux qu'il range sur trois lignes, alors que
 * l'œil n'en voit qu'une. C'est ce qui le faisait manquer sur certaines pages.
 *
 * --psm 6 : une bande de cartouche se lit comme un bloc de texte d'un seul tenant. Les
 * modes qui cherchent une mise en page s'égarent dans les traits du dessin.
 *
 * @return list<list<array{mot:string, gauche:int, haut:int, droite:int, bas:int, conf:float}>>
 */
function planCartoucheLire(string $cheminImage): array
{
    $sortie = [];
    exec(sprintf('tesseract %s - -l fra --psm 6 tsv 2>/dev/null', escapeshellarg($cheminImage)), $sortie);

    $mots = [];
    foreach ($sortie as $rang => $ligne) {
        if ($rang === 0) continue;
        $colonnes = explode("\t", $ligne);
        if (count($colonnes) < 12) continue;
        [, , , , , , $gauche, $haut, $largeur, $hauteur, $conf, $texte] = $colonnes;
        $normalise = planCartoucheNormaliser($texte);
        if ($normalise === '' || (int) $hauteur < 1) continue;
        $mots[] = [
            'mot' => $normalise,
            'gauche' => (int) $gauche,
            'haut' => (int) $haut,
            'droite' => (int) $gauche + (int) $largeur,
            'bas' => (int) $haut + (int) $hauteur,
            'conf' => (float) $conf,
        ];
    }

    if (!$mots) return [];

    // Ce qui n'a pas la taille d'une lettre n'en est pas une : un trait du dessin, une
    // hachure, un bout de cadre. Tesseract les rend comme des mots, et un seul d'entre
    // eux, assez haut pour toucher deux lignes du cartouche, les souderait en une.
    $hauteurs = array_map(static fn (array $mot): int => $mot['bas'] - $mot['haut'], $mots);
    sort($hauteurs);
    $mediane = max(1, $hauteurs[intdiv(count($hauteurs), 2)]);
    $mots = array_values(array_filter($mots, static function (array $mot) use ($mediane): bool {
        $hauteur = $mot['bas'] - $mot['haut'];
        return $hauteur >= $mediane * 0.4 && $hauteur <= $mediane * 2;
    }));

    // Un mot rejoint la ligne dont le milieu est le sien, à une demi-lettre près. La
    // comparaison porte sur le milieu moyen de la ligne et non sur son dernier mot :
    // de proche en proche, deux rangées du cartouche finiraient par se rejoindre.
    usort($mots, static fn (array $a, array $b): int => ($a['haut'] + $a['bas']) <=> ($b['haut'] + $b['bas']));
    $lignes = [];
    $milieux = [];
    foreach ($mots as $mot) {
        $milieu = ($mot['haut'] + $mot['bas']) / 2;
        $derniere = count($lignes) - 1;
        if ($derniere >= 0 && abs($milieu - $milieux[$derniere] / count($lignes[$derniere])) <= $mediane * 0.6) {
            $lignes[$derniere][] = $mot;
            $milieux[$derniere] += $milieu;
            continue;
        }
        $lignes[] = [$mot];
        $milieux[] = $milieu;
    }

    // Lue de gauche à droite, comme elle est écrite.
    foreach ($lignes as &$ligne) {
        usort($ligne, static fn (array $a, array $b): int => $a['gauche'] <=> $b['gauche']);
    }
    return $lignes;
}

/**
 * Combien de mots consécutifs peuvent porter l'intitulé : trois quand l'OCR le lit bien,
 * davantage quand il le hache. Au-delà, on ratisserait la moitié de la rangée.
 */
const PLAN_CARTOUCHE_MOTS_MAX = 6;

/**
 * La boîte de l'intitulé, cherché comme une suite de lettres et non comme trois mots.
 *
 * Les mots consécutifs d'une rangée sont mis bout à bout et comparés à « numerodeligne ».
 * Un découpage raté n'empêche donc plus de le reconnaître : « uméro » + « e » + « lig » +
 * « ne » se relit tout seul. La fenêtre s'arrête aux mots entiers, pour que la boîte
 * rendue ne commence ni ne finisse au milieu d'un voisin.
 *
 * @param list<list<array<string, mixed>>> $lignes
 * @return array{gauche:int, haut:int, droite:int, bas:int}|null
 */
function planCartoucheChercher(array $lignes): ?array
{
    $meilleur = null;

    foreach ($lignes as $mots) {
        $nombre = count($mots);
        for ($debut = 0; $debut < $nombre; $debut++) {
            $chaine = '';
            for ($fin = $debut; $fin < min($nombre, $debut + PLAN_CARTOUCHE_MOTS_MAX); $fin++) {
                $chaine .= $mots[$fin]['mot'];
                $distance = levenshtein($chaine, PLAN_CARTOUCHE_INTITULE);
                if ($distance > PLAN_CARTOUCHE_FAUTES) continue;
                if ($meilleur !== null && $distance >= $meilleur['distance']) continue;

                $trouves = array_slice($mots, $debut, $fin - $debut + 1);
                $confiance = array_sum(array_column($trouves, 'conf')) / count($trouves);
                if ($confiance < PLAN_CARTOUCHE_CONFIANCE) continue;

                $meilleur = [
                    'distance' => $distance,
                    'gauche' => min(array_column($trouves, 'gauche')),
                    'haut' => min(array_column($trouves, 'haut')),
                    'droite' => max(array_column($trouves, 'droite')),
                    'bas' => max(array_column($trouves, 'bas')),
                ];
            }
        }
    }

    if (!$meilleur) return null;
    unset($meilleur['distance']);
    return $meilleur;
}

/**
 * Élargit la boîte à l'encre qu'elle touche.
 *
 * On balaie vers la gauche puis vers la droite tant qu'on rencontre de l'encre, en
 * s'arrêtant sur un blanc large comme une espace : au-delà commence la colonne voisine du
 * cartouche, qu'il ne faut surtout pas recouvrir.
 *
 * @param array{largeur:int, hauteur:int, pixels:string} $image
 * @param array{gauche:int, haut:int, droite:int, bas:int} $boite
 * @return array{gauche:int, haut:int, droite:int, bas:int}
 */
function planCartoucheEtendre(array $image, array $boite): array
{
    $hauteur = $boite['bas'] - $boite['haut'];
    $espace = (int) max(2, round($hauteur * 0.45));
    // Jamais plus de la moitié du mot de chaque côté : sans cette butée, une bavure
    // d'encre suffirait à faire courir le cache jusqu'au bord de la feuille.
    $course = (int) max($espace * 2, round(($boite['droite'] - $boite['gauche']) * 0.5));

    $encree = static function (int $x) use ($image, $boite): bool {
        if ($x < 0 || $x >= $image['largeur']) return false;
        for ($y = max(0, $boite['haut']); $y < min($image['hauteur'], $boite['bas']); $y++) {
            if (ord($image['pixels'][$y * $image['largeur'] + $x]) < 128) return true;
        }
        return false;
    };

    foreach ([['gauche', -1], ['droite', 1]] as [$bord, $sens]) {
        $limite = $boite[$bord];
        $blancs = 0;
        for ($pas = 1; $pas <= $course; $pas++) {
            $x = $boite[$bord] + $sens * $pas;
            if ($encree($x)) {
                $limite = $x;
                $blancs = 0;
                continue;
            }
            if (++$blancs > $espace) break;
        }
        $boite[$bord] = $limite;
    }
    return $boite;
}

/**
 * L'image en niveaux de gris, telle que pdftoppm l'écrit (PGM binaire, P5).
 *
 * @return array{largeur:int, hauteur:int, pixels:string}|null
 */
function planCartoucheLireImage(string $chemin): ?array
{
    $brut = @file_get_contents($chemin);
    if ($brut === false || strncmp($brut, 'P5', 2) !== 0) return null;

    // Trois nombres après la signature — largeur, hauteur, valeur maximale — séparés par
    // des blancs, avec d'éventuels commentaires en #.
    $position = 2;
    $nombres = [];
    while (count($nombres) < 3 && $position < strlen($brut)) {
        $caractere = $brut[$position];
        if ($caractere === '#') {
            $position = strpos($brut, "\n", $position) ?: strlen($brut);
            continue;
        }
        if (ctype_space($caractere)) { $position++; continue; }
        $fin = $position;
        while ($fin < strlen($brut) && ctype_digit($brut[$fin])) $fin++;
        if ($fin === $position) return null;
        $nombres[] = (int) substr($brut, $position, $fin - $position);
        $position = $fin;
    }
    if (count($nombres) < 3 || $nombres[2] !== 255) return null;

    [$largeur, $hauteur] = $nombres;
    $pixels = substr($brut, $position + 1);
    if (strlen($pixels) < $largeur * $hauteur) return null;
    return ['largeur' => $largeur, 'hauteur' => $hauteur, 'pixels' => $pixels];
}

/** Minuscules, sans accent ni ponctuation : l'OCR rend « Numéro », « NUMERO », « Numéro, ». */
function planCartoucheNormaliser(string $mot): string
{
    $mot = mb_strtolower(trim($mot), 'UTF-8');
    $mot = strtr($mot, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e',
        'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    ]);
    return (string) preg_replace('/[^a-z0-9]/', '', $mot);
}
