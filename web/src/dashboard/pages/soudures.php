<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/vue/liste.php';
require_once __DIR__ . '/../includes/vue/soudures.php';
require_once __DIR__ . '/../includes/vue/manques.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

// Les plans confiés, et c'est l'affectation qui fait foi : un plan paraît même si aucune
// feuille ISO n'y est encore rattachée, parce que le travail, lui, est bien là.
$plans = [];
foreach (soudureAtelierPlans($db, (int) $actor['id']) as $plan) $plans[$plan['element']] = $plan;

// Les feuilles de tous les plans, chacune sous le sien. Le plan se choisissait dans une
// pastille de la barre, et ses feuilles venaient dessous sans rappeler de quel plan elles
// étaient : on cherchait la feuille qu'on a en main dans un plan après l'autre. Rien n'est
// relu des PDF : le compte des soudures vient du repère de l'indexation, un fichier par
// cahier (soudureAtelierFeuilles()).
$feuillesParPlan = [];
foreach (soudureAtelierFeuilles($db, (int) $actor['id']) as $feuille) {
    $feuillesParPlan[$feuille['element']][] = $feuille;
}
$toutes = $feuillesParPlan ? array_merge(...array_values($feuillesParPlan)) : [];
$parEtat = array_count_values(array_map(static fn (array $feuille): string =>
    soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees'])['cle'], $toutes));

// Les onglets d'état des feuilles, dans l'ordre du travail. Les deux derniers ne disent pas
// où en est le soudeur mais ce qu'on ne sait pas encore de la feuille : ils ne paraissent
// que s'ils comptent quelque chose. L'aide passe en infobulle, comme sur les demandes.
$onglets = [];
foreach ([
    'a_faire' => ['À faire', 'Aucune soudure renseignée.', true],
    'en_cours' => ['En cours', 'Des soudures renseignées, d’autres à faire.', true],
    'soudee' => ['Soudées', 'Toutes les soudures de la liste sont renseignées.', true],
    'non_lue' => ['Pas encore lues', 'Leur liste soudure se lit à la première ouverture.', false],
    'sans_liste' => ['Sans liste', 'Aucune liste soudure reconnue : prévenez le chef d’atelier.', false],
] as $cle => [$libelle, $aide, $toujours]) {
    if (!$toujours && empty($parEtat[$cle])) continue;
    $onglets[] = ['cle' => $cle, 'libelle' => $libelle, 'compte' => $parEtat[$cle] ?? 0,
        'point' => 'soudures-point est-' . $cle, 'aide' => $aide];
}

// Le résumé à droite des onglets : les soudures de tous les plans, faites sur prévues.
// soudures.js le récrit après chaque enregistrement, avec la même phrase.
//
// L'écran dit « soudure » partout, jamais « cordon » : c'est le mot de la liste soudure
// imprimée, celui que l'atelier emploie. Le code, lui, garde « cordon » dans ses noms.
$faites = array_sum(array_column($toutes, 'faites'));
$prevues = array_sum(array_map(static fn (array $feuille): int => (int) $feuille['imprimees'], $toutes));
$resume = '<span><strong data-cordons-faits>' . $faites . '</strong> <span data-cordons-libelle>'
    . prefaEscape(($faites > 1 ? 'soudures faites' : 'soudure faite') . ($prevues > 0 ? ' sur ' . $prevues : ''))
    . '</span></span>';

// Le matériel : ce que la personne a signalé, et les articles des bons de commande de
// chacun de ses plans, à proposer quand elle en signale un autre.
$manques = manquesDuSoudeur($db, (int) $actor['id']);
$articlesParPlan = [];
$articlesParDemande = [];
foreach ($plans as $plan) {
    $articlesParDemande[$plan['demande']] ??= manquesArticlesProposes($db, $plan['demande']);
    $articlesParPlan[$plan['element']] = $articlesParDemande[$plan['demande']];
}
?>

<?php // La coque et la grammaire des autres listes — « Mes plans », « Demandes de préfa »,
      // « Commande matériel » : l'en-tête et ses outils de vue, la barre de recherche, les
      // onglets d'état avec leur point, un tableau à même la surface, l'action au bout de la
      // ligne. Leurs pièces communes sont dans vue/liste.php, celles de l'écran dans
      // vue/soudures.php. ?>
<section class="page prefa-page prefa-list atelier-page soudures" id="soudures-page" aria-labelledby="soudures-page-title" data-densite="confortable">
    <div class="prefa-shell">
    <header class="prefa-heading prefa-list-heading">
        <div>
            <h1 id="soudures-page-title">Mes soudures &amp; Suivi matériel</h1>
            <p>Les feuilles de vos plans, et le matériel qui vous manque.</p>
        </div>
        <div class="prefa-vue-outils">
            <?= listeDensite() ?>
            <?= listeActualiser('pv-outil soudures-actualiser') ?>
            <?php // L'action de l'écran, à côté d'« Actualiser » comme « Nouvelle demande » sur
                  // la liste des demandes : un manque se signale sans ouvrir de feuille. ?>
            <?php if ($plans): ?>
                <button type="button" class="prefa-nouvelle-demande" data-signaler-manque>
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.01" /><path d="M10.3 3.9 2.6 17.4A2 2 0 0 0 4.3 20.4h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                    Signaler un manque
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$plans): ?>
        <p class="prefa-table-empty soudures-rien">Aucun plan ne vous est affecté pour le moment.</p>
    <?php else: ?>
        <?php // La recherche, et elle seule : elle parcourt tous les plans — le numéro de ligne,
              // le plan, sa demande, son affaire. L'état est dans les onglets, juste dessous ;
              // ils comptent les feuilles de tous les plans. ?>
        <form class="prefa-filters soudures-filtres" role="search">
            <?= listeRecherche('soudures-recherche', 'N° de ligne, plan, affaire…', 'Rechercher une feuille') ?>
        </form>
        <?php if ($toutes): ?>
            <?= listeOnglets('soudures-onglet', 'etat', 'État des feuilles', ['libelle' => 'Toutes', 'compte' => count($toutes)], $onglets, $resume) ?>
        <?php endif; ?>

        <div class="prefa-table-scroll" role="region" aria-label="Feuilles de vos plans" tabindex="0">
            <table class="prefa-table soudures-feuilles">
                <thead><tr>
                    <th scope="col">Feuille</th>
                    <th scope="col" class="soudures-feuille-page">Page</th>
                    <th scope="col" class="prefa-nombre">Soudures</th>
                    <th scope="col">Actions</th>
                </tr></thead>
                <?php foreach ($plans as $plan): ?>
                    <?= souduresPlanRendu($plan, $feuillesParPlan[$plan['element']] ?? []) ?>
                <?php endforeach; ?>
                <tbody class="soudures-aucune" data-aucune hidden>
                    <tr><td colspan="4" class="prefa-table-empty">Aucune feuille ne correspond à cette recherche.</td></tr>
                </tbody>
            </table>
        </div>

        <?php // La fenêtre du plan de chaque plan, posée ici à la première feuille qu'on en ouvre
              // (actions/soudures_lecteur.php) : c'est là qu'on remplit la feuille, en cliquant
              // une ligne de la liste soudure imprimée — ou le numéro de la soudure sur le tube —,
              // et sa fenêtre s'ouvre par-dessus. Une ligne de la nomenclature ouvre « Signaler
              // un manque » sur son article. ?>
        <div class="soudures-lecteurs" data-lecteurs></div>

        <?php // Le « Suivi matériel » du titre : ce que la personne a signalé, et où en est
              // chaque manque. Le titre le promettait, l'écran ne le montrait nulle part. ?>
        <section class="soudures-materiel" aria-labelledby="soudures-materiel-titre">
            <h2 class="prefa-section-titre" id="soudures-materiel-titre">Matériel signalé
                <span class="prefa-onglet-compte"><?= count($manques) ?></span></h2>
            <div data-manques><?= manquesSoudeurRendu($manques) ?></div>
        </section>

        <?= manqueFenetreRendu($plans, $articlesParPlan, unitesMateriel($db)) ?>
        <?= souduresCordonFenetre() ?>
    <?php endif; ?>
    </div>
</section>
