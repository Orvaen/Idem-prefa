<?php

/**
 * Lecture d'un certificat matière (CCPU, EN 10204) : numéros de coulée, et de quoi
 * reconnaître le produit (numéro de certificat, dimensions, nuance).
 *
 * Tout se passe sur le serveur, sans service externe. Poppler extrait le texte du PDF
 * quand il en porte un ; sinon il rend la page en image et Tesseract la lit. Dans les
 * deux cas on obtient des mots avec leur position, et le reste est de la géométrie :
 * une coulée est un jeton rangé sous un en-tête « Heat / Coulée / Schmelze / Colata ».
 * Aucune règle ne dépend d'un fournisseur.
 *
 * Le résultat est une proposition, jamais une vérité. Un scan se lit mal par endroits,
 * et un numéro de coulée faux est une non-conformité de traçabilité : chaque coulée
 * porte donc la confiance du lecteur et dit si une seconde lecture la confirme ailleurs
 * sur la page. C'est à l'écran de validation d'en tenir compte.
 */

/** Au-delà, on ne lit pas : un certificat tient en une ou deux pages, et chaque page scannée coûte une dizaine de secondes. */
const CCPU_PAGES_MAX = 6;

/** Outils système absents, parmi ceux dont la lecture a besoin. Vide quand tout est en place. */
function ccpuOutilsManquants(): array
{
    $manquants = [];
    foreach (['pdfinfo', 'pdfimages', 'pdftotext', 'pdftoppm', 'tesseract'] as $outil) {
        exec('command -v ' . escapeshellarg($outil) . ' 2>/dev/null', $sortie, $code);
        if ($code !== 0) $manquants[] = $outil;
    }
    return $manquants;
}

/**
 * Lit un certificat et renvoie ce qu'il a reconnu.
 *
 * @return array{pages:int, pages_lues:int, lecture:string, certificat:?string,
 *               dimensions:list<string>, nuances:list<string>,
 *               coulees:list<array{numero:string, page:int, confiance:int, confirmee:bool}>}
 */
function ccpuLire(string $pdf): array
{
    if ($manquants = ccpuOutilsManquants()) {
        throw new RuntimeException('Lecture des certificats indisponible : ' . implode(', ', $manquants) . ' absent(s) du serveur.');
    }
    if (!is_file($pdf)) throw new RuntimeException('Certificat introuvable.');

    ccpuExecuter(['pdfinfo', $pdf], $infos);
    if (!preg_match('/^Pages:\s+(\d+)/m', (string) $infos, $trouve)) throw new RuntimeException('Ce fichier n’est pas un PDF lisible.');
    $pages = (int) $trouve[1];

    $tmp = sys_get_temp_dir() . '/ccpu-' . bin2hex(random_bytes(8));
    if (!mkdir($tmp, 0700)) throw new RuntimeException('Dossier de travail indisponible.');

    $resultat = ['pages' => $pages, 'pages_lues' => min($pages, CCPU_PAGES_MAX), 'lecture' => '',
        'certificat' => null, 'dimensions' => [], 'nuances' => [], 'coulees' => []];
    $lectures = [];
    try {
        for ($page = 1; $page <= $resultat['pages_lues']; $page++) {
            $mots = ccpuMotsDePage($pdf, $page, $tmp, $lecture);
            if (!$mots) continue;
            $lectures[$lecture] = true;
            $texte = ccpuTexte($mots);
            $resultat['certificat'] ??= ccpuNumeroCertificat($texte);
            $resultat['dimensions'] = array_values(array_unique(array_merge($resultat['dimensions'], ccpuDimensions($texte))));
            $resultat['nuances'] = array_values(array_unique(array_merge($resultat['nuances'], ccpuNuances($texte))));
            foreach (ccpuCouleesDePage($mots) as $coulee) {
                // Une même coulée peut revenir d'une page à l'autre : on garde sa meilleure lecture.
                $connue = $resultat['coulees'][$coulee['numero']] ?? null;
                if (!$connue || $coulee['confiance'] > $connue['confiance']) {
                    $resultat['coulees'][$coulee['numero']] = $coulee + ['page' => $page];
                }
            }
        }
    } finally {
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
    }

    $resultat['coulees'] = array_values($resultat['coulees']);
    $resultat['lecture'] = count($lectures) > 1 ? 'mixte' : (array_key_first($lectures) ?? 'aucune');
    return $resultat;
}

/** Lance une commande sans passer par l'interpréteur de l'utilisateur : chaque argument est échappé. */
function ccpuExecuter(array $commande, ?string &$sortie = null): bool
{
    exec(implode(' ', array_map('escapeshellarg', $commande)) . ' 2>/dev/null', $lignes, $code);
    $sortie = implode("\n", $lignes);
    return $code === 0;
}

/**
 * Mots d'une page avec leur position, par le chemin le plus sûr disponible.
 *
 * @param-out string $lecture « texte » ou « ocr »
 */
function ccpuMotsDePage(string $pdf, int $page, string $tmp, ?string &$lecture): array
{
    $numero = (string) $page;

    // La couche texte d'abord : quand le fournisseur envoie le PDF d'origine, elle est
    // exacte et instantanée. On ne s'y fie pas pour un scan, même « interrogeable » :
    // son texte vient alors d'un OCR dont on ignore tout, et on le prendrait pour sûr.
    $tsv = "$tmp/texte-$page.tsv";
    if (!ccpuPageEstUnScan($pdf, $page) && ccpuExecuter(['pdftotext', '-tsv', '-f', $numero, '-l', $numero, $pdf, $tsv])) {
        $mots = ccpuLireTsv($tsv, 100);
        if (count($mots) >= 30) {
            $lecture = 'texte';
            return $mots;
        }
    }

    // Sinon c'est une image : 300 points par pouce en niveaux de gris, le réglage pour
    // lequel Tesseract est fait. Le format PGM n'est pas compressé, donc rapide à écrire.
    $base = "$tmp/page-$page";
    if (!ccpuExecuter(['pdftoppm', '-r', '300', '-gray', '-f', $numero, '-l', $numero, '-singlefile', $pdf, $base])) return [];
    if (!ccpuExecuter(['tesseract', $base . '.pgm', $base, '-l', 'eng+fra', '--psm', '6', 'tsv'])) return [];
    $lecture = 'ocr';
    return ccpuLireTsv($base . '.tsv', null);
}

/** Une page est un scan quand une image en occupe l'essentiel : un logo ne fait pas 1 000 pixels de côté. */
function ccpuPageEstUnScan(string $pdf, int $page): bool
{
    ccpuExecuter(['pdfimages', '-list', '-f', (string) $page, '-l', (string) $page, $pdf], $liste);
    foreach (array_slice(explode("\n", (string) $liste), 2) as $ligne) {
        $c = preg_split('/\s+/', trim($ligne));
        if (count($c) > 4 && (int) $c[3] >= 1000 && (int) $c[4] >= 1000) return true;
    }
    return false;
}

/**
 * Lit le format TSV commun à pdftotext et à Tesseract : une ligne par mot, avec sa
 * boîte englobante. Les coordonnées n'ont pas la même unité selon l'outil — points ou
 * pixels — et c'est sans importance : tout le reste raisonne en proportions.
 */
function ccpuLireTsv(string $fichier, ?int $confianceImposee): array
{
    $flux = @fopen($fichier, 'r');
    if (!$flux) return [];
    fgets($flux);   // ligne d'en-tête
    $mots = [];
    while (($ligne = fgets($flux)) !== false) {
        $c = explode("\t", rtrim($ligne, "\r\n"));
        if (count($c) < 12 || (int) $c[0] !== 5) continue;   // niveau 5 : le mot
        $texte = trim($c[11]);
        if ($texte === '' || str_starts_with($texte, '###')) continue;
        $mots[] = [
            'ligne' => $c[2] . '-' . $c[3] . '-' . $c[4],
            'x' => (float) $c[6], 'y' => (float) $c[7], 'l' => (float) $c[8], 'h' => (float) $c[9],
            'confiance' => $confianceImposee ?? max(0, (int) round((float) $c[10])),
            'texte' => $texte,
        ];
    }
    fclose($flux);
    return $mots;
}

/** Reconstitue le texte ligne par ligne, pour les motifs qui se lisent à plat. */
function ccpuTexte(array $mots): string
{
    $lignes = [];
    foreach ($mots as $mot) $lignes[$mot['ligne']][] = $mot['texte'];
    return implode("\n", array_map(static fn (array $ligne) => implode(' ', $ligne), $lignes));
}

/** Minuscules, sans accents ni ponctuation : « Coulée » et « COULEE: » se valent. */
function ccpuCle(string $texte): string
{
    $texte = strtr(mb_strtolower($texte), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ô' => 'o', 'ö' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c']);
    return preg_replace('/[^a-z0-9]/', '', $texte);
}

/** Le mot est-il un en-tête de colonne de coulées ? Anglais, français, allemand, italien, espagnol. */
function ccpuEstAncre(string $texte): bool
{
    // Un en-tête multilingue arrive parfois d'un seul tenant : « Schmelze/Coulée/Colata ».
    foreach (preg_split('~[/|]~', $texte) as $partie) {
        if (preg_match('/^(heat|coulees?|schmelze|schmelzen?r?|colata|colada|cast|charge)(n|no|nr|num)?$/', ccpuCle($partie))) return true;
    }
    return false;
}

/** Un jeton a-t-il la forme d'un numéro de coulée ? Renvoie sa forme nettoyée, ou null. */
function ccpuJetonDeCoulee(string $texte): ?string
{
    $jeton = strtoupper(trim($texte, " \t.,;:|()[]{}'\"`*"));
    if (!preg_match('~^[A-Z0-9][A-Z0-9/-]{3,11}$~', $jeton)) return null;
    return preg_match_all('/\d/', $jeton) >= 3 ? $jeton : null;
}

/**
 * Coulées d'une page : les jetons rangés sous un en-tête de colonne, chacun avec la
 * confiance du lecteur et l'indication d'une seconde lecture ailleurs sur la page.
 *
 * @return list<array{numero:string, confiance:int, confirmee:bool}>
 */
function ccpuCouleesDePage(array $mots): array
{
    // 1. Les mots de la colonne. Deux en-têtes superposés (« HEAT N° » puis « Coulée »)
    //    désignent la même colonne : l'indice du mot sert de clé, on ne compte rien deux fois.
    $dansColonne = [];
    foreach ($mots as $ancre) {
        if (!ccpuEstAncre($ancre['texte'])) continue;
        $hauteur = max($ancre['h'], 1.0);
        // La colonne est l'emprise de l'en-tête, élargie d'une marge comptée en hauteur
        // de caractère. Surtout pas en proportion de sa largeur : sous un en-tête long
        // (« Schmelze/Coulée/Colata »), la bande débordait sur la colonne voisine et en
        // ramenait les numéros d'éprouvette comme autant de coulées.
        $gauche = $ancre['x'] - 1.5 * $hauteur;
        $droite = $ancre['x'] + $ancre['l'] + 1.5 * $hauteur;
        $sous = [];
        foreach ($mots as $indice => $mot) {
            if ($mot['y'] <= $ancre['y'] + 0.5 * $hauteur || $mot['x'] + $mot['l'] < $gauche || $mot['x'] > $droite) continue;
            if (ccpuJetonDeCoulee($mot['texte']) !== null) $sous[$indice] = $mot['y'];
        }
        asort($sous);
        // La colonne s'arrête au premier grand vide : au-delà, c'est un autre tableau.
        $precedent = $ancre['y'];
        $seuil = 15 * $hauteur;   // l'en-tête peut s'étaler sur plusieurs lignes de traductions
        foreach ($sous as $indice => $y) {
            if ($y - $precedent > $seuil) break;
            $dansColonne[$indice] = true;
            $precedent = $y;
            $seuil = 6 * $hauteur;
        }
    }
    if (!$dansColonne) return [];

    // 2. Une valeur par coulée, avec sa meilleure lecture.
    $coulees = [];
    foreach (array_keys($dansColonne) as $indice) {
        $numero = ccpuJetonDeCoulee($mots[$indice]['texte']);
        $coulees[$numero] ??= ['numero' => $numero, 'confiance' => 0, 'lectures' => 0];
        $coulees[$numero]['confiance'] = max($coulees[$numero]['confiance'], $mots[$indice]['confiance']);
        $coulees[$numero]['lectures']++;
    }

    // 3. La seconde lecture. Un certificat cite ses coulées deux fois — analyse chimique,
    //    essais mécaniques — et l'autre tableau se lit souvent moins bien : on accepte un
    //    écart de deux caractères. Chaque jeton n'appuie que la coulée dont il est le plus
    //    proche, sans quoi « 546556 » confirmerait aussi sa voisine « 546555 ».
    foreach ($mots as $indice => $mot) {
        if (isset($dansColonne[$indice]) || ($jeton = ccpuJetonDeCoulee($mot['texte'])) === null) continue;
        $meilleure = null;
        $ecartMin = 3;
        $exAequo = false;
        foreach ($coulees as $numero => $_) {
            $ecart = levenshtein($jeton, (string) $numero);
            if ($ecart < $ecartMin) {
                [$meilleure, $ecartMin, $exAequo] = [$numero, $ecart, false];
            } elseif ($ecart === $ecartMin) {
                $exAequo = true;
            }
        }
        if ($meilleure !== null && !$exAequo) $coulees[$meilleure]['lectures']++;
    }

    return array_map(static fn (array $coulee) => [
        'numero' => (string) $coulee['numero'],
        'confiance' => $coulee['confiance'],
        'confirmee' => $coulee['lectures'] >= 2,
    ], array_values($coulees));
}

/** Numéro du certificat : le « N° » porté par la ligne de titre, quelle qu'en soit la langue. */
function ccpuNumeroCertificat(string $texte): ?string
{
    foreach (explode("\n", $texte) as $ligne) {
        if (preg_match('/certificat|zeugnis/i', $ligne) && preg_match('/N\s?[°ºo]\s?(\d{6,})/u', $ligne, $trouve)) return $trouve[1];
    }
    return null;
}

/** Dimensions au format diamètre × épaisseur × longueur, telles qu'écrites. */
function ccpuDimensions(string $texte): array
{
    preg_match_all('/\b\d{1,4}[.,]\d{1,2}\s?[xX×]\s?\d{1,3}[.,]\d{1,2}\s?[xX×]\s?\d{3,5}\b/u', $texte, $trouves);
    return array_map(static fn (string $d) => preg_replace('/\s*[xX×]\s*/u', ' × ', $d), $trouves[0]);
}

/** Nuances reconnues : le numéro de matière européen (1.4404) et la désignation TP / AISI (316L). */
function ccpuNuances(string $texte): array
{
    preg_match_all('/(?<![\d.,])1\.[0-9]{4}(?![\d.,])/', $texte, $numeros);
    preg_match_all('/\b(?:TP|AISI)[ .]?(\d{3}[A-Z]{0,2})\b/', $texte, $designations);
    return array_values(array_unique(array_merge($numeros[0], $designations[1])));
}
