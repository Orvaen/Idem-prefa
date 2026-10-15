<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
require_once __DIR__ . '/../includes/atelier/soudure.php';

// L'écran de l'encadrement : il regarde l'atelier entier d'un coup. L'atelier, lui, a
// « Mes soudures » et n'y voit que ses propres plans.
if (!$canManageWorkshop) prefaError(403, 'Cet écran est réservé au chargé de production et au chef d’atelier.');

$plans = soudureSuiviPlans($db);

// Les déclarations en attente, toutes affectations confondues : une seule lecture.
$idsAffectations = [];
foreach ($plans as $plan) foreach ($plan['affectations'] as $affectation) $idsAffectations[] = (int) $affectation['id'];
$attentes = avancementEnAttentePlusieurs($db, $idsAffectations);

// Ce que chaque plan porte vraiment. Un plan sans cordon ni liste reconnue n'a rien à
// faire ici : c'est un écran sur les soudures, pas sur les plans.
$lignes = [];
$affaires = [];
$aValider = 0;
foreach ($plans as $plan) {
    $elementId = (int) $plan['id'];
    $prevu = avancementSouduresPrevues($db, $elementId);
    $faites = avancementSouduresFaites($db, $elementId);
    if ($faites === 0 && $prevu['prevues'] === 0) continue;

    $enAttente = [];
    foreach ($plan['affectations'] as $affectation) {
        if (isset($attentes[(int) $affectation['id']])) {
            $enAttente[] = $attentes[(int) $affectation['id']] + ['affectation' => $affectation];
        }
    }
    $aValider += count($enAttente);
    if ($plan['nom_affaire']) $affaires[$plan['nom_affaire']] = $plan['nom_affaire'];

    $lignes[] = $plan + [
        'prevues' => $prevu['prevues'],
        'lues' => avancementFeuillesPhrase($prevu),
        'faites' => $faites,
        'attentes' => $enAttente,
        'avancement' => (int) ($plan['affectations'][0]['avancement'] ?? 0),
    ];
}
ksort($affaires);

// L'état d'un plan : sa clé pour le filtre, son libellé, et la couleur que la maison
// donne déjà à cet état-là. La pastille est un point coloré et du texte — pas de bordure,
// pas de fond : c'est la forme de « prefa-status », partout ailleurs dans l'application.
$etatDuPlan = static function (array $ligne): array {
    if ($ligne['attentes']) return ['a_valider', 'À valider', 'status-1'];
    if ($ligne['prevues'] > 0 && $ligne['faites'] >= $ligne['prevues']) return ['complet', 'Toutes soudées', 'status-2'];
    if ($ligne['faites'] > 0) return ['en_cours', 'En cours', 'atelier-status-progress'];
    return ['a_faire', 'Pas commencé', 'atelier-muted'];
};
?>
<section class="page prefa-page prefa-list atelier-page" id="suivi-soudures-page" aria-labelledby="suivi-soudures-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="suivi-soudures-title">Soudures</h1>
                <p>Les cordons relevés par l’atelier, et les avancements qu’il vous demande de valider.</p>
            </div>
        </header>

        <?php if ($lignes): ?>
            <div class="prefa-filters" role="search">
                <div class="floating-field">
                    <label for="suivi-soudures-recherche">Rechercher</label>
                    <input id="suivi-soudures-recherche" class="floating-control" type="search"
                           placeholder="N° de demande, affaire, plan ou soudeur">
                </div>
                <div class="floating-field floating-always">
                    <label for="suivi-soudures-etat">État</label>
                    <select id="suivi-soudures-etat" class="floating-control">
                        <option value="">Tous les plans</option>
                        <option value="a_valider">À valider</option>
                        <option value="en_cours">En cours</option>
                        <option value="complet">Toutes soudées</option>
                        <option value="a_faire">Pas commencé</option>
                    </select>
                </div>
                <?php if (count($affaires) > 1): ?>
                    <div class="floating-field floating-always">
                        <label for="suivi-soudures-affaire">Affaire</label>
                        <select id="suivi-soudures-affaire" class="floating-control">
                            <option value="">Toutes les affaires</option>
                            <?php foreach ($affaires as $nom): ?><option value="<?= prefaEscape($nom) ?>"><?= prefaEscape($nom) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="prefa-list-summary">
            <strong id="suivi-soudures-compte"><?= count($lignes) ?></strong>
            <span><?= count($lignes) === 1 ? 'plan' : 'plans' ?></span>
            <?= $aValider ? ' · ' . $aValider . ($aValider === 1 ? ' avancement à valider' : ' avancements à valider') : '' ?>
        </p>

        <div class="prefa-table-scroll" role="region" aria-label="Soudures par plan" tabindex="0">
            <table class="prefa-table suivi-soudures-table">
                <thead><tr>
                    <th scope="col">Demande n°</th>
                    <th scope="col">Affaire</th>
                    <th scope="col">Plan</th>
                    <th scope="col">Atelier</th>
                    <th scope="col">Cordons</th>
                    <th scope="col">Avancement</th>
                    <th scope="col">État</th>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$lignes): ?>
                    <tbody><tr><td colspan="8" class="prefa-table-empty">Aucun plan ne porte encore de liste de soudures. Elle est reconnue sur l’isométrique quand une feuille est ouverte.</td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($lignes as $ligne):
                    $elementId = (int) $ligne['id'];
                    [$etat, $etatLibelle, $etatClasse] = $etatDuPlan($ligne);
                    $personnes = array_values(array_unique(array_column($ligne['affectations'], 'personne')));
                    $relevees = soudureRelevees($db, $elementId);
                ?>
                    <tbody class="suivi-soudures-plan" data-etat="<?= $etat ?>"
                           data-affaire="<?= prefaEscape((string) $ligne['nom_affaire']) ?>"
                           data-cherche="<?= prefaEscape(mb_strtolower(prefaReferenceComplete((int) $ligne['id_demande']) . ' ' . $ligne['nom_affaire'] . ' ' . $ligne['reference'] . ' ' . $ligne['libelle'] . ' ' . implode(' ', $personnes))) ?>">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $ligne['id_demande'])) ?></td>
                            <td><?= prefaEscape((string) ($ligne['nom_affaire'] ?: '—')) ?></td>
                            <td><strong><?= prefaEscape($ligne['reference']) ?></strong><span class="atelier-row-description"><?= prefaEscape($ligne['libelle']) ?></span></td>
                            <td><?= $personnes ? prefaEscape(implode(', ', $personnes)) : '<span class="atelier-muted">Non affecté</span>' ?></td>
                            <td class="suivi-soudures-cordons">
                                <strong><?= $ligne['faites'] ?><?= $ligne['prevues'] ? ' / ' . $ligne['prevues'] : '' ?></strong>
                                <?php if ($ligne['lues']): ?><span class="atelier-row-description"><?= prefaEscape($ligne['lues']) ?></span><?php endif; ?>
                            </td>
                            <td><?= $ligne['avancement'] ?> %</td>
                            <td><span class="prefa-status <?= $etatClasse ?>"><?= $etatLibelle ?></span></td>
                            <?php // « Valider » et rien d'autre : c'est ce qu'on vient faire ici, et un
                                  // libellé qui change d'une ligne à l'autre oblige à lire chaque bouton
                                  // avant de cliquer. Le volet montre les cordons par la même occasion. ?>
                            <td><button type="button" class="prefa-toggle" aria-controls="suivi-soudures-detail-<?= $elementId ?>" aria-expanded="false">Valider</button></td>
                        </tr>

                        <tr id="suivi-soudures-detail-<?= $elementId ?>" class="prefa-detail-row" hidden>
                            <td colspan="8">
                                <div class="atelier-detail-content">
                                    <?php // Le volet s'ouvre en pleine page : sans cette croix, le bouton
                                          // qui l'a ouvert passe derrière lui et on ne peut plus sortir. ?>
                                    <div class="prefa-detail-heading">
                                        <div>
                                            <h2><?= prefaEscape($ligne['reference']) ?> <span><?= prefaEscape(prefaReferenceComplete((int) $ligne['id_demande'])) ?></span></h2>
                                            <p><?= prefaEscape($ligne['libelle']) ?><?= $ligne['nom_affaire'] ? ' · ' . prefaEscape($ligne['nom_affaire']) : '' ?></p>
                                        </div>
                                        <button type="button" class="prefa-detail-close" data-close-target="suivi-soudures-detail-<?= $elementId ?>" aria-label="Fermer le volet">✕</button>
                                    </div>

                                    <?php if (!$ligne['attentes']): ?>
                                        <p class="atelier-muted">Aucun avancement n’est en attente : l’atelier n’a rien déclaré depuis la dernière validation.</p>
                                    <?php endif; ?>

                                    <?php foreach ($ligne['attentes'] as $attente): ?>
                                        <?php // Le chiffre annoncé se tient à part du chiffre reconnu : c'est
                                              // tout l'objet de la validation. Même forme que sur l'écran des
                                              // plans, pour qu'on reconnaisse l'objet d'un coup d'œil. ?>
                                        <div class="atelier-declaration">
                                            <p class="atelier-declaration-titre">Avancement déclaré, à valider</p>
                                            <p class="atelier-declaration-chiffre"><?= prefaEscape(avancementPhrase($attente)) ?></p>
                                            <p class="atelier-muted"><?= prefaEscape($attente['auteur']) ?> · <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></p>
                                            <?php if ($attente['commentaire']): ?><p class="atelier-note"><?= prefaEscape($attente['commentaire']) ?></p><?php endif; ?>
                                            <form class="atelier-action atelier-declaration-actions" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $ligne['id_demande'] ?>">
                                                <input type="hidden" name="operation" value="valider">
                                                <input type="hidden" name="affectation" value="<?= (int) $attente['affectation']['id'] ?>">
                                                <input type="hidden" name="declaration" value="<?= (int) $attente['id'] ?>">
                                                <button type="submit" name="decision" value="valider" title="L’avancement du plan prendra ce chiffre.">Valider</button>
                                                <button type="submit" name="decision" value="refuser" class="bouton-discret" title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>
                                                <span class="atelier-message" role="status" aria-live="polite"></span>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>

                                    <section class="atelier-soudures">
                                        <div class="atelier-soudures-entete">
                                            <h4 class="atelier-plan-titre-section">Cordons relevés</h4>
                                            <p class="atelier-soudures-compte">
                                                <strong><?= $ligne['faites'] ?><?= $ligne['prevues'] ? ' / ' . $ligne['prevues'] : '' ?></strong>
                                                <span>renseignés<?= $ligne['prevues'] ? ' sur ' . $ligne['prevues'] . ' prévus au plan' : '' ?><?= $ligne['lues'] ? ' · ' . prefaEscape($ligne['lues']) : '' ?></span>
                                            </p>
                                        </div>

                                        <?php if (!$relevees): ?>
                                            <p class="atelier-muted">Aucun cordon n’a encore été relevé. Le soudeur les renseigne depuis « Mes soudures ».</p>
                                        <?php else: ?>
                                            <table class="atelier-soudures-table">
                                                <thead><tr>
                                                    <th scope="col">N°</th><th scope="col">Feuille</th>
                                                    <th scope="col">Soudeur</th><th scope="col">Date</th>
                                                    <th scope="col">Type</th>
                                                    <th scope="col" class="soudure-controle">RT</th>
                                                    <th scope="col" class="soudure-controle">VI</th>
                                                    <th scope="col" class="soudure-controle">PT</th>
                                                    <th scope="col">Visa</th>
                                                </tr></thead>
                                                <tbody>
                                                    <?php foreach ($relevees as $cordon):
                                                        $feuilleNom = preg_replace('/^[a-f0-9]{32}-/', '', (string) ($cordon['cle_document'] ?? ''));
                                                        $feuilleNom = preg_replace('/\.pdf$/i', '', $feuilleNom);
                                                    ?>
                                                        <tr>
                                                            <td><?= prefaEscape((string) $cordon['numero']) ?></td>
                                                            <td><?= prefaEscape($feuilleNom ?: '—') ?><?= (int) ($cordon['pages'] ?? 1) > 1 ? ' — p. ' . (int) $cordon['page'] : '' ?></td>
                                                            <td><?= prefaEscape((string) ($cordon['soudeur_nom'] ?: '—')) ?></td>
                                                            <td><?= $cordon['date_soudure'] ? prefaEscape(date('d/m/Y', strtotime((string) $cordon['date_soudure']))) : '—' ?></td>
                                                            <td><?= prefaEscape((string) ($cordon['type_soudure'] ?: '—')) ?></td>
                                                            <td class="soudure-controle"><?= trim((string) $cordon['rt']) === '' ? '' : SOUDURE_CROIX ?></td>
                                                            <td class="soudure-controle"><?= trim((string) $cordon['vi']) === '' ? '' : SOUDURE_CROIX ?></td>
                                                            <td class="soudure-controle"><?= trim((string) $cordon['pt']) === '' ? '' : SOUDURE_CROIX ?></td>
                                                            <td><?= prefaEscape((string) ($cordon['visa_nom'] ?: '—')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <p class="atelier-muted atelier-soudures-note">Les contrôles RT, VI et PT se cochent dans la fenêtre du plan, feuille par feuille — c’est là qu’on a le dessin sous les yeux.</p>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
