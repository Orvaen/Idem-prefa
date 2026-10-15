<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/soudure_atelier.php';
require_once __DIR__ . '/../includes/nomenclature.php';
require_once __DIR__ . '/../includes/plan_viewer.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

// Une entrée par page d'isométrique : c'est la question que se pose le soudeur — sur
// quelle feuille suis-je ? — et non « quel fichier, puis quelle page ».
$feuilles = soudureAtelierFeuilles($db, (int) $actor['id']);

$parPlan = [];
foreach ($feuilles as $feuille) $parPlan[$feuille['element']][] = $feuille;

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
// feuille ISO n'y est encore rattachée, parce que le travail, lui, est bien là.
//
// « DISTINCT » parce qu'une même personne peut tenir le plan, le montage et le suivi
// soudage du même plan : ici on vient souder, pas consulter la répartition du travail.
$confies = $db->prepare('SELECT DISTINCT a.id_element, e.reference, e.id_demande, d.nom_affaire
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY e.id_demande DESC, e.reference');
$confies->execute([$actor['id']]);
$plans = [];
foreach ($confies->fetchAll() as $confie) {
    $elementId = (int) $confie['id_element'];
    $plans[$elementId] = [
        'element' => $elementId,
        'reference' => (string) $confie['reference'],
        'affaire' => (string) $confie['nom_affaire'],
        'demande' => (int) $confie['id_demande'],
        'feuilles' => $parPlan[$elementId] ?? [],
    ];
}

// Le premier isométrique d'un plan, pour le bouton qui montre le dessin : on ouvre le
// cahier à sa première page, c'est de là qu'on se repère.
$premiereFeuille = static fn (array $plan): ?array => $plan['feuilles'][0] ?? null;
?>
<datalist id="soudure-procedes">
    <option value="TIG"></option><option value="MIG"></option><option value="MAG"></option>
    <option value="111"></option><option value="135"></option><option value="141"></option>
</datalist>

<?php // La coque, l'en-tête, la barre de filtres, le compte, le tableau : exactement ceux
      // des autres listes de l'application — « Demandes de préfabrication », « Soudures »
      // du chef, « Mes affaires ». Cet écran s'était inventé son propre gabarit, avec
      // un surtitre et un grand titre léger qu'on ne voit nulle part ailleurs : on
      // changeait de logiciel en changeant d'onglet. ?>
<section class="page prefa-page prefa-list atelier-page soudures" id="soudures-page" aria-labelledby="soudures-page-title">
    <div class="prefa-shell">
    <header class="prefa-heading prefa-list-heading">
        <div>
            <?php // Le titre de la page dit exactement ce que dit la barre : un onglet et
                  // son écran qui ne portent pas le même nom, on ne sait plus si on est au
                  // bon endroit. ?>
            <h1 id="soudures-page-title">Mes soudures &amp; Suivi matériel</h1>
            <?php // La phrase dit la chaîne entière — plan, feuille, cordon — parce que
                  // l'écran n'en montre qu'un maillon à la fois. Sans elle, on arrive devant
                  // un tableau et rien ne dit ce qu'on est venu y faire. ?>
            <p>Les feuilles des plans qui vous sont confiés. Ouvrez-en une et notez les cordons que vous avez soudés.</p>
        </div>
    </header>

    <?php if (!$plans): ?>
        <p class="soudures-rien">Aucun plan ne vous est affecté pour le moment.</p>
    <?php else: ?>
        <?php // Les utilitaires de la maison : un champ de recherche et des listes d'état.
              // Un cahier porte quinze feuilles, et on en a souvent trois : parcourir
              // quarante lignes à l'œil pour retrouver « YU-PRE-120-3 » est une corvée que
              // les autres écrans n'imposent pas. ?>
        <div class="prefa-filters" role="search">
            <div class="floating-field">
                <label for="soudures-recherche">Rechercher</label>
                <input id="soudures-recherche" class="floating-control" type="search"
                       placeholder="N° de ligne, plan, demande">
            </div>
            <div class="floating-field floating-always">
                <label for="soudures-filtre-etat">État</label>
                <select id="soudures-filtre-etat" class="floating-control">
                    <option value="">Toutes les feuilles</option>
                    <option value="a_faire">À faire</option>
                    <option value="en_cours">En cours</option>
                    <option value="soudee">Soudées</option>
                    <option value="non_lue">Pas encore ouvertes</option>
                </select>
            </div>
            <?php if (count($plans) > 1): ?>
                <div class="floating-field floating-always">
                    <label for="soudures-filtre-plan">Plan</label>
                    <select id="soudures-filtre-plan" class="floating-control">
                        <option value="">Tous les plans</option>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= (int) $plan['element'] ?>"><?= prefaEscape($plan['reference']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <p class="prefa-list-summary">
            <strong id="soudures-compte-feuilles"><?= count($feuilles) ?></strong>
            <span><?= count($feuilles) === 1 ? 'feuille' : 'feuilles' ?></span>
            · <strong><?= count($plans) ?></strong>
            <span><?= count($plans) === 1 ? 'plan' : 'plans' ?></span>
        </p>

        <?php // Le plan de travail : les plans et leurs feuilles à gauche, la feuille
              // travaillée à droite. Les deux colonnes sont posées dès l'arrivée — sans
              // quoi le tableau passait de pleine largeur à la moitié au moment du clic. ?>
        <div class="soudures-plan-de-travail">
        <div class="soudures-plans">
            <?php // Une section par plan : son nom, d'où il vient, et ses feuilles dessous.
                  // À plat, toutes les feuilles mélangées, on ne savait plus laquelle
                  // appartenait à quel plan — les colonnes qui le disaient avaient cédé
                  // leur place au volet de saisie. ?>
            <?php foreach ($plans as $plan):
                $demande = prefaReferenceComplete($plan['demande']);
                $nombre = count($plan['feuilles']);
                $premiere = $premiereFeuille($plan);
            ?>
                <section class="soudures-plan" data-element="<?= (int) $plan['element'] ?>"
                         aria-labelledby="soudures-plan-<?= (int) $plan['element'] ?>">
                    <div class="prefa-detail-heading soudures-plan-entete">
                        <div>
                            <h2 id="soudures-plan-<?= (int) $plan['element'] ?>">Plan <span><?= prefaEscape($plan['reference']) ?></span></h2>
                            <p><?= prefaEscape($demande) ?> · <?= $nombre ?> feuille<?= $nombre > 1 ? 's' : '' ?></p>
                        </div>
                        <?php if ($premiere): ?>
                            <?php // Le dessin du cahier, ouvert à sa première page : on y va pour
                                  // reconnaître le plan, pas pour une feuille en particulier. ?>
                            <button type="button" class="soudures-ouvrir-plan"
                                    data-repere="<?= $plan['element'] ?>:<?= $premiere['document'] ?>:1"
                                    title="Voir le dessin de ce plan">Ouvrir</button>
                        <?php endif; ?>
                    </div>

                    <?php if (!$plan['feuilles']): ?>
                        <p class="atelier-muted soudures-plan-vide">Aucune feuille ISO n’est rattachée à ce plan : le chef d’atelier les dépose depuis « Plans / ISO ».</p>
                    <?php else: ?>
                        <div class="prefa-table-scroll" role="region" aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>" tabindex="0">
                            <table class="prefa-table soudures-feuilles">
                                <thead><tr>
                                    <th scope="col">Feuille</th>
                                    <th scope="col">Cordons</th>
                                    <th scope="col">État</th>
                                    <th scope="col">Actions</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($plan['feuilles'] as $feuille):
                                        $etat = soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']);
                                        $repere = $repereFeuille($feuille);
                                        $nom = $nomFeuille($feuille);
                                    ?>
                                        <?php // La ligne porte son repère, son état et de quoi la
                                              // chercher : le script n'a rien à recomposer, ni à relire
                                              // des cellules pour filtrer ou pour redire au-dessous
                                              // quelle feuille on travaille. ?>
                                        <tr class="soudures-feuille"
                                            data-repere="<?= prefaEscape($repere) ?>"
                                            data-feuille="<?= prefaEscape($nom) ?>"
                                            data-plan="<?= prefaEscape($plan['reference']) ?>"
                                            data-demande="<?= prefaEscape($demande) ?>"
                                            data-element="<?= (int) $plan['element'] ?>"
                                            data-etat="<?= $etat['cle'] ?>"
                                            data-cherche="<?= prefaEscape(mb_strtolower($demande . ' ' . $plan['reference'] . ' ' . $plan['affaire'] . ' ' . $nom)) ?>">
                                            <td class="soudures-feuille-nom">
                                                <strong><?= prefaEscape($nom) ?></strong>
                                                <?php if ($feuille['numero'] !== '' && $feuille['pages'] > 1): ?>
                                                    <?php // Le numéro de ligne ne dit pas où la feuille se
                                                          // trouve dans le cahier : la page le dit, à la
                                                          // suite. ?>
                                                    <span>p. <?= $feuille['page'] ?> / <?= $feuille['pages'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="soudures-feuille-cordons" data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></td>
                                            <td class="soudures-feuille-etat"><span class="prefa-status <?= $etat['classe'] ?>" data-etat><?= prefaEscape($etat['libelle']) ?></span></td>
                                            <td class="soudures-feuille-actions">
                                                <?php // « Renseigner » amène le tableau de cette feuille
                                                      // sous les yeux ; « Plan » ouvre le dessin à SA page.
                                                      // Cliquer la ligne fait comme « Renseigner » — c'est
                                                      // le geste qu'on a dans une liste —, mais le bouton
                                                      // reste : il se voit, et il s'atteint au clavier. ?>
                                                <button type="button" class="soudures-renseigner">Renseigner</button>
                                                <button type="button" class="soudures-ouvrir-plan" data-repere="<?= prefaEscape($repere) ?>">Plan</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php // Le filtre peut tout cacher : la ligne le dit, plutôt que de laisser la
                  // colonne vide sans un mot. ?>
            <p id="soudures-aucune" class="prefa-table-empty soudures-aucune" hidden>Aucune feuille ne correspond à cette recherche.</p>
        </div>

        <?php // La colonne de droite existe dès l'arrivée, et elle attend : sans elle, le
              // tableau passait de pleine largeur à la moitié au moment du clic, et l'écran
              // se tordait sous la main. « data-vide » ne cache donc plus la colonne, mais
              // son contenu — l'invite prend sa place tant qu'on n'a rien choisi.
              //
              // C'est le volet de détail de la maison — même en-tête, même croix pour le
              // fermer que sur les écrans du chef — et sa liste est un tableau comme celui
              // des feuilles. Deux formes pour tout l'écran : un en-tête, un tableau. ?>
        <section class="soudures-saisie atelier-detail-content" aria-labelledby="soudures-saisie-titre" data-vide>
            <p class="soudures-invite">Choisissez une feuille dans la liste : ses cordons s’ouvrent ici.</p>
            <div class="soudures-saisie-contenu">
            <div class="prefa-detail-heading">
                <div>
                    <h2 id="soudures-saisie-titre">Feuille <span data-feuille-titre></span></h2>
                    <?php // Le plan et la demande sont dits ici : côte à côte, le tableau
                          // cède ses deux colonnes d'identité pour tenir dans la moitié
                          // gauche, et il faut bien que la feuille dise d'où elle vient. ?>
                    <p><span data-feuille-plan></span><span data-feuille-compte></span></p>
                </div>
                <div class="soudures-saisie-actions">
                    <button type="button" class="soudures-ouvrir-plan" data-feuille-plan-bouton data-repere=""
                            title="Voir le dessin pour retrouver le repère d’un cordon">Ouvrir la feuille</button>
                    <button type="button" class="prefa-detail-close" data-fermer-saisie aria-label="Fermer la feuille">✕</button>
                </div>
            </div>

            <form id="soudures-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                <div class="prefa-table-scroll" role="region" aria-label="Cordons de la feuille travaillée" tabindex="0">
                    <table class="prefa-table soudures-cordons">
                        <thead><tr>
                            <th scope="col">N°</th>
                            <th scope="col">Date</th>
                            <th scope="col">Type de soudure</th>
                            <th scope="col">Soudeur</th>
                            <th scope="col">Contrôles</th>
                        </tr></thead>
                        <tbody id="soudures-corps">
                            <tr><td colspan="5" class="prefa-table-empty">Lecture de la feuille…</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="soudures-pied">
                    <p class="soudures-compte" role="status" aria-live="polite"></p>
                    <div class="soudures-actions">
                        <span class="atelier-message" role="status" aria-live="polite"></span>
                        <button type="submit">Enregistrer</button>
                    </div>
                </div>
            </form>
            </div>
        </section>
        </div>

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
                <td class="soudures-controles"></td>
            </tr>
        </template>

        <?php
        // Le lecteur de plan, une fenêtre par élément : on regarde le dessin pour savoir
        // quel cordon porte le repère 3. Lecture seule — rien ne se pose dessus d'ici.
        foreach (array_keys($plans) as $elementId):
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
