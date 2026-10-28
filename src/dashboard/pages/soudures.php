<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';
require_once __DIR__ . '/../includes/vue/plan_viewer.php';
require_once __DIR__ . '/../includes/vue/filtres.php';
require_once __DIR__ . '/../includes/vue/manques.php';
require_once __DIR__ . '/../includes/vue/liste_filtrable.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

// Comment une feuille se nomme : par son numéro de ligne, celui qui est imprimé sur le
// papier que le soudeur a en main. Le nom du fichier et sa page ne servent que de secours,
// pour un cahier déposé avant qu'on relève les numéros. C'est la règle du sommaire de la
// fenêtre du plan, et deux noms pour une même feuille se paieraient à chaque recherche.
$nomFeuille = static fn (array $feuille): string => $feuille['numero'] !== ''
    ? $feuille['numero']
    : $feuille['nom'] . ($feuille['pages'] > 1 ? ' — p. ' . $feuille['page'] : '');

// Le repère voyage d'un seul morceau — élément, feuille, page — sur la ligne comme sur ses
// boutons : c'est ce qu'il faut pour lire le tableau soudure et pour ouvrir le dessin.
$repereFeuille = static fn (array $feuille): string =>
    $feuille['element'] . ':' . $feuille['document'] . ':' . $feuille['page'];

// Les plans confiés, et c'est l'affectation qui fait foi : un plan paraît même si aucune
// feuille ISO n'y est encore rattachée, parce que le travail, lui, est bien là. Une seule
// requête, sans rien lire des PDF : il peut y en avoir des centaines.
$plans = [];
foreach (soudureAtelierPlans($db, (int) $actor['id']) as $plan) $plans[$plan['element']] = $plan;

// Le plan ouvert : celui qu'on demande, sinon le dernier ouvert, sinon le premier qui a des
// feuilles. Il est retenu dans la session : enregistrer une feuille ou déposer un dessin
// recharge l'écran, et il doit revenir sur le même plan.
$demande = filter_var($_GET['plan'] ?? null, FILTER_VALIDATE_INT);
$retenu = (int) ($_SESSION['soudures_plan'] ?? 0);
$planOuvert = match (true) {
    $demande !== false && $demande !== null && isset($plans[$demande]) => $demande,
    isset($plans[$retenu]) => $retenu,
    default => array_key_first(array_filter($plans, static fn (array $p): bool => $p['feuilles'] > 0))
        ?? array_key_first($plans),
};
if ($planOuvert !== null) $_SESSION['soudures_plan'] = $planOuvert;

// Les feuilles du seul plan ouvert : c'est leur lecture qui coûte (l'analyse de chaque page).
$ouvert = $planOuvert !== null ? $plans[$planOuvert] + ['feuilles_liste' => soudureAtelierFeuilles($db, (int) $actor['id'], $planOuvert)] : null;

// Le premier isométrique d'un plan, pour le bouton qui montre le dessin : on ouvre le
// cahier à sa première page, c'est de là qu'on se repère.
$premiereFeuille = static fn (array $plan): ?array => $plan['feuilles_liste'][0] ?? null;

// Le matériel : ce que la personne a signalé, et les articles des bons de commande de
// chacun de ses plans, à proposer quand elle en signale un autre.
$manques = manquesDuSoudeur($db, (int) $actor['id']);
$articlesParPlan = [];
$articlesParDemande = [];
foreach ($plans as $plan) {
    $articlesParDemande[$plan['demande']] ??= manquesArticlesProposes($db, $plan['demande']);
    $articlesParPlan[$plan['element']] = $articlesParDemande[$plan['demande']];
}
$libelleFeuilles = static fn (int $n): string => $n === 0 ? 'en attente du dessin' : $n . ($n > 1 ? ' feuilles' : ' feuille');
?>
<datalist id="soudure-procedes">
    <option value="TIG"></option><option value="MIG"></option><option value="MAG"></option>
    <option value="111"></option><option value="135"></option><option value="141"></option>
</datalist>

<?php // La coque, l'en-tête, la barre, le tableau et le volet flottant : ceux des autres
      // écrans — « Mes plans », « Soudures » du chef, « Commande matériel ». L'écran avait
      // ses deux colonnes à lui, une carte fixe à droite qui coupait la liste en deux, et
      // deux boutons « Voir le dessin » à vingt pixels l'un de l'autre. ?>
<section class="page prefa-page prefa-list atelier-page soudures" id="soudures-page" aria-labelledby="soudures-page-title">
    <div class="prefa-shell">
    <header class="prefa-heading prefa-list-heading">
        <div>
            <h1 id="soudures-page-title">Mes soudures &amp; Suivi matériel</h1>
            <p>Les feuilles de vos plans, et le matériel qui vous manque.</p>
        </div>
        <?php // L'action de l'écran, au même endroit que « Nouvelle demande » sur la liste
              // des demandes : un manque se signale d'où qu'on soit, sans ouvrir de feuille. ?>
        <?php if ($plans): ?>
            <button type="button" class="prefa-nouvelle-demande" data-signaler-manque>
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.01" /><path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.4h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                Signaler un manque
            </button>
        <?php endif; ?>
    </header>

    <?php if (!$plans): ?>
        <p class="prefa-table-empty soudures-rien">Aucun plan ne vous est affecté pour le moment.</p>
    <?php else:
        // Le seul plan ouvert : ses feuilles sont lues ici, les autres ne le sont pas.
        $plan = $ouvert;
        $plan['feuilles'] = $ouvert['feuilles_liste'];
        $demande = prefaReferenceComplete($plan['demande']);
        $premiere = $premiereFeuille($ouvert);
    ?>
        <?php // Une seule barre, celle des autres listes : le plan, la recherche, l'état, et
              // le dessin à droite. Le plan était un bloc à part, au-dessus d'un filet, avec
              // son étiquette « Plan » et son compte : trois lignes avant la première feuille. ?>
        <form class="prefa-filters soudures-filtres" role="search">
            <div class="soudures-choix-plan" data-choix-plan>
                <?php // Le plan, choisi dans une liste à recherche : un soudeur peut en avoir des
                      // centaines. Le bouton a la forme des pastilles de filtre de la barre. ?>
                <button type="button" class="soudures-plan-declencheur" aria-haspopup="listbox" aria-expanded="false"
                        aria-label="Plan : <?= prefaEscape($ouvert['reference']) ?>, <?= count($plans) ?> plan<?= count($plans) > 1 ? 's' : '' ?>">
                    <span class="soudures-plan-libelle">Plan</span>
                    <strong id="soudures-plan-courant"><?= prefaEscape($ouvert['reference']) ?></strong>
                    <span class="soudures-plan-demande"><?= prefaEscape(prefaReference($ouvert['demande'])) ?></span>
                    <?php if (count($plans) > 1): ?><span class="filtre-compte"><?= count($plans) ?></span><?php endif; ?>
                    <svg class="soudures-plan-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
                </button>

                <div class="soudures-plan-menu" hidden>
                    <div class="soudures-plan-recherche">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input type="search" autocomplete="off" placeholder="Plan, demande, affaire…" aria-label="Rechercher un plan">
                    </div>
                    <ul class="soudures-plan-options" role="listbox" aria-label="Mes plans">
                        <?php foreach ($plans as $choix): ?>
                            <li role="option" class="soudures-plan-option<?= $choix['feuilles'] === 0 ? ' est-sans-dessin' : '' ?>"
                                data-element="<?= (int) $choix['element'] ?>"
                                data-cherche="<?= prefaEscape(mb_strtolower($choix['reference'] . ' ' . prefaReference($choix['demande']) . ' ' . $choix['affaire'])) ?>"
                                aria-selected="<?= $choix['element'] === $planOuvert ? 'true' : 'false' ?>" tabindex="-1">
                                <span class="soudures-plan-option-nom"><?= prefaEscape($choix['reference']) ?></span>
                                <span class="soudures-plan-option-demande"><?= prefaEscape(prefaReference($choix['demande'])) ?><?= $choix['affaire'] !== '' ? ' · ' . prefaEscape($choix['affaire']) : '' ?></span>
                                <span class="soudures-plan-option-compte"><?= $libelleFeuilles($choix['feuilles']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="soudures-plan-mot" role="status" hidden></p>
                </div>
            </div>

            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="soudures-recherche" type="search" autocomplete="off"
                       aria-label="Rechercher une feuille" placeholder="N° de ligne…">
            </div>
            <div class="filtres-barre">
                <?= filtreRendu('etat', 'État', [
                    ['id' => 'a_faire', 'libelle' => 'À faire'],
                    ['id' => 'en_cours', 'libelle' => 'En cours'],
                    ['id' => 'soudee', 'libelle' => 'Soudées'],
                    ['id' => 'non_lue', 'libelle' => 'Pas encore ouvertes'],
                ], []) ?>
                <button type="button" class="filtres-tout-effacer" hidden>Tout effacer</button>
            </div>
            <?php // Le dessin du plan, à droite de la barre : la seule commande qui ne filtre
                  // pas. Sans dessin, rien à montrer. ?>
            <?php if ($premiere): ?>
                <button type="button" class="soudures-ouvrir-plan soudures-voir-dessin"
                        data-repere="<?= $plan['element'] ?>:<?= $premiere['document'] ?>:1"
                        title="Voir le dessin de ce plan">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="m8.5 16 3-3 2 2 2.5-3" /></svg>
                    Voir le dessin
                </button>
            <?php endif; ?>
        </form>
        <p class="prefa-list-summary soudures-resultat" role="status" aria-live="polite" hidden></p>

        <section class="soudures-feuilles-section" data-element="<?= (int) $plan['element'] ?>"
                 aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>">
            <?php if (!$plan['feuilles']): ?>
                <?php // Le soudeur n'a pas l'écran « Plans / ISO » : lui dire où le chef
                      // clique ne l'avance à rien. Il lui faut savoir que ce n'est pas
                      // prêt, et vers qui se tourner. ?>
                <p class="prefa-table-empty soudures-plan-vide">Ce plan n’a pas encore de feuilles. Demandez au chef d’atelier de les ajouter.</p>
            <?php else: ?>
                <div class="prefa-table-scroll" role="region" aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>" tabindex="0">
                    <table class="prefa-table soudures-feuilles">
                        <thead><tr>
                            <th scope="col">Feuille</th>
                            <th scope="col" class="prefa-nombre">Cordons</th>
                            <th scope="col">État</th>
                            <th scope="col"><span class="atelier-sr-only">Dessin</span></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($plan['feuilles'] as $feuille):
                                $etat = soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']);
                                $repere = $repereFeuille($feuille);
                                $nom = $nomFeuille($feuille);
                                $partFaite = soudureAtelierPartFeuille($feuille['faites'], $feuille['imprimees']);
                            ?>
                                <?php // La ligne porte son repère, son état et de quoi la chercher :
                                      // le script n'a rien à recomposer. ?>
                                <tr class="soudures-feuille"
                                    data-repere="<?= prefaEscape($repere) ?>"
                                    data-feuille="<?= prefaEscape($nom) ?>"
                                    data-plan="<?= prefaEscape($plan['reference']) ?>"
                                    data-demande="<?= prefaEscape($demande) ?>"
                                    data-reference="<?= prefaEscape(prefaReference($plan['demande'])) ?>"
                                    data-element="<?= (int) $plan['element'] ?>"
                                    data-etat="<?= $etat['cle'] ?>"
                                    data-cherche="<?= prefaEscape(mb_strtolower($demande . ' ' . $plan['reference'] . ' ' . $plan['affaire'] . ' ' . $nom)) ?>">
                                    <td class="soudures-feuille-nom">
                                        <button type="button" class="soudures-renseigner">
                                            <strong><?= prefaEscape($nom) ?></strong>
                                            <?php if ($feuille['numero'] !== '' && $feuille['pages'] > 1): ?>
                                                <span>p. <?= $feuille['page'] ?> / <?= $feuille['pages'] ?></span>
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                    <td class="prefa-nombre soudures-feuille-cordons">
                                        <span data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></span>
                                        <?php if ($feuille['imprimees']): ?><span class="soudures-barre" aria-hidden="true"><span style="width: <?= $partFaite ?>%"></span></span><?php endif; ?>
                                    </td>
                                    <td class="soudures-feuille-etat"><span class="prefa-status <?= $etat['classe'] ?>" data-etat><?= prefaEscape($etat['libelle']) ?></span></td>
                                    <td class="soudures-feuille-actions">
                                        <button type="button" class="soudures-ouvrir-plan soudures-dessin-icone" data-repere="<?= prefaEscape($repere) ?>"
                                                title="Voir le dessin de cette feuille" aria-label="Voir le dessin de <?= prefaEscape($nom) ?>">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="m8.5 16 3-3 2 2 2.5-3" /></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="prefa-table-empty soudures-aucune" data-aucune hidden>Aucune feuille de ce plan ne correspond à cette recherche.</p>
        </section>

        <?php // Le « Suivi matériel » du titre : ce que la personne a signalé, et où en est
              // chaque manque. Le titre le promettait, l'écran ne le montrait nulle part. ?>
        <section class="soudures-materiel" aria-labelledby="soudures-materiel-titre">
            <h2 class="prefa-section-titre" id="soudures-materiel-titre">Matériel signalé
                <span class="prefa-onglet-compte"><?= count($manques) ?></span></h2>
            <div data-manques><?= manquesSoudeurRendu($manques) ?></div>
        </section>

        <?php // La feuille travaillée : le volet flottant de la maison, à droite, par-dessus la
              // liste — comme la fiche d'une demande, un PV ou un bon de commande. C'était une
              // carte fixe qui coupait l'écran en deux colonnes dès l'arrivée, pour rien tant
              // qu'aucune feuille n'était ouverte. ?>
        <div id="soudures-volet" class="prefa-detail-row soudures-volet-rang soudures-saisie" hidden>
            <div class="pv-volet" role="dialog" aria-labelledby="soudures-saisie-titre">
                <header class="pv-volet-tete">
                    <div>
                        <h2 id="soudures-saisie-titre"><span data-feuille-titre></span></h2>
                        <p><span data-feuille-plan></span><span data-feuille-compte></span></p>
                        <?php // Où en est la feuille auprès du chef : des cordons renseignés qui ne
                              // s'écrivent pas encore sur le plan, une déclaration qui attend. ?>
                        <p class="soudures-attente" data-feuille-attente hidden></p>
                    </div>
                    <div class="pv-volet-outils">
                        <button type="button" class="soudures-ouvrir-plan" data-feuille-plan-bouton data-repere=""
                                aria-label="Voir le dessin de cette feuille" title="Voir le dessin pour retrouver le repère d’un cordon">
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="m8.5 16 3-3 2 2 2.5-3" /></svg>
                        </button>
                        <button type="button" data-fermer-saisie aria-label="Fermer la feuille" title="Fermer">
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                        </button>
                    </div>
                </header>

                <form id="soudures-form" class="soudures-volet-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                    <section class="pv-volet-section">
                        <div class="pv-volet-titre-rang">
                            <h3 class="pv-volet-titre">Cordons</h3>
                        </div>
                        <p class="pv-volet-note">Pour chaque cordon soudé, notez la date et le procédé, puis enregistrez : le chef d’atelier valide ensuite.</p>
                        <table class="soudures-cordons">
                            <thead><tr>
                                <th scope="col">N°</th>
                                <th scope="col">Date</th>
                                <th scope="col">Procédé</th>
                                <th scope="col">Soudeur</th>
                            </tr></thead>
                            <tbody id="soudures-corps">
                                <tr><td colspan="4" class="prefa-table-empty">Lecture de la feuille…</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <?php // Le pied tient en bas du volet : l'enregistrement, et le manque —
                          // c'est en soudant qu'on s'aperçoit qu'une pièce n'est pas là. ?>
                    <footer class="soudures-volet-pied">
                        <button type="button" class="soudures-volet-manque bouton-discret" data-signaler-manque>Signaler un manque</button>
                        <span class="pv-message atelier-message" role="status" aria-live="polite"></span>
                        <button type="submit" class="pv-enregistrer">Enregistrer</button>
                    </footer>
                </form>
            </div>
        </div>

        <?php // Signaler un manque : la fenêtre de la maison. Le plan est celui qu'on regarde ;
              // l'article se choisit parmi ceux du bon de commande de sa demande, ou s'écrit. ?>
        <dialog class="fenetre" id="soudures-manque" aria-labelledby="soudures-manque-titre"
                data-articles="<?= prefaEscape(json_encode($articlesParPlan, JSON_UNESCAPED_UNICODE)) ?>">
            <form method="dialog" id="soudures-manque-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                <div class="fenetre-entete">
                    <h2 id="soudures-manque-titre">Signaler un manque</h2>
                    <button type="button" class="modal-close" data-fermer-manque aria-label="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
                <p class="fenetre-sous-titre">Le chef d’atelier est prévenu tout de suite.</p>
                <div class="fenetre-corps">
                    <?php // Les listes de la maison, pas celles du navigateur : on tape pour
                          // chercher, on choisit au clavier ou à la souris — comme le chargé
                          // d'affaire de « Nouvelle affaire ». Le mode sans étiquette : la
                          // fenêtre n'en porte pas, l'invite dit ce qu'on attend. ?>
                    <?php if (count($plans) > 1): ?>
                        <?= listeFiltrableRendu('manque-plan', 'Plan', array_map(static fn (array $choix): array => [
                            'id' => $choix['element'],
                            'libelle' => $choix['reference'] . ' · ' . prefaReference($choix['demande']),
                        ], array_values($plans)), $planOuvert, [
                            'nom' => 'element',
                            'aucun' => 'Aucun plan à ce nom.',
                            'chercher' => 'Plan',
                            'tableau' => true,
                        ]) ?>
                    <?php else: ?>
                        <input type="hidden" name="element" value="<?= (int) $planOuvert ?>">
                    <?php endif; ?>

                    <?php // L'article : ceux du bon de commande du plan, ou un autre, écrit
                          // dans le champ qui paraît sous la liste. Le script refait la liste
                          // quand on change de plan. ?>
                    <?= listeFiltrableRendu('manque-article', 'Article', array_map(static fn (array $article): array => [
                        'id' => $article['designation'],
                        'libelle' => $article['designation'],
                    ], $articlesParPlan[$planOuvert] ?? []), null, [
                        'nom' => 'designation',
                        'autre' => 'Autre article…',
                        'aucun' => 'Pas sur le bon de commande : choisissez « Autre article ».',
                        'chercher' => 'Article qui manque',
                        'tableau' => true,
                    ]) ?>
                    <div id="manque-article-autre-field" class="soudures-manque-autre" hidden style="display: none;">
                        <input id="manque-article-autre" class="fenetre-champ" name="designation_autre" maxlength="180"
                               autocomplete="off" placeholder="Article qui manque — bride DN40, tube Ø48,3…" aria-label="Article qui manque">
                    </div>

                    <div class="soudures-manque-quantite">
                        <input class="fenetre-champ" name="quantite" inputmode="decimal" placeholder="Quantité" aria-label="Quantité">
                        <input class="fenetre-champ" name="unite" maxlength="16" placeholder="Unité (u, m, kg…)" aria-label="Unité">
                    </div>

                    <?= listeFiltrableRendu('manque-motif', 'Motif', array_map(static fn (string $cle, string $libelle): array => [
                        'id' => $cle, 'libelle' => $libelle,
                    ], array_keys(manquesMotifs()), manquesMotifs()), null, [
                        'nom' => 'motif',
                        'aucun' => 'Aucun motif ne correspond.',
                        'chercher' => 'Motif du manque',
                        'tableau' => true,
                    ]) ?>
                    <textarea class="fenetre-champ" name="commentaire" maxlength="1000" rows="3"
                              placeholder="Précision pour le chef (facultatif) — repère, longueur, urgence…" aria-label="Commentaire"></textarea>
                    <p class="fenetre-message" role="status" aria-live="polite"></p>
                </div>
                <div class="fenetre-pied">
                    <button type="button" class="fenetre-annuler" data-fermer-manque>Annuler</button>
                    <button type="submit" class="fenetre-valider">Signaler</button>
                </div>
            </form>
        </dialog>

        <?php // Le moule d'une ligne : le script le clone plutôt que d'assembler du HTML dans
              // des chaînes, où une apostrophe de nom suffit à tout casser. ?>
        <template id="soudures-modele">
            <tr class="soudures-ligne">
                <td class="soudures-numero-cellule"><span class="soudures-numero"></span></td>
                <td class="soudures-champ">
                    <input type="date" class="soudures-date" aria-label="Date de soudage">
                </td>
                <td class="soudures-champ">
                    <input type="text" class="soudures-type" maxlength="30" list="soudure-procedes" placeholder="TIG…" aria-label="Type de soudure">
                </td>
                <td class="soudures-soudeur"></td>
            </tr>
        </template>

        <?php
        // Le lecteur de plan, une fenêtre par élément : on regarde le dessin pour savoir
        // quel cordon porte le repère 3. Lecture seule — rien ne se pose dessus d'ici.
        foreach ($planOuvert !== null ? [$planOuvert] : [] as $elementId):
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
