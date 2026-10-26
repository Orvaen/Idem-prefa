<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';
require_once __DIR__ . '/../includes/vue/plan_viewer.php';
require_once __DIR__ . '/../includes/vue/filtres.php';
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

// Aucun plan n'est ouvert à l'arrivée, pas même quand il n'y en a qu'un. Un écran qui
// choisit à la place de l'atelier lui fait croire qu'il sait où il en est, et la marque de
// la ligne « déjà prise » ne veut alors plus rien dire — c'est elle qui doit répondre à
// « où en suis-je ? ». L'invite, elle, dit quoi faire en une ligne.
$planOuvert = null;

// Le premier isométrique d'un plan, pour le bouton qui montre le dessin : on ouvre le
// cahier à sa première page, c'est de là qu'on se repère.
$premiereFeuille = static fn (array $plan): ?array => $plan['feuilles'][0] ?? null;
?>
<datalist id="soudure-procedes">
    <option value="TIG"></option><option value="MIG"></option><option value="MAG"></option>
    <option value="111"></option><option value="135"></option><option value="141"></option>
</datalist>

<?php // La coque, l'en-tête, la barre de filtres, le compte, les tableaux : exactement ceux
      // des autres listes de l'application — « Demandes de préfabrication », « Soudures »
      // du chef, « Mes plans ». Cet écran s'était inventé son propre gabarit, avec un
      // surtitre et un grand titre léger qu'on ne voit nulle part ailleurs : on changeait
      // de logiciel en changeant d'onglet. ?>
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
            <p>Ouvrez un de vos plans pour voir ses feuilles, puis notez sur chaque feuille les cordons que vous avez soudés.</p>
        </div>
    </header>

    <?php if (!$plans): ?>
        <p class="soudures-rien">Aucun plan ne vous est affecté pour le moment.</p>
    <?php else: ?>
            <?php // La recherche est sur la ligne du titre, à droite, où les autres écrans
                  // mettent leur bouton « Actualiser ». Elle a été un bloc entre les deux
                  // tableaux, puis dans l'en-tête des feuilles : à chaque fois, un troisième
                  // bloc se disputait la place avec eux. Ici elle n'en prend aucune.
                  //
                  // Les contrôles sont ceux de la maison : un champ à loupe et une pastille qui
                  // dit ce qu'elle retient. C'étaient deux champs de formulaire à étiquette
                  // flottante, hauts de 44 px — le gabarit de « Nom » ou « Mot de passe », alors
                  // qu'on ne saisit rien ici, on choisit.
                  //
                  // Elle porte sur toutes les feuilles, tous plans confondus : un soudeur
                  // connaît son numéro de ligne, pas le plan auquel il appartient. Les plans
                  // sans feuille retenue se rangent, et s'il n'en reste qu'un, il s'ouvre.
                  //
                  // La barre est écrite ici plutôt que par filtresBarre() pour une raison :
                  // « Tout effacer » n'y paraît que lorsqu'un filtre est posé, ce que le serveur
                  // ne sait pas — rien n'est filtré au rendu, tout l'est dans la page. ?>
            <form class="prefa-filters soudures-filtres" role="search" hidden>
                <?php // Les deux contrôles tiennent sur une ligne, le compte passe dessous :
                      // une barre souple se mesure à la somme de ses éléments sans les
                      // écarts, et dix pixels suffisaient à faire passer la pastille sous
                      // le champ. ?>
                <div class="soudures-filtres-ligne">
                    <div class="filtres-recherche">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input id="soudures-recherche" type="search" autocomplete="off"
                           aria-label="Rechercher une feuille" placeholder="N° de ligne, plan, demande…">
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
                </div>
                <p class="soudures-resultat" role="status" aria-live="polite" hidden></p>
            </form>

        <?php // Le plan de travail : à gauche les plans puis les feuilles de celui qu'on a
              // ouvert, à droite la feuille travaillée. Les deux colonnes sont posées dès
              // l'arrivée — sans quoi le tableau passait de pleine largeur à la moitié au
              // moment du clic, et l'écran se tordait sous la main. ?>
        <div class="soudures-plan-de-travail">
        <div class="soudures-plans">
            <?php // Les plans d'abord : un tableau court, une ligne par plan. On en ouvre un,
                  // et ses feuilles paraissent dessous. Toutes les feuilles ensemble, sans
                  // leur plan, on ne savait plus lesquelles étaient à qui. ?>
            <div class="prefa-table-scroll" role="region" aria-label="Mes plans" tabindex="0">
                <table class="prefa-table soudures-plans-table">
                    <thead><tr>
                        <th scope="col">Plan</th>
                        <th scope="col">Demande n°</th>
                        <th scope="col">Feuilles</th>
                        <th scope="col">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($plans as $plan):
                            $demande = prefaReferenceComplete($plan['demande']);
                            $nombre = count($plan['feuilles']);
                            $ouvert = $plan['element'] === $planOuvert;
                        ?>
                            <tr class="soudures-plan-ligne<?= $ouvert ? ' est-choisie' : '' ?>"
                                data-element="<?= (int) $plan['element'] ?>"
                                <?= $ouvert ? 'aria-current="true"' : '' ?>>
                                <td><strong><?= prefaEscape($plan['reference']) ?></strong></td>
                                <td><?= prefaEscape($demande) ?></td>
                                <td class="soudures-plan-nombre"><?= $nombre ?></td>
                                <td class="soudures-feuille-actions">
                                    <?php // « Ouvrir » montre les feuilles du plan — c'est le geste de
                                          // cet écran. Le dessin, lui, s'ouvre depuis l'en-tête des
                                          // feuilles, une fois le plan ouvert. ?>
                                    <button type="button" class="soudures-ouvrir-plan-feuilles" aria-expanded="false">Ouvrir</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // Une section de feuilles par plan, masquée jusqu'à ce qu'on l'ouvre. Elles
                  // sont toutes rendues : les montrer coûte un attribut, et aller les
                  // chercher coûterait un aller-retour au serveur à chaque changement. ?>
            <?php foreach ($plans as $plan):
                $demande = prefaReferenceComplete($plan['demande']);
                $premiere = $premiereFeuille($plan);
            ?>
                <section class="soudures-feuilles-section" data-element="<?= (int) $plan['element'] ?>"
                         aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>"
                         <?= $plan['element'] === $planOuvert ? '' : 'hidden' ?>>
                    <?php // Pas de titre ici : la ligne du plan, juste au-dessus, porte déjà son
                          // nom, sa demande et son nombre de feuilles — et elle est teintée
                          // tant qu'il est ouvert. « Feuilles de Plan de test » redisait tout
                          // cela une seconde fois, et volait une ligne de plus dès que la
                          // fenêtre se resserrait. Le nom reste pour qui lit l'écran à la
                          // voix : il est sur l'étiquette de la section.
                          //
                          // Ne reste que la barre d'outils : la recherche vient s'y poser,
                          // devant le bouton du dessin. ?>
                    <div class="soudures-plan-outils">
                        <?php if ($premiere): ?>
                            <?php // Le dessin du cahier, ouvert à sa première page : on y va pour
                                  // reconnaître le plan, pas pour une feuille en particulier. ?>
                            <button type="button" class="soudures-ouvrir-plan"
                                    data-repere="<?= $plan['element'] ?>:<?= $premiere['document'] ?>:1"
                                    title="Voir le dessin de ce plan">Voir le dessin</button>
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

                    <?php // Le filtre peut tout cacher dans ce plan : le mot le dit, plutôt que
                          // de laisser un tableau sans lignes sous ses en-têtes. ?>
                    <p class="prefa-table-empty soudures-aucune" data-aucune hidden>Aucune feuille de ce plan ne correspond à cette recherche.</p>
                </section>
            <?php endforeach; ?>
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
            <div class="soudures-saisie-contenu">
            <div class="prefa-detail-heading">
                <div>
                    <h2 id="soudures-saisie-titre">Feuille <span data-feuille-titre></span></h2>
                    <p><span data-feuille-plan></span><span data-feuille-compte></span></p>
                    <?php // Où en est la feuille auprès du chef. Le message d'enregistrement le
                          // disait une fois puis s'effaçait : on lisait « 1 soudure enregistrée »,
                          // en vert, et plus rien ensuite ne distinguait une ligne validée d'une
                          // ligne qui attend encore. ?>
                    <p class="soudures-attente" data-feuille-attente hidden></p>
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
                        </tr></thead>
                        <tbody id="soudures-corps">
                            <tr><td colspan="4" class="prefa-table-empty">Lecture de la feuille…</td></tr>
                        </tbody>
                    </table>
                </div>

                <?php // Le compte était redit ici, sous le tableau, alors que l'en-tête de la
                      // feuille le porte déjà trois lignes plus haut. ?>
                <div class="soudures-pied">
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
            </tr>
        </template>

        <?php
        // Le lecteur de plan, une fenêtre par élément : on regarde le dessin pour savoir
        // quel cordon porte le repère 3. Lecture seule — rien ne se pose dessus d'ici.
        //
        // Ce qui est renseigné s'écrit sur le dessin lui-même, dans la liste soudure que le
        // bureau d'études y a imprimée : c'est detecter_lignes.php qui renvoie les valeurs
        // avec la géométrie du tableau, et le lecteur les y pose — le même rendu que le
        // plan final. Un tableau de plus à côté du dessin dirait deux fois la même chose.
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
