<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier.php';
require_once __DIR__ . '/../includes/avancement.php';
require_once __DIR__ . '/../includes/soudure_atelier.php';
require_once __DIR__ . '/../includes/nomenclature.php';
require_once __DIR__ . '/../includes/plan_viewer.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

// Une entrée par page d'isométrique : c'est la question que se pose le soudeur — sur
// quelle feuille suis-je ? — et non « quel fichier, puis quelle page ».
$feuilles = soudureAtelierFeuilles($db, (int) $actor['id']);

$elements = [];
foreach ($feuilles as $feuille) $elements[$feuille['element']] ??= $feuille;

// Les affectations en cours : c'est d'ici que la validation se demande, parce que c'est
// ici que le soudeur a sous les yeux ce qu'il a fait.
$affectations = $db->prepare('SELECT a.id, a.id_element, a.revision, a.avancement, a.type_affectation,
        e.reference, e.id_demande
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY e.id_demande DESC, e.reference, a.type_affectation');
$affectations->execute([$actor['id']]);
$mesAffectations = $affectations->fetchAll();
$travaux = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
?>
<datalist id="soudure-procedes">
    <option value="TIG"></option><option value="MIG"></option><option value="MAG"></option>
    <option value="111"></option><option value="135"></option><option value="141"></option>
</datalist>

<section class="page prefa-page soudures" id="soudures-page" aria-labelledby="soudures-page-title">
    <?php // « page » est un conteneur flex : sans cette coque, chaque section de l'écran
          // devenait une colonne posée à côté des autres. C'est la coque maison, celle de
          // tous les écrans de préfabrication. ?>
    <div class="prefa-shell soudures-shell">
    <header class="soudures-entete">
        <div class="soudures-identite">
            <p class="soudures-surtitre">Atelier</p>
            <h1 class="soudures-titre" id="soudures-page-title">Mes soudures</h1>
            <p class="soudures-sous-titre">Renseignez vos cordons sur la feuille que vous travaillez.</p>
        </div>
        <?php if ($feuilles): ?>
            <div class="soudures-choix">
                <label for="soudures-feuille">Feuille travaillée</label>
                <div class="soudures-choix-ligne">
                    <?php // Le repère voyage dans la valeur : élément, feuille, page. Un seul
                          // contrôle, parce que c'est un seul choix. ?>
                    <select id="soudures-feuille">
                        <?php foreach ($feuilles as $index => $feuille): ?>
                            <option value="<?= $feuille['element'] ?>:<?= $feuille['document'] ?>:<?= $feuille['page'] ?>" <?= $index === 0 ? 'selected' : '' ?>>
                                <?= prefaEscape($feuille['reference']) ?> · <?= prefaEscape($feuille['nom']) ?><?= $feuille['pages'] > 1 ? ' — p. ' . $feuille['page'] : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="soudures-ouvrir-plan" title="Voir le dessin pour retrouver le repère d’un cordon">Ouvrir la feuille</button>
                </div>
            </div>
        <?php endif; ?>
    </header>

    <?php if (!$feuilles): ?>
        <p class="soudures-rien">Aucun plan ne vous est affecté, ou aucune feuille ISO n’y est rattachée.</p>
    <?php else: ?>
        <?php // Les trois chiffres de l'écran. Le premier répond à « où en suis-je sur cette
              // feuille », le deuxième à « et sur le plan entier », le troisième à « qu'est-ce
              // qui est reconnu ». C'est la seule ligne où la taille porte le sens. ?>
        <dl class="soudures-chiffres">
            <div>
                <dt>Sur cette feuille</dt>
                <dd><span class="soudures-nombre" data-chiffre="feuille">—</span></dd>
                <p class="soudures-chiffre-note" data-note="feuille">Lecture en cours…</p>
            </div>
            <div>
                <dt>Sur tout le plan</dt>
                <dd><span class="soudures-nombre" data-chiffre="plan">—</span></dd>
                <p class="soudures-chiffre-note" data-note="plan"></p>
            </div>
            <div>
                <dt>Avancement validé</dt>
                <dd><span class="soudures-nombre" data-chiffre="valide">—</span></dd>
                <p class="soudures-chiffre-note" data-note="valide">par le chef d’atelier</p>
            </div>
        </dl>

        <div class="soudures-jauge" role="presentation"><span data-jauge></span></div>

        <form id="soudures-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
            <div class="soudures-liste-entete" aria-hidden="true">
                <span>N°</span><span>Date</span><span>Type de soudure</span>
                <span>Soudeur</span><span>Contrôles</span>
            </div>
            <ol class="soudures-liste" id="soudures-corps">
                <li class="soudures-attente">Lecture de la feuille…</li>
            </ol>

            <div class="soudures-pied">
                <p class="soudures-compte" role="status" aria-live="polite"></p>
                <div class="soudures-actions">
                    <span class="atelier-message" role="status" aria-live="polite"></span>
                    <button type="submit">Enregistrer</button>
                </div>
            </div>
        </form>

        <?php // Le moule d'une ligne : le script le clone plutôt que d'assembler du HTML dans
              // des chaînes, où une apostrophe de nom suffit à tout casser. ?>
        <template id="soudures-modele">
            <li class="soudures-ligne">
                <span class="soudures-numero"></span>
                <span class="soudures-champ">
                    <input type="date" class="soudures-date" aria-label="Date de soudage">
                </span>
                <span class="soudures-champ">
                    <input type="text" class="soudures-type" maxlength="30" list="soudure-procedes" placeholder="TIG…" aria-label="Type de soudure">
                </span>
                <span class="soudures-soudeur"></span>
                <span class="soudures-controles"></span>
            </li>
        </template>

        <?php if ($mesAffectations): ?>
            <section class="soudures-validation" aria-labelledby="soudures-validation-titre">
                <p class="soudures-eyebrow" id="soudures-validation-titre">Demander la validation</p>
                <p class="soudures-sous-titre">Le chef d’atelier valide, et c’est sa validation qui déplace le pourcentage.</p>

                <?php foreach ($mesAffectations as $affectation):
                    $id = (int) $affectation['id'];
                    $elementId = (int) $affectation['id_element'];
                    $prevu = avancementSouduresPrevues($db, $elementId);
                    $total = $prevu['prevues'];
                    $faites = avancementSouduresFaites($db, $elementId);
                    $attente = avancementEnAttente($db, $id);
                ?>
                    <article class="soudures-travail">
                        <div class="soudures-travail-identite">
                            <strong><?= prefaEscape($affectation['reference']) ?></strong>
                            <span><?= prefaEscape(prefaReferenceComplete((int) $affectation['id_demande'])) ?> · <?= prefaEscape($travaux[$affectation['type_affectation']]) ?></span>
                        </div>

                        <?php if ($attente): ?>
                            <p class="soudures-en-attente">
                                <strong><?= prefaEscape(avancementPhrase($attente)) ?></strong>
                                en attente du chef d’atelier
                                <span>· déclaré le <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></span>
                            </p>
                        <?php elseif ($total > 0): ?>
                            <form class="atelier-action soudures-demande" data-endpoint="update_atelier.php">
                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                <input type="hidden" name="affectation" value="<?= $id ?>">
                                <input type="hidden" name="operation" value="progress">
                                <input type="hidden" name="revision" value="<?= (int) $affectation['revision'] ?>">
                                <?php // Le nombre n'est pas saisi : il est compté sur les cordons
                                      // renseignés. C'est tout l'intérêt de remplir la liste. ?>
                                <input type="hidden" name="soudures" value="<?= $faites ?>">
                                <p class="soudures-travail-compte">
                                    <strong><?= $faites ?> / <?= $total ?></strong>
                                    <span>soudure<?= $total > 1 ? 's' : '' ?> prévue<?= $total > 1 ? 's' : '' ?> au plan<?php if ($lues = avancementFeuillesPhrase($prevu)): ?> · <?= prefaEscape($lues) ?><?php endif; ?> · soit <?= avancementPourcentage($faites, $total) ?> %</span>
                                </p>
                                <button type="submit">Demander la validation</button>
                                <span class="atelier-message" role="status" aria-live="polite"></span>
                            </form>
                        <?php else: ?>
                            <p class="soudures-travail-compte"><span>Aucune liste de soudures n’a encore été lue sur ce plan.</span></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php
        // Le lecteur de plan, une fenêtre par élément : on regarde le dessin pour savoir
        // quel cordon porte le repère 3. Lecture seule — rien ne se pose dessus d'ici.
        foreach ($elements as $elementId => $feuille):
            $docs = planDocuments($db, $elementId);
            if (!$docs) continue;
        ?>
            <div class="soudures-lecteur" data-element="<?= (int) $elementId ?>" hidden>
                <?= planViewerRendu($elementId, array_map(static function (array $doc) use ($db, $elementId): array {
                    $reperes = [];
                    for ($page = 1; $page <= max(1, (int) $doc['pages']); $page++) {
                        $reperes[$page] = nomenclatureLignes($db, $elementId, $doc['id'], $page);
                    }
                    return $doc + ['reperes' => $reperes, 'empreintes' => []];
                }, $docs), [], false, false) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</section>
