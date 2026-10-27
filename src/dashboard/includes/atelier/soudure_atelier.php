<?php

/**
 * La liste soudure vue par le soudeur : la feuille qu'il travaille, et ce qu'il y écrit.
 *
 * Le tableau n'est pas inventé ici. Le bureau d'études l'imprime sur l'isométrique, avec
 * ses lignes numérotées 1, 2, 3… ; plan_table.php le reconnaît sur le PDF, page par page.
 * Le soudeur ne crée donc pas de lignes : il remplit celles qui existent déjà sur son
 * plan, et c'est pour ça qu'on sait dire « 3 sur 7 » plutôt que de compter ce qu'il a bien
 * voulu saisir.
 *
 * Ce qu'il renseigne : la date et le procédé, sur sa ligne. Ce qu'il ne touche pas : le
 * numéro — il est imprimé —, le nom du soudeur — c'est le sien, le serveur le pose —, les
 * contrôles RT/VI/PT et le visa, qui engagent l'encadrement.
 *
 * Voir soudure.php pour la même liste côté encadrement, qui elle peut tout reprendre.
 */

require_once __DIR__ . '/soudure.php';
require_once __DIR__ . '/plan_document.php';
require_once __DIR__ . '/avancement.php';
require_once __DIR__ . '/../lecture/plan_lignes.php';

/**
 * L'affectation active de cette personne sur cet élément, si elle existe.
 *
 * C'est la porte de tout ce fichier : on ne renseigne une soudure que sur un plan qui vous
 * est confié, et sur une demande encore ouverte au travail.
 *
 * @return array{id:int, id_element:int, type_affectation:string}|null
 */
function soudureAtelierAffectation(PDO $db, int $elementId, int $userId): ?array
{
    $stmt = $db->prepare('SELECT a.id, a.id_element, a.type_affectation
        FROM affectation_atelier a
        JOIN element_atelier e ON e.id = a.id_element
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE a.id_element = ? AND a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
        LIMIT 1');
    $stmt->execute([$elementId, $userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Combien de lignes la liste soudure imprimée porte sur cette page, et combien de place
 * reste sous le tableau.
 *
 * La reconnaissance est gardée à côté du plan — c'est planLignesAnalyse qui s'en charge —
 * mais la première lecture d'une page coûte quelques secondes. Elle n'a donc sa place que
 * dans une action qu'on déclenche en ouvrant une feuille, jamais dans le rendu d'une liste
 * d'écran : quinze pages à relire feraient attendre une demi-minute devant une page vide.
 *
 * @return array{imprimees:int, libres:int}
 */
function soudureAtelierTableauImprime(array $plan, int $page): array
{
    $analyse = planLignesAnalyse(
        $plan['chemin'],
        planLignesCache((int) $plan['id_demande'], (string) $plan['cle_document'], $page),
        $page
    );
    $soudures = $analyse['soudures'] ?? null;
    return [
        'imprimees' => $soudures ? count($soudures['lignes']) : 0,
        'libres' => (int) ($soudures['libres'] ?? 0),
    ];
}

/**
 * La feuille telle que le soudeur doit la voir : une ligne par repère imprimé sur le plan,
 * remplie de ce qui est déjà en base.
 *
 * Les lignes déjà saisies au-delà du tableau imprimé — le bureau d'études ne prévoit pas
 * toujours assez de place — suivent à la fin : elles existent, et les cacher ferait croire
 * à une perte.
 *
 * @return array{lignes: list<array<string, mixed>>, imprimees:int, faites:int, miennes:int}
 */
function soudureAtelierFeuille(PDO $db, int $elementId, array $plan, int $page, int $soudeurId): array
{
    $imprime = soudureAtelierTableauImprime($plan, $page);
    $imprimees = $imprime['imprimees'];

    // Ce qui est déjà en base pour cette page, rangé par numéro. On ignore les lignes
    // seulement proposées par un pointage : elles n'ont pas de repère décidé.
    $saisies = [];
    foreach (soudureLignes($db, $elementId, (int) $plan['id'], $page) as $ligne) {
        if (!empty($ligne['numero_propose'])) continue;
        $saisies[(string) $ligne['numero']] = $ligne;
    }

    // Ce que le chef a déjà validé : une ligne saisie après sa dernière validation
    // attend la prochaine. Le soudeur voyait « 1 soudure enregistrée », en vert, et rien
    // ne lui disait ensuite qu'elle attendait encore.
    $jusqua = avancementValideJusqua($db, $elementId);

    $lignes = [];
    $faites = 0;
    $miennes = 0;
    $enAttente = 0;
    $voir = static function (string $numero, ?array $saisie, bool $imprimee) use ($soudeurId, $jusqua, &$faites, &$miennes, &$enAttente): array {
        $aMoi = $saisie !== null && (int) ($saisie['id_soudeur'] ?? 0) === $soudeurId;
        $remplie = $saisie !== null && (trim((string) $saisie['type_soudure']) !== '' || !empty($saisie['date_soudure']));
        $validee = !$remplie || avancementCordonValide($saisie ?? [], $jusqua);
        if ($remplie) $faites++;
        if ($remplie && $aMoi) $miennes++;
        if ($remplie && !$validee) $enAttente++;
        return [
            'numero' => $numero,
            'imprimee' => $imprimee,
            'date' => (string) ($saisie['date_soudure'] ?? ''),
            'type' => (string) ($saisie['type_soudure'] ?? ''),
            'soudeur' => (string) ($saisie['soudeur_nom'] ?? ''),
            'rt' => (string) ($saisie['rt'] ?? ''),
            'vi' => (string) ($saisie['vi'] ?? ''),
            'pt' => (string) ($saisie['pt'] ?? ''),
            'visa' => (string) ($saisie['visa_nom'] ?? ''),
            'remplie' => $remplie,
            // Validée, donc écrite sur le plan. Sinon elle attend le chef, et la feuille le
            // dit — ligne par ligne et en un mot au-dessus du tableau.
            'validee' => $validee,
            // Une soudure posée par quelqu'un d'autre se lit, ne se reprend pas : son nom
            // est sur le plan final, et ce n'est pas au voisin de le changer.
            'a_moi' => $aMoi,
            'verrouillee' => $remplie && !$aMoi,
        ];
    };

    for ($rang = 1; $rang <= $imprimees; $rang++) {
        $numero = (string) $rang;
        $lignes[] = $voir($numero, $saisies[$numero] ?? null, true);
        unset($saisies[$numero]);
    }
    // Le reste : des repères saisis hors du tableau imprimé, ou toutes les lignes quand la
    // reconnaissance n'a rien donné sur cette page.
    foreach ($saisies as $numero => $saisie) {
        $lignes[] = $voir((string) $numero, $saisie, false);
    }

    return ['lignes' => $lignes, 'imprimees' => $imprimees, 'faites' => $faites,
        'miennes' => $miennes, 'enAttente' => $enAttente];
}

/**
 * Le soudeur enregistre ses lignes sur une page : date et procédé, rien d'autre.
 *
 * Une ligne vidée est retirée — c'est ainsi qu'on revient sur une erreur —, mais seulement
 * si elle est à lui. Les contrôles et le visa d'une ligne existante sont repris tels
 * quels : ils ne passent pas par ce formulaire, et les perdre en renseignant une date
 * effacerait le travail de l'encadrement.
 *
 * @param array<string, array{date?:string, type?:string}> $saisies indexé par numéro
 * @return array{ecrites:int, retirees:int}
 */
function soudureAtelierEnregistrer(PDO $db, int $elementId, array $plan, int $page, array $saisies, int $soudeurId): array
{
    $documentId = (int) $plan['id'];
    $feuille = soudureAtelierFeuille($db, $elementId, $plan, $page, $soudeurId);
    $connues = [];
    foreach ($feuille['lignes'] as $ligne) $connues[$ligne['numero']] = $ligne;

    $existante = $db->prepare('SELECT * FROM plan_soudure
        WHERE id_element = ? AND id_document = ? AND page = ? AND numero = ?');
    $poser = $db->prepare('INSERT INTO plan_soudure
            (id_element, id_document, page, numero, id_soudeur, date_soudure, type_soudure, saisie_par)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id_soudeur = VALUES(id_soudeur), date_soudure = VALUES(date_soudure),
            type_soudure = VALUES(type_soudure), saisie_par = VALUES(saisie_par)');
    $retirer = $db->prepare('DELETE FROM plan_soudure
        WHERE id_element = ? AND id_document = ? AND page = ? AND numero = ? AND id_soudeur = ?');

    $ecrites = 0;
    $retirees = 0;
    foreach ($saisies as $numero => $valeurs) {
        $numero = trim(mb_substr((string) $numero, 0, 20));
        // Un repère que la feuille ne porte pas n'a pas à arriver ici : le formulaire les
        // rend tous, et en accepter d'autres laisserait inventer des lignes.
        if ($numero === '' || !isset($connues[$numero])) continue;
        if ($connues[$numero]['verrouillee']) continue;

        $date = trim((string) ($valeurs['date'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : null;
        $type = trim(mb_substr((string) ($valeurs['type'] ?? ''), 0, 30));

        if ($date === null && $type === '') {
            // Vidée : la ligne redevient une soudure à faire. Seulement la sienne — la
            // requête le redit, pour que ce soit vrai même si la page était périmée.
            $retirer->execute([$elementId, $documentId, $page, $numero, $soudeurId]);
            $retirees += $retirer->rowCount();
            continue;
        }
        $existante->execute([$elementId, $documentId, $page, $numero]);
        $avant = $existante->fetch();
        if ($avant && (int) $avant['id_soudeur'] !== $soudeurId && (int) $avant['id_soudeur'] !== 0) continue;

        $poser->execute([$elementId, $documentId, $page, $numero, $soudeurId, $date, $type, $soudeurId]);
        $ecrites++;
    }
    return ['ecrites' => $ecrites, 'retirees' => $retirees];
}

/**
 * Les plans confiés à quelqu'un, en une seule requête : de quoi les nommer et les choisir,
 * sans rien lire des PDF. Le nombre de feuilles est la somme des pages de leurs ISO,
 * retenue au dépôt.
 *
 * C'est le sélecteur de « Mes soudures » qui s'en sert : il peut y avoir des centaines de
 * plans, et l'écran ne lit ensuite les feuilles que de celui qu'on ouvre.
 *
 * @return list<array{element: int, reference: string, demande: int, affaire: string, feuilles: int}>
 */
function soudureAtelierPlans(PDO $db, int $userId): array
{
    $stmt = $db->prepare("SELECT e.id, e.reference, e.id_demande, d.nom_affaire,
            COALESCE(SUM(GREATEST(COALESCE(doc.pages, 1), 1)), 0) AS feuilles
        FROM (SELECT DISTINCT id_element FROM affectation_atelier WHERE id_utilisateur = ? AND actif = 1) a
        JOIN element_atelier e ON e.id = a.id_element
        JOIN demande_prefabrication d ON d.id = e.id_demande
        LEFT JOIN element_atelier_document doc ON doc.id_element = e.id AND doc.type_document = 'plan'
        WHERE d.id_statut = 2
        GROUP BY e.id, e.reference, e.id_demande, d.nom_affaire
        ORDER BY e.id_demande DESC, e.reference");
    $stmt->execute([$userId]);
    return array_map(static fn (array $ligne): array => [
        'element' => (int) $ligne['id'],
        'reference' => (string) $ligne['reference'],
        'demande' => (int) $ligne['id_demande'],
        'affaire' => (string) ($ligne['nom_affaire'] ?? ''),
        'feuilles' => (int) $ligne['feuilles'],
    ], $stmt->fetchAll());
}

/**
 * Les feuilles ISO que cette personne peut travailler : une entrée par page de cahier,
 * avec où en est chacune.
 *
 * Une liste déroulante les cachait toutes derrière un seul contrôle. Un cahier en porte
 * quinze, et il fallait les ouvrir une à une pour savoir laquelle restait à faire : elles
 * se montrent donc en tableau. Chaque entrée porte le numéro de ligne imprimé sur la
 * feuille — c'est ce que l'atelier lit sur le papier qu'il a en main — et le compte de ses
 * cordons.
 *
 * Le dénominateur ne vient que du cache : aucun PDF n'est relu ici. La reconnaissance
 * coûte quelques secondes par page, et quinze feuilles feraient attendre une demi-minute
 * devant un écran vide. Une feuille jamais ouverte rend donc `imprimees` à null, et le
 * tableau le dit au lieu d'annoncer un total qu'il ne connaît pas.
 *
 * @return list<array{element:int, reference:string, libelle:string, demande:int, document:int,
 *                    nom:string, numero:string, page:int, pages:int, faites:int, imprimees:?int}>
 */
function soudureAtelierFeuilles(PDO $db, int $userId, ?int $seulPlan = null): array
{
    // « $seulPlan » : l'écran ne lit plus que le plan ouvert. Lire les feuilles de tous les
    // plans confiés relisait l'analyse de chaque page de chaque PDF à chaque affichage —
    // une seconde pour dix plans, une minute pour six cents.
    $stmt = $db->prepare('SELECT DISTINCT e.id, e.reference, e.libelle, e.id_demande
        FROM affectation_atelier a
        JOIN element_atelier e ON e.id = a.id_element
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2'
        . ($seulPlan !== null ? ' AND e.id = ?' : '') . '
        ORDER BY e.id_demande DESC, e.reference');
    $stmt->execute($seulPlan !== null ? [$userId, $seulPlan] : [$userId]);

    // Les cordons renseignés, rangés par feuille et par page : une lecture par plan, et
    // non une par ligne du tableau. Même condition qu'avancementSouduresFaites — une
    // ligne compte dès qu'elle porte une date ou un procédé.
    $comptes = $db->prepare("SELECT id_document, page, COUNT(*) AS faites FROM plan_soudure
        WHERE id_element = ? AND (TRIM(type_soudure) <> '' OR date_soudure IS NOT NULL)
        GROUP BY id_document, page");

    $feuilles = [];
    foreach ($stmt->fetchAll() as $element) {
        $elementId = (int) $element['id'];
        $comptes->execute([$elementId]);
        $faites = [];
        foreach ($comptes->fetchAll() as $compte) {
            $faites[(int) $compte['id_document'] . ':' . (int) $compte['page']] = (int) $compte['faites'];
        }

        foreach (planDocuments($db, $elementId) as $doc) {
            $plan = planDocumentChoisi($db, $elementId, (int) $doc['id']);
            $lisible = $plan !== null && is_file($plan['chemin']);
            for ($page = 1; $page <= max(1, (int) $doc['pages']); $page++) {
                $analyse = $lisible ? planLignesAnalyseGardee(
                    $plan['chemin'],
                    planLignesCache((int) $plan['id_demande'], (string) $plan['cle_document'], $page)
                ) : null;
                $feuilles[] = [
                    'element' => $elementId,
                    'reference' => (string) $element['reference'],
                    'libelle' => (string) $element['libelle'],
                    'demande' => (int) $element['id_demande'],
                    'document' => (int) $doc['id'],
                    'nom' => preg_replace('/\.pdf$/i', '', (string) $doc['nom']),
                    // Le numéro de ligne, relevé au dépôt. Le nom du fichier sert de
                    // secours : un cahier sans numéros reconnus en est réduit à ses pages.
                    'numero' => (string) ($doc['numeros_ligne'][$page] ?? ''),
                    'page' => $page,
                    'pages' => max(1, (int) $doc['pages']),
                    'faites' => $faites[(int) $doc['id'] . ':' . $page] ?? 0,
                    'imprimees' => $analyse === null ? null : count($analyse['soudures']['lignes'] ?? []),
                ];
            }
        }
    }
    return $feuilles;
}

/**
 * Où en est une feuille, en deux mots et dans la couleur que la maison donne déjà à cet
 * état-là — la même pastille que sur l'écran du chef.
 *
 * Elle est ici, et non dans l'écran, parce que le tableau la rend au chargement et que les
 * actions la renvoient après chaque enregistrement : deux listes de libellés à tenir
 * d'accord finiraient par ne plus l'être.
 *
 * `$imprimees` à null veut dire « feuille jamais ouverte » : son tableau n'a pas encore
 * été reconnu, et on ne sait donc pas combien de cordons elle demande. Zéro veut dire
 * « lue, mais sans liste soudure » — un scan, ou un gabarit que la détection ne sait pas
 * lire. Les deux se disent, parce qu'on n'y répond pas de la même façon.
 *
 * @return array{cle:string, libelle:string, classe:string}
 */
function soudureAtelierEtatFeuille(int $faites, ?int $imprimees): array
{
    if ($imprimees === null) {
        return ['cle' => 'non_lue', 'libelle' => 'Pas encore ouverte', 'classe' => 'atelier-muted'];
    }
    if ($imprimees === 0) {
        return $faites > 0
            ? ['cle' => 'en_cours', 'libelle' => 'En cours', 'classe' => 'atelier-status-progress']
            : ['cle' => 'sans_liste', 'libelle' => 'Aucune liste', 'classe' => 'atelier-muted'];
    }
    if ($faites >= $imprimees) {
        return ['cle' => 'soudee', 'libelle' => 'Soudée', 'classe' => 'status-2'];
    }
    return $faites > 0
        ? ['cle' => 'en_cours', 'libelle' => 'En cours', 'classe' => 'atelier-status-progress']
        : ['cle' => 'a_faire', 'libelle' => 'À faire', 'classe' => 'atelier-muted'];
}

/** Le compte d'une feuille, tel qu'il s'écrit dans le tableau : « 3 / 7 », ou « 3 / — ». */
function soudureAtelierCompteFeuille(int $faites, ?int $imprimees): string
{
    return $faites . ' / ' . ($imprimees === null ? '—' : $imprimees);
}
