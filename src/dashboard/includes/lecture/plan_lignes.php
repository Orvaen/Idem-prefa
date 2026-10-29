<?php

require_once __DIR__ . '/../demande/prefa_attachments.php';
require_once __DIR__ . '/plan_table.php';
require_once __DIR__ . '/../atelier/soudure.php';
require_once __DIR__ . '/../atelier/avancement.php';

/**
 * Ce qui s'écrit sur un plan : les tableaux qu'on y a lus, et la liste soudure rangée
 * comme sur la feuille. Calculé ici une seule fois, pour le lecteur comme pour le plan
 * final — ce qui sort du PDF doit être ce qu'on a vu à l'écran.
 */

/**
 * À incrémenter dès que la détection rend autre chose. Sans lui, un cache écrit par
 * l'ancien code survivrait au nouveau : le plan n'ayant pas bougé, il serait resservi tel
 * quel et personne ne verrait jamais le changement.
 */
const PLAN_LIGNES_VERSION = 11;

/**
 * Un cache par page : les pages d'un cahier n'ont ni le même tableau soudure ni le même
 * dessin. La première garde le nom d'origine, pour que les caches déjà écrits servent.
 */
function planLignesCache(int $requestId, string $cle, int $page = 1): string
{
    return prefaAttachmentDirectory($requestId) . '/lignes-' . substr($cle, 0, 32)
        . ($page > 1 ? '-p' . $page : '') . '.json';
}

/**
 * Ce qui est déjà gardé pour cette page, sans jamais relire le PDF.
 *
 * La détection coûte quelques secondes par page : un écran qui doit parler de quinze
 * feuilles ne peut pas la déclencher, il attendrait une demi-minute devant du vide. Il
 * lit donc ce qui est connu, et dit franchement ce qui ne l'est pas encore.
 *
 * @return array{version:int, cases:list<array>, soudures:?array, pages:int}|null
 */
function planLignesAnalyseGardee(string $source, string $cache): ?array
{
    if (!is_file($cache) || !is_file($source) || filemtime($cache) < filemtime($source)) return null;
    $analyse = json_decode((string) file_get_contents($cache), true);
    return is_array($analyse) && ($analyse['version'] ?? 0) === PLAN_LIGNES_VERSION ? $analyse : null;
}

/**
 * Les deux tableaux du plan, sur la page demandée. La détection est déterministe : son
 * résultat est gardé à côté du plan et refait seulement si le fichier a changé.
 *
 * @return array{version:int, cases:list<array>, soudures:?array, pages:int}
 */
function planLignesAnalyse(string $source, string $cache, int $page = 1): array
{
    $garde = planLignesAnalyseGardee($source, $cache);
    if ($garde !== null) return $garde;
    // Quelques secondes au pire sur un gros plan : la session est rendue avant, sinon les
    // autres pages de la même personne attendraient derrière celle-ci.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $analyse = [
        'version' => PLAN_LIGNES_VERSION,
        // Chaque page d'un cahier a sa nomenclature imprimée, donc ses propres cases.
        'cases' => planTableDetecter($source, $page),
        'soudures' => planTableSoudures($source, $page),
        // Retenu ici parce qu'on tient le fichier ouvert : le volet s'en sert pour dire à
        // l'atelier qu'il travaille sur la première page d'un cahier.
        'pages' => planLignesNombreDePages($source),
    ];
    @file_put_contents($cache, json_encode($analyse));
    return $analyse;
}

/** Le nombre de pages d'un PDF, ou 0 s'il est illisible. */
function planLignesNombreDePages(string $pdf): int
{
    $sortie = [];
    exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null', $sortie);
    return preg_match('/^Pages:\s+(\d+)/m', implode("\n", $sortie), $m) ? (int) $m[1] : 0;
}

/**
 * La liste soudure, rangée comme sur le plan : soudeur, date, procédé, contrôles, visa.
 *
 * La soudure n° 3 va sur la ligne 3 du plan, pas à la troisième place libre : le bureau
 * d'études imprime déjà 1, 2, 3… dans la colonne de gauche, et c'est ce numéro qui
 * désigne le cordon sur le dessin. Ranger dans l'ordre d'arrivée ferait mentir le plan.
 * Pour la même raison la colonne du numéro reste vide : elle est déjà écrite.
 *
 * Le bureau d'études ne prévoit pas toujours assez de lignes : un plan à cinq lignes peut
 * recevoir huit cordons. Les suivants vont sous le tableau, dans la place mesurée comme
 * libre — au-delà, on buterait sur le dessin ou le cartouche.
 *
 * @return array{valeurs: array<int, list<string>>, horsTableau: list<string>, proposees: int,
 *               imprimees: int, occupees: int}
 */
function planLignesSoudures(PDO $db, int $elementId, int $documentId, ?array $soudures, int $page = 1): array
{
    $imprimees = $soudures ? count($soudures['lignes']) : 0;
    $capacite = $imprimees + (int) ($soudures['libres'] ?? 0);

    $valeurs = [];
    $horsTableau = [];
    $proposees = 0;
    // Les cases que le plan porte déjà et qu'on laisse donc tranquilles : l'atelier doit
    // savoir que ce qu'il a saisi ne s'écrira pas à cet endroit.
    $occupees = 0;
    // Ce qui est saisi mais pas encore validé : le plan ne le porte pas, et il faut
    // pouvoir le dire — sinon on croit avoir perdu sa saisie.
    $enAttente = 0;
    $jusqua = avancementValideJusqua($db, $elementId);
    foreach (soudureLignes($db, $elementId, $documentId, $page) as $soudure) {
        $numero = (string) $soudure['numero'];
        if ($numero === '') continue;
        // Une ligne venue d'un pointage porte un numéro proposé : elle se compte à part
        // pour être signalée.
        if (!empty($soudure['numero_propose'])) $proposees++;
        // Le plan ne porte que du validé : un cordon s'y écrit quand le chef a validé la
        // déclaration qui le couvre, et pas avant.
        if (!avancementCordonValide($soudure, $jusqua)) { $enAttente++; continue; }
        $rang = ctype_digit($numero) ? (int) $numero - 1 : -1;
        if ($rang < 0 || $rang >= $capacite) { $horsTableau[] = $numero; continue; }
        // Même règle que le dessin : une case déjà écrite sur la feuille n'est pas
        // recouverte. On les compte pour pouvoir le dire.
        if ($rang < $imprimees) {
            foreach (array_slice($soudures['lignes'][$rang]['cellules'] ?? [], 1) as $ecrite) {
                if ($ecrite) $occupees++;
            }
        }
        $valeurs[$rang] = [
            // Le plan imprime lui-même les numéros de ses lignes, d'où la case laissée vide.
            // Ceux des rangs ajoutés sous le tableau, personne ne les a écrits.
            $rang < $imprimees ? '' : $numero,
            (string) ($soudure['soudeur_nom'] ?? ''),
            $soudure['date_soudure'] ? date('d/m/y', strtotime((string) $soudure['date_soudure'])) : '',
            (string) $soudure['type_soudure'],
            (string) $soudure['rt'],
            (string) $soudure['vi'],
            (string) $soudure['pt'],
            (string) ($soudure['visa_nom'] ?? ''),
        ];
    }
    return ['valeurs' => $valeurs, 'horsTableau' => $horsTableau, 'proposees' => $proposees,
        'imprimees' => $imprimees, 'occupees' => $occupees, 'enAttente' => $enAttente];
}

/**
 * La case VISA est la huitième de la liste soudure, après les trois contrôles. Le nom de
 * celui qui a validé s'y écrit comme une signature (ecriture_visa.php).
 */
const PLAN_LIGNES_VISA = 7;

/**
 * Où écrire chaque valeur de la liste soudure, et quels traits ajouter sous le tableau
 * quand il manque des lignes. Même calcul que le lecteur (remplirSoudures).
 *
 * Le nom de la case VISA est marqué « visa » : il s'écrit comme une signature, dans
 * l'écriture du visa, et non dans le gras du reste de la liste.
 *
 * @param array<int, list<string>> $valeurs
 * @return array{textes: list<array{texte:string, x:float, y:float, w:float, h:float, visa?:bool}>,
 *               traits: list<array{x1:float, y1:float, x2:float, y2:float}>}
 */
function planLignesSoudureDessin(?array $soudures, array $valeurs): array
{
    if (!$soudures || !$valeurs) return ['textes' => [], 'traits' => []];

    $imprimees = count($soudures['lignes']);
    $ajoutees = min(max(0, max(array_keys($valeurs)) + 1 - $imprimees), (int) ($soudures['libres'] ?? 0));
    $pas = (float) ($soudures['pas'] ?? 0);
    $bas = (float) ($soudures['bas'] ?? 0);

    // Les lignes ajoutées reprennent le pas et les bords du tableau imprimé : un trait
    // sous chaque rang, et les séparateurs prolongés d'un seul tenant.
    $traits = [];
    $bords = $soudures['bords'] ?? [];
    if ($ajoutees > 0 && count($bords) >= 2 && $pas > 0) {
        for ($rang = 1; $rang <= $ajoutees; $rang++) {
            $y = $bas + $rang * $pas;
            $traits[] = ['x1' => $bords[0], 'y1' => $y, 'x2' => end($bords), 'y2' => $y];
        }
        foreach ($bords as $x) {
            $traits[] = ['x1' => $x, 'y1' => $bas, 'x2' => $x, 'y2' => $bas + $ajoutees * $pas];
        }
    }

    $textes = [];
    foreach ($valeurs as $rang => $donnees) {
        if ($rang >= $imprimees + $ajoutees) continue;
        $ligne = $rang < $imprimees
            ? $soudures['lignes'][$rang]
            : ['y' => $bas + ($rang - $imprimees + PLAN_TABLE_RETRAIT) * $pas, 'h' => $pas * (1 - 2 * PLAN_TABLE_RETRAIT)];
        // Ce que la feuille porte déjà à cet endroit. Les rangs tracés sous le tableau
        // sont vierges par construction : rien n'y a jamais été imprimé.
        $ecrites = $rang < $imprimees ? ($soudures['lignes'][$rang]['cellules'] ?? []) : [];
        foreach ($soudures['colonnes'] as $index => $colonne) {
            $texte = trim((string) ($donnees[$index] ?? ''));
            if ($texte === '') continue;
            // Une case déjà écrite est laissée telle quelle : un plan final redéposé, ou
            // une ligne remplie à la main, porte son texte — le doubler le rend illisible.
            if (!empty($ecrites[$index])) continue;
            $textes[] = ['texte' => $texte, 'x' => (float) $colonne['x'], 'y' => (float) $ligne['y'],
                'w' => (float) $colonne['w'], 'h' => (float) $ligne['h']]
                + ($index === PLAN_LIGNES_VISA ? ['visa' => true] : []);
        }
    }
    return ['textes' => $textes, 'traits' => $traits];
}
