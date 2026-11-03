<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
require_once __DIR__ . '/../includes/atelier/soudure.php';
require_once __DIR__ . '/../includes/atelier/decision.php';

// L'écran de l'encadrement : il regarde l'atelier entier d'un coup. L'atelier, lui, a
// « Mes soudures » et n'y voit que ses propres plans.
if (!$canManageWorkshop) prefaError(403, 'Cet écran est réservé au chargé de production et au chef d’atelier.');

$plans = soudureSuiviPlans($db);

// Qui a relevé des cordons sur un plan et y est affecté, à n'importe quel travail : quand
// le plan n'a pas encore de suivi soudage, « Valider » l'ouvre à son nom. Une affectation
// par soudeur suffit — c'est elle qui dit que l'encadrement l'a mis sur ce plan. « Fait » se
// lit comme partout : un procédé ou une date.
$soudeursAffectes = $db->prepare("SELECT MIN(a.id) AS affectation, a.id_utilisateur AS soudeur, TRIM(CONCAT(u.prenom, ' ', u.name)) AS nom
    FROM affectation_atelier a
    JOIN Utilisateur u ON u.id = a.id_utilisateur
    WHERE a.id_element = ? AND a.actif = 1 AND u.id_role = ?
      AND EXISTS (SELECT 1 FROM plan_soudure s
          WHERE s.id_element = a.id_element AND s.id_soudeur = a.id_utilisateur
            AND (TRIM(s.type_soudure) <> '' OR s.date_soudure IS NOT NULL))
    GROUP BY a.id_utilisateur, u.prenom, u.name
    ORDER BY nom");

// Les déclarations en attente, toutes affectations confondues : une seule lecture.
$idsAffectations = [];
foreach ($plans as $plan) foreach ($plan['affectations'] as $affectation) $idsAffectations[] = (int) $affectation['id'];
$attentes = avancementEnAttentePlusieurs($db, $idsAffectations);

// Ce que chaque plan porte vraiment. Un plan sans cordon ni liste reconnue n'a rien à
// faire ici : c'est un écran sur les soudures, pas sur les plans.
$lignes = [];
$aValider = 0;
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
    $aValider += count($enAttente);

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

// Les états d'un plan, dans l'ordre des onglets : ce qui attend une décision d'abord, puis
// le travail dans l'ordre où il avance. Chacun a son point — la légende de ceux qui ouvrent
// les lignes — et dit dans son infobulle ce qu'il range.
$etats = [
    'a_valider' => ['libelle' => 'À valider', 'aide' => 'Un avancement déclaré attend votre décision'],
    'en_cours' => ['libelle' => 'En cours', 'aide' => 'Des soudures relevées, pas encore toutes'],
    'a_faire' => ['libelle' => 'Pas commencé', 'aide' => 'Une liste de soudures, aucune relevée'],
    'complet' => ['libelle' => 'Toutes soudées', 'aide' => 'Toutes les soudures prévues sont relevées'],
];
foreach ($lignes as &$ligne) {
    $ligne['etat'] = match (true) {
        (bool) $ligne['attentes'] => 'a_valider',
        $ligne['prevues'] > 0 && $ligne['faites'] >= $ligne['prevues'] => 'complet',
        $ligne['faites'] > 0 => 'en_cours',
        default => 'a_faire',
    };
}
unset($ligne);

// Rangés par état, dans l'ordre des onglets : ce qui attend en tête. Dans un même état,
// l'ordre de la lecture — la demande la plus récente d'abord (usort est stable).
$rangEtat = array_flip(array_keys($etats));
usort($lignes, static fn (array $a, array $b): int => $rangEtat[$a['etat']] <=> $rangEtat[$b['etat']]);
$parEtat = array_count_values(array_column($lignes, 'etat'));
$faitesTotal = array_sum(array_column($lignes, 'faites'));
$prevuesTotal = array_sum(array_column($lignes, 'prevues'));
?>
<section class="page prefa-page prefa-list atelier-page" id="suivi-soudures-page" aria-labelledby="suivi-soudures-title" data-densite="confortable">
    <div class="prefa-shell">
        <?php // L'en-tête des listes : le titre à gauche, la densité et « Actualiser » à droite,
              // comme les demandes, les plans et les commandes. ?>
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="suivi-soudures-title">Soudures</h1>
                <p>Les soudures relevées par l’atelier, et les avancements qu’il vous demande de valider.</p>
            </div>
            <div class="prefa-vue-outils">
                <?= listeDensite() ?>
                <button type="button" class="suivi-soudures-actualiser pv-outil">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>
                    Actualiser
                </button>
            </div>
        </header>

        <?php // La barre de la maison : la recherche seule. L'état n'y est plus — ce sont les
              // onglets, juste dessous — et l'affaire se cherche comme le reste : sa liste
              // déroulante ne paraissait qu'au-delà d'un chantier, et répétait la recherche. ?>
        <div class="prefa-filters" role="search">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="suivi-soudures-recherche" type="search" autocomplete="off" aria-label="Rechercher un plan"
                       placeholder="Plan, demande, affaire, soudeur…">
            </div>
        </div>

        <?php // Un onglet par état, avec son point : ce sont eux, la légende des points qui
              // ouvrent les lignes. L'état avait une liste déroulante dans la barre, et une
              // colonne au bout de la ligne, loin du nom du plan. ?>
        <div class="prefa-onglets-barre">
            <div class="prefa-onglets" role="group" aria-label="État des plans">
                <button type="button" class="prefa-onglet suivi-soudures-onglet" data-etat="" aria-pressed="true">
                    Tous <span class="prefa-onglet-compte"><?= count($lignes) ?></span>
                </button>
                <?php foreach ($etats as $cle => $etatPlan): ?>
                    <button type="button" class="prefa-onglet suivi-soudures-onglet" data-etat="<?= $cle ?>"
                            title="<?= prefaEscape($etatPlan['aide']) ?>" aria-pressed="false">
                        <span class="prefa-point suivi-soudures-point est-<?= $cle ?>" aria-hidden="true"></span>
                        <?= prefaEscape($etatPlan['libelle']) ?>
                        <span class="prefa-onglet-compte"><?= $parEtat[$cle] ?? 0 ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php // Ce que l'atelier a fait, en un chiffre ; et ce qui attend une décision, à la
                  // couleur de l'attente, comme les « PV à faire ». ?>
            <p class="prefa-list-summary">
                <?php if ($aValider): ?>
                    <span class="suivi-soudures-a-valider"><strong><?= $aValider ?></strong> <?= $aValider === 1 ? 'avancement à valider' : 'avancements à valider' ?></span>
                <?php endif; ?>
                <span><strong><?= $faitesTotal ?></strong> <?= $prevuesTotal
                    ? ($faitesTotal > 1 ? 'soudures relevées' : 'soudure relevée') . ' sur ' . $prevuesTotal
                    : ($faitesTotal > 1 ? 'soudures relevées' : 'soudure relevée') ?></span>
            </p>
        </div>

        <div class="prefa-table-scroll" role="region" aria-label="Soudures par plan" tabindex="0">
            <table class="prefa-table prefa-table-points suivi-soudures-table">
                <thead><tr>
                    <th scope="col">Plan</th>
                    <th scope="col">Demande n°</th>
                    <th scope="col" class="prefa-affaire">Affaire</th>
                    <th scope="col">Atelier</th>
                    <th scope="col" class="prefa-nombre">Soudures</th>
                    <th scope="col" class="prefa-nombre">Validé</th>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$lignes): ?>
                    <tbody><tr><td colspan="7" class="prefa-table-empty">Aucun plan ne porte encore de liste de soudures. Elle est reconnue sur l’isométrique quand une feuille est ouverte.</td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($lignes as $ligne):
                    $elementId = (int) $ligne['id'];
                    $etat = $ligne['etat'];
                    // Une personne par nom, quel que soit le nombre de travaux qu'elle tient.
                    $personnes = [];
                    foreach ($ligne['affectations'] as $affectation) $personnes[$affectation['personne']] ??= $affectation;
                    $personnes = array_values($personnes);
                    $relevees = soudureRelevees($db, $elementId);
                    // La déclaration du suivi soudage, s'il y en a une : c'est le chiffre de la
                    // colonne « Validé » qu'elle remplacerait.
                    $attenteSoudage = null;
                    foreach ($ligne['attentes'] as $attente) {
                        if ($attente['affectation']['type_affectation'] === 'soudage') $attenteSoudage = $attente;
                    }
                    // La barre va du validé au relevé : la part pleine est reconnue, la part pâle
                    // est relevée et attend encore d'être validée.
                    $partRelevee = $ligne['prevues'] ? min(100, (int) round($ligne['faites'] * 100 / $ligne['prevues'])) : 0;
                    $partValidee = min(100, max(0, $ligne['avancement']));
                ?>
                    <tbody class="prefa-request suivi-soudures-plan" data-etat="<?= $etat ?>"
                           data-cherche="<?= prefaEscape(mb_strtolower(prefaReferenceComplete((int) $ligne['id_demande']) . ' ' . $ligne['nom_affaire'] . ' ' . $ligne['reference'] . ' ' . $ligne['libelle'] . ' ' . implode(' ', array_column($personnes, 'personne')))) ?>">
                        <tr>
                            <?php // Le point de l'état ouvre la ligne, puis la place de l'urgence, puis
                                  // le plan : c'est lui qu'on examine. La demande et l'affaire le
                                  // situent, à côté. ?>
                            <td class="prefa-reference suivi-soudures-nom">
                                <span class="prefa-point suivi-soudures-point est-<?= $etat ?>" role="img"
                                      aria-label="<?= prefaEscape($etats[$etat]['libelle']) ?>" title="<?= prefaEscape($etats[$etat]['libelle']) ?>"></span><span class="prefa-urgence"><?php if (!empty($ligne['urgent'])): ?><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Demande urgente"><title>Demande urgente</title><path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" /></svg><?php endif; ?></span><?= prefaEscape($ligne['reference']) ?>
                                <?php if ($ligne['libelle'] !== ''): ?><span class="atelier-row-description"><?= prefaEscape($ligne['libelle']) ?></span><?php endif; ?>
                            </td>
                            <td class="prefa-secondaire"><?= prefaEscape(prefaReference((int) $ligne['id_demande'])) ?></td>
                            <td class="prefa-affaire"><?= prefaEscape((string) ($ligne['nom_affaire'] ?: '—')) ?></td>
                            <?php // Qui y travaille, au monogramme comme le demandeur d'une demande ; au-delà
                                  // d'une personne, le compte, et tous les noms dans l'infobulle. ?>
                            <td class="prefa-secondaire suivi-soudures-atelier">
                                <?php if ($personnes): $premier = $personnes[0]; ?>
                                    <span class="prefa-personne"<?= count($personnes) > 1 ? ' title="' . prefaEscape(implode(', ', array_column($personnes, 'personne'))) . '"' : '' ?>>
                                        <span class="prefa-avatar" aria-hidden="true"><?= prefaEscape(mb_strtoupper(mb_substr(trim((string) $premier['prenom']), 0, 1) . mb_substr(trim((string) $premier['name']), 0, 1))) ?></span><?= prefaEscape($premier['personne']) ?>
                                        <?php if (count($personnes) > 1): ?><span class="suivi-soudures-plus">+<?= count($personnes) - 1 ?></span><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="suivi-soudures-sur">Non affecté</span>
                                <?php endif; ?>
                            </td>
                            <td class="prefa-nombre suivi-soudures-cordons"<?= $ligne['lues'] ? ' title="' . prefaEscape('Soudures prévues : ' . $ligne['lues']) . '"' : '' ?>>
                                <span class="suivi-soudures-compte"><?= $ligne['faites'] ?><?= $ligne['prevues'] ? '<span> / ' . $ligne['prevues'] . '</span>' : '' ?></span>
                                <?php if ($ligne['prevues']): ?><span class="suivi-soudures-barre" aria-hidden="true" style="--validee: <?= min($partValidee, $partRelevee) ?>%; --relevee: <?= max(0, $partRelevee - $partValidee) ?>%"><span class="suivi-soudures-barre-validee"></span><span class="suivi-soudures-barre-relevee"></span></span><?php endif; ?>
                            </td>
                            <?php // Le chiffre reconnu, et celui qui l'attend : la flèche dit ce que
                                  // « Valider » changerait. ?>
                            <td class="prefa-nombre suivi-soudures-valide">
                                <?= $ligne['avancement'] ?> %<?php if ($attenteSoudage): ?><span class="suivi-soudures-propose" title="<?= prefaEscape('Déclaré par ' . $attenteSoudage['auteur'] . ', à valider') ?>">→ <?= (int) $attenteSoudage['avancement'] ?> %</span><?php endif; ?>
                            </td>
                            <?php // « Examiner », au contour comme « Détails » d'un bon : il ouvre la
                                  // fenêtre où l'on décide, il ne valide rien lui-même. Le libellé ne
                                  // change pas d'une ligne à l'autre — l'état est dans le point. Toute
                                  // la ligne l'actionne (suivi-soudures.js). ?>
                            <td class="prefa-actions">
                                <span class="prefa-decision">
                                    <button type="button" class="prefa-toggle prefa-ligne-contour suivi-soudures-examiner"
                                            aria-controls="suivi-soudures-detail-<?= $elementId ?>" aria-expanded="false" aria-haspopup="dialog">Examiner</button>
                                </span>
                            </td>
                        </tr>

                        <tr id="suivi-soudures-detail-<?= $elementId ?>" class="prefa-detail-row suivi-soudures-detail" hidden>
                            <td colspan="7">
                                <div class="atelier-detail-content suivi-soudures-volet">
                                    <?php // L'en-tête des volets : ce que c'est, lequel, de quelle demande. ?>
                                    <div class="prefa-detail-heading suivi-soudures-entete">
                                        <div class="suivi-soudures-identite">
                                            <p class="suivi-soudures-surtitre">Soudures du plan</p>
                                            <h2><?= prefaEscape($ligne['reference']) ?> <span><?= prefaEscape($ligne['libelle']) ?></span></h2>
                                            <p class="suivi-soudures-parcours"><?= prefaEscape(prefaReference((int) $ligne['id_demande'])) ?><?= $ligne['nom_affaire'] ? ' · ' . prefaEscape($ligne['nom_affaire']) : '' ?></p>
                                        </div>
                                        <button type="button" class="prefa-detail-close" data-close-target="suivi-soudures-detail-<?= $elementId ?>" aria-label="Fermer le volet" title="Fermer">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                                        </button>
                                    </div>

                                    <?php // L'avancement en lignes de propriété : ce qui est reconnu, ce que
                                          // l'atelier a relevé, qui y travaille. La déclaration à valider vient
                                          // dessous, à part — c'est tout l'objet de la validation. Une phrase
                                          // disait qu'il n'y en avait pas ; l'absence de la bande le dit. ?>
                                    <section class="suivi-soudures-section">
                                        <h3 class="suivi-soudures-titre">Avancement</h3>
                                        <dl class="suivi-soudures-proprietes">
                                            <div><dt>Validé</dt><dd>
                                                <span class="suivi-soudures-barre suivi-soudures-barre-large" aria-hidden="true"><span style="--part: <?= min(100, max(0, $ligne['avancement'])) ?>%"></span></span>
                                                <strong><?= $ligne['avancement'] ?> %</strong>
                                            </dd></div>
                                            <div><dt>Soudures relevées</dt><dd><strong><?= $ligne['faites'] ?></strong><?= $ligne['prevues'] ? '<span class="suivi-soudures-sur"> sur ' . $ligne['prevues'] . ' prévues</span>' : '' ?></dd></div>
                                            <div><dt>Atelier</dt><dd><?= $personnes ? prefaEscape(implode(', ', array_column($personnes, 'personne'))) : '<span class="suivi-soudures-sur">Non affecté</span>' ?></dd></div>
                                        </dl>

                                        <?php foreach ($ligne['attentes'] as $attente) {
                                            // Une déclaration peut venir d'un autre travail que le suivi
                                            // soudage : le bloc dit lequel.
                                            echo avancementDecisionRendu([
                                                'titre' => 'Avancement déclaré',
                                                'avancement' => (int) $attente['avancement'],
                                                'valide' => (int) $attente['affectation']['avancement'],
                                                'compte' => avancementCompte($attente),
                                                'contexte' => atelierTravaux()[$attente['affectation']['type_affectation']] ?? null,
                                                'qui' => $attente['auteur'],
                                                'quand' => $attente['date_saisie'],
                                                'commentaire' => $attente['commentaire'],
                                            ], avancementDecisionFormulaire([
                                                'champs' => ['id' => (int) $ligne['id_demande'], 'operation' => 'valider',
                                                    'affectation' => (int) $attente['affectation']['id'], 'declaration' => (int) $attente['id']],
                                                'cordons' => avancementCordonsAValider($db, $elementId, (int) $attente['affectation']['id_utilisateur']),
                                                'visa' => signatureAnnonceVisa($db, $utilisateur),
                                                'valider' => 'Valider',
                                                'valider_aide' => 'L’avancement du plan prendra ce chiffre.',
                                                'refuser' => true,
                                            ]));
                                        } ?>

                                        <?php // Des cordons relevés que rien ne propose de valider : le soudeur a
                                              // enregistré avant que la liste du plan soit lue, ou avant que le
                                              // suivi soudage s'ouvre tout seul. Le chef n'avait alors qu'une liste
                                              // sous les yeux, et aucun moyen de reconnaître ce qu'il voyait. Il
                                              // pose le chiffre lui-même : il est l'autorité.
                                              //
                                              // Sans suivi soudage, il n'a rien à affecter avant : le soudeur l'est
                                              // déjà, et son « Valider » ouvre le suivi à son nom (opération
                                              // « cordons »). Il lui fallait affecter « Suivi soudage » ailleurs, et
                                              // il ne comprenait pas pourquoi.
                                        $calcule = $ligne['prevues'] > 0 ? avancementPourcentage(min($ligne['faites'], $ligne['prevues']), $ligne['prevues']) : null;
                                        if (!$ligne['attentes'] && $calcule !== null && $ligne['faites'] > 0 && $calcule !== $ligne['avancement']):
                                            $soudures = min($ligne['faites'], $ligne['prevues']);
                                            $soudeurs = [];
                                            if (!$ligne['soudage']) {
                                                $soudeursAffectes->execute([$elementId, Role::PersonnelAtelier->value]);
                                                $soudeurs = $soudeursAffectes->fetchAll(PDO::FETCH_ASSOC);
                                            }
                                            $formulaires = '';
                                            if ($ligne['soudage']) {
                                                $formulaires .= avancementDecisionFormulaire([
                                                    'champs' => ['id' => (int) $ligne['id_demande'], 'operation' => 'progress',
                                                        'affectation' => (int) $ligne['soudage']['id'], 'revision' => (int) $ligne['soudage']['revision'],
                                                        'soudures' => $soudures],
                                                    'cordons' => avancementCordonsAValider($db, $elementId, (int) $ligne['soudage']['id_utilisateur']),
                                                    'visa' => signatureAnnonceVisa($db, $utilisateur),
                                                    'valider' => 'Valider',
                                                    'valider_aide' => 'L’avancement du suivi soudage prendra ce chiffre.',
                                                ]);
                                            }
                                            // Un bouton par soudeur affecté, nommé seulement s'ils sont plusieurs :
                                            // c'est à son nom que le suivi s'ouvre.
                                            foreach ($soudeurs as $soudeur) {
                                                $formulaires .= avancementDecisionFormulaire([
                                                    'champs' => ['id' => (int) $ligne['id_demande'], 'operation' => 'cordons',
                                                        'affectation' => (int) $soudeur['affectation'], 'soudures' => $soudures],
                                                    'cordons' => avancementCordonsAValider($db, $elementId, (int) $soudeur['soudeur']),
                                                    'visa' => signatureAnnonceVisa($db, $utilisateur),
                                                    'valider' => 'Valider' . (count($soudeurs) > 1 ? ' · suivi par ' . $soudeur['nom'] : ''),
                                                    'valider_aide' => 'Le suivi soudage du plan prendra ce chiffre.',
                                                ]);
                                            }
                                            // Le soudeur n'est plus affecté au plan, ou les cordons viennent de
                                            // l'encadrement : personne au nom de qui ouvrir le suivi.
                                            if ($formulaires === '') {
                                                $formulaires = '<div class="decision-note">Personne de l’atelier n’est affecté à ce plan : affectez « Suivi soudage »'
                                                    . ' dans Plans / ISO &amp; Affectations pour pouvoir valider.</div>';
                                            }
                                            echo avancementDecisionRendu([
                                                'titre' => 'Soudures relevées, pas encore validées',
                                                'avancement' => $calcule,
                                                'valide' => $ligne['avancement'],
                                                'compte' => avancementCompte(['soudures' => $soudures, 'soudures_total' => $ligne['prevues'], 'avancement' => $calcule]),
                                            ], $formulaires);
                                        endif; ?>
                                    </section>

                                    <section class="suivi-soudures-section atelier-soudures">
                                        <h3 class="suivi-soudures-titre">Soudures relevées</h3>

                                        <?php if (!$relevees): ?>
                                            <p class="suivi-soudures-vide">Aucune soudure relevée pour l’instant.</p>
                                        <?php else: ?>
                                            <?php // La note sur les contrôles « qui se cochent dans la fenêtre du
                                                  // plan » est partie : elle répondait à une question que personne ne
                                                  // pose ici. L'infobulle des colonnes la garde pour qui la cherche. ?>
                                            <table class="atelier-soudures-table">
                                                <thead><tr>
                                                    <th scope="col">N°</th><th scope="col">Feuille</th>
                                                    <th scope="col">Soudeur</th><th scope="col">Date</th>
                                                    <th scope="col">Type</th>
                                                    <th scope="col" class="soudure-controle" title="Radiographie — se coche en validant, ou dans la fenêtre du plan">RT</th>
                                                    <th scope="col" class="soudure-controle" title="Contrôle visuel — se coche en validant, ou dans la fenêtre du plan">VT</th>
                                                    <th scope="col" class="soudure-controle" title="Ressuage — se coche en validant, ou dans la fenêtre du plan">PT</th>
                                                    <th scope="col">Visa</th>
                                                </tr></thead>
                                                <tbody>
                                                    <?php foreach ($relevees as $cordon):
                                                        $feuilleNom = preg_replace('/^[a-f0-9]{32}-/', '', (string) ($cordon['cle_document'] ?? ''));
                                                        $feuilleNom = preg_replace('/\.pdf$/i', '', $feuilleNom);
                                                        $fait = static fn ($valeur): string => trim((string) $valeur) === ''
                                                            ? '<span class="soudure-controle-non" aria-label="non fait">—</span>'
                                                            : '<svg class="soudure-controle-oui" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="fait"><path d="m3.5 8.5 3 3 6-7" /></svg>';
                                                    ?>
                                                        <tr>
                                                            <td><?= prefaEscape((string) $cordon['numero']) ?></td>
                                                            <td title="<?= prefaEscape($feuilleNom) ?>"><?= prefaEscape($feuilleNom ?: '—') ?><?= (int) ($cordon['pages'] ?? 1) > 1 ? '<span class="suivi-soudures-sur"> · p. ' . (int) $cordon['page'] . '</span>' : '' ?></td>
                                                            <td><?= prefaEscape((string) ($cordon['soudeur_nom'] ?: '—')) ?></td>
                                                            <td><?= $cordon['date_soudure'] ? prefaEscape(date('d/m/Y', strtotime((string) $cordon['date_soudure']))) : '—' ?></td>
                                                            <td><?= prefaEscape((string) ($cordon['type_soudure'] ?: '—')) ?></td>
                                                            <td class="soudure-controle"><?= $fait($cordon['rt']) ?></td>
                                                            <td class="soudure-controle"><?= $fait($cordon['vi']) ?></td>
                                                            <td class="soudure-controle"><?= $fait($cordon['pt']) ?></td>
                                                            <td><?= $cordon['visa_nom'] ? prefaEscape((string) $cordon['visa_nom']) : '<span class="suivi-soudures-sur">—</span>' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>

                <tbody class="suivi-soudures-aucun" data-aucun hidden>
                    <tr><td colspan="7" class="prefa-table-empty">Aucun plan ne correspond à cette recherche.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
