<?php

/**
 * Le numéro de ligne que porte chaque feuille d'un cahier ISO.
 *
 * Un cahier ne numérote pas ses pages, mais chaque feuille porte dans son cartouche le
 * numéro de la ligne qu'elle dessine — YU-PRE-101, YU-PRE-108A-1… C'est sous ce nom que
 * l'atelier parle de ses plans, et c'est lui qui est imprimé sur le tirage papier. Le
 * sommaire du lecteur s'en sert : on lit le numéro sur la feuille qu'on a en main, on le
 * retrouve dans la liste.
 *
 * Lecture du texte, et non reconnaissance d'image. AutoCAD trace les INTITULÉS du
 * cartouche en courbes — « Numéro de ligne », « Approuvé par » — mais pas les VALEURS :
 * celles-là restent du texte, et pdftotext les rend telles quelles. Une seule lecture
 * suffit pour tout le document, là où l'OCR demandait une demi-seconde par page.
 */

/** Ce qui ressemble à un numéro de ligne : des groupes alphanumériques liés par des tirets. */
const PLAN_NUMERO_FORME = '/^[A-Z0-9]+(?:-[A-Z0-9]+)+$/';

/**
 * Les numéros de ligne d'un cahier, page par page.
 *
 * Une page dont on ne reconnaît rien est simplement absente du tableau : un plan d'un
 * autre gabarit, ou dont le cartouche est dessiné en courbes jusqu'aux valeurs, n'est pas
 * une anomalie — le sommaire retombe alors sur le numéro de page.
 *
 * @return array<int, string> numéro de page => numéro de ligne
 */
function planNumerosDeLigne(string $cheminPdf): array
{
    if (!is_file($cheminPdf)) return [];

    $sortie = [];
    exec('pdftotext -bbox ' . escapeshellarg($cheminPdf) . ' - 2>/dev/null', $sortie, $code);
    if ($code !== 0) return [];

    $numeros = [];
    foreach (planNumerosPages(implode("\n", $sortie)) as $page => $mots) {
        $numero = planNumerosChoisir($mots);
        if ($numero !== null) $numeros[$page] = $numero;
    }
    return $numeros;
}

/**
 * Les mots de chaque page, avec leur boîte.
 *
 * @return array<int, list<array{texte:string, x:float, y:float}>>
 */
function planNumerosPages(string $xml): array
{
    $pages = [];
    $numero = 0;
    // Un découpage par balise plutôt qu'un analyseur XML : la sortie de pdftotext est
    // plate et régulière, et charger un DOM pour en tirer dix mots coûterait plus que la
    // lecture elle-même.
    foreach (preg_split('/<page\b/', $xml, -1, PREG_SPLIT_NO_EMPTY) as $bloc) {
        if (!str_contains($bloc, '<word')) continue;
        $numero++;
        $mots = [];
        if (preg_match_all('/<word xMin="([-0-9.]+)" yMin="([-0-9.]+)" xMax="[-0-9.]+" yMax="[-0-9.]+">(.*?)<\/word>/s', $bloc, $trouves, PREG_SET_ORDER)) {
            foreach ($trouves as $mot) {
                $texte = trim(html_entity_decode($mot[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($texte !== '') $mots[] = ['texte' => $texte, 'x' => (float) $mot[1], 'y' => (float) $mot[2]];
            }
        }
        $pages[$numero] = $mots;
    }
    return $pages;
}

/**
 * Le numéro de ligne parmi les mots d'une page.
 *
 * @param list<array{texte:string, x:float, y:float}> $mots
 */
function planNumerosChoisir(array $mots): ?string
{
    $candidats = array_values(array_filter($mots, static fn (array $mot): bool => planNumerosRessemble($mot['texte'])));
    if (!$candidats) return null;
    if (count($candidats) === 1) return $candidats[0]['texte'];

    // Plusieurs prétendants : on retient celui de la rangée du cartouche. C'est la plus
    // fournie — intitulé de l'ouvrage, numéro, dessinateur, approbateur, date, indice se
    // tiennent tous sur une même ligne de base, quand le reste de la feuille n'a que des
    // mots isolés. À égalité, le plus à gauche, puisque le numéro précède les noms.
    $parRangee = [];
    foreach ($mots as $mot) {
        $parRangee[(string) round($mot['y'], 1)][] = $mot;
    }
    $peuplement = static function (array $mot) use ($parRangee): int {
        return count($parRangee[(string) round($mot['y'], 1)] ?? []);
    };
    usort($candidats, static function (array $a, array $b) use ($peuplement): int {
        return [$peuplement($b), -$a['x']] <=> [$peuplement($a), -$b['x']];
    });
    return $candidats[0]['texte'];
}

/** Un numéro de ligne porte au moins une lettre : « 08-09-26 » est une date, pas un repère. */
function planNumerosRessemble(string $texte): bool
{
    $texte = strtoupper($texte);
    if (mb_strlen($texte) < 3 || mb_strlen($texte) > 40) return false;
    if (!preg_match(PLAN_NUMERO_FORME, $texte)) return false;
    return (bool) preg_match('/[A-Z]/', $texte);
}
