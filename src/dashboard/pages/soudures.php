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
//
// Un plan ne dit que son nom et ses feuilles. Ni compte de cordons, ni jauge, ni
// pourcentage : où en est le plan entier se lit dans « Mes affectations », et c'est là
// aussi que l'avancement se déclare. Le même chiffre à deux endroits oblige à se demander
// lequel des deux fait foi.
$confies = $db->prepare('SELECT DISTINCT a.id_element, e.reference, e.id_demande
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
        'demande' => (int) $confie['id_demande'],
        'feuilles' => $parPlan[$elementId] ?? [],
    ];
}
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
            <?php // Le titre de la page dit exactement ce que dit la barre : un onglet et
                  // son écran qui ne portent pas le même nom, on ne sait plus si on est au
                  // bon endroit. ?>
            <h1 class="soudures-titre" id="soudures-page-title">Mes soudures &amp; Suivi matériel</h1>
            <?php // La phrase dit la chaîne entière — plan, feuille, cordon — parce que
                  // l'écran n'en montre qu'un maillon à la fois. Sans elle, on arrive devant
                  // une ligne grise et rien ne dit ce qu'on est venu y faire. ?>
            <p class="soudures-sous-titre">Les plans qui vous sont confiés. Ouvrez-en un pour voir ses feuilles, puis notez sur chaque feuille les cordons que vous avez soudés.</p>
        </div>
    </header>

    <?php if (!$plans): ?>
        <p class="soudures-rien">Aucun plan ne vous est affecté pour le moment.</p>
    <?php else: ?>
        <?php // Un plan par bloc, replié sur ses feuilles. Les plans arrivent fermés et
              // aucune feuille n'est choisie : un cahier de quinze pages n'a pas à tenir
              // l'écran avant qu'on ait dit sur lequel on est, et la première lecture d'une
              // page coûte quelques secondes — les dépenser pour une feuille que personne
              // n'a demandée, c'est attendre pour rien. ?>
        <ol class="soudures-plans">
            <?php foreach ($plans as $plan):
                $idFeuilles = 'soudures-plan-' . $plan['element'];
                $nombre = count($plan['feuilles']);
            ?>
                <li class="soudures-plan est-repliee">
                    <?php // Le plan tout entier est le bouton qui l'ouvre : c'est la ligne qu'on
                          // vise, pas une poignée au bout. Le chevron dit dans quel état on est
                          // sans qu'on ait à lire un mot. ?>
                    <button type="button" class="soudures-plan-bascule"
                            aria-expanded="false" aria-controls="<?= $idFeuilles ?>">
                        <span class="soudures-plan-chevron" aria-hidden="true"></span>
                        <span class="soudures-plan-identite">
                            <strong><?= prefaEscape($plan['reference']) ?></strong>
                            <span><?= prefaEscape(prefaReferenceComplete($plan['demande'])) ?> · <?= $nombre ?> feuille<?= $nombre > 1 ? 's' : '' ?></span>
                        </span>
                    </button>

                    <div class="soudures-plan-feuilles" id="<?= $idFeuilles ?>">
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
                                        ?>
                                            <?php // La ligne porte son repère et ses noms : le script n'a
                                                  // rien à recomposer, ni à aller relire des cellules pour
                                                  // redire au-dessous de quelle feuille on travaille. ?>
                                            <tr class="soudures-feuille"
                                                data-repere="<?= prefaEscape($repere) ?>"
                                                data-feuille="<?= prefaEscape($nomFeuille($feuille)) ?>"
                                                data-plan="<?= prefaEscape($feuille['reference']) ?>"
                                                data-demande="<?= prefaEscape(prefaReferenceComplete($feuille['demande'])) ?>">
                                                <td class="soudures-feuille-nom">
                                                    <strong><?= prefaEscape($nomFeuille($feuille)) ?></strong>
                                                    <?php if ($feuille['numero'] !== '' && $feuille['pages'] > 1): ?>
                                                        <?php // Le numéro de ligne ne dit pas où la feuille se
                                                              // trouve dans le cahier : la page le dit, à la
                                                              // suite. Sur deux étages, la ligne se serrait et
                                                              // quinze feuilles faisaient un mur. ?>
                                                        <span>p. <?= $feuille['page'] ?> / <?= $feuille['pages'] ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="soudures-feuille-cordons" data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></td>
                                                <td class="soudures-feuille-etat"><span class="prefa-status <?= $etat['classe'] ?>" data-etat><?= prefaEscape($etat['libelle']) ?></span></td>
                                                <td class="soudures-feuille-actions">
                                                    <?php // « Renseigner » amène le tableau de cette feuille
                                                          // sous les yeux ; « Plan » ouvre le dessin, pour
                                                          // retrouver le repère d'un cordon. Cliquer la ligne
                                                          // fait comme « Renseigner » — c'est le geste qu'on a
                                                          // dans une liste —, mais le bouton reste : il se
                                                          // voit, et il s'atteint au clavier. ?>
                                                    <button type="button" class="soudures-renseigner">Renseigner</button>
                                                    <button type="button" class="soudures-ouvrir-plan" data-repere="<?= prefaEscape($repere) ?>">Plan</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php // La saisie n'existe qu'une fois une feuille choisie : « data-vide » la tient
              // repliée, et le premier clic sur une ligne la pose avec son nom. Le script la
              // déplace alors dans le plan travaillé, sous ses feuilles : elle est écrite
              // ici, une fois, parce qu'on ne remplit qu'une feuille à la fois.
              //
              // Son en-tête a la forme de celui d'un plan — un nom, une ligne grise, un
              // bouton à droite — et sa liste a la forme du tableau des feuilles. Deux
              // formes pour tout l'écran : un en-tête, un tableau. On en comptait quatre,
              // et chacune demandait d'apprendre où regarder. ?>
        <section class="soudures-saisie" aria-labelledby="soudures-saisie-titre" data-vide>
            <div class="soudures-saisie-entete">
                <h2 class="soudures-saisie-titre" id="soudures-saisie-titre">
                    <strong data-feuille-titre></strong>
                    <span data-feuille-compte></span>
                </h2>
                <button type="button" class="soudures-ouvrir-plan" data-feuille-plan-bouton data-repere=""
                        title="Voir le dessin pour retrouver le repère d’un cordon">Ouvrir la feuille</button>
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
        </section>

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
