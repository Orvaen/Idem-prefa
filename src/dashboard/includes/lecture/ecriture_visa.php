<?php

require_once __DIR__ . '/pdf.php';

/**
 * L'écriture du visa : le prénom et le nom de celui qui a validé, écrits comme une
 * signature dans la case VISA du plan.
 *
 * Valider des cordons, c'est les viser (avancementViserCordons()) : le nom vient du compte
 * du validateur, il ne se dessine pas. Un tracé à la souris, dans une case de quelques
 * millimètres, ne se lisait plus ; c'est le nom qui engage, et l'écriture qui le fait lire
 * comme une signature.
 *
 * L'écriture est Great Vibes (licence OFL), réduite aux caractères que le PDF sait écrire —
 * le latin d'Europe de l'Ouest, WinAnsi : 36 Ko au lieu de 457. Le plan final l'embarque, pour
 * s'afficher et s'imprimer pareil partout, sans dépendre des polices du poste ; l'écran la
 * charge en woff2 (plan-viewer.css). Changer d'écriture, c'est remplacer ces deux fichiers,
 * produits par fontTools :
 *
 *   pyftsubset GreatVibes-Regular.ttf --unicodes=<WinAnsi> --layout-features='' --no-hinting
 *
 * Sans tables de mise en forme : le PDF écrit lettre après lettre, sans crénage ni ligature,
 * et l'écran doit montrer la même chose.
 */

const VISA_ECRITURE = __DIR__ . '/../../../assets/fonts/great-vibes.ttf';

/**
 * Ce que le PDF doit savoir de l'écriture, lu dans le fichier une fois.
 *
 * Une police embarquée en « TrueType simple » se déclare avec la chasse de chacun de ses
 * caractères, sa boîte et ses hauteurs : le lecteur s'en sert pour poser le texte. On les lit
 * dans les tables du fichier — head, hhea, hmtx, cmap, OS/2 et name —, en millièmes de
 * cadratin comme le PDF les veut.
 *
 * @return array{fichier: string, nom: string, chasses: array<int, int>, boite: list<int>,
 *               ascendante: int, descendante: int, capitales: int}
 * @throws RuntimeException si le fichier manque ou n'est pas une police TrueType
 */
function visaEcriture(): array
{
    static $ecriture = null;
    if ($ecriture !== null) return $ecriture;

    $ttf = @file_get_contents(VISA_ECRITURE);
    if ($ttf === false || strlen($ttf) < 12) throw new RuntimeException('L’écriture du visa est introuvable.');

    // Le répertoire des tables : leur nom, et où elles commencent.
    $tables = [];
    $nombre = unpack('n', $ttf, 4)[1];
    for ($i = 0; $i < $nombre; $i++) {
        $entree = unpack('a4nom/Nsomme/Ndebut/Nlongueur', $ttf, 12 + 16 * $i);
        $tables[$entree['nom']] = $entree['debut'];
    }
    foreach (['head', 'hhea', 'hmtx', 'cmap'] as $requise) {
        if (!isset($tables[$requise])) throw new RuntimeException('L’écriture du visa n’est pas une police TrueType lisible.');
    }

    $entier = static fn (int $position): int => ($valeur = unpack('n', $ttf, $position)[1]) >= 0x8000 ? $valeur - 0x10000 : $valeur;
    $naturel = static fn (int $position): int => unpack('n', $ttf, $position)[1];

    $unites = $naturel($tables['head'] + 18);
    $milliemes = static fn (int $valeur): int => (int) round($valeur * 1000 / $unites);

    // Les chasses, glyphe par glyphe : au-delà des métriques déclarées, tous ont la dernière.
    $metriques = $naturel($tables['hhea'] + 34);
    $chasse = static function (int $glyphe) use ($naturel, $tables, $metriques): int {
        return $naturel($tables['hmtx'] + 4 * min($glyphe, $metriques - 1));
    };

    // Le glyphe d'un caractère Unicode, par la table cmap (3, 1) au format 4.
    $cmap = $tables['cmap'];
    $sousTable = null;
    for ($i = 0, $n = $naturel($cmap + 2); $i < $n; $i++) {
        $plateforme = $naturel($cmap + 4 + 8 * $i);
        $codage = $naturel($cmap + 6 + 8 * $i);
        $debut = $cmap + unpack('N', $ttf, $cmap + 8 + 8 * $i)[1];
        if ($plateforme === 3 && $codage === 1 && $naturel($debut) === 4) $sousTable = $debut;
    }
    if ($sousTable === null) throw new RuntimeException('L’écriture du visa n’a pas de table de caractères Unicode.');
    $segments = $naturel($sousTable + 6) / 2;
    $fins = $sousTable + 14;
    $debuts = $fins + 2 * $segments + 2;
    $deltas = $debuts + 2 * $segments;
    $decalages = $deltas + 2 * $segments;
    $glyphe = static function (int $code) use ($naturel, $segments, $fins, $debuts, $deltas, $decalages): int {
        for ($i = 0; $i < $segments; $i++) {
            if ($naturel($fins + 2 * $i) < $code) continue;
            $premier = $naturel($debuts + 2 * $i);
            if ($premier > $code) return 0;
            $delta = $naturel($deltas + 2 * $i);
            $decalage = $naturel($decalages + 2 * $i);
            if ($decalage === 0) return ($code + $delta) & 0xFFFF;
            $g = $naturel($decalages + 2 * $i + $decalage + 2 * ($code - $premier));
            return $g === 0 ? 0 : ($g + $delta) & 0xFFFF;
        }
        return 0;
    };

    // L'encre d'un glyphe, du bas de son jambage au haut de sa boucle : l'en-tête de chaque
    // glyphe la donne (glyf), à l'adresse que la table loca indique. Un glyphe sans contour —
    // l'espace — n'en a pas.
    $encre = static function (int $numero) use ($ttf, $tables, $naturel, $entier): ?array {
        if (!isset($tables['loca'], $tables['glyf'])) return null;
        $longue = $entier($tables['head'] + 50) === 1;
        $adresse = static fn (int $i): int => $longue
            ? unpack('N', $ttf, $tables['loca'] + 4 * $i)[1]
            : 2 * $naturel($tables['loca'] + 2 * $i);
        $debut = $adresse($numero);
        if ($adresse($numero + 1) <= $debut) return null;
        $position = $tables['glyf'] + $debut;
        return [$entier($position + 4), $entier($position + 8)];
    };

    // Les chasses et l'encre des caractères 32 à 255 de WinAnsi, l'encodage que le PDF
    // déclarera.
    $chasses = [];
    $encres = [];
    for ($code = 32; $code <= 255; $code++) {
        $unicode = mb_ord(mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252'), 'UTF-8');
        $numero = $glyphe($unicode ?: 0);
        $chasses[$code] = $milliemes($chasse($numero));
        $limites = $encre($numero);
        if ($limites) $encres[$code] = array_map($milliemes, $limites);
    }

    // Le nom PostScript, celui que le PDF donne à la police : l'entrée 6 de la table name.
    $nom = 'Visa';
    if (isset($tables['name'])) {
        $base = $tables['name'];
        $chaines = $base + $naturel($base + 4);
        for ($i = 0, $n = $naturel($base + 2); $i < $n; $i++) {
            $enregistrement = $base + 6 + 12 * $i;
            if ($naturel($enregistrement + 6) !== 6) continue;
            $brut = substr($ttf, $chaines + $naturel($enregistrement + 10), $naturel($enregistrement + 8));
            $nom = $naturel($enregistrement) === 3 ? mb_convert_encoding($brut, 'UTF-8', 'UTF-16BE') : $brut;
            break;
        }
    }

    $hhea = $tables['hhea'];
    $ascendante = $milliemes($entier($hhea + 4));
    return $ecriture = [
        'fichier' => $ttf,
        'nom' => preg_replace('/[^A-Za-z0-9-]/', '', $nom) ?: 'Visa',
        'chasses' => $chasses,
        'encres' => $encres,
        'boite' => array_map($milliemes, [$entier($tables['head'] + 36), $entier($tables['head'] + 38),
            $entier($tables['head'] + 40), $entier($tables['head'] + 42)]),
        'ascendante' => $ascendante,
        'descendante' => $milliemes($entier($hhea + 6)),
        // La hauteur des capitales, dans OS/2 depuis sa version 2 ; à défaut, une part de
        // l'ascendante, qui compte aussi les boucles des majuscules.
        'capitales' => isset($tables['OS/2']) && $naturel($tables['OS/2']) >= 2
            ? $milliemes($entier($tables['OS/2'] + 88))
            : (int) round($ascendante * 0.75),
    ];
}

/**
 * L'encre d'un nom, en cadratins depuis sa ligne de base : le haut de sa plus haute boucle,
 * le bas de son plus long jambage (négatif).
 *
 * C'est sur elle que le nom se règle dans sa case, pas sur les hauteurs de la police : une
 * écriture manuscrite a de grandes capitales et de longs jambages, et un nom qui n'en a pas
 * n'a pas à laisser leur place vide.
 *
 * @return array{haut: float, bas: float}
 */
function visaEncre(string $texte): array
{
    $encres = visaEcriture()['encres'];
    $bas = $haut = null;
    foreach (str_split(PdfPlan::versWinAnsi($texte)) as $caractere) {
        if (!isset($encres[ord($caractere)])) continue;
        [$dessous, $dessus] = $encres[ord($caractere)];
        $bas = $bas === null ? $dessous : min($bas, $dessous);
        $haut = $haut === null ? $dessus : max($haut, $dessus);
    }
    return $haut === null ? ['haut' => 0.75, 'bas' => 0.0] : ['haut' => $haut / 1000, 'bas' => $bas / 1000];
}

/** La largeur d'un nom écrit dans l'écriture du visa, en points. */
function visaLargeur(string $texte, float $corps): float
{
    $chasses = visaEcriture()['chasses'];
    $total = 0;
    foreach (str_split(PdfPlan::versWinAnsi($texte)) as $caractere) {
        $total += $chasses[ord($caractere)] ?? 0;
    }
    return $total / 1000 * $corps;
}
