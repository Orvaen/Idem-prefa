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
                        // Le plan porte-t-il une liste de soudures ? Alors l'avancement se compte
                        // sur les cordons renseignés dans « Mes soudures », et ne se tape pas ici :
                        // deux endroits pour le même chiffre, c'est un chiffre de trop.
                        $prevu = avancementSouduresPrevues($db, $element);
                        $soudureTotal = $prevu['prevues'];
                        $soudureFaites = $soudureTotal ? avancementSouduresFaites($db, $element) : 0;
                        $etapes = atelierElementSteps($db, $element);
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
                                <td>
                                    <?php // Un travail, une pastille : son nom et où il en est. Le détail —
                                          // déclarations, étapes, fichiers — est dans le volet. ?>
                                    <span class="mes-plans-travaux">
                                        <?php foreach ($plan['travaux'] as $travail):
                                            [$etat] = $etatTravail((int) $travail['avancement']);
                                            $attente = $enAttente[(int) $travail['id']] ?? null; ?>
                                            <span class="atelier-work-status is-<?= $etat ?>"<?= $attente ? ' title="' . prefaEscape('Déclaré : ' . avancementPhrase($attente) . ', en attente de validation') . '"' : '' ?>><?= prefaEscape($types[$travail['type_affectation']]) ?> · <?= (int) $travail['avancement'] ?> %</span>
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
                                        <div class="prefa-detail-heading mes-plans-entete">
                                            <div class="mes-plans-identite">
                                                <p class="mes-plans-surtitre">Plan confié</p>
                                                <h2><?= prefaEscape($plan['reference']) ?><?php if ($plan['libelle'] !== ''): ?> <span><?= prefaEscape($plan['libelle']) ?></span><?php endif; ?></h2>
                                                <p class="mes-plans-parcours"><?= prefaEscape(prefaReference($plan['demande'])) ?><?= $plan['affaire'] !== '' ? ' · ' . prefaEscape($plan['affaire']) : '' ?></p>
                                            </div>
                                            <button type="button" class="prefa-detail-close" data-close-target="<?= $voletId ?>" aria-label="Fermer le volet" title="Fermer"><?= $fermer ?></button>
                                        </div>

                                        <?php // Ses travaux sur ce plan, comme les affectations du volet du chef :
                                              // le travail, son état, et de quoi le faire avancer. ?>
                                        <section class="mes-plans-section">
                                            <h3 class="mes-plans-titre">Mes travaux</h3>
                                            <dl class="mes-plans-proprietes">
                                                <?php foreach ($plan['travaux'] as $travail):
                                                    $pct = (int) $travail['avancement'];
                                                    [$etat, $etatLibelle] = $etatTravail($pct);
                                                    $attente = $enAttente[(int) $travail['id']] ?? null;
                                                    $libelle = $types[$travail['type_affectation']]; ?>
                                                    <div>
                                                        <dt><?= prefaEscape($libelle) ?></dt>
                                                        <dd>
                                                            <span class="atelier-work-status is-<?= $etat ?>"><?= $etatLibelle ?> · <?= $pct ?> %</span>
                                                            <?php if (!$soudureTotal): ?>
                                                                <button type="button" class="mes-plans-commande-ligne atelier-edit-progress"
                                                                        data-dialog="mes-plans-declaration"
                                                                        data-affectation="<?= (int) $travail['id'] ?>"
                                                                        data-revision="<?= (int) $travail['revision'] ?>"
                                                                        data-avancement="<?= $pct ?>"
                                                                        data-soudures="0" data-origine=""
                                                                        data-commentaire="<?= prefaEscape((string) ($travail['commentaire'] ?? '')) ?>"
                                                                        data-label="<?= prefaEscape($plan['reference'] . ' · ' . $libelle) ?>">Déclarer</button>
                                                            <?php endif; ?>
                                                        </dd>
                                                        <?php // Ce qu'il a déclaré et que le chef n'a pas tranché : la bande
                                                              // d'attente des volets, sous le chiffre reconnu. ?>
                                                        <?php if ($attente): ?>
                                                            <dd class="mes-plans-attente">Déclaré : <strong><?= prefaEscape(avancementPhrase($attente)) ?></strong> · en attente du chef d’atelier · <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></dd>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($soudureTotal): ?>
                                                    <div>
                                                        <dt>Cordons</dt>
                                                        <dd>
                                                            <span><strong><?= $soudureFaites ?></strong><span class="mes-plans-sur"> sur <?= $soudureTotal ?> · soit <?= avancementPourcentage($soudureFaites, $soudureTotal) ?> %</span></span>
                                                            <button type="button" class="mes-plans-commande-ligne atelier-vers-soudures">Renseigner mes soudures</button>
                                                        </dd>
                                                    </div>
                                                <?php endif; ?>
                                            </dl>
                                        </section>

                                        <?php // Les étapes du plan, partagées par tous ceux qui y travaillent. Un
                                              // état en boutons côte à côte, comme celui des PV : sept listes
                                              // déroulantes cachaient chacune leur valeur derrière un clic. ?>
                                        <section class="mes-plans-section">
                                            <h3 class="mes-plans-titre">Étapes du plan</h3>
                                            <p class="mes-plans-aide">Partagées avec les autres personnes affectées à ce plan.</p>
                                            <form class="atelier-action mes-plans-etapes" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                <input type="hidden" name="affectation" value="<?= $affectationEtapes ?>">
                                                <input type="hidden" name="operation" value="steps">
                                                <input type="hidden" name="etapes_revision" value="<?= $etapes['revision'] ?>">
                                                <dl class="mes-plans-proprietes">
                                                    <?php foreach (atelierSteps() as $cleEtape => $libelleEtape):
                                                        $valeur = $etapes['statuts'][$cleEtape] ?? ($cleEtape === 'autre' ? 'RAS' : '');
                                                        $nomGroupe = 'mes-plans-etape-' . $element . '-' . $cleEtape; ?>
                                                        <div>
                                                            <dt id="<?= $nomGroupe ?>"><?= prefaEscape($libelleEtape) ?></dt>
                                                            <dd>
                                                                <span class="mes-plans-segments" role="radiogroup" aria-labelledby="<?= $nomGroupe ?>">
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
                                                    <button type="submit" class="mes-plans-enregistrer">Enregistrer les étapes</button>
                                                </div>
                                            </form>
                                            <?php if ($historiqueEtapes): ?>
                                                <details class="mes-plans-historique">
                                                    <summary>Historique des étapes (<?= count($historiqueEtapes) ?>)</summary>
                                                    <ol>
                                                        <?php foreach ($historiqueEtapes as $saisie): $statuts = json_decode($saisie['statuts'], true) ?: []; ?>
                                                            <li>
                                                                <span class="mes-plans-sur"><?= prefaEscape(atelierLocalDateTime($saisie['date_saisie'])) ?> · <?= prefaEscape($saisie['auteur']) ?></span>
                                                                <span><?= prefaEscape(implode(' · ', array_map(
                                                                    static fn (string $cle, string $libelle): string => $libelle . ' ' . (($statuts[$cle] ?? '') ?: '—'),
                                                                    array_keys(atelierSteps()), atelierSteps()))) ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>
                                                </details>
                                            <?php endif; ?>
                                        </section>

                                        <?php // Les fichiers du plan, qu'on ouvre ou qu'on télécharge — comme les
                                              // feuilles du volet du chef, sans ce qui sert à les gérer. ?>
                                        <section class="mes-plans-section">
                                            <h3 class="mes-plans-titre">Fichiers du plan</h3>
                                            <?php if (!$documents): ?>
                                                <p class="mes-plans-aide">Pas encore de fichier. Le chef d’atelier les ajoute à ce plan.</p>
                                            <?php else: ?>
                                                <ul class="mes-plans-fichiers">
                                                    <?php foreach ($documents as $doc): ?>
                                                        <li>
                                                            <span class="mes-plans-fichier-nom"><?= prefaEscape($doc['nom']) ?></span>
                                                            <span class="mes-plans-fichier-commandes">
                                                                <?php if ($doc['pdf']): ?>
                                                                    <a class="mes-plans-commande-ligne prefa-pdf-link" data-name="<?= prefaEscape($doc['nom']) ?>" href="<?= prefaEscape($doc['url']) ?>">Ouvrir</a>
                                                                <?php endif; ?>
                                                                <a class="mes-plans-commande-ligne" href="<?= prefaEscape($doc['url']) ?>" download>Télécharger</a>
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </section>

                                        <?php if ($historiqueAvancement): ?>
                                            <section class="mes-plans-section">
                                                <details class="mes-plans-historique">
                                                    <summary>Historique de l’avancement (<?= count($historiqueAvancement) ?>)</summary>
                                                    <ol>
                                                        <?php foreach ($historiqueAvancement as $saisie): ?>
                                                            <li>
                                                                <span><strong><?= prefaEscape($types[$saisie['type_affectation']] ?? '') ?> · <?= prefaEscape(avancementPhrase($saisie)) ?></strong> <span class="mes-plans-sur">· <?= prefaEscape(avancementEtatPhrase($saisie)) ?></span></span>
                                                                <span class="mes-plans-sur"><?= prefaEscape(atelierLocalDateTime($saisie['date_saisie'])) ?> · <?= prefaEscape($saisie['auteur']) ?></span>
                                                                <?php if ($saisie['commentaire']): ?><span class="mes-plans-note"><?= prefaEscape($saisie['commentaire']) ?></span><?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>
                                                </details>
                                            </section>
                                        <?php endif; ?>
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

            <?php // Déclarer l'avancement d'un travail : la fenêtre de la maison, comme « Nouvel
                  // utilisateur ». Une seule pour l'écran ; le bouton du travail la remplit
                  // (atelier.js, « .atelier-edit-progress »). Le champ des soudures reste dans le
                  // balisage pour ce script, éteint : un plan qui porte une liste de soudures se
                  // déclare dans « Mes soudures », pas ici. ?>
            <dialog id="mes-plans-declaration" class="mes-plans-dialogue" aria-labelledby="mes-plans-declaration-titre">
                <form class="atelier-action" data-endpoint="update_atelier.php">
                    <div class="mes-plans-dialogue-tete">
                        <h2 id="mes-plans-declaration-titre">Déclarer l’avancement</h2>
                        <button type="button" class="atelier-close-plan-edit mes-plans-dialogue-fermer" aria-label="Fermer"><?= $fermer ?></button>
                    </div>
                    <div class="mes-plans-dialogue-corps">
                        <p class="atelier-progress-target mes-plans-dialogue-cible"></p>
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                        <input type="hidden" name="operation" value="progress">
                        <input type="hidden" name="affectation">
                        <input type="hidden" name="revision">
                        <div class="form-group atelier-champ-soudures" hidden>
                            <label for="mes-plans-declaration-soudures">Soudures faites</label>
                            <input id="mes-plans-declaration-soudures" name="soudures" type="number" min="0" step="1" disabled>
                            <span class="atelier-champ-aide"><span class="atelier-soudures-origine"></span> · <output class="atelier-quotient"></output></span>
                        </div>
                        <div class="form-group atelier-champ-pourcentage">
                            <label for="mes-plans-declaration-pourcentage">Avancement (%)</label>
                            <input id="mes-plans-declaration-pourcentage" name="avancement" type="number" min="0" max="100" step="1" required>
                        </div>
                        <div class="form-group">
                            <label for="mes-plans-declaration-note">Compte rendu <span class="mes-plans-facultatif">facultatif</span></label>
                            <textarea id="mes-plans-declaration-note" name="commentaire" maxlength="2000" rows="3"></textarea>
                        </div>
                        <p class="mes-plans-dialogue-aide">Le chef d’atelier valide avant que le pourcentage change.</p>
                        <span class="atelier-message" role="status" aria-live="polite"></span>
                    </div>
                    <div class="mes-plans-dialogue-pied">
                        <button type="button" class="prefa-ligne-contour atelier-close-plan-edit">Annuler</button>
                        <button type="submit" class="mes-plans-enregistrer">Déclarer</button>
                    </div>
                </form>
            </dialog>
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
