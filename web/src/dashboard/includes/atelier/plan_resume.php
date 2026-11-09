<?php

/**
 * Le résumé d'un plan : ce qui lui manque encore, feuille ISO par feuille ISO.
 *
 * Deux choses, celles que le plan final écrit : les soudures — renseignées par l'atelier,
 * puis validées par le chef —, et les numéros de coulée de la nomenclature, que les
 * certificats matière de la demande (CCPU) doivent couvrir. Le chef l'ouvre depuis le volet
 * du plan, « Résumé du plan » : un cahier de quinze feuilles ne se vérifie pas en les
 * ouvrant une à une.
 *
 * Rien n'est relu des PDF : la liste soudure et la nomenclature de chaque page ont été lues
 * au dépôt (plan_index.php). Une page que la lecture n'a pas atteinte le dit, au lieu
 * d'annoncer un compte qu'on ne connaît pas.
 */

require_once __DIR__ . '/plan_document.php';
require_once __DIR__ . '/../lecture/plan_index.php';

/**
 * Ce qui manque à un plan, page par page de ses cahiers.
 *
 * @return array{
 *     certificats: list<array{nom:string, certificat:string, coulees:list<string>}>,
 *     feuilles: list<array{document:int, page:int, nom:string, imprimees:?int,
 *         a_renseigner:list<int>, a_valider:list<string>, nomenclature:string,
 *         sans_coulee:list<array{repere:string, article:string}>,
 *         hors_ccpu:list<array{repere:string, article:string, coulee:string}>}>
 * }
 */
function planResume(PDO $db, int $elementId): array
{
    $demande = $db->prepare('SELECT id_demande FROM element_atelier WHERE id = ?');
    $demande->execute([$elementId]);
    $requestId = (int) $demande->fetchColumn();

    // Les certificats de la demande, et les coulées qu'on y a lues.
    $certificats = [];
    $stmt = $db->prepare('SELECT d.id, d.nom, d.certificat, c.numero FROM demande_ccpu_documents d
        LEFT JOIN demande_ccpu_coulees c ON c.id_document = d.id
        WHERE d.id_demande = ? ORDER BY d.id, c.numero');
    $stmt->execute([$requestId]);
    foreach ($stmt->fetchAll() as $ligne) {
        $certificats[(int) $ligne['id']] ??= ['nom' => (string) $ligne['nom'],
            'certificat' => trim((string) ($ligne['certificat'] ?? '')), 'coulees' => []];
        if ($ligne['numero'] !== null) $certificats[(int) $ligne['id']]['coulees'][] = (string) $ligne['numero'];
    }

    // Les soudures renseignées — une date ou un procédé, comme avancementSouduresFaites() —,
    // et si le chef les a visées.
    $saisies = [];
    $stmt = $db->prepare("SELECT id_document, page, numero, vise_par FROM plan_soudure
        WHERE id_element = ? AND (TRIM(type_soudure) <> '' OR date_soudure IS NOT NULL)");
    $stmt->execute([$elementId]);
    foreach ($stmt->fetchAll() as $soudure) {
        $saisies[$soudure['id_document'] . ':' . $soudure['page']][(string) $soudure['numero']] = $soudure['vise_par'] !== null;
    }

    // Les cases de coulée enregistrées, page par page.
    $cases = [];
    $stmt = $db->prepare('SELECT id_document, page, pos_y, pos_h, description, numero_coulee, id_coulee
        FROM plan_nomenclature WHERE id_element = ? ORDER BY rang');
    $stmt->execute([$elementId]);
    foreach ($stmt->fetchAll() as $case) $cases[$case['id_document'] . ':' . $case['page']][] = $case;

    $feuilles = [];
    foreach (planDocuments($db, $elementId) as $doc) {
        $cle = (string) $doc['cle_document'];
        $source = prefaAttachmentDirectory($requestId) . '/' . $cle;
        // Le compte de chaque page, dans le repère du cahier quand il est à jour.
        $index = planIndexLu($requestId, $cle);
        for ($page = 1; $page <= $doc['pages']; $page++) {
            $clePage = $doc['id'] . ':' . $page;
            // La liste soudure imprimée : combien de lignes, numérotées 1, 2, 3…
            $imprimees = $index['soudures'][$page - 1] ?? null;
            if ($imprimees === null) {
                $analyse = planLignesAnalyseGardee($source, planLignesCache($requestId, $cle, $page));
                $imprimees = $analyse === null ? null : count($analyse['soudures']['lignes'] ?? []);
            }
            $faites = $saisies[$clePage] ?? [];
            $aRenseigner = [];
            for ($numero = 1; $numero <= (int) $imprimees; $numero++) {
                if (!isset($faites[(string) $numero])) $aRenseigner[] = $numero;
            }
            $aValider = array_map('strval', array_keys(array_filter($faites, static fn (bool $visee): bool => !$visee)));
            sort($aValider, SORT_NATURAL);

            $numero = (string) ($doc['numeros_ligne'][$page] ?? '');
            $feuilles[] = [
                'document' => (int) $doc['id'],
                'page' => $page,
                // Le nom que porte le papier : son numéro de ligne, sinon le fichier et sa page.
                'nom' => $numero !== '' ? $numero
                    : preg_replace('/\.pdf$/i', '', (string) $doc['nom']) . ($doc['pages'] > 1 ? ' — p. ' . $page : ''),
                'imprimees' => $imprimees,
                'a_renseigner' => $aRenseigner,
                'a_valider' => $aValider,
            ] + planResumeCoulees(planNomenclatureGardee($source, planNomenclatureCache($requestId, $cle, $page)),
                $cases[$clePage] ?? []);
        }
    }
    return ['certificats' => array_values($certificats), 'feuilles' => $feuilles];
}

/**
 * Les lignes de la nomenclature d'une page qui n'ont pas de coulée, ou une coulée qu'aucun
 * certificat de la demande ne porte — tapée à la main, elle n'est tracée nulle part.
 *
 * Une case va à la ligne qu'elle couvre sur le dessin : sa hauteur, pas son rang. Le chef
 * en ajoute et en retire, et les rangs se renumérotent ; la coulée de la ligne 4 se serait
 * retrouvée en face de la ligne 3.
 *
 * @param array{nomenclature: ?array}|null $lue la nomenclature gardée (planNomenclatureGardee())
 * @param list<array<string, mixed>> $cases les cases enregistrées de la page
 * @return array{nomenclature:string, sans_coulee:list<array{repere:string, article:string}>,
 *               hors_ccpu:list<array{repere:string, article:string, coulee:string}>}
 *         « nomenclature » : « lue », « non reconnue » — un scan, un autre gabarit — ou « pas lue »
 */
function planResumeCoulees(?array $lue, array $cases): array
{
    $lignes = $lue['nomenclature']['lignes'] ?? [];
    $sans = [];
    $hors = [];
    $prises = [];
    foreach ($lignes as $rang => $ligne) {
        $quoi = ['repere' => trim((string) ($ligne['repere'] ?? '')) ?: (string) ($rang + 1),
            'article' => planNomenclatureDesignation($ligne)];
        // La case la plus proche du milieu de la ligne, à moins d'une ligne de lui.
        $milieu = (float) $ligne['y'] + (float) $ligne['h'] / 2;
        $retenue = null;
        foreach ($cases as $i => $case) {
            if (isset($prises[$i]) || $case['pos_y'] === null) continue;
            $ecart = abs((float) $case['pos_y'] + (float) $case['pos_h'] / 2 - $milieu);
            if ($ecart <= (float) $ligne['h'] && ($retenue === null || $ecart < $retenue[1])) $retenue = [$i, $ecart];
        }
        $case = $retenue !== null ? $cases[$retenue[0]] : null;
        if ($retenue !== null) $prises[$retenue[0]] = true;

        $coulee = trim((string) ($case['numero_coulee'] ?? ''));
        if ($coulee === '') $sans[] = $quoi;
        elseif ($case['id_coulee'] === null) $hors[] = $quoi + ['coulee' => $coulee];
    }
    // Une case posée hors des lignes reconnues — sous le tableau, ou sur un scan — n'a pas de
    // ligne à manquer ; mais une coulée qu'aucun certificat ne porte s'y signale quand même.
    foreach ($cases as $i => $case) {
        $coulee = trim((string) $case['numero_coulee']);
        if (isset($prises[$i]) || $coulee === '' || $case['id_coulee'] !== null) continue;
        $hors[] = ['repere' => '', 'article' => trim((string) $case['description']), 'coulee' => $coulee];
    }
    return [
        'nomenclature' => $lue === null ? 'pas lue' : ($lignes ? 'lue' : 'non reconnue'),
        'sans_coulee' => $sans,
        'hors_ccpu' => $hors,
    ];
}

/** « 1 à 5, 8, 10, 11 » : des numéros, les suites resserrées. Un numéro qui n'en est pas un suit, tel quel. */
function planResumePlages(array $numeros): string
{
    $numeros = array_map('strval', $numeros);
    $entiers = array_values(array_unique(array_map('intval', array_filter($numeros, 'ctype_digit'))));
    sort($entiers);
    $plages = [];
    foreach ($entiers as $numero) {
        $derniere = array_key_last($plages);
        if ($derniere !== null && $plages[$derniere][1] === $numero - 1) $plages[$derniere][1] = $numero;
        else $plages[] = [$numero, $numero];
    }
    $morceaux = array_map(static fn (array $plage): string => match ($plage[1] - $plage[0]) {
        0 => (string) $plage[0],
        1 => $plage[0] . ', ' . $plage[1],
        default => $plage[0] . ' à ' . $plage[1],
    }, $plages);
    return implode(', ', [...$morceaux, ...array_filter($numeros, static fn (string $numero): bool => !ctype_digit($numero))]);
}

/**
 * Le résumé tel que sa fenêtre le montre : deux chiffres — les soudures à renseigner, à
 * valider —, puis les feuilles où il manque quelque chose — les soudures, puis les coulées.
 * Une feuille complète n'y paraît pas : la fenêtre dit ce qu'il reste à faire, pas ce qui est
 * fait.
 *
 * Les lignes sans coulée et les coulées hors CCPU avaient leur chiffre ; retirés le
 * 9 novembre 2026 à la demande de l'utilisateur. Le tableau des coulées les détaille toujours.
 *
 * Le nom de chaque feuille est un bouton : il ouvre la feuille dans la fenêtre du plan, à
 * sa page (iso.js).
 */
function planResumeRendu(array $resume): string
{
    $feuilles = $resume['feuilles'];
    $somme = static fn (string $cle): int => array_sum(array_map(static fn (array $f): int => count($f[$cle]), $feuilles));
    $aRenseigner = $somme('a_renseigner');
    $aValider = $somme('a_valider');
    $pluriel = static fn (int $n, string $un, string $plusieurs): string => $n . ' ' . ($n > 1 ? $plusieurs : $un);

    $feuille = static fn (array $f): string => '<button type="button" class="plan-resume-feuille" data-document="'
        . $f['document'] . '" data-page="' . $f['page'] . '" title="Ouvrir cette feuille">' . prefaEscape($f['nom']) . '</button>';

    // Un chiffre à zéro est ce qui est fait : il passe au vert ; le reste attend.
    $chiffre = static fn (int $n, string $texte): string => '<div class="plan-resume-chiffre '
        . ($n === 0 ? 'est-fait' : 'est-a-faire') . '"><strong>' . $n . '</strong> ' . $texte . '</div>';
    $html = '<div class="plan-resume-bilan">'
        . $chiffre($aRenseigner, $aRenseigner > 1 ? 'soudures à renseigner' : 'soudure à renseigner')
        . $chiffre($aValider, 'à valider')
        . '</div>';

    // ----- Les soudures
    $html .= '<section class="plan-resume-section" aria-labelledby="plan-resume-soudures"><h3 id="plan-resume-soudures">Soudures</h3>';
    $manquent = array_filter($feuilles, static fn (array $f): bool => $f['a_renseigner'] || $f['a_valider']);
    if ($manquent) {
        $html .= '<table class="plan-resume-table"><thead><tr><th scope="col">Feuille</th><th scope="col">À renseigner</th>'
            . '<th scope="col">À valider</th></tr></thead><tbody>';
        foreach ($manquent as $f) {
            $html .= '<tr><td>' . $feuille($f) . '</td>'
                . '<td>' . ($f['a_renseigner'] ? 'n° ' . prefaEscape(planResumePlages($f['a_renseigner'])) : '—') . '</td>'
                . '<td>' . ($f['a_valider'] ? 'n° ' . prefaEscape(planResumePlages($f['a_valider'])) : '—') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $completes = count(array_filter($feuilles, static fn (array $f): bool => $f['imprimees'] && !$f['a_renseigner'] && !$f['a_valider']));
    $nonLues = count(array_filter($feuilles, static fn (array $f): bool => $f['imprimees'] === null));
    $notes = [];
    if (!$manquent && !$nonLues) $notes[] = 'Toutes les soudures sont renseignées et validées.';
    elseif ($completes) $notes[] = ($manquent
        ? $pluriel($completes, 'autre feuille est complète', 'autres feuilles sont complètes')
        : $pluriel($completes, 'feuille est complète', 'feuilles sont complètes')) . '.';
    if ($nonLues) $notes[] = $pluriel($nonLues, 'feuille n’est pas encore lue : ses soudures ne sont pas comptées',
        'feuilles ne sont pas encore lues : leurs soudures ne sont pas comptées') . '.';
    if ($notes) $html .= '<div class="plan-resume-note">' . prefaEscape(implode(' ', $notes)) . '</div>';
    $html .= '</section>';

    // ----- Les coulées
    $html .= '<section class="plan-resume-section" aria-labelledby="plan-resume-coulees"><h3 id="plan-resume-coulees">Coulées (CCPU)</h3>';
    if ($resume['certificats']) {
        foreach ($resume['certificats'] as $certificat) {
            $html .= '<div class="plan-resume-ccpu">Certificat n° ' . prefaEscape($certificat['certificat'] !== '' ? $certificat['certificat'] : '— (non lu)')
                . ' <span class="plan-resume-discret">' . prefaEscape($certificat['nom']) . '</span> · '
                . ($certificat['coulees'] ? 'coulées ' . prefaEscape(implode(', ', $certificat['coulees'])) : 'aucune coulée lue') . '</div>';
        }
    } else {
        $html .= '<div class="plan-resume-ccpu est-alerte">Aucun CCPU déposé sur la demande : aucune coulée ne peut être tracée.</div>';
    }
    $manquent = array_filter($feuilles, static fn (array $f): bool => $f['sans_coulee'] || $f['hors_ccpu']);
    if ($manquent) {
        $reperes = static fn (array $lignes): string => implode(', ', array_map(static fn (array $l): string =>
            '<span class="plan-resume-repere" title="' . prefaEscape($l['article'] !== '' ? $l['article'] : 'Article non lu') . '">'
            . prefaEscape($l['repere'] !== '' ? $l['repere'] : '?') . (isset($l['coulee']) ? ' (' . prefaEscape($l['coulee']) . ')' : '')
            . '</span>', $lignes));
        $html .= '<table class="plan-resume-table"><thead><tr><th scope="col">Feuille</th><th scope="col">Repères sans coulée</th>'
            . '<th scope="col">Coulée hors CCPU</th></tr></thead><tbody>';
        foreach ($manquent as $f) {
            $html .= '<tr><td>' . $feuille($f) . '</td>'
                . '<td>' . ($f['sans_coulee'] ? $reperes($f['sans_coulee']) : '—') . '</td>'
                . '<td>' . ($f['hors_ccpu'] ? $reperes($f['hors_ccpu']) : '—') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $nonLues = count(array_filter($feuilles, static fn (array $f): bool => $f['nomenclature'] === 'pas lue'));
    $nonReconnues = count(array_filter($feuilles, static fn (array $f): bool => $f['nomenclature'] === 'non reconnue'));
    $notes = [];
    if (!$manquent && !$nonLues && !$nonReconnues) $notes[] = 'Chaque ligne de nomenclature a sa coulée, et chaque coulée son certificat.';
    if ($nonReconnues) $notes[] = $pluriel($nonReconnues, 'feuille n’a pas de nomenclature reconnue', 'feuilles n’ont pas de nomenclature reconnue')
        . ' — un scan, un autre gabarit : leurs coulées se vérifient sur le dessin.';
    if ($nonLues) $notes[] = $pluriel($nonLues, 'nomenclature n’est pas encore lue', 'nomenclatures ne sont pas encore lues') . '.';
    if ($notes) $html .= '<div class="plan-resume-note">' . prefaEscape(implode(' ', $notes)) . '</div>';
    return $html . '</section>';
}
