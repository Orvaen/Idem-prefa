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
    $soudage = null;
    foreach ($plan['affectations'] as $affectation) {
        if ($soudage === null && $affectation['type_affectation'] === 'soudage') $soudage = $affectation;
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
        // L'avancement que les cordons font bouger : celui du suivi soudage. La première
        // affectation venue — rangées par type, c'était le Plan / ISO — restait à 0 % alors
        // que la validation avait bien écrit 75 % sur le suivi soudage.
        'avancement' => (int) ($soudage['avancement'] ?? 0),
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
            <?php // La barre de filtres de la DA : contrôles de 32px, libellé court à côté du
                  // champ. Les étiquettes flottantes de 44px coupaient l'invite de la recherche
                  // en deux (« Rechercher ıde, affaire… »). ?>
            <div class="prefa-filters suivi-soudures-filtres" role="search">
                <div class="filtres-recherche">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input id="suivi-soudures-recherche" type="search" aria-label="Rechercher"
                           placeholder="Demande, affaire, plan, soudeur…">
                </div>
                <div class="suivi-soudures-champ">
                    <label for="suivi-soudures-etat">État</label>
                    <span class="suivi-soudures-select">
                        <select id="suivi-soudures-etat">
                            <option value="">Tous</option>
                            <option value="a_valider">À valider</option>
                            <option value="en_cours">En cours</option>
                            <option value="complet">Toutes soudées</option>
                            <option value="a_faire">Pas commencé</option>
                        </select>
                        <svg class="suivi-soudures-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
                    </span>
                </div>
                <?php if (count($affaires) > 1): ?>
                    <div class="suivi-soudures-champ">
                        <label for="suivi-soudures-affaire">Affaire</label>
                        <span class="suivi-soudures-select">
                            <select id="suivi-soudures-affaire">
                                <option value="">Toutes</option>
                                <?php foreach ($affaires as $nom): ?><option value="<?= prefaEscape($nom) ?>"><?= prefaEscape($nom) ?></option><?php endforeach; ?>
                            </select>
                            <svg class="suivi-soudures-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
                        </span>
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
                    <th scope="col">Demande</th>
                    <th scope="col">Affaire</th>
                    <th scope="col">Plan</th>
                    <th scope="col">Atelier</th>
                    <th scope="col" class="prefa-nombre">Cordons</th>
                    <th scope="col" class="prefa-nombre">Avancement</th>
                    <th scope="col">État</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
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
                            <?php // Le numéro seul : l'affaire a sa colonne, juste à côté. ?>
                            <td class="suivi-soudures-reference"><?= prefaEscape(prefaReference((int) $ligne['id_demande'])) ?></td>
                            <td><?= prefaEscape((string) ($ligne['nom_affaire'] ?: '—')) ?></td>
                            <td><strong><?= prefaEscape($ligne['reference']) ?></strong><span class="atelier-row-description"><?= prefaEscape($ligne['libelle']) ?></span></td>
                            <td><?= $personnes ? prefaEscape(implode(', ', $personnes)) : '<span class="atelier-muted">Non affecté</span>' ?></td>
                            <?php // « lues sur 2 feuilles » sous chaque compte ne servait qu'à qui se
                                  // demande d'où sort le total : il passe dans l'infobulle. La barre
                                  // montre le compte d'un coup d'œil. ?>
                            <?php $partFaite = $ligne['prevues'] ? min(100, (int) round($ligne['faites'] * 100 / $ligne['prevues'])) : 0; ?>
                            <td class="prefa-nombre suivi-soudures-cordons"<?= $ligne['lues'] ? ' title="' . prefaEscape('Cordons prévus ' . $ligne['lues']) . '"' : '' ?>>
                                <span class="suivi-soudures-compte"><?= $ligne['faites'] ?><?= $ligne['prevues'] ? '<span> / ' . $ligne['prevues'] . '</span>' : '' ?></span>
                                <?php if ($ligne['prevues']): ?><span class="suivi-soudures-barre" aria-hidden="true"><span style="width: <?= $partFaite ?>%"></span></span><?php endif; ?>
                            </td>
                            <td class="prefa-nombre"><?= $ligne['avancement'] ?> %</td>
                            <td><span class="prefa-status <?= $etatClasse ?>"><?= $etatLibelle ?></span></td>
                            <?php // « Examiner », comme sur la liste des demandes : ce bouton n'est pas
                                  // celui qui valide, il ouvre le volet où l'on décide — le chiffre
                                  // déclaré d'un côté, les cordons relevés de l'autre, et c'est là que
                                  // se trouvent « Valider » et « Refuser ». Il s'est appelé « Valider »,
                                  // et promettait alors une action qu'il ne faisait pas : sur un plan
                                  // sans déclaration en attente, il ouvrait un volet pour y lire qu'il
                                  // n'y avait rien à valider. Le libellé ne change toujours pas d'une
                                  // ligne à l'autre — l'état de la ligne est dans sa colonne. ?>
                            <td><button type="button" class="prefa-toggle" aria-controls="suivi-soudures-detail-<?= $elementId ?>" aria-expanded="false">Examiner</button></td>
                        </tr>

                        <tr id="suivi-soudures-detail-<?= $elementId ?>" class="prefa-detail-row suivi-soudures-detail" hidden>
                            <td colspan="8">
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
                                                <span class="suivi-soudures-barre suivi-soudures-barre-large" aria-hidden="true"><span style="width: <?= min(100, max(0, $ligne['avancement'])) ?>%"></span></span>
                                                <strong><?= $ligne['avancement'] ?> %</strong>
                                            </dd></div>
                                            <div><dt>Cordons relevés</dt><dd><strong><?= $ligne['faites'] ?></strong><?= $ligne['prevues'] ? '<span class="suivi-soudures-sur"> sur ' . $ligne['prevues'] . ' prévus</span>' : '' ?></dd></div>
                                            <div><dt>Atelier</dt><dd><?= $personnes ? prefaEscape(implode(', ', $personnes)) : '<span class="suivi-soudures-sur">Non affecté</span>' ?></dd></div>
                                        </dl>

                                        <?php foreach ($ligne['attentes'] as $attente): ?>
                                            <div class="atelier-declaration">
                                                <p class="atelier-declaration-titre">À valider · déclaré par <?= prefaEscape($attente['auteur']) ?>, <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></p>
                                                <p class="atelier-declaration-chiffre"><?= prefaEscape(avancementPhrase($attente)) ?></p>
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
                                    </section>

                                    <section class="suivi-soudures-section atelier-soudures">
                                        <h3 class="suivi-soudures-titre">Cordons relevés</h3>

                                        <?php if (!$relevees): ?>
                                            <p class="suivi-soudures-vide">Aucun cordon relevé pour l’instant.</p>
                                        <?php else: ?>
                                            <?php // La note sur les contrôles « qui se cochent dans la fenêtre du
                                                  // plan » est partie : elle répondait à une question que personne ne
                                                  // pose ici. L'infobulle des colonnes la garde pour qui la cherche. ?>
                                            <table class="atelier-soudures-table">
                                                <thead><tr>
                                                    <th scope="col">N°</th><th scope="col">Feuille</th>
                                                    <th scope="col">Soudeur</th><th scope="col">Date</th>
                                                    <th scope="col">Type</th>
                                                    <th scope="col" class="soudure-controle" title="Radiographie — se coche dans la fenêtre du plan">RT</th>
                                                    <th scope="col" class="soudure-controle" title="Contrôle visuel — se coche dans la fenêtre du plan">VI</th>
                                                    <th scope="col" class="soudure-controle" title="Ressuage — se coche dans la fenêtre du plan">PT</th>
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
            </table>
        </div>
    </div>
</section>
