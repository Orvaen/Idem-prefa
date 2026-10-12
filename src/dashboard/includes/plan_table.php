<?php

/**
 * Détection du tableau « NOMENCLATURE DE MATÉRIEL » sur un plan ISO.
 *
 * Les plans sortis d'un logiciel de CAO sont vectoriels : leur tableau est tracé avec
 * de vraies lignes, et c'est ce qu'on cherche. Pas d'OCR, pas de bibliothèque d'images —
 * le plan est converti en bitmap noir et blanc, format que PHP lit octet par octet.
 *
 * La détection rend une case par ligne de nomenclature, posée dans la colonne de droite,
 * sur la page demandée : un cahier ISO porte une nomenclature par isométrique.
 * Elle échoue proprement sur un plan scanné ou un autre gabarit : aucune case n'est
 * alors posée.
 */

/**
 * Résolution de lecture des plans.
 *
 * Toute la géométrie est mesurée sur cette image : un trait n'est connu qu'au pixel près,
 * et cette erreur se retrouve telle quelle sur la feuille finale. À 100 dpi elle valait
 * un quart de millimètre, assez pour qu'une ligne ajoutée sous un tableau ne soit pas
 * dans l'alignement de ses voisines. À 200 elle tombe sous le dixième de millimètre, pour
 * quatre fois plus de pixels à parcourir — une seconde au plus, et le résultat est gardé.
 */
const PLAN_TABLE_DPI = 200;

/**
 * Convertit une longueur en pouces vers des pixels de l'image analysée.
 *
 * Les seuils de voisinage sont exprimés en pouces et non en pixels : écrits en pixels,
 * ils changeraient de sens le jour où l'on change la résolution — une double règle cesserait
 * d'être reconnue comme un seul trait, et la lecture du tableau s'effondrerait sans bruit.
 */
function planTablePx(float $pouces): int
{
    return max(1, (int) round(PLAN_TABLE_DPI * $pouces));
}

/**
 * Retrait d'une case dans sa ligne, en part du pas : elle se pose à l'intérieur des traits,
 * pas dessus. Le lecteur (plan-viewer.js) et le plan final emploient le même, sans quoi un
 * chiffre n'aurait pas la même taille à l'écran et sur le papier.
 */
const PLAN_TABLE_RETRAIT = 0.18;

/**
 * @return list<array{x:float, y:float, w:float, h:float}> les cases, en fractions de
 *         l'image — jamais des pixels, pour rester valables à tout zoom.
 */
function planTableDetecter(string $cheminPdf, int $page = 1): array
{
    if (!is_file($cheminPdf)) return [];
    $base = tempnam(sys_get_temp_dir(), 'plan');
    if ($base === false) return [];
    @unlink($base);

    try {
        exec(sprintf(
            'pdftoppm -mono -r %d -f %d -l %d -singlefile %s %s 2>/dev/null',
            PLAN_TABLE_DPI,
            $page,
            $page,
            escapeshellarg($cheminPdf),
            escapeshellarg($base)
        ), $sortie, $code);
        if ($code !== 0 || !is_file($base . '.pbm')) return [];
        return planTableAnalyser($base . '.pbm');
    } finally {
        @unlink($base . '.pbm');
    }
}

/** @return list<array{x:float, y:float, w:float, h:float}> */
function planTableAnalyser(string $cheminPbm): array
{
    $image = planTableLirePbm($cheminPbm);
    if (!$image) return [];
    $largeur = $image['l'];
    $hauteur = $image['h'];

    // Le tableau occupe le quart haut droit de la feuille : s'y limiter écarte le cadre
    // de la page, le cartouche du bas et la liste des soudures, qui ont la même allure.
    $retenues = [];
    foreach (planTableHorizontales($image, (int) ($largeur * 0.12)) as $ligne) {
        if ($ligne['y'] >= $hauteur * 0.30 || $ligne['x1'] <= $largeur * 0.55) continue;
        $retenues[] = $ligne;
    }
    // Un trait épais noircit deux ou trois rangées voisines : elles ne font qu'un trait.
    $rangees = planTableFusionner($retenues, planTablePx(0.02));
    // Titre, en-tête et au moins une ligne : moins, ce n'est pas une nomenclature.
    if (count($rangees) < 4) return [];

    // Le trait du haut manque-t-il à l'appel ?
    //
    // Une rangée n'est jugée que sur son plus long segment. Or le haut du tableau tombe
    // souvent sur le cadre de la feuille, qui court d'un bord à l'autre : le plus long
    // segment part alors de la marge, la rangée est écartée comme n'appartenant pas au
    // tableau, et toutes les lignes glissent d'un rang — la première ligne de matériel
    // n'ayant plus de case où recevoir sa coulée.
    //
    // On ne rebalaie pas toute la feuille pour autant : on regarde un seul endroit, à un
    // pas au-dessus du premier trait connu, et sur la seule largeur du tableau. Ailleurs
    // on ne ramasserait que des traits de cadre, qui feraient prendre le titre pour une
    // ligne de matériel.
    $premier = planTableTraitAuDessus($image, $rangees);
    if ($premier !== null) array_unshift($rangees, $premier);

    $bas = end($rangees)['y'];
    $gauche = min(array_column($rangees, 'x1'));
    $droite = max(array_column($rangees, 'x2'));

    // Les séparateurs de colonnes ne commencent que SOUS le titre : « NOMENCLATURE DE
    // MATÉRIEL » occupe toute la largeur et aucune verticale ne le traverse. Les
    // chercher depuis le haut du tableau ne trouverait que ses deux bords.
    $hautGrille = $rangees[1]['y'];
    $colonnes = planTableVerticales($image, $gauche, $droite, (int) $hautGrille, (int) $bas);
    if (count($colonnes) < 3) return [];

    // « N° COULÉE » est la dernière colonne : entre l'avant-dernier séparateur et le bord.
    $xCoulee = $colonnes[count($colonnes) - 2];
    $largeurCoulee = $droite - $xCoulee;
    if ($largeurCoulee < $largeur * 0.02) return [];

    // Les lignes de données commencent sous l'en-tête, c'est-à-dire à la 3e rangée.
    //
    // La case se pose À L'INTÉRIEUR du trait, pas dessus : les coordonnées trouvées
    // vont d'une ligne à l'autre, bordures comprises. Sans cette marge, le repère
    // déborde de la cellule et son numéro vient toucher les traits du tableau.
    $cases = [];
    for ($rang = 2; $rang < count($rangees) - 1; $rang++) {
        $y1 = $rangees[$rang]['y'];
        $y2 = $rangees[$rang + 1]['y'];
        $margeY = ($y2 - $y1) * PLAN_TABLE_RETRAIT;
        $margeX = $largeurCoulee * 0.07;
        $cases[] = [
            'x' => ($xCoulee + $margeX) / $largeur,
            'y' => ($y1 + $margeY) / $hauteur,
            'w' => ($largeurCoulee - 2 * $margeX) / $largeur,
            'h' => ($y2 - $y1 - 2 * $margeY) / $hauteur,
        ];
    }
    return $cases;
}

/**
 * Détecte le tableau « LISTE SOUDURE » et rend la géométrie de ses cases.
 *
 * Ce tableau se trouve sous la nomenclature, dans la même colonne de droite. Il porte
 * huit colonnes — N°, soudeur, date, type, RT, VT, PT, visa — et autant de lignes que
 * le bureau d'études en a prévu pour la pièce.
 *
 * Le bureau d'études n'en prévoit pas toujours assez : un plan à cinq lignes peut
 * recevoir huit cordons. On rend donc aussi de quoi prolonger le tableau — les bords des
 * colonnes, le pas des lignes, le trait du bas, et le nombre de rangs qu'on peut encore
 * tracer avant de buter sur le dessin ou le cartouche.
 *
 * Le tableau est lu sur la page demandée : un cahier ISO déposé d'un bloc porte une liste
 * soudure par isométrique, et les cordons de la page 3 vont dans le tableau de la page 3.
 *
 * Chaque ligne dit aussi, case par case, ce qui y est déjà écrit : une liste remplie à la
 * main, ou un plan final redéposé comme plan, porte déjà son texte. On ne complète que le
 * vide — écrire par-dessus rend les deux illisibles.
 *
 * @return array{colonnes: list<array{x:float, w:float}>,
 *               lignes: list<array{y:float, h:float, cellules: list<bool>}>,
 *               bords: list<float>, pas: float, bas: float, libres: int}|null
 */
function planTableSoudures(string $cheminPdf, int $page = 1): ?array
{
    if (!is_file($cheminPdf)) return null;
    $base = tempnam(sys_get_temp_dir(), 'plan');
    if ($base === false) return null;
    @unlink($base);

    try {
        exec(sprintf(
            'pdftoppm -mono -r %d -f %d -l %d -singlefile %s %s 2>/dev/null',
            PLAN_TABLE_DPI,
            $page,
            $page,
            escapeshellarg($cheminPdf),
            escapeshellarg($base)
        ), $sortie, $code);
        if ($code !== 0 || !is_file($base . '.pbm')) return null;
        return planTableSouduresAnalyser($base . '.pbm');
    } finally {
        @unlink($base . '.pbm');
    }
}

/** @return array{colonnes: list<array{x:float, w:float}>, lignes: list<array{y:float, h:float}>}|null */
function planTableSouduresAnalyser(string $cheminPbm): ?array
{
    $image = planTableLirePbm($cheminPbm);
    if (!$image) return null;
    $largeur = $image['l'];
    $hauteur = $image['h'];

    // Les deux tableaux occupent la même colonne de droite, séparés par un vide franc.
    // On les isole en blocs, et la liste soudure est celui du bas.
    $retenues = [];
    foreach (planTableHorizontales($image, (int) ($largeur * 0.10)) as $ligne) {
        if ($ligne['x1'] <= $largeur * 0.50 || $ligne['y'] > $hauteur * 0.85) continue;
        $retenues[] = $ligne;
    }
    // Un trait épais ou une double règle noircit plusieurs rangées voisines : elles ne
    // font qu'un trait, tant qu'elles restent bien plus proches qu'une ligne de tableau.
    $blocs = [];
    $courant = [];
    foreach (planTableFusionner($retenues, planTablePx(0.08)) as $trait) {
        $derniere = end($courant);
        if ($derniere !== false && $trait['y'] - $derniere['y'] > $hauteur * 0.04) {
            $blocs[] = $courant;
            $courant = [];
        }
        $courant[] = $trait;
    }
    if ($courant) $blocs[] = $courant;
    if (count($blocs) < 2) return null;

    $bloc = end($blocs);
    // Titre, bandeau du tuyauteur, en-tête, et au moins une soudure.
    if (count($bloc) < 5) return null;

    $bas = end($bloc)['y'];
    $gauche = min(array_column($bloc, 'x1'));
    $droite = max(array_column($bloc, 'x2'));

    // Les huit colonnes ne descendent que sous le bandeau « NOM DU TUYAUTEUR / VISA ».
    $separateurs = planTableVerticales($image, $gauche, $droite, (int) $bloc[2]['y'], (int) $bas);
    if (count($separateurs) < 6) return null;

    $colonnes = [];
    for ($i = 0; $i < count($separateurs) - 1; $i++) {
        $x1 = $separateurs[$i];
        $x2 = $separateurs[$i + 1];
        $marge = ($x2 - $x1) * 0.06;
        $colonnes[] = ['x' => ($x1 + $marge) / $largeur, 'w' => ($x2 - $x1 - 2 * $marge) / $largeur];
    }

    // Les soudures commencent sous l'en-tête : titre, bandeau, en-tête, puis les lignes.
    $lignes = [];
    for ($rang = 3; $rang < count($bloc) - 1; $rang++) {
        $y1 = $bloc[$rang]['y'];
        $y2 = $bloc[$rang + 1]['y'];
        $marge = ($y2 - $y1) * PLAN_TABLE_RETRAIT;
        $lignes[] = [
            'y' => ($y1 + $marge) / $hauteur,
            'h' => ($y2 - $y1 - 2 * $marge) / $hauteur,
            'cellules' => planTableCellulesEcrites($image, $separateurs, (int) $y1, (int) $y2),
        ];
    }
    if (!$lignes) return null;

    // Le pas d'un rang et le trait du bas, ajustés sur TOUS les traits de données et pas
    // seulement sur les deux extrêmes. Les rangs qu'on ajoutera sous le tableau se placent
    // avec, et un demi-pixel d'erreur ici se voit sur la feuille : la ligne ajoutée
    // n'aurait pas la hauteur de ses voisines, et le décalage sauterait aux yeux.
    [$pas, $bas] = planTableAjusterPas(array_slice(array_column($bloc, 'y'), 3));

    return [
        'colonnes' => $colonnes,
        'lignes' => $lignes,
        'bords' => array_map(static fn (float $x): float => $x / $largeur, $separateurs),
        'pas' => $pas / $hauteur,
        'bas' => $bas / $hauteur,
        'libres' => planTableSouduresPlaceLibre($image, $separateurs, $bas, $pas),
    ];
}

/**
 * Fond les rangées voisines en un seul trait, repéré par son MILIEU.
 *
 * Un trait du plan noircit une à trois rangées selon son épaisseur et la résolution. En
 * retenir la première revient à prendre son bord haut, soit un demi-pixel trop haut à
 * chaque fois — un biais constant, qui se répercute tel quel sur tout ce qu'on place
 * ensuite. Le milieu, lui, tombe sur le trait quelle que soit son épaisseur.
 *
 * @param list<array{y:int, x1:int, x2:int}> $lignes
 * @param int $tolerance écart maximal, en pixels, entre deux rangées d'un même trait
 * @return list<array{y:float, x1:int, x2:int}>
 */
function planTableFusionner(array $lignes, int $tolerance): array
{
    $traits = [];
    $groupe = [];
    foreach ($lignes as $ligne) {
        $derniere = end($groupe);
        if ($derniere !== false && $ligne['y'] - $derniere['y'] > $tolerance) {
            $traits[] = planTableMilieu($groupe);
            $groupe = [];
        }
        $groupe[] = $ligne;
    }
    if ($groupe) $traits[] = planTableMilieu($groupe);
    return $traits;
}

/** @param list<array{y:int, x1:int, x2:int}> $groupe */
function planTableMilieu(array $groupe): array
{
    $premier = $groupe[0];
    $dernier = end($groupe);
    return [
        // Le demi-pixel n'est pas un détail : une rangée d'indice y couvre [y, y+1), son
        // centre est donc y+0,5. L'omettre place chaque trait un demi-pixel trop haut —
        // toujours dans le même sens, donc l'erreur ne se compense jamais.
        'y' => ($premier['y'] + $dernier['y']) / 2 + 0.5,
        // L'étendue du trait est celle de sa rangée la plus longue : une rangée de bord
        // s'arrête parfois plus tôt que le trait lui-même.
        'x1' => min(array_column($groupe, 'x1')),
        'x2' => max(array_column($groupe, 'x2')),
    ];
}

/**
 * Le pas des rangs et la position du dernier trait, ajustés par moindres carrés.
 *
 * Les traits sont relevés sur une image : chacun porte l'erreur de sa rangée de pixels.
 * Prendre l'écart entre le premier et le dernier laisse ces deux erreurs-là intactes,
 * alors que la droite qui passe au plus près de tous les traits les moyenne.
 *
 * @param list<float> $y les traits de données, du haut vers le bas
 * @return array{0: float, 1: float} le pas, et le dernier trait ajusté
 */
function planTableAjusterPas(array $y): array
{
    $n = count($y);
    if ($n < 2) return [0.0, $y ? (float) $y[0] : 0.0];

    $moyenneRang = ($n - 1) / 2;
    $moyenneY = array_sum($y) / $n;
    $numerateur = 0.0;
    $denominateur = 0.0;
    foreach ($y as $rang => $valeur) {
        $ecart = $rang - $moyenneRang;
        $numerateur += $ecart * ($valeur - $moyenneY);
        $denominateur += $ecart * $ecart;
    }
    if ($denominateur <= 0) return [0.0, (float) end($y)];

    $pas = $numerateur / $denominateur;
    // Le dernier trait lu sur la droite ajustée, et non la valeur brute : c'est de lui
    // que partent les rangs ajoutés.
    $origine = $moyenneY - $pas * $moyenneRang;
    return [$pas, $origine + $pas * ($n - 1)];
}

/**
 * Ce qui est déjà écrit dans une ligne du tableau, case par case.
 *
 * Le bureau d'études imprime le numéro du rang dans la première colonne : elle ressort
 * donc toujours occupée, ce qui tombe bien — on n'y écrit jamais sur un rang imprimé.
 *
 * Le relevé se fait à l'intérieur des traits : les compter comme de l'encre rendrait
 * toutes les cases occupées, et plus rien ne s'écrirait jamais.
 *
 * @param list<int|float> $separateurs les bords verticaux des colonnes, en pixels
 * @return list<bool> une entrée par colonne
 */
function planTableCellulesEcrites(array $image, array $separateurs, int $haut, int $bas): array
{
    $marge = planTablePx(0.015);
    // Quelques pixels isolés ne sont que du bruit de rasterisation ; une lettre en
    // noircit bien davantage. Même seuil que sous le tableau, à la surface près.
    $bruit = (int) max(4, round(4 * (PLAN_TABLE_DPI / 100) ** 2));

    $y1 = max(0, $haut + $marge);
    $y2 = min($image['h'] - 1, $bas - $marge);

    $cellules = [];
    for ($i = 0; $i < count($separateurs) - 1; $i++) {
        $x1 = max(0, (int) $separateurs[$i] + $marge);
        $x2 = min($image['l'] - 1, (int) $separateurs[$i + 1] - $marge);
        $noirs = 0;
        for ($y = $y1; $y <= $y2 && $noirs <= $bruit; $y++) {
            $ligne = $y * $image['l'];
            for ($x = $x1; $x <= $x2; $x++) {
                if ($image['bits'][$ligne + $x] === "\1" && ++$noirs > $bruit) break;
            }
        }
        $cellules[] = $noirs > $bruit;
    }
    return $cellules;
}

/**
 * Combien de rangs peut-on encore tracer sous le tableau avant de buter sur quelque chose.
 *
 * On descend bande par bande sous le dernier trait et on s'arrête à la première encre :
 * le dessin isométrique déborde parfois dans cette colonne, et le cartouche ferme le bas
 * de la feuille. Prolonger le tableau par-dessus l'un ou l'autre rendrait le plan illisible.
 */
function planTableSouduresPlaceLibre(array $image, array $separateurs, float $bas, float $pas): int
{
    $marge = planTablePx(0.02);
    if ($pas < $marge) return 0;
    // On regarde à l'intérieur des bords, jamais dessus : celui de droite est le trait
    // du cadre de la feuille, qui descend jusqu'au cartouche. Le compter comme de l'encre
    // ferait conclure qu'il n'y a pas un seul rang de libre sous le tableau.
    $gauche = (int) $separateurs[0] + $marge;
    $droite = (int) end($separateurs) - $marge;
    if ($droite - $gauche < planTablePx(0.1)) return 0;

    // Quelques pixels isolés ne sont que du bruit de rasterisation ; une vraie marque en
    // noircit bien davantage. Le seuil suit la surface, donc le carré de la résolution.
    $bruit = (int) max(4, round(4 * (PLAN_TABLE_DPI / 100) ** 2));

    for ($rang = 0; $rang < 40; $rang++) {
        // Le trait du rang précédent est sauté : c'est de l'encre qu'on vient d'ajouter.
        $haut = (int) ($bas + $rang * $pas) + $marge;
        $pied = (int) ($bas + ($rang + 1) * $pas) - 1;
        if ($pied >= $image['h']) return $rang;

        $noirs = 0;
        for ($y = $haut; $y <= $pied; $y++) {
            $ligne = $y * $image['l'];
            for ($x = $gauche; $x <= $droite; $x++) {
                if ($image['bits'][$ligne + $x] === "\1" && ++$noirs > $bruit) return $rang;
            }
        }
    }
    return 40;
}

/**
 * Lit un bitmap PBM binaire (P4) : un bit par pixel, 1 = noir.
 *
 * @return array{l:int, h:int, bits:string}|null un octet par pixel, pour parcourir
 *         l'image sans masque à chaque accès
 */
function planTableLirePbm(string $chemin): ?array
{
    $brut = @file_get_contents($chemin);
    if ($brut === false || strncmp($brut, 'P4', 2) !== 0) return null;

    $position = 2;
    $nombres = [];
    while (count($nombres) < 2 && $position < strlen($brut)) {
        while ($position < strlen($brut) && ctype_space($brut[$position])) $position++;
        if ($brut[$position] === '#') {
            while ($position < strlen($brut) && $brut[$position] !== "\n") $position++;
            continue;
        }
        $debut = $position;
        while ($position < strlen($brut) && ctype_digit($brut[$position])) $position++;
        if ($position === $debut) return null;
        $nombres[] = (int) substr($brut, $debut, $position - $debut);
    }
    if (count($nombres) < 2) return null;
    $position++;
    [$largeur, $hauteur] = $nombres;
    if ($largeur < 10 || $hauteur < 10 || $largeur * $hauteur > 40_000_000) return null;

    $parLigne = intdiv($largeur + 7, 8);
    if (strlen($brut) - $position < $parLigne * $hauteur) return null;

    $pixels = str_repeat("\0", $largeur * $hauteur);
    for ($y = 0; $y < $hauteur; $y++) {
        $ligne = substr($brut, $position + $y * $parLigne, $parLigne);
        $base = $y * $largeur;
        for ($x = 0; $x < $largeur; $x++) {
            if ((ord($ligne[$x >> 3]) >> (7 - ($x & 7))) & 1) $pixels[$base + $x] = "\1";
        }
    }
    return ['l' => $largeur, 'h' => $hauteur, 'bits' => $pixels];
}

/**
 * Les rangées portant un segment noir assez long pour être un trait de tableau.
 *
 * Le segment tolère de minuscules coupures. Un trait tracé à la limite de deux rangées de
 * pixels ne les noircit pas toutes : il laisse çà et là un pixel blanc, d'autant plus que
 * l'image est fine. Exiger un segment d'un seul tenant faisait disparaître des traits
 * entiers, et avec eux des lignes du tableau.
 *
 * @return list<array{y:int, x1:int, x2:int}>
 */
function planTableHorizontales(array $image, int $longueurMini): array
{
    $trouvees = [];
    $coupureMax = planTablePx(0.01);
    for ($y = 0; $y < $image['h']; $y++) {
        $base = $y * $image['l'];
        $debut = -1;
        $fin = -1;
        $meilleure = 0;
        $meilleurDebut = 0;
        for ($x = 0; $x < $image['l']; $x++) {
            if ($image['bits'][$base + $x] !== "\1") continue;
            // Trop loin du dernier pixel noir : c'est un autre segment qui commence.
            if ($debut < 0 || $x - $fin - 1 > $coupureMax) $debut = $x;
            $fin = $x;
            $longueur = $fin - $debut + 1;
            if ($longueur > $meilleure) { $meilleure = $longueur; $meilleurDebut = $debut; }
        }
        if ($meilleure >= $longueurMini) {
            $trouvees[] = ['y' => $y, 'x1' => $meilleurDebut, 'x2' => $meilleurDebut + $meilleure];
        }
    }
    return $trouvees;
}

/**
 * Le trait qui manquerait juste au-dessus du premier trait connu, s'il y en a un.
 *
 * On le cherche là où la régularité du tableau l'attend — un pas plus haut — et sur sa
 * seule largeur. Un trait de cadre qui passerait par là est donc bien le haut du tableau,
 * puisqu'il en épouse la largeur et tombe dans l'alignement.
 *
 * @param list<array{y:float, x1:int, x2:int}> $rangees
 * @return array{y:float, x1:int, x2:int}|null
 */
function planTableTraitAuDessus(array $image, array $rangees): ?array
{
    if (count($rangees) < 3) return null;

    // Le pas courant du tableau : la médiane des écarts, insensible à une ligne plus haute
    // que les autres comme le titre.
    $ecarts = [];
    for ($i = 1; $i < count($rangees); $i++) $ecarts[] = $rangees[$i]['y'] - $rangees[$i - 1]['y'];
    sort($ecarts);
    $pas = $ecarts[intdiv(count($ecarts), 2)];
    if ($pas < 3) return null;

    $gauche = (int) min(array_column($rangees, 'x1'));
    $droite = min((int) max(array_column($rangees, 'x2')), $image['l'] - 1);
    $largeur = $droite - $gauche + 1;
    if ($largeur < 10) return null;

    $vise = $rangees[0]['y'] - $pas;
    $tolerance = max(1.0, $pas * 0.2);
    $exiges = (int) ($largeur * 0.95);

    $meilleur = null;
    for ($y = (int) ceil($vise - $tolerance); $y <= (int) floor($vise + $tolerance); $y++) {
        if ($y < 0 || $y >= $image['h']) continue;
        $base = $y * $image['l'];
        $noirs = 0;
        for ($x = $gauche; $x <= $droite; $x++) {
            if ($image['bits'][$base + $x] === "\1") $noirs++;
        }
        if ($noirs < $exiges) continue;
        if ($meilleur === null || abs($y + 0.5 - $vise) < abs($meilleur - $vise)) $meilleur = $y + 0.5;
    }
    return $meilleur === null ? null : ['y' => $meilleur, 'x1' => $gauche, 'x2' => $droite + 1];
}

/**
 * Les colonnes noires sur presque toute la hauteur demandée : les séparateurs.
 *
 * Rendus par leur centre, comme les traits horizontaux : une colonne d'indice x couvre
 * [x, x+1). Un séparateur épais compte pour un seul trait, repéré au milieu.
 *
 * @return list<float>
 */
function planTableVerticales(array $image, int $gauche, int $droite, int $haut, int $bas): array
{
    $noires = [];
    $exigees = ($bas - $haut) * 0.9;
    for ($x = $gauche; $x <= $droite; $x++) {
        $noirs = 0;
        for ($y = $haut; $y <= $bas; $y++) {
            if ($image['bits'][$y * $image['l'] + $x] === "\1") $noirs++;
        }
        if ($noirs >= $exigees) $noires[] = $x;
    }

    $colonnes = [];
    $groupe = [];
    $tolerance = planTablePx(0.02);
    foreach ($noires as $x) {
        if ($groupe && $x - end($groupe) > $tolerance) {
            $colonnes[] = ($groupe[0] + end($groupe)) / 2 + 0.5;
            $groupe = [];
        }
        $groupe[] = $x;
    }
    if ($groupe) $colonnes[] = ($groupe[0] + end($groupe)) / 2 + 0.5;
    return $colonnes;
}
