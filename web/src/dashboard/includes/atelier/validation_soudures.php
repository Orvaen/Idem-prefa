<?php
/**
 * La validation des soudures : ce que l'atelier a relevé et déclaré, et qui attend la
 * décision de l'encadrement — les contrôles RT, VT, PT, la signature, puis « Refuser » ou
 * « Valider ».
 *
 * Elle a son écran, « Validation des soudures » (pages/validation_soudures.php) — demandé le
 * 6 novembre 2026 : « la validation de soudure dans un autre onglet ». Elle était répétée à
 * trois endroits, au milieu de ce qu'on y venait faire : la fiche d'une demande, le volet
 * « Plans & équipes » — entre deux affectations —, la fenêtre d'un plan dans « Soudures ».
 * Ces trois endroits n'en gardent qu'une ligne, qui y mène (validationSouduresRenvoi()).
 *
 * L'écran est une file — les plans à gauche, celui qu'on vise à droite — et ses décisions ont
 * leur dessin à elles (validationSouduresDecisionRendu()), plus serré que celui des volets
 * (decision.php) : refait le même jour, « je déteste l'interface ». Le plan s'y redisait deux
 * fois avant les contrôles, et « Valider » y paraissait deux fois pour deux gestes différents.
 * Le formulaire, lui, n'a pas changé : mêmes champs, même action (update_atelier.php), même
 * script (atelier.js).
 *
 * Ce qui ne vise aucune soudure — un avancement aux étapes — reste là où il est : il se
 * tranche d'un clic, sans contrôle ni signature.
 */

require_once __DIR__ . '/atelier.php';
require_once __DIR__ . '/avancement.php';
require_once __DIR__ . '/soudure.php';
require_once __DIR__ . '/decision.php';
require_once __DIR__ . '/signature.php';

/**
 * Les plans qui portent des soudures — relevées, ou prévues par une liste lue —, et ce qui
 * s'y attend : la lecture de « Soudures », et celle de « Validation des soudures ».
 *
 * Un plan sans cordon ni liste reconnue n'y est pas : ce sont des écrans sur les soudures,
 * pas sur les plans.
 *
 * @return list<array<string, mixed>> le plan (soudureSuiviPlans()), avec « prevues », « lues »,
 *         « faites », « attentes » (les déclarations en attente, toutes affectations du plan),
 *         « avancement » (celui du suivi soudage) et « soudage » (son affectation)
 */
function soudureSuiviLignes(PDO $db): array
{
    $plans = soudureSuiviPlans($db);

    // Les déclarations en attente, toutes affectations confondues : une seule lecture.
    $idsAffectations = [];
    foreach ($plans as $plan) foreach ($plan['affectations'] as $affectation) $idsAffectations[] = (int) $affectation['id'];
    $attentes = avancementEnAttentePlusieurs($db, $idsAffectations);

    $lignes = [];
    foreach ($plans as $plan) {
        $elementId = (int) $plan['id'];
        $prevu = avancementSouduresPrevues($db, $elementId);
        $faites = avancementSouduresFaites($db, $elementId);
        if ($faites === 0 && $prevu['prevues'] === 0) continue;

        $enAttente = [];
        $soudage = null;
        foreach ($plan['affectations'] as $affectation) {
            if ($soudage === null && $affectation['type_affectation'] === 'soudage') $soudage = $affectation;
            if (isset($attentes[(int) $affectation['id']])) {
                $enAttente[] = $attentes[(int) $affectation['id']] + ['affectation' => $affectation];
            }
        }

        $lignes[] = $plan + [
            'prevues' => $prevu['prevues'],
            'lues' => avancementFeuillesPhrase($prevu),
            'faites' => $faites,
            'attentes' => $enAttente,
            // L'avancement que les cordons font bouger : celui du suivi soudage. La première
            // affectation venue — rangées par type, c'était le Plan / ISO — restait à 0 % alors
            // que la validation avait bien écrit 75 % sur le suivi soudage.
            'avancement' => (int) ($soudage['avancement'] ?? 0),
            'soudage' => $soudage,
        ];
    }
    return $lignes;
}

/**
 * Ce qu'un plan a de soudures à valider : les décisions à prendre, et le nombre de soudures
 * qu'elles visent. Vide s'il n'y a rien.
 *
 * Deux cas :
 *  - une déclaration de l'atelier qui vise des soudures — celles qu'il a relevées depuis la
 *    dernière validation ;
 *  - des soudures relevées que rien ne propose de valider : le soudeur a enregistré avant que
 *    la liste du plan soit lue, ou avant que le suivi soudage s'ouvre tout seul. Le chef pose
 *    le chiffre lui-même : il est l'autorité. Sans suivi soudage, son « Valider » l'ouvre au
 *    nom du soudeur déjà affecté au plan (opération « cordons ») — il lui fallait sinon
 *    affecter « Suivi soudage » ailleurs, et il ne comprenait pas pourquoi.
 *
 * Des données, pas du HTML : « Soudures » n'en veut que le compte (son renvoi), et l'écran de
 * validation les dessine à sa façon (validationSouduresDecisionRendu()).
 *
 * @param array<string, mixed> $ligne une ligne de soudureSuiviLignes()
 * @return array{decisions: list<array<string, mixed>>, soudures: int}
 *         chaque décision : « qui », « quand », « travail » (s'il n'est pas le suivi soudage),
 *         « commentaire », « compte », « avant » et « apres » (les pourcentages), « formulaires »
 *         (ce qui la tranche) et « note » (pourquoi on ne peut pas la trancher d'ici)
 */
function validationSouduresPlan(PDO $db, Utilisateur $utilisateur, array $ligne): array
{
    // Qui a relevé des cordons sur un plan et y est affecté, à n'importe quel travail : une
    // affectation par soudeur suffit — c'est elle qui dit que l'encadrement l'a mis sur ce
    // plan. « Fait » se lit comme partout : un procédé ou une date.
    static $soudeursAffectes = null;
    $soudeursAffectes ??= $db->prepare("SELECT MIN(a.id) AS affectation, a.id_utilisateur AS soudeur, TRIM(CONCAT(u.prenom, ' ', u.name)) AS nom
        FROM affectation_atelier a
        JOIN Utilisateur u ON u.id = a.id_utilisateur
        WHERE a.id_element = ? AND a.actif = 1 AND u.id_role = ?
          AND EXISTS (SELECT 1 FROM plan_soudure s
              WHERE s.id_element = a.id_element AND s.id_soudeur = a.id_utilisateur
                AND (TRIM(s.type_soudure) <> '' OR s.date_soudure IS NOT NULL))
        GROUP BY a.id_utilisateur, u.prenom, u.name
        ORDER BY nom");

    $elementId = (int) $ligne['id'];
    $idDemande = (int) $ligne['id_demande'];
    $decisions = [];
    $soudures = 0;

    foreach ($ligne['attentes'] as $attente) {
        $cordons = avancementCordonsAValider($db, $elementId, (int) $attente['affectation']['id_utilisateur']);
        // Aucune soudure à viser : un avancement aux étapes. Il se tranche là où il est.
        if (!$cordons) continue;
        $soudures += count($cordons);
        $type = (string) $attente['affectation']['type_affectation'];
        $decisions[] = [
            'qui' => $attente['auteur'],
            'quand' => $attente['date_saisie'],
            // Une déclaration peut venir d'un autre travail que le suivi soudage : la
            // décision dit lequel. Le suivi soudage, c'est le cas ordinaire : il ne se dit pas.
            'travail' => $type !== 'soudage' ? (atelierTravaux()[$type] ?? null) : null,
            'commentaire' => $attente['commentaire'],
            'compte' => avancementCompte($attente),
            'avant' => (int) $attente['affectation']['avancement'],
            'apres' => (int) $attente['avancement'],
            'formulaires' => [[
                'champs' => ['id' => $idDemande, 'operation' => 'valider',
                    'affectation' => (int) $attente['affectation']['id'], 'declaration' => (int) $attente['id']],
                'cordons' => $cordons,
                'visa' => signatureVisa($db, $utilisateur),
                'valider' => validationSouduresLibelle(count($cordons)),
                'aide' => 'L’avancement du plan prendra ce chiffre.',
                'refuser' => true,
            ]],
            'note' => null,
        ];
    }

    $calcule = $ligne['prevues'] > 0 ? avancementPourcentage(min($ligne['faites'], $ligne['prevues']), $ligne['prevues']) : null;
    if (!$ligne['attentes'] && $calcule !== null && $ligne['faites'] > 0 && $calcule !== $ligne['avancement']) {
        $total = min($ligne['faites'], $ligne['prevues']);
        $soudeurs = [];
        if (!$ligne['soudage']) {
            $soudeursAffectes->execute([$elementId, Role::PersonnelAtelier->value]);
            $soudeurs = $soudeursAffectes->fetchAll(PDO::FETCH_ASSOC);
        }
        $formulaires = [];
        if ($ligne['soudage']) {
            $cordons = avancementCordonsAValider($db, $elementId, (int) $ligne['soudage']['id_utilisateur']);
            $soudures += count($cordons);
            $formulaires[] = [
                'champs' => ['id' => $idDemande, 'operation' => 'progress',
                    'affectation' => (int) $ligne['soudage']['id'], 'revision' => (int) $ligne['soudage']['revision'],
                    'soudures' => $total],
                'cordons' => $cordons,
                'visa' => signatureVisa($db, $utilisateur),
                'valider' => validationSouduresLibelle(count($cordons)),
                'aide' => 'L’avancement du suivi soudage prendra ce chiffre.',
                'refuser' => false,
            ];
        }
        // Un bouton par soudeur affecté, nommé seulement s'ils sont plusieurs : c'est à son
        // nom que le suivi s'ouvre.
        foreach ($soudeurs as $soudeur) {
            $cordons = avancementCordonsAValider($db, $elementId, (int) $soudeur['soudeur']);
            $soudures += count($cordons);
            $formulaires[] = [
                'champs' => ['id' => $idDemande, 'operation' => 'cordons',
                    'affectation' => (int) $soudeur['affectation'], 'soudures' => $total],
                'cordons' => $cordons,
                'visa' => signatureVisa($db, $utilisateur),
                'valider' => validationSouduresLibelle(count($cordons), count($soudeurs) > 1 ? (string) $soudeur['nom'] : null),
                'aide' => 'Le suivi soudage du plan prendra ce chiffre.',
                'refuser' => false,
            ];
        }
        $decisions[] = [
            'qui' => null,
            'quand' => null,
            'travail' => null,
            'commentaire' => null,
            'compte' => avancementCompte(['soudures' => $total, 'soudures_total' => $ligne['prevues'], 'avancement' => $calcule]),
            'avant' => (int) $ligne['avancement'],
            'apres' => $calcule,
            'formulaires' => $formulaires,
            // Le soudeur n'est plus affecté au plan, ou les cordons viennent de l'encadrement :
            // personne au nom de qui ouvrir le suivi.
            'note' => $formulaires ? null
                : 'Personne de l’atelier n’est affecté à ce plan : affectez « Suivi soudage » dans « Plans & équipes » pour pouvoir valider.',
        ];
    }

    return ['decisions' => $decisions, 'soudures' => $soudures];
}

/**
 * Le libellé de « Valider » : ce qu'il vise, en toutes lettres — « Valider 5 soudures ». Il
 * disait « Valider » tout court, comme le bouton de la liste qui ne faisait qu'ouvrir la
 * fenêtre ; on ne savait plus lequel engageait.
 *
 * @param ?string $de le soudeur, quand plusieurs se partagent le plan
 */
function validationSouduresLibelle(int $soudures, ?string $de = null): string
{
    $libelle = match (true) {
        $soudures === 1 => 'Valider 1 soudure',
        $soudures > 1 => 'Valider ' . $soudures . ' soudures',
        default => 'Valider',
    };
    if ($de === null || $de === '') return $libelle;
    return $libelle . ($soudures > 0 ? ' de ' : ' · ') . $de;
}

/**
 * Une décision, dans le plan qu'on vise : d'où elle vient en une ligne — « Karim Benali a
 * déclaré 2 soudures sur 69 · 06/11/2026 à 09:12 » —, la note de l'atelier s'il en a laissé
 * une, puis ce qui la tranche.
 *
 * La classe « decision » est gardée : elle porte le pied, le visa et « Tracer ma signature »
 * (atelier.css), et c'est elle que parametres.js et atelier.js reconnaissent.
 *
 * @param array<string, mixed> $decision une décision de validationSouduresPlan()
 */
function validationSouduresDecisionRendu(array $decision): string
{
    $quand = !empty($decision['quand']) ? atelierLocalDateTime((string) $decision['quand']) : null;
    $compte = $decision['compte'] ?? null;

    if (!empty($decision['qui'])) {
        $origine = '<strong>' . prefaEscape((string) $decision['qui']) . '</strong> a déclaré '
            . prefaEscape($compte ?? 'son avancement');
        $libelle = 'Déclaration de ' . (string) $decision['qui'];
    } else {
        $origine = 'Relevées par l’atelier, sans déclaration' . ($compte !== null ? ' · ' . prefaEscape($compte) : '');
        $libelle = 'Soudures relevées, sans déclaration';
    }
    if ($quand !== null) $origine .= ' · ' . prefaEscape($quand);
    if (!empty($decision['travail'])) $origine .= ' · ' . prefaEscape((string) $decision['travail']);

    ob_start();
    ?>
<section class="decision validation-decision" aria-label="<?= prefaEscape($libelle) ?>">
    <p class="validation-origine"><?= $origine ?></p>
    <?php if (!empty($decision['commentaire'])): ?>
        <div class="validation-note">
            <span class="validation-note-titre">Note de l’atelier</span>
            <span class="validation-note-texte"><?= prefaEscape((string) $decision['commentaire']) ?></span>
        </div>
    <?php endif; ?>
    <?php foreach ($decision['formulaires'] as $formulaire): ?>
        <?= validationSouduresFormulaire($formulaire, (int) $decision['avant'], (int) $decision['apres']) ?>
    <?php endforeach; ?>
    <?php if (!empty($decision['note'])): ?>
        <p class="decision-note validation-impossible"><?= prefaEscape((string) $decision['note']) ?></p>
    <?php endif; ?>
</section>
<?php
    return ob_get_clean();
}

/**
 * Ce qui tranche : les champs cachés, les contrôles des soudures, et le pied — le visa, ce que
 * la validation change, « Refuser » et « Valider N soudures ».
 *
 * Le contrat d'avancementDecisionFormulaire() (decision.php), mot pour mot : les mêmes champs,
 * « decision » sur les boutons quand il y a de quoi refuser, « data-signature-requise » et
 * « data-attend-signature » sans signature — parametres.js rallume « Valider » une fois la
 * signature tracée. Seul le dessin change.
 *
 * Le pied reste en vue pendant qu'on coche (« position: sticky », atelier.css) : sur vingt
 * soudures, « Valider » partait sous le bas de l'écran.
 *
 * @param array<string, mixed> $formulaire un formulaire d'une décision de validationSouduresPlan()
 */
function validationSouduresFormulaire(array $formulaire, int $avant, int $apres): string
{
    $refuser = !empty($formulaire['refuser']);
    // La signature n'est demandée que s'il y a des soudures à viser.
    $sansSignature = $formulaire['cordons'] && $formulaire['visa']['manquante'];
    $avant = max(0, min(100, $avant));
    $apres = max(0, min(100, $apres));

    ob_start();
    ?>
<form class="atelier-action decision-formulaire validation-formulaire" data-endpoint="update_atelier.php">
    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
    <?php foreach ($formulaire['champs'] as $nom => $valeur): ?>
        <input type="hidden" name="<?= prefaEscape($nom) ?>" value="<?= prefaEscape((string) $valeur) ?>">
    <?php endforeach; ?>
    <?= validationSouduresControles($formulaire['cordons']) ?>
    <div class="decision-pied validation-pied">
        <?php if ($sansSignature): ?>
            <div class="decision-signature" data-signature-requise>
                <p><strong>Il vous faut une signature pour valider.</strong> Tracez-la une fois : elle visera les soudures que vous validez.</p>
                <button type="button" class="decision-signer" data-signature-tracer><?= signatureIcone('refaire') ?>Tracer ma signature</button>
            </div>
        <?php elseif ($formulaire['cordons']): ?>
            <p class="decision-visa"><?= $formulaire['visa']['annonce'] ?></p>
        <?php endif; ?>
        <div class="decision-boutons">
            <?php // Ce que la validation change, en sourd : le chef vise des soudures, le
                  // pourcentage n'en est que la suite. Il était en tête de la décision, en
                  // couleur, au-dessus des soudures. ?>
            <span class="validation-effet">
                <span class="sr-only">En validant, l’avancement passe de <?= $avant ?> % à <?= $apres ?> %.</span>
                <span aria-hidden="true">Avancement <?= $avant ?> % <span class="validation-effet-fleche">→</span> <strong><?= $apres ?> %</strong></span>
            </span>
            <?php if ($refuser): ?>
                <button type="submit" name="decision" value="refuser" class="decision-refuser"
                        title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>
            <?php endif; ?>
            <button type="submit"<?= $refuser ? ' name="decision" value="valider"' : '' ?> class="decision-valider"
                    title="<?= prefaEscape((string) $formulaire['aide']) ?>"<?= $sansSignature ? ' disabled data-attend-signature' : '' ?>><?= prefaEscape((string) $formulaire['valider']) ?></button>
            <span class="atelier-message" role="status" aria-live="polite"></span>
        </div>
    </div>
</form>
<?php
    return ob_get_clean();
}

/**
 * Les contrôles des soudures qu'une validation couvre : un tableau, une soudure par ligne,
 * rangées sous leur feuille — le numéro de ligne ISO, et la page quand il en a plusieurs : les
 * numéros 1, 2, 3 reviennent d'une page à l'autre.
 *
 * Chaque contrôle est une case qu'on touche : verte et cochée, il est OK — une croix sur le
 * plan. RT, VT et PT, en tête de colonne, cochent la colonne entière : sur vingt soudures,
 * cocher VT une à une, c'est vingt touchers pour dire une seule chose. Ces cases-là n'ont pas
 * de nom : elles ne partent pas avec le formulaire, seules celles des soudures comptent
 * (atelier.js, « .soudure-controles-tout »).
 *
 * « N° 1 » et la feuille se touchent : la fenêtre du plan s'ouvre sur la feuille, la soudure
 * cerclée sur le dessin (prefa-interactions.js, [data-voir-soudure]).
 *
 * @param list<array<string, mixed>> $cordons des lignes de soudureRelevees()
 */
function validationSouduresControles(array $cordons): string
{
    $html = '<input type="hidden" name="controles_vus" value="1">';
    if (!$cordons) return $html;

    $plusieurs = count($cordons) > 1;
    $coche = '<svg class="validation-coche" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-7" /></svg>';
    $oeil = '<svg class="icon soudure-voir-oeil" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/></svg>';
    $voir = static function (array $cordon, ?string $numero = null): string {
        if (empty($cordon['id_document']) || empty($cordon['id_element'])) return '';
        return ' data-voir-soudure data-element="' . (int) $cordon['id_element'] . '" data-document="' . (int) $cordon['id_document'] . '"'
            . ' data-page="' . max(1, (int) ($cordon['page'] ?? 1)) . '"'
            . ($numero !== null ? ' data-numero="' . prefaEscape($numero) . '"' : '');
    };

    // En tête de colonne : la case de toute la colonne, cochée quand chaque soudure l'est.
    $entete = '';
    foreach (SOUDURE_CONTROLES as $cle => $libelle) {
        if (!$plusieurs) {
            $entete .= '<th scope="col" class="validation-col-controle">' . $libelle . '</th>';
            continue;
        }
        $toutes = !array_filter($cordons, static fn (array $cordon): bool => trim((string) $cordon[$cle]) === '');
        $entete .= '<th scope="col" class="validation-col-controle">'
            . '<label class="validation-croix validation-croix-tete" title="' . $libelle . ' OK sur toutes les soudures">'
            . '<input type="checkbox" class="soudure-croix soudure-controles-tout" data-controle="' . $cle . '"'
            . ($toutes ? ' checked' : '') . ' aria-label="' . $libelle . ' OK sur toutes les soudures">'
            . '<span>' . $coche . $libelle . '</span></label></th>';
    }

    $corps = '';
    $groupe = null;
    foreach ($cordons as $cordon) {
        $feuille = soudureFeuille($cordon);
        if ($feuille['cle'] !== $groupe) {
            if ($groupe !== null) $corps .= '</tbody>';
            $titre = prefaEscape($feuille['ligne'])
                . ($feuille['page'] !== null ? '<span class="validation-feuille-page">page ' . $feuille['page'] . '</span>' : '');
            $attributs = $voir($cordon);
            $corps .= '<tbody><tr class="validation-feuille"><th colspan="' . (1 + count(SOUDURE_CONTROLES)) . '" scope="rowgroup">'
                . ($attributs !== ''
                    ? '<button type="button" class="soudure-voir"' . $attributs . ' title="Ouvrir le PDF de la feuille">' . $titre
                    . '<span class="soudure-voir-libelle">' . $oeil . 'Ouvrir le PDF</span></button>'
                    : $titre)
                . '</th></tr>';
            $groupe = $feuille['cle'];
        }

        $id = (int) $cordon['id'];
        $numero = prefaEscape((string) $cordon['numero']);
        $detail = implode(' · ', array_filter([
            trim((string) ($cordon['type_soudure'] ?? '')),
            $cordon['date_soudure'] ? date('d/m/Y', strtotime((string) $cordon['date_soudure'])) : '',
        ], 'strlen'));
        $attributs = $voir($cordon, (string) $cordon['numero']);
        $nom = '<strong>N° ' . $numero . '</strong>';
        // Le même numéro revient d'une feuille à l'autre : le nom des cases dit laquelle.
        $qui = 'Soudure ' . $numero . ' (' . prefaEscape($feuille['ligne']) . ')';

        $corps .= '<tr><th scope="row">'
            . ($attributs !== ''
                ? '<button type="button" class="soudure-voir"' . $attributs . ' title="Voir la soudure N° ' . $numero . ' sur le PDF">' . $nom . $oeil . '</button>'
                : $nom)
            . ($detail !== '' ? '<span class="validation-soudure-detail">' . prefaEscape($detail) . '</span>' : '')
            . '<input type="hidden" name="cordons[]" value="' . $id . '"></th>';
        foreach (SOUDURE_CONTROLES as $cle => $libelle) {
            $corps .= '<td class="validation-col-controle"><label class="validation-croix">'
                . '<input type="checkbox" class="soudure-croix" name="controles[' . $cle . '][]" value="' . $id . '" data-controle="' . $cle . '"'
                . (trim((string) $cordon[$cle]) !== '' ? ' checked' : '') . ' aria-label="' . $qui . ' : ' . $libelle . ' OK">'
                . '<span>' . $coche . '</span></label></td>';
        }
        $corps .= '</tr>';
    }
    $corps .= '</tbody>';

    return $html . '<div class="soudure-controles validation-controles">'
        . '<p class="validation-controles-aide">Touchez une case quand le contrôle est OK : c’est une croix sur le plan.'
        . ($plusieurs ? ' RT, VT ou PT, en tête, cochent toute la colonne.' : '') . '</p>'
        . '<table class="validation-table"><thead><tr><th scope="col">Soudure</th>' . $entete . '</tr></thead>'
        . $corps . '</table></div>';
}

/**
 * Les plans dont le suivi soudage attend une décision : la pastille de « Validation des
 * soudures ». Une lecture, pas le calcul de l'écran : le tableau de bord la fait à chaque
 * ouverture. Le cas rare des soudures relevées sans déclaration ne s'y compte pas ; l'écran,
 * lui, le montre.
 */
function validationSouduresCompte(PDO $db): int
{
    return (int) $db->query("SELECT COUNT(DISTINCT a.id_element)
        FROM avancement_atelier h
        JOIN affectation_atelier a ON a.id = h.id_affectation AND a.actif = 1
        JOIN element_atelier e ON e.id = a.id_element
        JOIN demande_prefabrication d ON d.id = e.id_demande AND d.id_statut = 2
        WHERE h.etat = 'propose' AND a.type_affectation = 'soudage'")->fetchColumn();
}

/**
 * Ce qui reste d'une validation de soudures là où elle se faisait avant son écran : combien
 * attendent, et le bouton qui ouvre « Validation des soudures » sur ce plan
 * (prefa-interactions.js, [data-vers-validation]).
 *
 * @param string $contexte de quel plan il s'agit, quand l'endroit ne le dit pas déjà (la fiche
 *                         d'une demande couvre tous ses plans)
 */
function validationSouduresRenvoi(int $elementId, int $soudures, string $contexte = ''): string
{
    $combien = match (true) {
        $soudures === 1 => '1 soudure à valider',
        $soudures > 1 => $soudures . ' soudures à valider',
        default => 'Des soudures à valider',
    };
    return '<div class="validation-renvoi">'
        . '<span class="validation-renvoi-texte"><span class="decision-etat">À valider</span>'
        . ($contexte !== '' ? '<strong>' . prefaEscape($contexte) . '</strong> · ' : '') . $combien . '</span>'
        . '<button type="button" class="validation-renvoi-bouton" data-vers-validation="' . $elementId . '"'
        . ' title="Ouvre « Validation des soudures » sur ce plan">Valider →</button>'
        . '</div>';
}
