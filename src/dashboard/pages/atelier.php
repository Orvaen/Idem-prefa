<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

/*
|--------------------------------------------------------------------------
| « Mes plans » — sur le modèle des écrans de l'encadrement
|--------------------------------------------------------------------------
|
| L'écran avait sa grammaire à lui : une ligne par affectation, un détail en pleine page,
| des onglets « Étapes / Avancement », sept listes déroulantes et des boutons bleus. Il
| suit maintenant la DA (docs/direction-artistique.md) et la forme des écrans déjà repris
| — « Plans / ISO & Affectations », « Soudures », les PV : une recherche, des onglets
| comptés, un tableau groupé, et un volet flottant par plan.
|
| Une ligne par plan, et non par affectation : c'est le plan qu'on ouvre, et ses travaux
| se lisent dedans. Les étapes sont celles du plan — partagées par tous ceux qui y
| travaillent — et le volet de chaque travail les répétait.
*/

$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];

$stmt = $db->prepare('SELECT a.*, e.reference, e.libelle, e.id_demande, d.nom_affaire, d.urgent
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY e.id_demande DESC, e.reference');
$stmt->execute([$actor['id']]);

$plans = [];
foreach ($stmt->fetchAll() as $affectation) {
    $element = (int) $affectation['id_element'];
    $plans[$element] ??= [
        'element' => $element,
        'reference' => (string) $affectation['reference'],
        'libelle' => (string) $affectation['libelle'],
        'demande' => (int) $affectation['id_demande'],
        'affaire' => (string) ($affectation['nom_affaire'] ?? ''),
        'urgent' => !empty($affectation['urgent']),
        'travaux' => [],
    ];
    $plans[$element]['travaux'][] = $affectation;
}

// Les travaux d'un plan dans l'ordre où ils se font, et non dans l'ordre alphabétique.
$ordreTravaux = array_flip(array_keys($types));
foreach ($plans as &$plan) {
    usort($plan['travaux'], static fn (array $a, array $b): int =>
        ($ordreTravaux[$a['type_affectation']] ?? 9) <=> ($ordreTravaux[$b['type_affectation']] ?? 9));
}
unset($plan);

// Les déclarations pas encore tranchées par le chef, toutes en une lecture.
$affectationIds = [];
foreach ($plans as $plan) foreach ($plan['travaux'] as $travail) $affectationIds[] = (int) $travail['id'];
$enAttente = avancementEnAttentePlusieurs($db, $affectationIds);

// Les fichiers de chaque plan : les feuilles ISO, et ce qui les accompagne (QMOS, DMOS).
$documentsDuPlan = [];
$feuillesDuPlan = [];
$lireDocuments = $db->prepare('SELECT * FROM element_atelier_document WHERE id_element = ? ORDER BY id');
foreach ($plans as $element => $plan) {
    $documentsDuPlan[$element] = [];
    $feuillesDuPlan[$element] = 0;
    $lireDocuments->execute([$element]);
    foreach ($lireDocuments->fetchAll() as $doc) {
        $nom = atelierDocumentName($db, $plan['demande'], $doc['type_document'], $doc['cle_document']);
        if (!$nom) continue;
        $endpoint = $doc['type_document'] === 'plan' ? 'download_prefa_attachment.php' : 'download_' . $doc['type_document'] . '.php';
        // La référence voyage dans l'adresse, pas l'identifiant technique : les points
        // d'entrée de téléchargement ne lisent que « ref ».
        $documentsDuPlan[$element][] = [
            'nom' => $nom,
            'pdf' => strtolower(pathinfo($nom, PATHINFO_EXTENSION)) === 'pdf',
            'url' => 'actions/' . $endpoint . '?ref=' . rawurlencode(prefaReference($plan['demande']))
                . '&file=' . rawurlencode($doc['cle_document']),
        ];
        if ($doc['type_document'] === 'plan') $feuillesDuPlan[$element] += max(1, (int) $doc['pages']);
    }
}

/*
| Les groupes et les onglets. Un plan est terminé quand tous ses travaux sont à 100 %, en
| cours dès que l'un d'eux a avancé, à commencer sinon. Seul l'avancement validé compte :
| un chiffre déclaré n'est pas un fait tant que le chef ne l'a pas reconnu.
*/
$groupes = [
    'en-cours' => ['libelle' => 'En cours', 'aide' => 'travaux commencés', 'plans' => []],
    'a-commencer' => ['libelle' => 'À commencer', 'aide' => 'rien n’a encore avancé', 'plans' => []],
    'termines' => ['libelle' => 'Terminés', 'aide' => 'tous les travaux à 100 %', 'plans' => []],
];
foreach ($plans as $plan) {
    $avancements = array_map(static fn (array $t): int => (int) $t['avancement'], $plan['travaux']);
    $cle = min($avancements) === 100 ? 'termines' : (max($avancements) > 0 ? 'en-cours' : 'a-commencer');
    $groupes[$cle]['plans'][] = $plan;
}
$declarationsEnAttente = count($enAttente);

/** Le signe d'un groupe : le pointillé de l'attente, le demi-cercle de ce qui avance, le plein de ce qui est fini. */
$iconeGroupe = static function (string $groupe): string {
    return match ($groupe) {
        'a-commencer' => '<svg class="prefa-statut-icone status-1" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.1" /></svg>',
        'en-cours' => '<svg class="prefa-statut-icone plans-iso-en-cours" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.6" />'
            . '<path d="M8 4a4 4 0 0 1 0 8z" fill="currentColor" /></svg>',
        default => '<svg class="prefa-statut-icone status-2" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7" fill="currentColor" />'
            . '<path d="m5 8.2 2 2 4-4.2" fill="none" stroke="var(--surface)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>',
    };
};

/** L'état d'un travail, dans la pastille du volet du chef : « En cours · 40 % ». */
$etatTravail = static function (int $pct): array {
    $cle = $pct === 100 ? 'done' : ($pct > 0 ? 'progress' : 'todo');
    return [$cle, ['done' => 'Terminé', 'progress' => 'En cours', 'todo' => 'À commencer'][$cle]];
};

// Les icônes des lignes du volet, au trait des volets des PV.
$iconesTravail = [
    'plan' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" /></svg>',
    'montage' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5l3 3 5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z" /></svg>',
    'soudage' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c1.5 3 4 4.5 4 8a4 4 0 0 1-8 0c0-2 1-3 2-4 .2 1.5 1 2 2 2 0-2-1-3.5 0-6Z" /><path d="M5 21h14" /></svg>',
    'cordons' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 14c1.4 0 1.4-2 2.8-2s1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.5 2" /><path d="M3.5 19h17" /></svg>',
];
$trombone = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m21 11-8.6 8.6a5 5 0 0 1-7-7L14 4a3.3 3.3 0 0 1 4.7 4.7l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4L15.4 7" /></svg>';
$telecharger = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11" /><path d="m7 10 5 5 5-5" /><path d="M5 20h14" /></svg>';
$fermer = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>';

// Les saisies faites sur des plans qui ne lui sont plus confiés : l'historique ne se perd
// pas avec l'affectation.
$stmt = $db->prepare("SELECT h.*, e.reference, e.id_demande, a.type_affectation,
        TRIM(CONCAT(v.prenom, ' ', v.name)) AS validateur
    FROM avancement_atelier h
    JOIN affectation_atelier a ON a.id = h.id_affectation JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    LEFT JOIN Utilisateur v ON v.id = h.valide_par
    WHERE h.id_utilisateur = ? AND NOT (COALESCE(a.actif, 0) = 1 AND d.id_statut = 2) ORDER BY h.date_saisie DESC, h.id DESC LIMIT 100");
$stmt->execute([$actor['id']]);
$anciennes = $stmt->fetchAll();
?>
<section class="page prefa-page prefa-list atelier-page mes-plans-page" id="mes-plans-page" aria-labelledby="mes-plans-titre" data-densite="confortable">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="mes-plans-titre">Mes plans</h1>
                <p>Les plans qui vous sont confiés, et où en sont vos travaux.</p>
            </div>

            <?php // Les mêmes outils de vue que les autres listes, au même endroit. ?>
            <div class="prefa-vue-outils">
                <div class="prefa-densite" role="group" aria-label="Densité du tableau">
                    <button type="button" class="prefa-densite-choix" data-densite="confortable"
                            aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="3" width="12" height="2.6" rx="1" />
                            <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
                            <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
                        </svg>
                    </button>
                    <button type="button" class="prefa-densite-choix" data-densite="compact"
                            aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
                        </svg>
                    </button>
                </div>

                <button type="button" class="atelier-refresh mes-plans-actualiser">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>
                    Actualiser
                </button>
            </div>
        </header>

        <?php // La recherche de la maison : un champ à loupe à la hauteur des pastilles. Tous les
              // plans confiés sont déjà dans la page, elle filtre sur place. ?>
        <div class="prefa-filters" role="search">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="mes-plans-recherche" type="search" autocomplete="off"
                       aria-label="Rechercher un plan" placeholder="Plan, demande, affaire…">
            </div>
        </div>

        <?php if (!$plans): ?>
            <p class="prefa-table-empty mes-plans-vide">Aucun plan ne vous est confié pour le moment.</p>
        <?php else: ?>

            <div class="prefa-onglets-barre">
                <div class="prefa-onglets" role="group" aria-label="Avancement de mes plans">
                    <button type="button" class="prefa-onglet mes-plans-onglet" data-groupe="" aria-pressed="true">
                        Tous <span class="prefa-onglet-compte"><?= count($plans) ?></span>
                    </button>
                    <?php foreach ($groupes as $cle => $groupe): ?>
                        <button type="button" class="prefa-onglet mes-plans-onglet" data-groupe="<?= $cle ?>" aria-pressed="false">
                            <?= prefaEscape($groupe['libelle']) ?>
                            <span class="prefa-onglet-compte"><?= count($groupe['plans']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php // Ce qu'il a déclaré et que le chef n'a pas encore tranché : rien à faire de
                      // son côté, mais c'est ce qui explique un pourcentage qui ne bouge pas. ?>
                <p class="prefa-list-summary">
                    <span><strong><?= $declarationsEnAttente ?></strong> déclaration<?= $declarationsEnAttente > 1 ? 's' : '' ?> en attente de validation</span>
                </p>
            </div>

            <div class="prefa-table-scroll" role="region" aria-label="Mes plans" tabindex="0">
                <table class="prefa-table mes-plans-table">
                    <thead><tr>
                        <th scope="col">Plan</th>
                        <th scope="col">Demande n°</th>
                        <th scope="col" class="prefa-affaire">Affaire</th>
                        <th scope="col">Travaux</th>
                        <th scope="col" class="prefa-nombre">Feuilles</th>
                        <th scope="col"><span class="atelier-sr-only">Actions</span></th>
                    </tr></thead>

                <?php foreach ($groupes as $cleGroupe => $groupe): ?>
                    <?php if (!$groupe['plans']) continue; ?>

                    <tbody class="prefa-groupe mes-plans-groupe" data-groupe="<?= $cleGroupe ?>">
                        <tr>
                            <th scope="colgroup" colspan="6">
                                <span class="prefa-groupe-nom prefa-groupe-statut"><?= $iconeGroupe($cleGroupe) ?><?= prefaEscape($groupe['libelle']) ?></span>
                                <span class="prefa-groupe-compte"><?= count($groupe['plans']) ?></span>
                                <span class="prefa-groupe-aide"><?= prefaEscape($groupe['aide']) ?></span>
                            </th>
                        </tr>
                    </tbody>

                    <?php foreach ($groupe['plans'] as $plan):
                        $element = $plan['element'];
                        $voletId = 'mes-plans-volet-' . $element;
                        $documents = $documentsDuPlan[$element];
                        $feuilles = $feuillesDuPlan[$element];
                        // Rien ne se tape ici : l'avancement se calcule (avancementSource()). Le
                        // suivi soudage d'un plan qui porte une liste de soudures se compte sur les
                        // cordons renseignés dans « Mes soudures » ; tout le reste, sur les étapes.
                        $prevu = avancementSouduresPrevues($db, $element);
                        $soudureTotal = $prevu['prevues'];
                        $soudureFaites = $soudureTotal ? avancementSouduresFaites($db, $element) : 0;
                        $etapes = atelierElementSteps($db, $element);
                        $compteEtapes = avancementEtapes($etapes['statuts']);
                        // Les étapes s'enregistrent au titre d'une affectation de la personne sur
                        // ce plan : n'importe laquelle, elles appartiennent au plan.
                        $affectationEtapes = (int) $plan['travaux'][0]['id'];
                        $idsDuPlan = array_map(static fn (array $t): int => (int) $t['id'], $plan['travaux']);
                        $lireHistorique = $db->prepare("SELECT h.*, a.type_affectation, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur,
                                TRIM(CONCAT(v.prenom, ' ', v.name)) AS validateur
                            FROM avancement_atelier h JOIN affectation_atelier a ON a.id = h.id_affectation
                            JOIN Utilisateur u ON u.id = h.id_utilisateur
                            LEFT JOIN Utilisateur v ON v.id = h.valide_par
                            WHERE h.id_affectation IN (" . implode(',', array_fill(0, count($idsDuPlan), '?')) . ")
                            ORDER BY h.date_saisie DESC, h.id DESC");
                        $lireHistorique->execute($idsDuPlan);
                        $historiqueAvancement = $lireHistorique->fetchAll();
                        $lireEtapes = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
                            FROM element_atelier_etapes_historique h JOIN Utilisateur u ON u.id = h.modifie_par
                            WHERE h.id_element = ? ORDER BY h.revision DESC");
                        $lireEtapes->execute([$element]);
                        $historiqueEtapes = $lireEtapes->fetchAll();
                        $cherche = mb_strtolower($plan['reference'] . ' ' . $plan['libelle'] . ' ' . prefaReference($plan['demande']) . ' ' . $plan['affaire']);
                    ?>
                        <tbody class="mes-plans-plan" data-groupe="<?= $cleGroupe ?>" data-cherche="<?= prefaEscape($cherche) ?>">
                            <tr>
                                <?php // L'urgence de la demande ouvre la ligne, à la place que les listes
                                      // de la maison lui réservent — vide ou non, c'est elle qui aligne
                                      // la colonne sur son en-tête. ?>
                                <td class="mes-plans-nom">
                                    <span class="prefa-urgence"><?php if ($plan['urgent']): ?><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Demande urgente"><title>Demande urgente</title><path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" /></svg><?php endif; ?></span><strong><?= prefaEscape($plan['reference']) ?></strong>
                                    <?php if ($plan['libelle'] !== ''): ?><span class="atelier-row-description"><?= prefaEscape($plan['libelle']) ?></span><?php endif; ?>
                                </td>
                                <td class="prefa-reference"><?= prefaEscape(prefaReference($plan['demande'])) ?></td>
                                <td class="prefa-affaire"><?= prefaEscape($plan['affaire'] ?: '—') ?></td>
                                <td class="mes-plans-travaux-cellule">
                                    <?php // Un travail, une pastille : son nom et où il en est. Le détail —
                                          // déclarations, étapes, fichiers — est dans le volet. ?>
                                    <span class="mes-plans-travaux">
                                        <?php foreach ($plan['travaux'] as $travail):
                                            [$etat] = $etatTravail((int) $travail['avancement']);
                                            $attente = $enAttente[(int) $travail['id']] ?? null; ?>
                                            <span class="atelier-work-status is-<?= $etat ?>"<?= $attente ? ' title="' . prefaEscape('En attente du chef d’atelier : ' . avancementPhrase($attente)) . '"' : '' ?>><?= prefaEscape($types[$travail['type_affectation']]) ?> · <?= (int) $travail['avancement'] ?> %</span>
                                        <?php endforeach; ?>
                                    </span>
                                </td>
                                <td class="prefa-nombre"><?= $feuilles ?: '<span class="mes-plans-aucun">—</span>' ?></td>
                                <td class="mes-plans-commande"><button type="button" class="prefa-toggle" aria-controls="<?= $voletId ?>" aria-expanded="false">Mettre à jour</button></td>
                            </tr>

                            <tr id="<?= $voletId ?>" class="prefa-detail-row mes-plans-detail" hidden>
                                <td colspan="6">
                                    <div class="atelier-detail-content mes-plans-volet">
                                        <?php // L'en-tête des volets : ce que c'est, lequel, de quelle demande. ?>
                                        <header class="pv-volet-tete mes-plans-entete">
                                            <div>
                                                <h2><?= prefaEscape($plan['reference']) ?></h2>
                                                <p><?= $plan['libelle'] !== '' ? prefaEscape($plan['libelle']) . ' · ' : '' ?><?= prefaEscape(prefaReference($plan['demande'])) ?><?= $plan['affaire'] !== '' ? ' · ' . prefaEscape($plan['affaire']) : '' ?></p>
                                            </div>
                                            <div class="pv-volet-outils">
                                                <button type="button" class="prefa-detail-close" data-close-target="<?= $voletId ?>" aria-label="Fermer le volet" title="Fermer"><?= $fermer ?></button>
                                            </div>
                                        </header>

                                        <?php // Ses travaux sur ce plan, comme les affectations du volet du chef :
                                              // le travail, son état, et d'où vient le chiffre. ?>
                                        <section class="pv-volet-section mes-plans-section">
                                            <h3 class="pv-volet-titre mes-plans-titre">Mes travaux</h3>
                                            <dl class="pv-proprietes mes-plans-proprietes">
                                                <?php foreach ($plan['travaux'] as $travail):
                                                    $pct = (int) $travail['avancement'];
                                                    [$etat, $etatLibelle] = $etatTravail($pct);
                                                    $attente = $enAttente[(int) $travail['id']] ?? null;
                                                    $libelle = $types[$travail['type_affectation']]; ?>
                                                    <div>
                                                        <dt><?= $iconesTravail[$travail['type_affectation']] ?? '' ?><?= prefaEscape($libelle) ?></dt>
                                                        <dd>
                                                            <span class="mes-plans-source"><?= avancementSource((string) $travail['type_affectation'], $soudureTotal) === 'cordons' ? 'selon les cordons' : 'selon les étapes' ?></span>
                                                            <span class="atelier-work-status is-<?= $etat ?>"><?= $etatLibelle ?> · <?= $pct ?> %</span>
                                                        </dd>
                                                        <?php // Le nouveau chiffre, que le chef n'a pas encore tranché : la bande
                                                              // d'attente des volets, sous le chiffre reconnu. ?>
                                                        <?php if ($attente): ?>
                                                            <dd class="mes-plans-attente">En attente du chef d’atelier : <strong><?= prefaEscape(avancementPhrase($attente)) ?></strong> · <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></dd>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($soudureTotal): ?>
                                                    <div>
                                                        <dt><?= $iconesTravail['cordons'] ?>Cordons</dt>
                                                        <dd>
                                                            <span><strong><?= $soudureFaites ?></strong><span class="mes-plans-sur"> sur <?= $soudureTotal ?> · soit <?= avancementPourcentage($soudureFaites, $soudureTotal) ?> %</span></span>
                                                            <button type="button" class="mes-plans-commande-ligne atelier-vers-soudures">Renseigner mes soudures</button>
                                                        </dd>
                                                    </div>
                                                <?php endif; ?>
                                            </dl>
                                        </section>

                                        <?php // Les étapes du plan, partagées par tous ceux qui y travaillent, et
                                              // ce qui fait l'avancement de leurs travaux. Un état en boutons côte à
                                              // côte, comme celui des PV : sept listes déroulantes cachaient chacune
                                              // leur valeur derrière un clic. ?>
                                        <section class="pv-volet-section mes-plans-section">
                                            <div class="pv-volet-titre-rang mes-plans-titre-ligne">
                                                <h3 class="pv-volet-titre mes-plans-titre">Étapes du plan</h3>
                                                <span class="mes-plans-compte"><?= $compteEtapes['applicables']
                                                    ? $compteEtapes['faites'] . ' sur ' . $compteEtapes['applicables'] . ' faite' . ($compteEtapes['faites'] > 1 ? 's' : '')
                                                    : 'aucune ne s’applique' ?></span>
                                            </div>
                                            <p class="mes-plans-aide">Elles font l’avancement : OK compte, RAS ne compte pas. Partagées avec ceux qui travaillent sur ce plan.</p>
                                            <form class="atelier-action mes-plans-etapes" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                <input type="hidden" name="affectation" value="<?= $affectationEtapes ?>">
                                                <input type="hidden" name="operation" value="steps">
                                                <input type="hidden" name="etapes_revision" value="<?= $etapes['revision'] ?>">
                                                <dl class="pv-proprietes mes-plans-proprietes">
                                                    <?php foreach (atelierSteps() as $cleEtape => $libelleEtape):
                                                        $valeur = $etapes['statuts'][$cleEtape] ?? ($cleEtape === 'autre' ? 'RAS' : '');
                                                        $nomGroupe = 'mes-plans-etape-' . $element . '-' . $cleEtape; ?>
                                                        <div>
                                                            <dt id="<?= $nomGroupe ?>"><?= prefaEscape($libelleEtape) ?></dt>
                                                            <dd>
                                                                <span class="pv-segments mes-plans-segments" role="radiogroup" aria-labelledby="<?= $nomGroupe ?>">
                                                                    <?php foreach (['' => '—', 'OK' => 'OK', 'Non OK' => 'Non OK', 'RAS' => 'RAS'] as $choix => $texte): ?>
                                                                        <label<?= $choix === '' ? ' title="Non renseigné"' : '' ?>>
                                                                            <input type="radio" name="etapes[<?= $cleEtape ?>]" value="<?= prefaEscape($choix) ?>"<?= $valeur === $choix ? ' checked' : '' ?><?= $choix === '' ? ' aria-label="Non renseigné"' : '' ?>>
                                                                            <span class="est-<?= $choix === '' ? 'vide' : strtolower(str_replace(' ', '-', $choix)) ?>"><?= $texte ?></span>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </span>
                                                            </dd>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </dl>
                                                <div class="mes-plans-pied">
                                                    <span class="atelier-message" role="status" aria-live="polite"></span>
                                                    <button type="submit" class="pv-enregistrer mes-plans-enregistrer">Enregistrer les étapes</button>
                                                </div>
                                            </form>

                                        </section>

                                        <?php // Les fichiers du plan, qu'on ouvre ou qu'on télécharge — comme les
                                              // feuilles du volet du chef, sans ce qui sert à les gérer. ?>
                                        <section class="pv-volet-section mes-plans-section">
                                            <h3 class="pv-volet-titre mes-plans-titre">Fichiers du plan</h3>
                                            <?php if (!$documents): ?>
                                                <p class="mes-plans-aide">Pas encore de fichier. Le chef d’atelier les ajoute à ce plan.</p>
                                            <?php else: ?>
                                                <?php // En puces, comme les pièces jointes des PV : la puce ouvre le PDF
                                                      // dans le visualiseur, la flèche le télécharge. ?>
                                                <ul class="pv-documents mes-plans-fichiers">
                                                    <?php foreach ($documents as $doc): ?>
                                                        <li class="pv-document">
                                                            <a class="prefa-document-link<?= $doc['pdf'] ? ' prefa-pdf-link' : '' ?>" data-name="<?= prefaEscape($doc['nom']) ?>" href="<?= prefaEscape($doc['url']) ?>"<?= $doc['pdf'] ? ' aria-haspopup="dialog"' : ' download' ?>>
                                                                <?= $trombone ?><span class="pv-document-nom"><?= prefaEscape($doc['nom']) ?></span>
                                                            </a>
                                                            <a class="mes-plans-telecharger" href="<?= prefaEscape($doc['url']) ?>" download aria-label="Télécharger <?= prefaEscape($doc['nom']) ?>" title="Télécharger"><?= $telecharger ?></a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </section>

                                        <?php
                                        // L'historique du plan, en frise comme celui des PV : les étapes
                                        // enregistrées et les avancements calculés, mêlés, le plus récent en
                                        // tête. Ils étaient repliés dans deux « Historique (…) » distincts.
                                        $frise = [];
                                        foreach ($historiqueEtapes as $saisie) {
                                            $statuts = json_decode($saisie['statuts'], true) ?: [];
                                            $faites = array_keys(array_filter($statuts, static fn ($v) => $v === 'OK'));
                                            $frise[] = [
                                                'genre' => 'etapes',
                                                'quand' => (string) $saisie['date_saisie'],
                                                'titre' => 'Étapes enregistrées',
                                                'texte' => implode(' · ', array_map(
                                                    static fn (string $cle, string $libelle): string => $libelle . ' ' . (($statuts[$cle] ?? '') ?: '—'),
                                                    array_keys(atelierSteps()), atelierSteps())),
                                                'qui' => (string) $saisie['auteur'],
                                                'note' => '',
                                            ];
                                        }
                                        foreach ($historiqueAvancement as $saisie) {
                                            $frise[] = [
                                                'genre' => in_array($saisie['etat'] ?? '', ['valide', 'refuse'], true) ? ($saisie['etat'] === 'valide' ? 'fait' : 'refus') : 'a_faire',
                                                'quand' => (string) $saisie['date_saisie'],
                                                'titre' => ($types[$saisie['type_affectation']] ?? '') . ' · ' . avancementPhrase($saisie),
                                                'texte' => avancementEtatPhrase($saisie),
                                                'qui' => (string) $saisie['auteur'],
                                                'note' => (string) ($saisie['commentaire'] ?? ''),
                                            ];
                                        }
                                        usort($frise, static fn ($a, $b) => strcmp($b['quand'], $a['quand']));
                                        ?>
                                        <section class="pv-volet-section mes-plans-section">
                                            <h3 class="pv-volet-titre mes-plans-titre">Historique</h3>
                                            <?php if ($frise): ?>
                                                <ol class="pv-historique">
                                                    <?php foreach ($frise as $evenement): ?>
                                                        <li class="pv-historique-<?= $evenement['genre'] ?>">
                                                            <span class="pv-historique-signe" aria-hidden="true">
                                                                <?php if ($evenement['genre'] === 'fait'): ?>
                                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                                                <?php elseif ($evenement['genre'] === 'etapes'): ?>
                                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" /></svg>
                                                                <?php else: ?>
                                                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8.5" stroke-dasharray="3 2.6" /></svg>
                                                                <?php endif; ?>
                                                            </span>
                                                            <div>
                                                                <strong><?= prefaEscape($evenement['titre']) ?></strong>
                                                                <p><?= prefaEscape($evenement['texte']) ?></p>
                                                                <?php if ($evenement['note'] !== ''): ?><p class="plan-historique-note"><?= prefaEscape($evenement['note']) ?></p><?php endif; ?>
                                                                <time datetime="<?= prefaEscape($evenement['quand']) ?>"><?= prefaEscape(atelierLocalDateTime($evenement['quand'])) ?> · <?= prefaEscape($evenement['qui']) ?></time>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <p class="pv-volet-note">Rien n’a encore été enregistré sur ce plan.</p>
                                            <?php endif; ?>
                                        </section>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                    <tbody class="mes-plans-aucun-resultat" hidden>
                        <tr><td class="prefa-table-empty" colspan="6">Aucun plan ne correspond à cette recherche.</td></tr>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <?php if ($anciennes): ?>
            <details class="mes-plans-historique mes-plans-anciennes">
                <summary>Mes saisies sur des plans qui ne me sont plus confiés (<?= count($anciennes) ?>)</summary>
                <ol>
                    <?php foreach ($anciennes as $saisie): ?>
                        <li>
                            <span><strong><?= prefaEscape($saisie['reference']) ?> · <?= prefaEscape($types[$saisie['type_affectation']] ?? '') ?> · <?= prefaEscape(avancementPhrase($saisie)) ?></strong> <span class="mes-plans-sur">· <?= prefaEscape(avancementEtatPhrase($saisie)) ?></span></span>
                            <span class="mes-plans-sur"><?= prefaEscape(prefaReferenceComplete((int) $saisie['id_demande'])) ?> · <?= prefaEscape(atelierLocalDateTime($saisie['date_saisie'])) ?></span>
                            <?php if ($saisie['commentaire']): ?><span class="mes-plans-note"><?= prefaEscape($saisie['commentaire']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>
    </div>
</section>
