<?php

require_once __DIR__ . '/ccpu.php';

/**
 * Les articles d'un bon de commande, lus dans le PDF déposé.
 *
 * Un bon porte quarante lignes : référence, désignation, échéance, unité, quantité. Les
 * recopier à la main, c'est une demi-heure et autant d'occasions de se tromper d'un
 * chiffre. On les lit donc, et le chef d'atelier corrige ce qui est de travers — la
 * lecture propose, elle ne décide pas.
 *
 * La lecture passe par le même chemin que les CCPU : couche texte quand le fournisseur
 * envoie son PDF d'origine, OCR local quand c'est un scan. Les fonctions sont celles de
 * ccpu.php : elles ne savent rien des CCPU, elles savent rendre les mots d'une page avec
 * leur boîte. En écrire une seconde paire, c'est se garantir qu'elles divergeront.
 *
 * Aucun en-tête n'est cherché. L'OCR rend « Date Livraison » en « Oute Lwranon » et
 * « Qté » en « Oté » : se caler dessus, c'est se caler sur ce qui se lit le plus mal.
 * Une ligne d'article, elle, a une forme qu'on reconnaît sans la lire — un rang, puis
 * une date, puis une unité, puis des nombres.
 */

/** Au-delà, ce n'est plus un bon de commande : on s'arrête avant d'y passer la journée. */
const COMMANDE_LIGNES_PAGES_MAX = 12;

/** Les unités qu'on rencontre sur un bon de tuyauterie. */
const COMMANDE_LIGNES_UNITES = ['PCE', 'ML', 'M', 'KG', 'U', 'UN', 'L', 'M2', 'M3', 'ENS'];

/** Rangées lues sous « COMMANDE » pour y trouver le numéro : au-delà, c'est l'adresse. */
const COMMANDE_LIGNES_ENTETE_RANGEES = 2;

/**
 * Ce qu'on tolère entre la quantité lue et celle que l'arithmétique du bon donne.
 *
 * Le bon se vérifie lui-même : quantité × prix unitaire = total. Au-delà de ce centième,
 * c'est que l'un des trois est mal lu — « 3,00 » rendu « 300 », par exemple.
 */
const COMMANDE_LIGNES_ECART = 0.01;

/**
 * Les articles du bon, page après page, et ce que son en-tête annonce.
 *
 * @return array{lignes: list<array<string, mixed>>, lecture: string, pages: int,
 *               numero: ?string, date: ?string}
 *         lecture vaut « texte », « ocr » ou « mixte » ; elle dit au chef d'atelier à
 *         quel point il doit relire.
 */
function commandeLignesLire(string $pdf): array
{
    $vide = ['lignes' => [], 'lecture' => '', 'pages' => 0, 'numero' => null, 'date' => null];
    if (!is_file($pdf) || ccpuOutilsManquants()) return $vide;

    $pages = commandeLignesNombreDePages($pdf);
    if ($pages < 1) return $vide;
    $pages = min($pages, COMMANDE_LIGNES_PAGES_MAX);

    $tmp = sys_get_temp_dir() . '/bon-' . bin2hex(random_bytes(8));
    if (!@mkdir($tmp) && !is_dir($tmp)) return $vide;

    $lignes = [];
    $lectures = [];
    $entete = ['numero' => null, 'date' => null];
    try {
        for ($page = 1; $page <= $pages; $page++) {
            $lecture = null;
            $mots = ccpuMotsDePage($pdf, $page, $tmp, $lecture);
            if (!$mots) continue;
            $lectures[$lecture ?? 'ocr'] = true;
            // L'en-tête est en première page, mais un bon scanné commence parfois par sa
            // page de garde : on le cherche tant qu'on ne l'a pas.
            if ($entete['numero'] === null) $entete = commandeLignesEnTete($mots);
            foreach (commandeLignesDeLaPage($mots) as $ligne) {
                $ligne['page'] = $page;
                $lignes[] = $ligne;
            }
        }
    } finally {
        commandeLignesNettoyer($tmp);
    }

    return [
        'lignes' => array_values($lignes),
        'lecture' => count($lectures) > 1 ? 'mixte' : (string) array_key_first($lectures),
        'pages' => $pages,
        'numero' => $entete['numero'],
        'date' => $entete['date'],
    ];
}

/**
 * Le numéro et la date que l'en-tête du bon annonce : « COMMANDE 957-MAT-I-03-28588
 * Du 24/06/2026 ».
 *
 * C'est le seul endroit où l'on se cale sur un mot imprimé, et c'est tenable parce que
 * « COMMANDE » est en capitales et en gras, là où « Qté » sort en « Oté ». Le titre et
 * ce qu'il annonce ne sont pas sur la même rangée pour autant — sur un scan IDEM, le mot
 * est seul sur sa ligne et le numéro arrive sous lui, précédé des restes du logo — d'où
 * les quelques rangées qu'on accepte de lire à la suite.
 *
 * Rien n'en dépend : sans numéro reconnu, le bon se nomme par son fichier, et ses
 * articles sont lus de la même façon.
 *
 * @param list<array<string, mixed>> $mots
 * @return array{numero: ?string, date: ?string}
 */
function commandeLignesEnTete(array $mots): array
{
    $rangees = [];
    foreach ($mots as $mot) $rangees[$mot['ligne']][] = $mot;
    $rangees = array_values($rangees);

    foreach ($rangees as $rang => $rangee) {
        usort($rangee, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
        $textes = array_map(static fn (array $m): string => (string) $m['texte'], $rangee);

        $titre = null;
        foreach ($textes as $position => $texte) {
            if (strcasecmp($texte, 'COMMANDE') === 0) { $titre = $position; break; }
        }
        if ($titre === null) continue;

        // Ce qui suit le titre sur sa rangée, puis les deux suivantes : au-delà on serait
        // dans l'adresse du fournisseur, qui porte elle aussi des nombres.
        $suite = array_slice($textes, $titre + 1);
        foreach (array_slice($rangees, $rang + 1, COMMANDE_LIGNES_ENTETE_RANGEES) as $voisine) {
            usort($voisine, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            foreach ($voisine as $mot) $suite[] = (string) $mot['texte'];
        }

        $numero = null;
        $date = null;
        foreach ($suite as $texte) {
            $lue = commandeLignesDate($texte);
            if ($lue !== null) { $date = $lue; break; }
            // Un numéro de commande porte des chiffres et des tirets. L'exiger écarte les
            // débris du logo — « D> », « RA » — et les mots de l'adresse qui suivent.
            if ($numero === null && preg_match('#^(?=.*[0-9])(?=.*-)[0-9A-Za-z][0-9A-Za-z./-]{6,39}$#', $texte)) {
                $numero = strtoupper($texte);
            }
        }
        if ($numero !== null) return ['numero' => $numero, 'date' => $date];
    }
    return ['numero' => null, 'date' => null];
}

/** Le nombre de pages du PDF, ou 0 s'il est illisible. */
function commandeLignesNombreDePages(string $pdf): int
{
    if (!ccpuExecuter(['pdfinfo', $pdf], $sortie)) return 0;
    return preg_match('/^Pages:\s+(\d+)/m', (string) $sortie, $trouve) ? (int) $trouve[1] : 0;
}

/** Le dossier de travail s'en va avec ce qu'il contient. */
function commandeLignesNettoyer(string $dossier): void
{
    foreach (glob($dossier . '/*') ?: [] as $fichier) @unlink($fichier);
    @rmdir($dossier);
}

/**
 * Les articles trouvés dans les mots d'une page.
 *
 * @param list<array<string, mixed>> $mots
 * @return list<array<string, mixed>>
 */
function commandeLignesDeLaPage(array $mots): array
{
    $rangees = [];
    foreach ($mots as $mot) $rangees[$mot['ligne']][] = $mot;

    $articles = [];
    foreach ($rangees as $rangee) {
        usort($rangee, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
        $textes = commandeLignesRecoller($rangee);

        $article = commandeLignesInterpreter($textes);
        if ($article) {
            $article['confiance'] = commandeLignesConfiance($rangee);
            $articles[] = $article;
            continue;
        }

        // Une référence trop longue passe à la ligne, seule, sous la sienne. Elle n'a ni
        // rang ni date : c'est une suite, et elle appartient à l'article du dessus.
        $suite = commandeLignesSuiteDeReference($textes);
        if ($suite !== null && $articles) {
            $articles[count($articles) - 1]['reference'] .= $suite;
        }
    }
    return $articles;
}

/**
 * Les mots d'une rangée, les milliers recollés.
 *
 * « 1 523,08 » s'imprime avec une espace fine, et la lecture en fait deux mots. Laissé
 * tel quel, « 523,08 » passerait pour le total de la ligne et la vérification par le
 * calcul condamnerait une quantité juste.
 *
 * Ce qui sépare une espace fine d'un saut de colonne, ce n'est pas la forme des nombres
 * — « 2 » suivi de « 744,27 » a exactement l'allure d'un millier — c'est la distance.
 * On la mesure donc, à l'échelle de la hauteur des lettres, qui vaut pour toutes les
 * résolutions.
 *
 * @param list<array<string, mixed>> $rangee triée de gauche à droite
 * @return list<string>
 */
function commandeLignesRecoller(array $rangee): array
{
    $textes = [];
    $precedent = null;

    foreach ($rangee as $mot) {
        $colle = $precedent !== null
            && preg_match('/^\d{1,3}$/', (string) $precedent['texte'])
            && preg_match('/^\d{3}(?:[.,]\d+)?$/', (string) $mot['texte'])
            && ($mot['x'] - ($precedent['x'] + $precedent['l'])) < $precedent['h'] * 0.5;

        if ($colle) {
            $textes[count($textes) - 1] .= $mot['texte'];
            // Le mot recollé occupe désormais toute la largeur des deux : un troisième
            // groupe de milliers doit se coller au même titre.
            $precedent = ['texte' => $textes[count($textes) - 1],
                          'x' => $precedent['x'], 'l' => $mot['x'] + $mot['l'] - $precedent['x'],
                          'h' => $precedent['h']];
            continue;
        }

        $textes[] = (string) $mot['texte'];
        $precedent = $mot;
    }
    return $textes;
}

/**
 * Lit une rangée de mots comme une ligne d'article, ou rend null.
 *
 * La forme attendue : un rang, une référence, une désignation, une date de livraison,
 * une unité, puis la quantité, le prix unitaire et le total.
 *
 * @param list<string> $textes
 */
function commandeLignesInterpreter(array $textes): ?array
{
    if (count($textes) < 6) return null;
    if (!preg_match('/^\d{1,3}$/', $textes[0])) return null;

    $dateAu = null;
    foreach ($textes as $rang => $texte) {
        if ($rang > 0 && preg_match('#^\d{2}/\d{2}/\d{2,4}$#', $texte)) { $dateAu = $rang; break; }
    }
    // Sans échéance ce n'est pas une ligne d'article : les totaux de bas de page ont eux
    // aussi un nombre en tête et des montants, et ils passeraient pour des articles.
    if ($dateAu === null || $dateAu < 3) return null;

    $apres = array_slice($textes, $dateAu + 1);
    $unite = '';
    if ($apres && in_array(strtoupper($apres[0]), COMMANDE_LIGNES_UNITES, true)) {
        $unite = strtoupper(array_shift($apres));
    }

    $nombres = [];
    foreach ($apres as $texte) {
        $valeur = commandeLignesNombre($texte);
        if ($valeur !== null) $nombres[] = $valeur;
    }
    if (!$nombres) return null;

    $quantite = $nombres[0];
    $unitaire = $nombres[1] ?? null;
    $total = count($nombres) >= 3 ? $nombres[count($nombres) - 1] : null;

    // Le bon se vérifie lui-même. Quand quantité × prix ne fait pas le total, c'est l'un
    // des trois qui est mal lu — « 3,00 » rendu « 300 » est l'erreur la plus commune, et
    // commander trois cents brides au lieu de trois ne se rattrape pas à la réception.
    $douteuse = false;
    if ($unitaire !== null && $total !== null && $unitaire > 0) {
        $attendue = $total / $unitaire;
        if (abs($attendue - $quantite) > max(COMMANDE_LIGNES_ECART, $attendue * COMMANDE_LIGNES_ECART)) {
            $douteuse = true;
            // Le calcul l'emporte quand il tombe juste : le total et le prix se lisent en
            // bout de ligne, là où le scan est le plus net.
            if (abs($attendue - round($attendue, 2)) < COMMANDE_LIGNES_ECART) $quantite = round($attendue, 3);
        }
    }

    return [
        'rang' => (int) $textes[0],
        'reference' => $textes[1],
        'designation' => trim(implode(' ', array_slice($textes, 2, $dateAu - 2))),
        'date_livraison' => commandeLignesDate($textes[$dateAu]),
        'unite' => $unite ?: 'u',
        'quantite' => $quantite,
        'douteuse' => $douteuse,
    ];
}

/**
 * La suite d'une référence coupée, ou null si la rangée est autre chose.
 *
 * @param list<string> $textes
 */
function commandeLignesSuiteDeReference(array $textes): ?string
{
    if (count($textes) !== 1) return null;
    $suite = $textes[0];
    return preg_match('/^[A-Za-z0-9]{1,12}$/', $suite) ? $suite : null;
}

/** Un nombre du bon : « 1 523,08 » et « 43,00 » s'écrivent à la française. */
function commandeLignesNombre(string $texte): ?float
{
    $propre = str_replace([' ', "\u{00A0}", ' '], '', $texte);
    if (!preg_match('/^-?\d+(?:[.,]\d+)?$/', $propre)) return null;
    return (float) str_replace(',', '.', $propre);
}

/** La date du bon, rendue au format de la base. */
function commandeLignesDate(string $texte): ?string
{
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{2,4})$#', $texte, $t)) return null;
    $annee = strlen($t[3]) === 2 ? '20' . $t[3] : $t[3];
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', $t[1] . '/' . $t[2] . '/' . $annee);
    return $date ? $date->format('Y-m-d') : null;
}

/** La confiance de la rangée, au plus faible de ses mots : c'est lui qui trahit la lecture. */
function commandeLignesConfiance(array $rangee): int
{
    $confiances = array_map(static fn (array $m): int => (int) $m['confiance'], $rangee);
    return $confiances ? min($confiances) : 0;
}
