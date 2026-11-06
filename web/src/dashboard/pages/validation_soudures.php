<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste.php';
require_once __DIR__ . '/../includes/atelier/validation_soudures.php';

// « Validation des soudures » : tout ce que l'atelier a soudé et déclaré, et qui attend la
// décision de l'encadrement, à un seul endroit — demandé le 6 novembre 2026, « la validation
// de soudure dans un autre onglet ». La fiche d'une demande, « Plans & équipes » et
// « Soudures » n'en gardent qu'une ligne, qui mène ici, sur le bon plan
// (validationSouduresRenvoi()).
//
// Une file, pas une liste — refait le même jour : « je déteste l'interface ». C'était la
// grammaire de « Soudures » trait pour trait : un onglet seul qu'on ne pouvait pas changer, un
// tableau, et un « Valider » qui ne validait rien — il ouvrait une grande fenêtre où le plan se
// redisait deux fois, en titre puis en trois chiffres, avant d'arriver aux contrôles. Il fallait
// refermer, revenir à la liste et rouvrir le plan suivant, autant de fois qu'il y avait de plans.
//
// Maintenant : les plans à gauche, celui qu'on vise à droite — ses soudures tout de suite, et
// un seul « Valider 5 soudures », qui reste en vue. Validé, le plan quitte la file et le
// suivant s'ouvre (validation-soudures.js). Sous 851 px, une colonne à la fois.
if (!$canManageWorkshop) prefaError(403, 'Cet écran est réservé au chargé de production et au chef d’atelier.');

$plans = [];
foreach (soudureSuiviLignes($db) as $ligne) {
    $validation = validationSouduresPlan($db, $utilisateur, $ligne);
    if (!$validation['decisions']) continue;
    // Quand l'atelier l'a déclaré : la déclaration la plus récente du plan.
    $declareLe = null;
    foreach ($ligne['attentes'] as $attente) {
        if ($declareLe === null || $attente['date_saisie'] > $declareLe) $declareLe = $attente['date_saisie'];
    }
    // Une personne par nom, quel que soit le nombre de travaux qu'elle tient.
    $personnes = [];
    foreach ($ligne['affectations'] as $affectation) $personnes[(string) $affectation['personne']] = true;
    $plans[] = $ligne + ['validation' => $validation, 'declare_le' => $declareLe, 'personnes' => array_keys($personnes)];
}

// L'ordre d'une file : l'urgent d'abord, puis ce qui attend depuis le plus longtemps. Les
// soudures relevées sans déclaration, qui n'ont pas de date, ferment la marche.
usort($plans, static fn (array $a, array $b): int =>
    [empty($a['urgent']), $a['declare_le'] === null, (string) $a['declare_le']]
    <=> [empty($b['urgent']), $b['declare_le'] === null, (string) $b['declare_le']]);

$soudures = array_sum(array_map(static fn (array $plan): int => $plan['validation']['soudures'], $plans));
$urgence = '<span class="prefa-urgence"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
    . ' stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Demande urgente"><title>Demande urgente</title>'
    . '<path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" /></svg></span>';
?>
<?php // data-a-valider : la pastille du menu se remet au compte de l'écran après chaque
      // décision (atelier.js). ?>
<section class="page prefa-page prefa-list" id="validation-soudures-page" aria-labelledby="validation-soudures-titre"
         data-a-valider="<?= count($plans) ?>">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="validation-soudures-titre">Validation des soudures</h1>
            </div>
            <div class="prefa-vue-outils">
                <button type="button" class="atelier-refresh pv-outil">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36L21 8" /><path d="M21 3v5h-5" />
                    </svg>
                    <span class="outil-libelle">Actualiser</span>
                </button>
            </div>
        </header>

        <?php if (!$plans): ?>
            <div class="validation-vide" role="status">
                <p><strong>Rien à valider pour l’instant.</strong></p>
                <p>Les soudures que l’atelier déclare arrivent ici.</p>
            </div>
        <?php else: ?>
            <div class="prefa-filters" role="search">
                <?= listeRecherche('validation-recherche', 'Plan, demande, affaire, soudeur…', 'Rechercher un plan à valider') ?>
            </div>

            <?php // data-vue : sous 851 px, la colonne montrée — la file, ou le plan choisi. ?>
            <div class="validation-file" data-vue="liste">
                <div class="validation-liste">
                    <p class="validation-resume">
                        <strong><?= count($plans) ?></strong> <?= count($plans) === 1 ? 'plan' : 'plans' ?> ·
                        <strong><?= $soudures ?></strong> <?= $soudures === 1 ? 'soudure à viser' : 'soudures à viser' ?>
                    </p>
                    <?php // La file : un bouton par plan. Le choisir l'ouvre à droite ; les
                          // flèches passent d'un plan à l'autre (validation-soudures.js). ?>
                    <ul class="validation-plans" aria-label="Plans à valider">
                        <?php foreach ($plans as $rang => $plan):
                            $elementId = (int) $plan['id'];
                            $aValider = $plan['validation']['soudures'];
                            $meta = array_filter([
                                prefaReference((int) $plan['id_demande']),
                                $plan['personnes'] ? $plan['personnes'][0] . (count($plan['personnes']) > 1 ? ' +' . (count($plan['personnes']) - 1) : '') : 'Non affecté',
                                $plan['declare_le'] ? atelierLocalDateTime((string) $plan['declare_le'], 'd/m') : null,
                            ]);
                        ?>
                            <li data-cherche="<?= prefaEscape(mb_strtolower(prefaReferenceComplete((int) $plan['id_demande']) . ' ' . $plan['nom_affaire'] . ' ' . $plan['reference'] . ' ' . $plan['libelle'] . ' ' . implode(' ', $plan['personnes']))) ?>">
                                <button type="button" class="validation-plan" id="validation-plan-<?= $elementId ?>" data-plan="<?= $elementId ?>"
                                        aria-controls="validation-detail-<?= $elementId ?>" aria-current="<?= $rang === 0 ? 'true' : 'false' ?>">
                                    <span class="validation-plan-ligne">
                                        <span class="validation-plan-nom"><?= !empty($plan['urgent']) ? $urgence : '' ?><?= prefaEscape($plan['reference']) ?></span>
                                        <?php // Pas de « 0 » : un plan où personne ne peut valider —
                                              // aucun soudeur affecté — n'a rien à compter, il le dit à droite. ?>
                                        <?php if ($aValider > 0): ?>
                                            <span class="validation-plan-compte" title="<?= $aValider === 1 ? '1 soudure à valider' : $aValider . ' soudures à valider' ?>"><?= $aValider ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ((string) $plan['libelle'] !== ''): ?>
                                        <span class="validation-plan-libelle"><?= prefaEscape($plan['libelle']) ?></span>
                                    <?php endif; ?>
                                    <span class="validation-plan-meta"><?= prefaEscape(implode(' · ', $meta)) ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="validation-aucun" data-aucun hidden>Aucun plan ne correspond à cette recherche.</p>
                </div>

                <div class="validation-detail-zone">
                    <?php foreach ($plans as $rang => $plan):
                        $elementId = (int) $plan['id'];
                        $parcours = array_filter([
                            prefaReference((int) $plan['id_demande']),
                            (string) ($plan['nom_affaire'] ?? ''),
                            $plan['personnes'] ? 'Atelier : ' . implode(', ', $plan['personnes']) : 'Non affecté',
                        ], 'strlen');
                    ?>
                        <article class="validation-detail" id="validation-detail-<?= $elementId ?>" aria-labelledby="validation-detail-titre-<?= $elementId ?>"<?= $rang === 0 ? '' : ' hidden' ?>>
                            <header class="validation-detail-entete">
                                <?php // Sous 851 px seulement : le retour à la file. ?>
                                <button type="button" class="validation-retour" data-validation-retour>
                                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                                    Plans à valider
                                </button>
                                <h2 id="validation-detail-titre-<?= $elementId ?>" tabindex="-1">
                                    <?= !empty($plan['urgent']) ? $urgence : '' ?><?= prefaEscape($plan['reference']) ?>
                                    <?php if ((string) $plan['libelle'] !== ''): ?><span class="validation-detail-libelle"><?= prefaEscape($plan['libelle']) ?></span><?php endif; ?>
                                </h2>
                                <p class="validation-detail-parcours"><?= prefaEscape(implode(' · ', $parcours)) ?></p>
                            </header>
                            <?php foreach ($plan['validation']['decisions'] as $decision): ?>
                                <?= validationSouduresDecisionRendu($decision) ?>
                            <?php endforeach; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
