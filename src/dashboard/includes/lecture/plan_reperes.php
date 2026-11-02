<?php

require_once __DIR__ . '/../demande/prefa_attachments.php';
require_once __DIR__ . '/plan_table.php';

/**
 * Les numéros de soudure dessinés sur l'isométrique : où ils sont, et lequel est lequel.
 *
 * Le soudeur renseigne un cordon en cliquant son numéro sur le dessin, là où il le voit sur
 * son tirage. Il faut donc savoir où le bureau d'études l'a posé. Rien ne le dit dans le
 * PDF : AutoCAD Plant 3D trace tout son texte en courbes, et pdftotext n'y trouve que les
 * valeurs du cartouche.
 *
 * On le cherche donc sur l'image. Sur ces plans, un numéro de soudure est un chiffre dans un
 * petit rectangle, relié au cordon par un trait — les repères de la nomenclature, eux, sont
 * dans des cercles. Les rectangles se trouvent sur l'image noir et blanc de la page, celle
 * que lit déjà plan_table.php. Le chiffre de chacun se reconnaît ensuite en le comparant à
 * ceux de la colonne N° de la liste soudure : le bureau d'études y imprime 1, 2, 3… dans la
 * même police. Pas d'OCR — un 2 dont la barre touche le cadre passait pour un 7 —, et la
 * réponse ne peut être qu'un numéro de la liste.
 *
 * Ce qu'on ne reconnaît pas n'est pas deviné : un numéro sans place sûre reste à saisir
 * hors du dessin. Une seule exception, l'élimination — quand il reste un rectangle et un
 * numéro, c'est lui.
 */

/**
 * À incrémenter dès que la détection rend autre chose : le cache écrit par l'ancien code
 * serait sinon resservi tel quel, le plan n'ayant pas bougé.
 */
const PLAN_REPERES_VERSION = 2;

/**
 * La résolution de la lecture des chiffres. Les rectangles se trouvent à celle de
 * plan_table.php (200 dpi) ; leurs chiffres, eux, touchent presque le cadre — il faut le
 * triple pour qu'un pixel blanc les en sépare.
 */
const PLAN_REPERES_LECTURE_DPI = 600;

/** En deçà, un chiffre ne ressemble à aucun de la liste : on ne lui donne pas de numéro. */
const PLAN_REPERES_RESSEMBLANCE_MINI = 0.6;

/** Le cache des repères d'une page, à côté de celui des tableaux (planLignesCache()). */
function planReperesCache(int $requestId, string $cle, int $page): string
{
    return prefaAttachmentDirectory($requestId) . '/reperes-' . substr($cle, 0, 32) . '-p' . $page . '.json';
}

/**
 * Les repères d'une page, lus une fois puis gardés. Refaits si le plan a changé.
 *
 * @param array|null $soudures la liste soudure de la page (planTableSoudures())
 * @return list<array{numero:?string, x:float, y:float, w:float, h:float, deduit:bool}>
 */
function planReperesAnalyse(string $source, string $cache, int $page, ?array $soudures): array
{
    if (is_file($cache) && is_file($source) && filemtime($cache) >= filemtime($source)) {
        $garde = json_decode((string) file_get_contents($cache), true);
        if (is_array($garde) && ($garde['version'] ?? 0) === PLAN_REPERES_VERSION) return $garde['reperes'];
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $reperes = planReperesDetecter($source, $page, $soudures, $complete);
    // Une lecture interrompue — un rendu qui a échoué — sert à cette fois, mais n'est pas
    // gardée : elle serait resservie telle quelle jusqu'au prochain dépôt du plan.
    if ($complete) @file_put_contents($cache, json_encode(['version' => PLAN_REPERES_VERSION, 'reperes' => $reperes]));
    return $reperes;
}

/**
 * Trouve les rectangles de la page et donne à chacun son numéro.
 *
 * Sans liste soudure reconnue, il n'y a ni numéros à attribuer ni rien à renseigner : la
 * page n'a pas de repères.
 *
 * @param bool|null $complete faux quand un rendu a échoué en route : le résultat est partiel
 * @return list<array{numero:?string, x:float, y:float, w:float, h:float, deduit:bool}>
 *         en fractions de l'image, comme les cases de plan_table.php
 */
function planReperesDetecter(string $pdf, int $page, ?array $soudures, ?bool &$complete = null): array
{
    $complete = true;
    if (!$soudures || !$soudures['lignes'] || count($soudures['bords'] ?? []) < 2 || !is_file($pdf)) return [];

    $image = planReperesImage($pdf, $page, PLAN_TABLE_DPI);
    if (!$image) {
        $complete = false;
        return [];
    }
    $rectangles = planReperesHorsTableau(planReperesRectangles($image), $image, $soudures);
    if (!$rectangles) return [];

    $modeles = planReperesModeles($pdf, $page, $image, $soudures);
    if (!$modeles) {
        $complete = false;
        return [];
    }
    $scores = [];
    foreach ($rectangles as $rang => $rectangle) {
        $glyphe = planReperesChiffre($pdf, $page, $rectangle, $rendu);
        if (!$rendu) $complete = false;
        foreach ($modeles as $numero => $modele) {
            $scores[$rang][$numero] = $glyphe && $modele ? planReperesRessemblance($modele, $glyphe) : 0.0;
        }
    }
    $attribues = planReperesAttribuer($scores, array_keys($modeles));

    $reperes = [];
    foreach ($rectangles as $rang => [$x1, $y1, $x2, $y2]) {
        $reperes[] = [
            'numero' => isset($attribues[$rang]) ? (string) $attribues[$rang]['numero'] : null,
            'x' => $x1 / $image['l'],
            'y' => $y1 / $image['h'],
            'w' => ($x2 - $x1 + 1) / $image['l'],
            'h' => ($y2 - $y1 + 1) / $image['h'],
            'deduit' => (bool) ($attribues[$rang]['deduit'] ?? false),
        ];
    }
    return $reperes;
}

/**
 * Une page entière en noir et blanc, lue par planTableLirePbm() — l'image où l'on cherche
 * les rectangles, la même que celle des tableaux.
 */
function planReperesImage(string $pdf, int $page, int $dpi): ?array
{
    $base = tempnam(sys_get_temp_dir(), 'repere');
    if ($base === false) return null;
    @unlink($base);
    try {
        exec(sprintf('pdftoppm -mono -r %d -f %d -l %d -singlefile %s %s 2>/dev/null',
            $dpi, $page, $page, escapeshellarg($pdf), escapeshellarg($base)), $sortie, $code);
        return $code === 0 && is_file($base . '.pbm') ? planTableLirePbm($base . '.pbm') : null;
    } finally {
        @unlink($base . '.pbm');
    }
}

/** En deçà de ce gris, un pixel est de l'encre : le noir, mais aussi le rouge et le vert du plan. */
const PLAN_REPERES_SEUIL = 170;

/**
 * Un morceau de page, à la résolution de lecture : rendu en gris, puis tranché ici.
 *
 * Pas en noir et blanc : au-delà de 300 dpi, pdftoppm trame les couleurs au hasard. Les
 * traits rouges et verts du plan — une ligne de cote, le tube — sortaient d'un appel à
 * l'autre en pointillés différents, et un chiffre qui touche un trait s'y collait une fois
 * sur deux. Le même plan donnait un numéro, puis aucun. Le gris, lui, sort pareil à chaque
 * fois, et le seuil est le nôtre.
 *
 * @param array{0:int, 1:int, 2:int, 3:int} $morceau x, y, largeur, hauteur en pixels à cette résolution
 * @return array{l:int, h:int, bits:string}|null comme planTableLirePbm()
 */
function planReperesMorceau(string $pdf, int $page, array $morceau): ?array
{
    $base = tempnam(sys_get_temp_dir(), 'repere');
    if ($base === false) return null;
    @unlink($base);
    try {
        exec(sprintf('pdftoppm -gray -r %d -f %d -l %d -x %d -y %d -W %d -H %d -singlefile %s %s 2>/dev/null',
            PLAN_REPERES_LECTURE_DPI, $page, $page, $morceau[0], $morceau[1], $morceau[2], $morceau[3],
            escapeshellarg($pdf), escapeshellarg($base)), $sortie, $code);
        if ($code !== 0 || !is_file($base . '.pgm')) return null;
        $brut = (string) file_get_contents($base . '.pgm');
    } finally {
        @unlink($base . '.pgm');
    }

    // L'en-tête d'un PGM binaire : « P5 », la largeur, la hauteur, la valeur maximale.
    if (!preg_match('/^P5\s+(\d+)\s+(\d+)\s+(\d+)\s/', $brut, $entete)) return null;
    [$tout, $largeur, $hauteur, $maxi] = $entete;
    $largeur = (int) $largeur;
    $hauteur = (int) $hauteur;
    $pixels = substr($brut, strlen($tout), $largeur * $hauteur);
    if ((int) $maxi !== 255 || strlen($pixels) !== $largeur * $hauteur) return null;

    // Chaque niveau de gris devient encre ou papier d'un seul passage, en C.
    static $traduction = null;
    if ($traduction === null) {
        $traduction = ['', ''];
        for ($gris = 0; $gris < 256; $gris++) {
            $traduction[0] .= chr($gris);
            $traduction[1] .= $gris < PLAN_REPERES_SEUIL ? "\1" : "\0";
        }
    }
    return ['l' => $largeur, 'h' => $hauteur, 'bits' => strtr($pixels, $traduction[0], $traduction[1])];
}

function planReperesNoir(array $image, int $x, int $y): bool
{
    return $x >= 0 && $y >= 0 && $x < $image['l'] && $y < $image['h'] && $image['bits'][$y * $image['l'] + $x] === "\1";
}

/** La part noire d'un segment horizontal ou vertical, à un pixel près de part et d'autre. */
function planReperesCouverture(array $image, int $x1, int $y1, int $x2, int $y2): float
{
    $noirs = 0;
    $total = 0;
    if ($y1 === $y2) {
        for ($x = $x1; $x <= $x2; $x++) {
            $total++;
            if (planReperesNoir($image, $x, $y1) || planReperesNoir($image, $x, $y1 - 1) || planReperesNoir($image, $x, $y1 + 1)) $noirs++;
        }
    } else {
        for ($y = $y1; $y <= $y2; $y++) {
            $total++;
            if (planReperesNoir($image, $x1, $y) || planReperesNoir($image, $x1 - 1, $y) || planReperesNoir($image, $x1 + 1, $y)) $noirs++;
        }
    }
    return $total ? $noirs / $total : 0.0;
}

/**
 * Les petits rectangles de la page.
 *
 * On part de chaque trait horizontal court : le haut ou le bas d'un rectangle. Les traits
 * des tableaux, du cadre et du cartouche courent d'un bord à l'autre et ne sont jamais
 * courts — ils s'écartent d'eux-mêmes. Du trait, on cherche les deux côtés et le trait
 * d'en face par la seule présence de l'encre : un rectangle que traverse le tube, ou qui
 * touche son voisin, garde ses quatre côtés même si l'un d'eux se prolonge.
 *
 * @return list<array{0:int, 1:int, 2:int, 3:int}> x1, y1, x2, y2 en pixels
 */
function planReperesRectangles(array $image): array
{
    $largeurMini = planTablePx(0.075);
    $largeurMaxi = planTablePx(0.35);
    $hauteurMini = planTablePx(0.06);
    $hauteurMaxi = planTablePx(0.20);
    $coin = planTablePx(0.015);

    $trouves = [];
    for ($y = 0; $y < $image['h']; $y++) {
        $rangee = substr($image['bits'], $y * $image['l'], $image['l']);
        if (!str_contains($rangee, "\1")) continue;
        preg_match_all('/\x01+/', $rangee, $traits, PREG_OFFSET_CAPTURE);
        foreach ($traits[0] as [$trait, $x1]) {
            $longueur = strlen($trait);
            if ($longueur < $largeurMini || $longueur > $largeurMaxi) continue;
            $x2 = $x1 + $longueur - 1;
            // Le trait sert de haut (on descend) ou de bas (on monte). Ses deux coins
            // doivent partir dans ce sens, sinon ce n'est pas un rectangle.
            foreach ([1, -1] as $sens) {
                $ligne = $y + $coin * $sens;
                if (!planReperesNoir($image, $x1, $ligne) && !planReperesNoir($image, $x1 + 1, $ligne)) continue;
                if (!planReperesNoir($image, $x2, $ligne) && !planReperesNoir($image, $x2 - 1, $ligne)) continue;
                for ($ecart = $hauteurMini; $ecart <= $hauteurMaxi; $ecart++) {
                    $y2 = $y + $sens * $ecart;
                    if ($y2 < 0 || $y2 >= $image['h']) break;
                    if (planReperesCouverture($image, $x1, $y2, $x2, $y2) < 0.9) continue;
                    [$haut, $bas] = $sens > 0 ? [$y, $y2] : [$y2, $y];
                    if (planReperesCouverture($image, $x1, $haut, $x1, $bas) < 0.9) continue;
                    if (planReperesCouverture($image, $x2, $haut, $x2, $bas) < 0.9) continue;
                    $trouves[] = [$x1, $haut, $x2, $bas];
                    break;
                }
            }
        }
    }

    // Un même rectangle se trouve plusieurs fois — par son haut et par son bas, ou deux
    // fois quand son trait est épais. On garde le plus grand de ceux qui se recouvrent.
    usort($trouves, static fn (array $a, array $b): int =>
        ($b[2] - $b[0]) * ($b[3] - $b[1]) <=> ($a[2] - $a[0]) * ($a[3] - $a[1]));
    $gardes = [];
    foreach ($trouves as $rectangle) {
        foreach ($gardes as $garde) {
            $communX = min($rectangle[2], $garde[2]) - max($rectangle[0], $garde[0]);
            $communY = min($rectangle[3], $garde[3]) - max($rectangle[1], $garde[1]);
            if ($communX > 0 && $communY > 0
                && $communX * $communY > 0.5 * ($rectangle[2] - $rectangle[0]) * ($rectangle[3] - $rectangle[1])) continue 2;
        }
        $gardes[] = $rectangle;
    }
    return $gardes;
}

/**
 * Écarte les rectangles de la liste soudure elle-même : ses cases ne sont pas des repères
 * du dessin. Seule son emprise compte — le dessin déborde parfois dans la colonne de
 * droite, au-dessus d'elle.
 *
 * @param list<array{0:int, 1:int, 2:int, 3:int}> $rectangles
 * @return list<array{0:int, 1:int, 2:int, 3:int}>
 */
function planReperesHorsTableau(array $rectangles, array $image, array $soudures): array
{
    $pas = (float) ($soudures['pas'] ?? 0);
    // L'en-tête — titre, tuyauteur, intitulés des colonnes — tient en trois rangs au-dessus
    // de la première ligne ; un de plus pour la marge.
    $haut = (float) $soudures['lignes'][0]['y'] - 4 * $pas;
    $bas = (float) ($soudures['bas'] ?? 1) + 0.005;
    $gauche = (float) $soudures['bords'][0] - 0.005;
    $droite = (float) end($soudures['bords']) + 0.005;
    return array_values(array_filter($rectangles, static function (array $r) use ($image, $haut, $bas, $gauche, $droite): bool {
        $x = $r[0] / $image['l'];
        $y = $r[1] / $image['h'];
        return !($x >= $gauche && $x <= $droite && $y >= $haut && $y <= $bas);
    }));
}

/**
 * Le chiffre de chaque ligne de la liste soudure, lu dans sa colonne N° : les modèles.
 *
 * @return array<int, array{l:int, h:int, g:list<int>}|null> numéro => glyphe ; vide si la
 *         colonne n'a pas pu être rendue
 */
function planReperesModeles(string $pdf, int $page, array $image, array $soudures): array
{
    $facteur = PLAN_REPERES_LECTURE_DPI / PLAN_TABLE_DPI;
    $pas = (float) $soudures['pas'];
    $premiere = (float) $soudures['lignes'][0]['y'] - PLAN_TABLE_RETRAIT * $pas;
    // Toute la colonne d'un coup, à la résolution de lecture : une seule image pour les
    // dix lignes plutôt que dix.
    $x = (int) floor($soudures['bords'][0] * $image['l'] * $facteur);
    $y = (int) floor($premiere * $image['h'] * $facteur);
    $l = (int) ceil(($soudures['bords'][1] - $soudures['bords'][0]) * $image['l'] * $facteur);
    $h = (int) ceil(count($soudures['lignes']) * $pas * $image['h'] * $facteur);
    $colonne = planReperesMorceau($pdf, $page, [$x, $y, $l, $h]);
    if (!$colonne) return [];

    $modeles = [];
    $marge = (int) round(4 * $facteur);
    foreach ($soudures['lignes'] as $rang => $ligne) {
        // La case de la ligne, juste à l'intérieur de ses traits : le chiffre y a sa marge.
        $haut = (int) round((((float) $ligne['y'] - PLAN_TABLE_RETRAIT * $pas) * $image['h'] * $facteur) - $y);
        $hauteur = (int) round($pas * $image['h'] * $facteur);
        $modeles[$rang + 1] = planReperesGlyphe($colonne, $marge, $haut + $marge, $l - 1 - $marge, $haut + $hauteur - 1 - $marge, $marge);
    }
    return $modeles;
}

/**
 * Le chiffre d'un rectangle, lu à la résolution de lecture.
 *
 * @param array{0:int, 1:int, 2:int, 3:int} $rectangle en pixels de l'image à 200 dpi
 * @param bool|null $rendu faux si le morceau n'a pas pu être rendu — et non s'il est vide
 */
function planReperesChiffre(string $pdf, int $page, array $rectangle, ?bool &$rendu = null): ?array
{
    $facteur = PLAN_REPERES_LECTURE_DPI / PLAN_TABLE_DPI;
    // Le rectangle et un peu d'autour : c'est là qu'on voit si un trait qui touche le
    // cadre continue au-delà — un trait qui traverse, et non un chiffre.
    $autour = 4;
    [$x1, $y1, $x2, $y2] = $rectangle;
    $morceau = [(int) (($x1 - $autour) * $facteur), (int) (($y1 - $autour) * $facteur),
        (int) (($x2 - $x1 + 1 + 2 * $autour) * $facteur), (int) (($y2 - $y1 + 1 + 2 * $autour) * $facteur)];
    $image = planReperesMorceau($pdf, $page, $morceau);
    $rendu = $image !== null;
    if (!$image) return null;

    // L'intérieur du cadre : on avance depuis chaque bord tant qu'on est dans le trait.
    $cadre = planReperesInterieur($image, (int) ($autour * $facteur));
    if (!$cadre) return null;
    [$gauche, $haut, $droite, $bas] = $cadre;
    return planReperesGlyphe($image, $gauche, $haut, $droite, $bas, (int) round(3 * $facteur));
}

/**
 * L'intérieur d'un rectangle cadré avec $autour pixels de marge : les rangées et colonnes
 * de son trait sont sautées.
 *
 * @return array{0:int, 1:int, 2:int, 3:int}|null
 */
function planReperesInterieur(array $image, int $autour): ?array
{
    $plein = static function (array $image, bool $rangee, int $indice, int $de, int $a): bool {
        $noirs = 0;
        for ($i = $de; $i <= $a; $i++) {
            if (planReperesNoir($image, $rangee ? $i : $indice, $rangee ? $indice : $i)) $noirs++;
        }
        return $noirs >= 0.6 * ($a - $de + 1);
    };
    $l = $image['l'];
    $h = $image['h'];
    // Le trait est cherché autour de l'endroit attendu, puis on le traverse.
    $traverser = static function (int $depart, int $pas, bool $rangee) use ($image, $plein, $l, $h, $autour): ?int {
        $limite = $rangee ? $h : $l;
        $de = $autour;
        $a = ($rangee ? $l : $h) - 1 - $autour;
        $i = $depart;
        // Trouver le trait, à quelques pixels près de sa place attendue.
        for ($essais = 0; $essais < 3 * $autour && !$plein($image, $rangee, $i, $de, $a); $essais++) {
            $i += $pas;
            if ($i < 0 || $i >= $limite) return null;
        }
        if (!$plein($image, $rangee, $i, $de, $a)) return null;
        while ($i >= 0 && $i < $limite && $plein($image, $rangee, $i, $de, $a)) $i += $pas;
        return $i;
    };
    $haut = $traverser(0, 1, true);
    $bas = $traverser($h - 1, -1, true);
    $gauche = $traverser(0, 1, false);
    $droite = $traverser($l - 1, -1, false);
    if ($haut === null || $bas === null || $gauche === null || $droite === null) return null;
    return $bas - $haut >= 4 && $droite - $gauche >= 4 ? [$gauche, $haut, $droite, $bas] : null;
}

/**
 * Le chiffre au milieu d'une zone : ses pixels, recadrés sur l'encre.
 *
 * Un trait qui traverse la zone — le tube, une ligne de cote — y entre par un côté et en
 * ressort par un autre : il touche le bord en deux endroits, et continue au-delà ($dehors
 * pixels plus loin) des deux. Il est écarté. Un chiffre peut toucher le cadre — il en
 * occupe toute la hauteur —, et un trait voisin peut passer juste derrière : ça ne fait
 * qu'un côté.
 *
 * @return array{l:int, h:int, g:list<int>}|null
 */
function planReperesGlyphe(array $image, int $x1, int $y1, int $x2, int $y2, int $dehors): ?array
{
    $largeur = $x2 - $x1 + 1;
    $hauteur = $y2 - $y1 + 1;
    if ($largeur < 3 || $hauteur < 3) return null;

    $vus = [];
    $pixels = [];
    for ($y = 0; $y < $hauteur; $y++) {
        for ($x = 0; $x < $largeur; $x++) {
            if (isset($vus[$y * $largeur + $x]) || !planReperesNoir($image, $x1 + $x, $y1 + $y)) continue;
            // Une composante, de proche en proche.
            $pile = [[$x, $y]];
            $vus[$y * $largeur + $x] = true;
            $composante = [];
            $sorties = [];
            while ($pile) {
                [$cx, $cy] = array_pop($pile);
                $composante[] = [$cx, $cy];
                if ($cx === 0 && planReperesNoir($image, $x1 - $dehors, $y1 + $cy)) $sorties['gauche'] = true;
                if ($cx === $largeur - 1 && planReperesNoir($image, $x2 + $dehors, $y1 + $cy)) $sorties['droite'] = true;
                if ($cy === 0 && planReperesNoir($image, $x1 + $cx, $y1 - $dehors)) $sorties['haut'] = true;
                if ($cy === $hauteur - 1 && planReperesNoir($image, $x1 + $cx, $y2 + $dehors)) $sorties['bas'] = true;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $cx + $dx;
                        $ny = $cy + $dy;
                        if ($nx < 0 || $ny < 0 || $nx >= $largeur || $ny >= $hauteur || isset($vus[$ny * $largeur + $nx])) continue;
                        if (!planReperesNoir($image, $x1 + $nx, $y1 + $ny)) continue;
                        $vus[$ny * $largeur + $nx] = true;
                        $pile[] = [$nx, $ny];
                    }
                }
            }
            // Une poussière n'est pas un trait de chiffre.
            if (count($sorties) < 2 && count($composante) >= 6) array_push($pixels, ...$composante);
        }
    }
    if (!$pixels) return null;

    $xs = array_column($pixels, 0);
    $ys = array_column($pixels, 1);
    $gx = min($xs);
    $gy = min($ys);
    $gl = max($xs) - $gx + 1;
    $gh = max($ys) - $gy + 1;
    $grille = array_fill(0, $gl * $gh, 0);
    foreach ($pixels as [$px, $py]) $grille[($py - $gy) * $gl + ($px - $gx)] = 1;
    return ['l' => $gl, 'h' => $gh, 'g' => $grille];
}

/**
 * Ressemblance de deux chiffres, de 0 à 1 : le second ramené à la taille du premier, chaque
 * pixel d'encre cherché dans l'autre à deux pixels près.
 *
 * La largeur compte en plus : ramenés à la même taille, « 1 » et « 11 » se ressembleraient.
 */
function planReperesRessemblance(array $modele, array $glyphe): float
{
    if (abs($modele['h'] - $glyphe['h']) > max(6, 0.3 * $modele['h'])) return 0.0;

    // Le glyphe, ramené à la grille du modèle.
    $ramene = ['l' => $modele['l'], 'h' => $modele['h'], 'g' => []];
    for ($y = 0; $y < $modele['h']; $y++) {
        for ($x = 0; $x < $modele['l']; $x++) {
            $sx = min($glyphe['l'] - 1, (int) floor(($x + 0.5) * $glyphe['l'] / $modele['l']));
            $sy = min($glyphe['h'] - 1, (int) floor(($y + 0.5) * $glyphe['h'] / $modele['h']));
            $ramene['g'][] = $glyphe['g'][$sy * $glyphe['l'] + $sx];
        }
    }
    $proche = static function (array $grille, int $x, int $y): bool {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx >= 0 && $ny >= 0 && $nx < $grille['l'] && $ny < $grille['h'] && $grille['g'][$ny * $grille['l'] + $nx]) return true;
            }
        }
        return false;
    };

    $dansModele = 0; $encreModele = 0; $dansGlyphe = 0; $encreGlyphe = 0;
    for ($y = 0; $y < $modele['h']; $y++) {
        for ($x = 0; $x < $modele['l']; $x++) {
            $i = $y * $modele['l'] + $x;
            if ($modele['g'][$i]) { $encreModele++; if ($proche($ramene, $x, $y)) $dansModele++; }
            if ($ramene['g'][$i]) { $encreGlyphe++; if ($proche($modele, $x, $y)) $dansGlyphe++; }
        }
    }
    if (!$encreModele || !$encreGlyphe) return 0.0;
    $precision = $dansGlyphe / $encreGlyphe;
    $rappel = $dansModele / $encreModele;
    if ($precision + $rappel <= 0) return 0.0;
    $forme = min($modele['l'], $glyphe['l']) / max($modele['l'], $glyphe['l']);
    return 2 * $precision * $rappel / ($precision + $rappel) * (0.6 + 0.4 * $forme);
}

/**
 * Chaque numéro va à un seul rectangle, et chaque rectangle reçoit au plus un numéro : la
 * paire la plus ressemblante d'abord, et ainsi de suite.
 *
 * Quand il ne reste qu'un rectangle sans numéro et qu'un numéro sans place, c'est lui :
 * on l'attribue, en le disant déduit. Au-delà, on ne devine pas.
 *
 * @param array<int, array<int, float>> $scores rang du rectangle => numéro => ressemblance
 * @param list<int> $numeros
 * @return array<int, array{numero:int, deduit:bool}>
 */
function planReperesAttribuer(array $scores, array $numeros): array
{
    $paires = [];
    foreach ($scores as $rang => $parNumero) {
        foreach ($parNumero as $numero => $score) {
            if ($score >= PLAN_REPERES_RESSEMBLANCE_MINI) $paires[] = [$score, $rang, $numero];
        }
    }
    usort($paires, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

    $attribues = [];
    $pris = [];
    foreach ($paires as [, $rang, $numero]) {
        if (isset($attribues[$rang]) || isset($pris[$numero])) continue;
        $attribues[$rang] = ['numero' => $numero, 'deduit' => false];
        $pris[$numero] = true;
    }

    $sansNumero = array_values(array_diff(array_keys($scores), array_keys($attribues)));
    $sansPlace = array_values(array_diff($numeros, array_keys($pris)));
    if (count($sansNumero) === 1 && count($sansPlace) === 1) {
        $attribues[$sansNumero[0]] = ['numero' => $sansPlace[0], 'deduit' => true];
    }
    return $attribues;
}
