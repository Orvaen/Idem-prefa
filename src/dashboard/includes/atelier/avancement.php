<?php

/**
 * L'avancement d'une affectation : déclaré par l'atelier, validé par le chef.
 *
 * Deux écritures distinctes, et c'est tout l'objet de ce fichier :
 *
 * - la personne affectée **déclare** — sa ligne part en « propose » et ne déplace rien ;
 * - le chef d'atelier **tranche** — il valide, et c'est cette validation qui écrit le
 *   pourcentage sur l'affectation ; ou il refuse, et rien ne bouge.
 *
 * Le chef peut aussi poser un pourcentage lui-même, sans déclaration : il est l'autorité,
 * sa saisie est validée d'office et porte son nom comme validateur.
 *
 * `affectation_atelier.avancement` ne contient donc plus que du reconnu. Le planning et
 * les listes qui le lisent n'ont rien à changer : ils montraient déjà cette colonne.
 */

/**
 * Les soudures faites : les lignes de la liste qui portent une date ou un procédé.
 *
 * C'est le numérateur. Une ligne n'existe en base que lorsqu'elle a été renseignée —
 * ni le soudeur ni l'encadrement n'enregistrent de ligne vide — mais la condition est
 * écrite quand même : elle dit ce qu'on compte, et ne dépend pas de cette habitude.
 */
function avancementSouduresFaites(PDO $db, int $elementId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM plan_soudure
        WHERE id_element = ? AND (TRIM(type_soudure) <> '' OR date_soudure IS NOT NULL)");
    $stmt->execute([$elementId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Les soudures prévues : les lignes que le bureau d'études a imprimées sur les feuilles.
 *
 * C'est le dénominateur, et il ne vient pas de la base : il vient du dessin. Chaque
 * isométrique porte sa liste soudure numérotée 1, 2, 3… ; plan_table.php la reconnaît sur
 * le PDF, et le résultat est gardé à côté du plan. Compter ce que l'atelier a saisi ne
 * donnerait jamais qu'un rapport de soi à soi — toujours 100 %.
 *
 * On ne lit ici que ce qui est déjà reconnu. Une page jamais ouverte ne compte pas encore,
 * et c'est pourquoi la fonction rend aussi combien de pages sont lues : les écrans disent
 * « 4 feuilles sur 15 lues » plutôt que d'annoncer un total qu'ils savent incomplet.
 *
 * @return array{prevues:int, lues:int, pages:int}
 */
function avancementSouduresPrevues(PDO $db, int $elementId): array
{
    require_once __DIR__ . '/plan_document.php';
    require_once __DIR__ . '/../lecture/plan_lignes.php';

    $demande = $db->prepare('SELECT id_demande FROM element_atelier WHERE id = ?');
    $demande->execute([$elementId]);
    $requestId = (int) $demande->fetchColumn();
    if (!$requestId) return ['prevues' => 0, 'lues' => 0, 'pages' => 0];

    $prevues = 0;
    $lues = 0;
    $pages = 0;
    foreach (planDocuments($db, $elementId) as $doc) {
        $plan = planDocumentChoisi($db, $elementId, (int) $doc['id']);
        if (!$plan || !is_file($plan['chemin'])) continue;
        for ($page = 1; $page <= max(1, (int) $doc['pages']); $page++) {
            $pages++;
            $analyse = planLignesAnalyseGardee($plan['chemin'],
                planLignesCache($requestId, (string) $doc['cle_document'], $page));
            if ($analyse === null) continue;
            $lues++;
            $prevues += count($analyse['soudures']['lignes'] ?? []);
        }
    }
    return ['prevues' => $prevues, 'lues' => $lues, 'pages' => $pages];
}

/**
 * D'où sort le dénominateur, en une ligne : « sur 21 soudures prévues au plan · 3 feuilles
 * sur 15 lues ».
 *
 * La seconde moitié n'est pas un détail : tant que toutes les feuilles d'un cahier n'ont
 * pas été reconnues, le total est incomplet, et un pourcentage calculé dessus flatte le
 * travail. Le dire vaut mieux que d'afficher un chiffre dont on sait qu'il bougera.
 *
 * @param array{prevues:int, lues:int, pages:int} $prevu
 */
function avancementDenominateurPhrase(array $prevu): string
{
    $total = $prevu['prevues'];
    $soudures = 'sur ' . $total . ($total === 1 ? ' soudure prévue' : ' soudures prévues');
    $feuilles = avancementFeuillesPhrase($prevu);
    return $feuilles === '' ? $soudures . ' au plan' : $soudures . ' · ' . $feuilles;
}

/**
 * Où en est la lecture des feuilles : « les 15 feuilles sont lues », ou « 3 sur 15 ».
 *
 * À part, parce que l'écran du soudeur dit déjà « 2 sur 69 » juste avant : y répéter
 * « sur 69 soudures prévues » ferait deux « sur » dans la même phrase.
 *
 * @param array{prevues:int, lues:int, pages:int} $prevu
 */
function avancementFeuillesPhrase(array $prevu): string
{
    if ($prevu['pages'] <= 1) return '';
    if ($prevu['lues'] >= $prevu['pages']) return 'les ' . $prevu['pages'] . ' feuilles sont lues';
    return $prevu['lues'] . ' feuille' . ($prevu['lues'] === 1 ? '' : 's')
        . ' sur ' . $prevu['pages'] . ' lue' . ($prevu['lues'] === 1 ? '' : 's');
}

/** Le pourcentage d'un rapport de soudures. Sans liste, il n'y a pas de rapport : zéro. */
function avancementPourcentage(int $faites, int $total): int
{
    if ($total < 1) return 0;
    return max(0, min(100, (int) round($faites * 100 / $total)));
}

/**
 * La déclaration en attente d'une affectation, s'il y en a une.
 *
 * Une seule à la fois : déclarer de nouveau remplace la précédente — voir
 * avancementDeclarer(). Le chef n'a donc jamais une file à traiter pour un même travail,
 * seulement le dernier chiffre annoncé.
 */
function avancementEnAttente(PDO $db, int $affectationId): ?array
{
    $stmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
        FROM avancement_atelier h JOIN Utilisateur u ON u.id = h.id_utilisateur
        WHERE h.id_affectation = ? AND h.etat = 'propose'
        ORDER BY h.id DESC LIMIT 1");
    $stmt->execute([$affectationId]);
    return $stmt->fetch() ?: null;
}

/**
 * Les déclarations en attente de plusieurs affectations, en une lecture.
 *
 * L'écran du chef affiche une demande entière : ses plans, et pour chacun ses trois
 * travaux. Les interroger un par un ferait une requête par case.
 *
 * @param list<int> $affectationIds
 * @return array<int, array<string, mixed>> indexé par identifiant d'affectation
 */
function avancementEnAttentePlusieurs(PDO $db, array $affectationIds): array
{
    $ids = array_values(array_unique(array_map('intval', $affectationIds)));
    if (!$ids) return [];
    $trous = implode(',', array_fill(0, count($ids), '?'));
    // La dernière de chaque affectation : les précédentes ont été remplacées.
    $stmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
        FROM avancement_atelier h JOIN Utilisateur u ON u.id = h.id_utilisateur
        WHERE h.id_affectation IN ($trous) AND h.etat = 'propose'
        ORDER BY h.id");
    $stmt->execute($ids);
    $attentes = [];
    foreach ($stmt->fetchAll() as $ligne) {
        $attentes[(int) $ligne['id_affectation']] = $ligne;
    }
    return $attentes;
}

/**
 * Combien de déclarations attendent une décision du chef, sur l'atelier entier.
 *
 * Elle sert la tuile d'accueil. Sans ce compteur, une déclaration n'existe que dans l'écran
 * du plan où elle a été faite : il faudrait les ouvrir un par un pour s'apercevoir que
 * quelqu'un attend, et une validation qu'on ne voit pas n'arrive jamais.
 *
 * Les travaux retirés et les demandes refermées ne comptent pas : plus personne n'ira
 * trancher dessus.
 */
function avancementAValider(PDO $db): int
{
    return (int) $db->query("SELECT COUNT(*) FROM avancement_atelier h
        JOIN affectation_atelier a ON a.id = h.id_affectation
        JOIN element_atelier e ON e.id = a.id_element
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE h.etat = 'propose' AND a.actif = 1 AND d.id_statut = 2")->fetchColumn();
}

/**
 * La personne affectée annonce où elle en est. Rien n'est écrit sur l'affectation.
 *
 * Sa déclaration précédente, si elle n'avait pas encore été traitée, est retirée : ce qui
 * compte est le dernier chiffre annoncé, et le chef n'a pas à valider un état dépassé.
 * Une déclaration déjà validée ou refusée est de l'histoire, elle ne bouge pas.
 *
 * L'appelant tient la transaction et a déjà verrouillé l'affectation.
 */
function avancementDeclarer(PDO $db, int $affectationId, int $acteurId, int $pourcentage, ?int $soudures, ?int $total, ?string $commentaire): void
{
    $db->prepare("DELETE FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'")
        ->execute([$affectationId]);
    $db->prepare("INSERT INTO avancement_atelier
        (id_affectation, id_utilisateur, avancement, soudures, soudures_total, commentaire, etat)
        VALUES (?, ?, ?, ?, ?, ?, 'propose')")
        ->execute([$affectationId, $acteurId, $pourcentage, $soudures, $total, $commentaire]);
}

/**
 * Le chef pose lui-même un pourcentage : validé d'office, et l'affectation suit.
 *
 * Il reprend la main sur la déclaration en attente, s'il y en avait une : la valider
 * ensuite écrirait par-dessus le chiffre qu'il vient de choisir.
 *
 * L'appelant tient la transaction, a verrouillé l'affectation et a comparé sa révision.
 */
function avancementPoser(PDO $db, int $affectationId, int $acteurId, int $pourcentage, ?int $soudures, ?int $total, ?string $commentaire): void
{
    $db->prepare("DELETE FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'")
        ->execute([$affectationId]);
    $db->prepare("INSERT INTO avancement_atelier
        (id_affectation, id_utilisateur, avancement, soudures, soudures_total, commentaire, etat, valide_par, date_validation)
        VALUES (?, ?, ?, ?, ?, ?, 'valide', ?, NOW())")
        ->execute([$affectationId, $acteurId, $pourcentage, $soudures, $total, $commentaire, $acteurId]);
    $db->prepare('UPDATE affectation_atelier SET avancement = ?, commentaire = ?, revision = revision + 1 WHERE id = ?')
        ->execute([$pourcentage, $commentaire, $affectationId]);
}

/**
 * Le chef tranche une déclaration : il la valide, et le pourcentage de l'affectation
 * devient celui qu'elle annonce ; ou il la refuse, et l'affectation ne bouge pas.
 *
 * Le refus garde la ligne et le nom de qui a refusé. Effacer la déclaration serait plus
 * simple, mais la personne affectée verrait sa saisie disparaître sans savoir si elle est
 * passée.
 *
 * Le compte rendu de la déclaration n'est repris sur l'affectation qu'en cas d'accord :
 * c'est le texte qui accompagne le chiffre retenu.
 *
 * L'appelant tient la transaction et a verrouillé l'affectation.
 *
 * @return array<string, mixed> la déclaration tranchée
 * @throws DomainException si elle n'existe plus, ou si quelqu'un l'a déjà tranchée
 */
function avancementTrancher(PDO $db, int $affectationId, int $declarationId, int $acteurId, bool $accepte): array
{
    // Verrouillée, et rattachée à cette affectation : sans la seconde condition, un
    // identifiant pris ailleurs ferait valider la déclaration d'un autre travail.
    $stmt = $db->prepare('SELECT * FROM avancement_atelier WHERE id = ? AND id_affectation = ? FOR UPDATE');
    $stmt->execute([$declarationId, $affectationId]);
    $declaration = $stmt->fetch();
    if (!$declaration) {
        throw new DomainException('Cette déclaration n’existe plus. Actualisez la page.');
    }
    if ($declaration['etat'] !== 'propose') {
        throw new DomainException($declaration['etat'] === 'valide'
            ? 'Cette déclaration a déjà été validée.'
            : 'Cette déclaration a déjà été refusée.');
    }

    $db->prepare('UPDATE avancement_atelier SET etat = ?, valide_par = ?, date_validation = NOW() WHERE id = ?')
        ->execute([$accepte ? 'valide' : 'refuse', $acteurId, $declarationId]);

    if ($accepte) {
        $db->prepare('UPDATE affectation_atelier SET avancement = ?, commentaire = ?, revision = revision + 1 WHERE id = ?')
            ->execute([(int) $declaration['avancement'], $declaration['commentaire'], $affectationId]);
    }
    return $declaration;
}

/**
 * Ce qu'une déclaration annonce, en une phrase : « 7 soudures sur 7 — 100 % ».
 *
 * Le rapport d'abord, le pourcentage ensuite. Une déclaration faite sur un plan sans
 * liste soudure ne porte qu'un pourcentage : elle se lit alors telle quelle.
 */
function avancementPhrase(array $declaration): string
{
    $pourcentage = (int) $declaration['avancement'] . ' %';
    if (($declaration['soudures'] ?? null) === null || (int) $declaration['soudures_total'] < 1) {
        return $pourcentage;
    }
    $faites = (int) $declaration['soudures'];
    return $faites . ($faites === 1 ? ' soudure sur ' : ' soudures sur ')
        . (int) $declaration['soudures_total'] . ' — ' . $pourcentage;
}

/** L'état d'une ligne d'historique, dit en clair avec le nom de qui a tranché. */
function avancementEtatPhrase(array $ligne): string
{
    $qui = trim((string) ($ligne['validateur'] ?? ''));
    return match ($ligne['etat'] ?? 'valide') {
        'propose' => 'En attente du chef d’atelier',
        'refuse' => 'Refusé' . ($qui !== '' ? ' par ' . $qui : ''),
        default => 'Validé' . ($qui !== '' ? ' par ' . $qui : ''),
    };
}
