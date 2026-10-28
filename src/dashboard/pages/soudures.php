<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';
require_once __DIR__ . '/../includes/vue/plan_viewer.php';
require_once __DIR__ . '/../includes/vue/filtres.php';
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
            <?php // Plus de phrase d'aide sous le titre : le panneau de droite, tant
                  // qu'aucune feuille n'est ouverte, dit déjà les étapes une à une. Dite
                  // deux fois, et à chaque visite, elle n'était plus que du bruit. ?>
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

        <?php // Le plan de travail : à gauche les plans puis les feuilles de celui qu'on a
              // ouvert, à droite la feuille travaillée. Les deux colonnes sont posées dès
              // l'arrivée — sans quoi le tableau passait de pleine largeur à la moitié au
              // moment du clic, et l'écran se tordait sous la main. ?>
        <?php // Le plan, choisi dans une liste à recherche : des onglets ne tiennent plus
              // au-delà de cinq plans, et un soudeur peut en avoir des centaines. Le bouton
              // dit le plan ouvert ; la liste, ouverte, cherche dans le nom, la demande et
              // l'affaire. ?>
        <?php $libelleFeuilles = static fn (int $n): string => $n === 0 ? 'en attente du dessin' : $n . ($n > 1 ? ' feuilles' : ' feuille'); ?>
        <div class="soudures-choix-plan" data-choix-plan>
            <span class="soudures-choix-libelle" id="soudures-choix-libelle">Plan</span>
            <button type="button" class="soudures-plan-declencheur" aria-haspopup="listbox" aria-expanded="false"
                    aria-labelledby="soudures-choix-libelle soudures-plan-courant">
                <span id="soudures-plan-courant" class="soudures-plan-courant">
                    <strong><?= prefaEscape($ouvert['reference']) ?></strong>
                    <span><?= prefaEscape(prefaReference($ouvert['demande'])) ?><?= $ouvert['affaire'] !== '' ? ' · ' . prefaEscape($ouvert['affaire']) : '' ?></span>
                </span>
                <svg class="soudures-plan-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 6.5 3.5 3.5 3.5-3.5" /></svg>
            </button>
            <span class="soudures-choix-total"><?= count($plans) ?> plan<?= count($plans) > 1 ? 's' : '' ?></span>

            <div class="soudures-plan-menu" hidden>
                <div class="soudures-plan-recherche">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input type="search" autocomplete="off" placeholder="Plan, demande, affaire…" aria-label="Rechercher un plan">
                </div>
                <ul class="soudures-plan-options" role="listbox" aria-label="Mes plans">
                    <?php foreach ($plans as $plan): ?>
                        <li role="option" class="soudures-plan-option<?= $plan['feuilles'] === 0 ? ' est-sans-dessin' : '' ?>"
                            data-element="<?= (int) $plan['element'] ?>"
                            data-cherche="<?= prefaEscape(mb_strtolower($plan['reference'] . ' ' . prefaReference($plan['demande']) . ' ' . $plan['affaire'])) ?>"
                            aria-selected="<?= $plan['element'] === $planOuvert ? 'true' : 'false' ?>" tabindex="-1">
                            <span class="soudures-plan-option-nom"><?= prefaEscape($plan['reference']) ?></span>
                            <span class="soudures-plan-option-demande"><?= prefaEscape(prefaReference($plan['demande'])) ?><?= $plan['affaire'] !== '' ? ' · ' . prefaEscape($plan['affaire']) : '' ?></span>
                            <span class="soudures-plan-option-compte"><?= $libelleFeuilles($plan['feuilles']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="soudures-plan-mot" role="status" hidden></p>
            </div>
        </div>

        <div class="soudures-plan-de-travail">
        <div class="soudures-plans">

            <?php // La recherche, sous les onglets, à gauche : elle porte sur les feuilles du
                  // plan choisi. Elle flottait à droite, entre les deux tableaux, et sortait du
                  // cadre. ?>
            <form class="prefa-filters soudures-filtres" role="search">
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

            <?php // Une section de feuilles par plan, masquée jusqu'à ce qu'on l'ouvre. Elles
                  // sont toutes rendues : les montrer coûte un attribut, et aller les
                  // chercher coûterait un aller-retour au serveur à chaque changement. ?>
            <?php // Le seul plan ouvert : ses feuilles sont lues ici, les autres ne le sont pas.
                  $plan = $ouvert;
                  $plan['feuilles'] = $ouvert['feuilles_liste'];
                  $demande = prefaReferenceComplete($plan['demande']);
                  $premiere = $premiereFeuille($ouvert);
            ?>
                <section class="soudures-feuilles-section" data-element="<?= (int) $plan['element'] ?>"
                         aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>">
                    <?php // Pas de titre ici : la ligne du plan, juste au-dessus, porte déjà son
                          // nom, sa demande et son nombre de feuilles — et elle est teintée
                          // tant qu'il est ouvert. « Feuilles de Plan de test » redisait tout
                          // cela une seconde fois, et volait une ligne de plus dès que la
                          // fenêtre se resserrait. Le nom reste pour qui lit l'écran à la
                          // voix : il est sur l'étiquette de la section.
                          //
                          // Ne reste que la barre d'outils : la recherche vient s'y poser,
                          // devant le bouton du dessin. ?>
                    <?php // Le dessin du plan, seul : la demande et l'affaire sont déjà sur le
                          // sélecteur, juste au-dessus. Les redire ici faisait lire deux fois
                          // la même ligne. Sans dessin, la barre n'a rien à porter : on ne la
                          // pose pas. ?>
                    <?php if ($premiere): ?>
                        <div class="soudures-plan-outils">
                            <button type="button" class="soudures-ouvrir-plan soudures-voir-dessin"
                                    data-repere="<?= $plan['element'] ?>:<?= $premiere['document'] ?>:1"
                                    title="Voir le dessin de ce plan">Voir le dessin</button>
                        </div>
                    <?php endif; ?>

                    <?php if (!$plan['feuilles']): ?>
                        <?php // Le soudeur n'a pas l'écran « Plans / ISO » : lui dire où le chef
                              // clique ne l'avance à rien. Il lui faut savoir que ce n'est pas
                              // prêt, et vers qui se tourner. ?>
                        <p class="soudures-plan-vide">Ce plan n’a pas encore de feuilles. Demandez au chef d’atelier de les ajouter.</p>
                    <?php else: ?>
                        <div class="prefa-table-scroll" role="region" aria-label="Feuilles de <?= prefaEscape($plan['reference']) ?>" tabindex="0">
                            <table class="prefa-table soudures-feuilles">
                                <thead><tr>
                                    <th scope="col">Feuille</th>
                                    <th scope="col" class="prefa-nombre">Cordons</th>
                                    <th scope="col">État</th>
                                    <th scope="col"><span class="sr-only">Dessin</span></th>
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
                                            data-reference="<?= prefaEscape(prefaReference($plan['demande'])) ?>"
                                            data-element="<?= (int) $plan['element'] ?>"
                                            data-etat="<?= $etat['cle'] ?>"
                                            data-cherche="<?= prefaEscape(mb_strtolower($demande . ' ' . $plan['reference'] . ' ' . $plan['affaire'] . ' ' . $nom)) ?>">
                                            <?php // Le nom ouvre la feuille : c'est le geste de la liste, et un
                                                  // bouton s'atteint au clavier. « Renseigner », répété sur
                                                  // quinze lignes, ne disait rien de plus. ?>
                                            <td class="soudures-feuille-nom">
                                                <button type="button" class="soudures-renseigner">
                                                    <strong><?= prefaEscape($nom) ?></strong>
                                                    <?php if ($feuille['numero'] !== '' && $feuille['pages'] > 1): ?>
                                                        <span>p. <?= $feuille['page'] ?> / <?= $feuille['pages'] ?></span>
                                                    <?php endif; ?>
                                                </button>
                                            </td>
                                            <?php $partFaite = soudureAtelierPartFeuille($feuille['faites'], $feuille['imprimees']); ?>
                                            <td class="prefa-nombre soudures-feuille-cordons">
                                                <span data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></span>
                                                <?php if ($feuille['imprimees']): ?><span class="soudures-barre" aria-hidden="true"><span style="width: <?= $partFaite ?>%"></span></span><?php endif; ?>
                                            </td>
                                            <td class="soudures-feuille-etat"><span class="prefa-status <?= $etat['classe'] ?>" data-etat><?= prefaEscape($etat['libelle']) ?></span></td>
                                            <td class="soudures-feuille-actions">
                                                <?php // Le dessin à SA page : une icône, qui ne paraît qu'au survol de
                                                      // la ligne — et au clavier. ?>
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

                    <?php // Le filtre peut tout cacher dans ce plan : le mot le dit, plutôt que
                          // de laisser un tableau sans lignes sous ses en-têtes. ?>
                    <p class="prefa-table-empty soudures-aucune" data-aucune hidden>Aucune feuille de ce plan ne correspond à cette recherche.</p>
                </section>
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
            <?php // Tant qu'aucune feuille n'est choisie, la colonne dit comment se sert
                  // l'écran, en trois gestes. Elle était vide — ou absente —, et rien ne disait
                  // ce qu'on était venu faire devant une liste de feuilles. ?>
            <div class="soudures-invite">
                <svg class="soudures-invite-icone" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 16c1.4 0 1.4-2 2.8-2s1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.5 2"/><path d="M3.5 20h17"/><path d="M8 9.5 6.5 6M12 8.5V4.5M16 9.5 17.5 6"/></svg>
                <p class="soudures-invite-titre">Aucune feuille ouverte</p>
                <ol class="soudures-invite-etapes">
                    <li><span>1</span>Choisissez une feuille dans la liste.</li>
                    <li><span>2</span>Pour chaque cordon soudé, notez la date et le procédé.</li>
                    <li><span>3</span>Enregistrez : le chef d’atelier valide ensuite.</li>
                </ol>
            </div>
            <div class="soudures-saisie-contenu">
            <?php // L'en-tête des volets : ce que c'est (« Feuille »), laquelle, et d'où elle
                  // vient ; les commandes à droite, sur la ligne du titre. Elles tombaient
                  // sous le texte, à gauche, et la croix flottait seule à côté d'un bouton. ?>
            <div class="prefa-detail-heading soudures-saisie-entete">
                <div class="soudures-saisie-identite">
                    <p class="soudures-saisie-surtitre">Feuille</p>
                    <h2 id="soudures-saisie-titre"><span data-feuille-titre></span></h2>
                    <p class="soudures-saisie-parcours"><span data-feuille-plan></span><span data-feuille-compte></span></p>
                    <?php // Où en est la feuille auprès du chef. Le message d'enregistrement le
                          // disait une fois puis s'effaçait : on lisait « 1 soudure enregistrée »,
                          // en vert, et plus rien ensuite ne distinguait une ligne validée d'une
                          // ligne qui attend encore. ?>
                    <p class="soudures-attente" data-feuille-attente hidden></p>
                </div>
                <div class="soudures-saisie-actions">
                    <button type="button" class="soudures-ouvrir-plan" data-feuille-plan-bouton data-repere=""
                            title="Voir le dessin pour retrouver le repère d’un cordon">Voir le dessin</button>
                    <button type="button" class="prefa-detail-close" data-fermer-saisie aria-label="Fermer la feuille" title="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </div>

            <form id="soudures-form" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                <div class="prefa-table-scroll" role="region" aria-label="Cordons de la feuille travaillée" tabindex="0">
                    <table class="prefa-table soudures-cordons">
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
