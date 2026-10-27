<?php

/**
 * L'avancement d'une affectation : calculé, déclaré, validé par le chef.
 *
 * Personne ne tape de pourcentage côté atelier. Il se calcule (avancementSource()) :
 *
 * - le suivi soudage, sur les cordons — faits sur prévus — dès que le plan porte une
 *   liste de soudures ; enregistrer ses cordons déclare le chiffre ;
 * - tous les autres travaux, et le suivi soudage d'un plan sans liste, sur les étapes du
 *   plan — faites sur applicables — ; enregistrer les étapes déclare le chiffre.
 *
 * Deux écritures distinctes, et c'est tout l'objet de ce fichier :
 *
 * - le calcul **déclare** — sa ligne part en « propose » et ne déplace rien ;
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
 * D'où se calcule l'avancement d'un travail.
 *
 * Le suivi soudage se mesure aux cordons dès que le plan porte une liste de soudures : ce
 * sont les lignes que le bureau d'études a imprimées, rien de plus juste ne le dira. Tout
 * le reste — le montage, le plan / ISO, et le suivi soudage tant qu'aucune liste n'est
 * reconnue — se mesure aux étapes du plan.
 *
 * @return 'cordons'|'etapes'
 */
function avancementSource(string $type, int $souduresPrevues): string
{
    return $type === 'soudage' && $souduresPrevues > 0 ? 'cordons' : 'etapes';
}

/**
 * Les étapes du plan, comptées : celles qui sont faites, sur celles qui s'appliquent.
 *
 * - « OK » : faite ;
 * - « RAS » : rien à faire à cette étape sur ce plan — elle sort du total, sans quoi un
 *   plan sans passivation ne pourrait jamais atteindre 100 % ;
 * - « Non OK » : à reprendre, donc pas faite ;
 * - sans état : pas encore faite.
 *
 * « Autre » vaut RAS tant que personne ne l'a renseignée : c'est la valeur que l'écran
 * propose d'office, et une étape fourre-tout ne doit pas retenir un plan à 86 %.
 *
 * @param array<string, string> $statuts ceux d'atelierElementSteps()
 * @return array{faites:int, applicables:int}
 */
function avancementEtapes(array $statuts): array
{
    require_once __DIR__ . '/atelier.php';
    $faites = 0;
    $applicables = 0;
    foreach (array_keys(atelierSteps()) as $cle) {
        $valeur = $statuts[$cle] ?? ($cle === 'autre' ? 'RAS' : '');
        if ($valeur === 'RAS') continue;
        $applicables++;
        if ($valeur === 'OK') $faites++;
    }
    return ['faites' => $faites, 'applicables' => $applicables];
}

/**
 * La base sait-elle ranger le compte d'étapes avec une déclaration ?
 *
 * Les colonnes viennent de la migration 2026-10-27_avancement_etapes. Tant qu'elle n'est
 * pas passée sur un serveur, la déclaration part quand même, avec son seul pourcentage :
 * enregistrer ses étapes ne doit pas échouer pour une colonne qui manque.
 */
function avancementEtapesEnBase(PDO $db): bool
{
    static $presentes = null;
    return $presentes ??= (bool) $db->query("SHOW COLUMNS FROM avancement_atelier LIKE 'etapes_total'")->fetch();
}

/**
 * Le travail que les cordons font avancer : le suivi soudage du plan, quel que soit celui
 * qui le tient.
 *
 * Les cordons sont ceux du plan, toutes feuilles et tous soudeurs confondus. Le chiffre
 * partait sur l'affectation de celui qui enregistrait — n'importe laquelle, la requête ne
 * choisissait pas — et pouvait donc tomber sur son montage.
 *
 * @return array{id:int, avancement:int}|null
 */
function avancementAffectationCordons(PDO $db, int $elementId, bool $verrouiller = false): ?array
{
    $stmt = $db->prepare("SELECT id, avancement FROM affectation_atelier
        WHERE id_element = ? AND type_affectation = 'soudage' AND actif = 1" . ($verrouiller ? ' FOR UPDATE' : ''));
    $stmt->execute([$elementId]);
    $ligne = $stmt->fetch();
    return $ligne ? ['id' => (int) $ligne['id'], 'avancement' => (int) $ligne['avancement']] : null;
}

/**
 * Les étapes du plan viennent de changer : chaque travail qui s'y mesure reçoit le nouveau
 * chiffre.
 *
 * Tous les travaux du plan, et pas seulement ceux de qui enregistre : les étapes sont
 * celles du plan, partagées, et le montage de l'un ne peut pas rester sur l'ancien compte
 * quand l'autre vient de cocher « Pliage ».
 *
 * Le chiffre part en déclaration, que le chef valide — sauf quand c'est le chef qui a
 * changé les étapes : il est l'autorité, le chiffre est posé. Un chiffre déjà reconnu ne
 * part pas une seconde fois ; une déclaration en attente devenue fausse est alors retirée.
 *
 * L'appelant tient la transaction.
 *
 * @return array{pourcentage:int, faites:int, applicables:int, travaux:int}|null
 *         null quand aucune étape ne s'applique ; « travaux », combien ont reçu le chiffre
 */
function avancementSelonEtapes(PDO $db, int $elementId, int $acteurId, bool $autorite): ?array
{
    require_once __DIR__ . '/atelier.php';
    $compte = avancementEtapes(atelierElementSteps($db, $elementId)['statuts']);
    if ($compte['applicables'] < 1) return null;
    $pourcentage = avancementPourcentage($compte['faites'], $compte['applicables']);
    $prevues = avancementSouduresPrevues($db, $elementId)['prevues'];

    $stmt = $db->prepare('SELECT id, type_affectation, avancement, commentaire FROM affectation_atelier
        WHERE id_element = ? AND actif = 1 FOR UPDATE');
    $stmt->execute([$elementId]);
    $travaux = 0;
    foreach ($stmt->fetchAll() as $affectation) {
        if (avancementSource((string) $affectation['type_affectation'], $prevues) !== 'etapes') continue;
        $id = (int) $affectation['id'];
        if ((int) $affectation['avancement'] === $pourcentage) {
            $db->prepare("DELETE FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'")
                ->execute([$id]);
            continue;
        }
        if ($autorite) {
            // Le compte rendu de l'affectation reste le sien : un calcul n'a rien à en dire.
            avancementPoser($db, $id, $acteurId, $pourcentage, null, null, $affectation['commentaire'],
                $compte['faites'], $compte['applicables']);
        } else {
            avancementDeclarer($db, $id, $acteurId, $pourcentage, null, null, null,
                $compte['faites'], $compte['applicables']);
        }
        $travaux++;
    }
    return $compte + ['pourcentage' => $pourcentage, 'travaux' => $travaux];
}

/**
 * Jusqu'à quand les cordons de chaque soudeur sont couverts par une validation, sur cet
 * élément.
 *
 * Le chef ne valide pas une ligne, il valide une déclaration : un nombre de cordons,
 * arrêté à une date. Ce qui avait été saisi avant cette date est donc couvert par elle.
 * C'est ce qui décide de ce qui s'écrit sur le plan — le dessin à l'écran comme le PDF
 * qui part au client ne portent que du validé.
 *
 * @return array<int, string> identifiant du soudeur => date de sa dernière validation
 */
function avancementValideJusqua(PDO $db, int $elementId): array
{
    $stmt = $db->prepare("SELECT a.id_utilisateur, MAX(h.date_validation) AS jusqua
        FROM avancement_atelier h
        JOIN affectation_atelier a ON a.id = h.id_affectation
        WHERE a.id_element = ? AND h.etat = 'valide' AND h.date_validation IS NOT NULL
        GROUP BY a.id_utilisateur");
    $stmt->execute([$elementId]);
    $jusqua = [];
    foreach ($stmt->fetchAll() as $ligne) {
        $jusqua[(int) $ligne['id_utilisateur']] = (string) $ligne['jusqua'];
    }
    return $jusqua;
}

/**
 * Ce cordon peut-il s'écrire sur le plan ?
 *
 * @param array<string, mixed> $soudure une ligne de soudureLignes()
 * @param array<int, string> $jusqua ce que rend avancementValideJusqua()
 */
function avancementCordonValide(array $soudure, array $jusqua): bool
{
    // Un visa est plus fort que tout : quelqu'un a signé cette ligne-là, nommément.
    if (!empty($soudure['vise_par'])) return true;
    // Une ligne seulement proposée par un pointage n'a même pas été saisie.
    if (!empty($soudure['numero_propose'])) return false;
    // Sans soudeur, la ligne vient de l'encadrement : il n'a personne à attendre.
    $soudeur = (int) ($soudure['id_soudeur'] ?? 0);
    if ($soudeur < 1) return true;
    $saisie = (string) ($soudure['date_saisie'] ?? '');
    // Les deux dates sont au même format : les comparer comme du texte les range bien.
    return $saisie !== '' && isset($jusqua[$soudeur]) && $saisie <= $jusqua[$soudeur];
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
function avancementDeclarer(PDO $db, int $affectationId, int $acteurId, int $pourcentage, ?int $soudures, ?int $total, ?string $commentaire, ?int $etapes = null, ?int $etapesTotal = null): void
{
    $db->prepare("DELETE FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'")
        ->execute([$affectationId]);
    avancementInserer($db, [
        'id_affectation' => $affectationId, 'id_utilisateur' => $acteurId, 'avancement' => $pourcentage,
        'soudures' => $soudures, 'soudures_total' => $total, 'commentaire' => $commentaire, 'etat' => 'propose',
    ], $etapes, $etapesTotal);
}

/**
 * L'heure de la base, pour une colonne de date : la base compte en UTC, et PHP à l'heure
 * de Paris — une date prise côté PHP se lirait décalée d'une heure ou deux.
 */
const AVANCEMENT_MAINTENANT = "\0NOW()";

/**
 * Une ligne d'avancement, avec le compte d'étapes quand la base sait le ranger.
 *
 * @param array<string, int|string|null> $valeurs colonne => valeur ; AVANCEMENT_MAINTENANT
 *        pour l'heure de la base
 */
function avancementInserer(PDO $db, array $valeurs, ?int $etapes, ?int $etapesTotal): void
{
    if ($etapes !== null && avancementEtapesEnBase($db)) {
        $valeurs['etapes'] = $etapes;
        $valeurs['etapes_total'] = $etapesTotal;
    }
    $marques = [];
    $parametres = [];
    foreach ($valeurs as $valeur) {
        if ($valeur === AVANCEMENT_MAINTENANT) {
            $marques[] = 'NOW()';
        } else {
            $marques[] = '?';
            $parametres[] = $valeur;
        }
    }
    $db->prepare('INSERT INTO avancement_atelier (' . implode(', ', array_keys($valeurs)) . ')
        VALUES (' . implode(', ', $marques) . ')')
        ->execute($parametres);
}

/**
 * Le chef pose lui-même un pourcentage : validé d'office, et l'affectation suit.
 *
 * Il reprend la main sur la déclaration en attente, s'il y en avait une : la valider
 * ensuite écrirait par-dessus le chiffre qu'il vient de choisir.
 *
 * L'appelant tient la transaction, a verrouillé l'affectation et a comparé sa révision.
 */
function avancementPoser(PDO $db, int $affectationId, int $acteurId, int $pourcentage, ?int $soudures, ?int $total, ?string $commentaire, ?int $etapes = null, ?int $etapesTotal = null): void
{
    $db->prepare("DELETE FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'")
        ->execute([$affectationId]);
    avancementInserer($db, [
        'id_affectation' => $affectationId, 'id_utilisateur' => $acteurId, 'avancement' => $pourcentage,
        'soudures' => $soudures, 'soudures_total' => $total, 'commentaire' => $commentaire, 'etat' => 'valide',
        'valide_par' => $acteurId, 'date_validation' => AVANCEMENT_MAINTENANT,
    ], $etapes, $etapesTotal);
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
        // Une déclaration calculée — sur les étapes ou les cordons — n'a pas de compte
        // rendu : elle laisse en place celui de l'affectation au lieu de l'effacer.
        $db->prepare('UPDATE affectation_atelier SET avancement = ?, commentaire = COALESCE(?, commentaire), revision = revision + 1 WHERE id = ?')
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
    if (($declaration['etapes'] ?? null) !== null && (int) ($declaration['etapes_total'] ?? 0) > 0) {
        $faites = (int) $declaration['etapes'];
        return $faites . ($faites > 1 ? ' étapes sur ' : ' étape sur ')
            . (int) $declaration['etapes_total'] . ' — ' . $pourcentage;
    }
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
