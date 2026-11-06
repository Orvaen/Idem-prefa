<?php

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste.php';
// return et non exit : cette page est aussi incluse par dashboard.php, qui a encore le
// pied de page à écrire. « return » au premier niveau d'un fichier inclus rend la main à
// celui qui l'inclut, et termine le script quand c'est le navigateur qui l'appelle.
if ($isWorkshopPersonnel) {
    require __DIR__ . '/atelier.php';
    return;
}
require_once __DIR__ . '/../partials/prefa_list_data.php';
require_once __DIR__ . '/../includes/vue/filtres.php';

// Les chefs d'atelier ne changent pas d'une ligne à l'autre : une seule requête,
// et seulement pour l'administrateur, qui est le seul à désigner quelqu'un d'autre.
$chiefs = $isAdmin
    ? $db->query("SELECT id, TRIM(CONCAT(prenom, ' ', name)) AS nom FROM Utilisateur WHERE id_role = 2 ORDER BY prenom, name, id")->fetchAll()
    : [];

/*
|--------------------------------------------------------------------------
| Valeurs par défaut
|--------------------------------------------------------------------------
*/

$partial = $partial ?? false;
$pendingTotal = $pendingTotal ?? 0;
$urgentPending = $urgentPending ?? 0;
$total = $total ?? 0;

$q = $q ?? '';
$userId = $userId ?? '';
$status = $status ?? '';
$priority = $priority ?? '';

$users = $users ?? [];
$statuses = $statuses ?? [];
$requests = $requests ?? [];
$comptesParStatut = $comptesParStatut ?? [];
$enRetardTotal = $enRetardTotal ?? 0;

/*
|--------------------------------------------------------------------------
| Onglets de statut
|--------------------------------------------------------------------------
|
| Le statut n'est plus une pastille de filtre parmi les autres : c'est la façon dont on
| découpe la liste, et il se lit en onglets au-dessus du tableau, chacun avec son compte.
| Un seul onglet à la fois — « Toutes » quand aucun statut n'est retenu. Un ancien lien
| qui en retient plusieurs reste valable : aucun onglet n'est alors marqué.
*/

$libelleOnglet = [1 => 'En attente', 2 => 'Validées', 3 => 'Refusées'];

// Ce que chaque statut attend, ou ce qu'il est devenu : une phrase pour qui découvre
// l'écran. Elle s'écrivait sous chaque titre de groupe ; elle passe en infobulle de
// l'onglet, qui sert aussi de légende aux points de couleur des lignes.
$aideStatut = [
    1 => $isAdmin ? 'À examiner, puis valider ou refuser' : 'En attente de validation',
    2 => 'Ouvertes à l’atelier',
    3 => 'Non retenues',
];

$ongletsStatut = [[
    'valeur' => '',
    'libelle' => 'Toutes',
    'compte' => array_sum($comptesParStatut),
    'actif' => !$status,
    'aide' => '',
]];
foreach ($statuses as $s) {
    $id = (int) $s['id'];
    $ongletsStatut[] = [
        'valeur' => (string) $id,
        'libelle' => $libelleOnglet[$id] ?? (string) $s['libelle'],
        'compte' => $comptesParStatut[$id] ?? 0,
        'actif' => $status === [(string) $id],
        'aide' => $aideStatut[$id] ?? '',
    ];
}

/*
|--------------------------------------------------------------------------
| Titre et description
|--------------------------------------------------------------------------
*/

if ($canViewAllPrefa) {
    $pageTitle = 'Demandes de préfabrication';
    $pageDescription = 'Vérifiez les demandes en attente avant de les valider ou de les refuser.';
} else {
    $pageTitle = 'Mes demandes';
    $pageDescription = 'Vos demandes de préfabrication, rangées par affaire.';
}

if ($isWorkshopChief) {
    // La prise en charge n'existe plus : une demande validée est ouverte à l'atelier, et
    // plusieurs chefs y travaillent.
    $pageDescription = 'Suivez les demandes et préparez les plans de celles qui sont validées.';
}
if ($isWorkshopPersonnel) {
    $pageTitle = 'Mes plans';
    $pageDescription = 'Consultez les demandes validées qui vous sont affectées.';
}

?>

<?php if (!$partial): ?>

    <?php // « Mes demandes » n'est pas la liste de tout le monde : quatre colonnes au
          // lieu de neuf, et de la place pour les lire. La classe sert de crochet. ?>
    <?php // La densité est posée dès le rendu, et non par le script au chargement : sinon
          // la page s'affichait en confortable puis se resserrait sous les yeux. Le script
          // ne corrige que si la personne a retenu l'autre. ?>
    <section class="page prefa-page prefa-list<?= $vueParAffaire ? ' prefa-mes-demandes' : '' ?>" data-densite="confortable">

        <div class="prefa-shell">

            <!-- En-tête -->
            <div class="prefa-heading prefa-list-heading">

                <div>
                    <h1><?= prefaEscape($pageTitle) ?></h1>
                    <p><?= prefaEscape($pageDescription) ?></p>
                </div>

                <?php // Deux façons de lire le même tableau. Ce n'est pas un filtre — rien
                      // n'entre ni ne sort de la liste — donc le choix vit hors du formulaire
                      // de recherche, à côté d'« Actualiser » : ce sont tous deux des
                      // commandes de la vue, pas du contenu. ?>
                <div class="prefa-vue-outils">

                    <?= listeDensite() ?>

                    <button type="button" id="refresh-prefa">

                    <svg
                        class="icon"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.75"
                        stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36L21 8" /><path d="M21 3v5h-5" />
                    </svg>

                        <span class="outil-libelle">Actualiser</span>

                    </button>

                    <?php // L'action de l'écran, pour qui dépose : la demande se crée d'ici, dans
                          // une fenêtre au-dessus de la liste — la même que « Modifier ». Elle
                          // avait son entrée à part dans le menu, loin du tableau qu'elle remplit. ?>
                    <?php if ($isRequester): ?>
                        <button type="button" class="prefa-nouvelle-demande" data-nouvelle-demande>
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                            Nouvelle demande
                        </button>
                    <?php endif; ?>

                </div>

            </div>

            <!-- Filtres : une pastille par critère, qui ouvre sa liste à cocher. Le
                 balisage vient de includes/vue/filtres.php ; le rafraîchissement sérialise ce
                 formulaire, il n'a donc rien de particulier à savoir. -->
            <form
                id="prefa-filters"
                class="prefa-filters"
                role="search">

                <!-- La recherche n'est pas un filtre à choix : elle garde son champ, en
                     tête de barre, à l'échelle des barres d'outils. -->
                <div class="filtres-recherche">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input
                        id="prefa-search"
                        type="search"
                        name="q"
                        value="<?= prefaEscape($q) ?>"
                        aria-label="Rechercher une demande"
                        placeholder="N° de demande, affaire…">
                </div>

                <?php
                $barre = '';

                if ($canViewAllPrefa) {
                    $barre .= filtreRendu('user', 'Utilisateur', array_map(
                        static fn (array $u): array => [
                            'id' => (int) $u['id'],
                            'libelle' => trim(($u['prenom'] ?? '') . ' ' . ($u['name'] ?? '')),
                        ],
                        $users
                    ), $userId, ['aucun' => 'Aucun utilisateur à ce nom.']);
                }

                // Le statut se choisit dans les onglets, au-dessus du tableau. Il reste un
                // champ du formulaire pour que le rafraîchissement l'emporte sans rien savoir
                // des onglets.
                foreach ($status ?: [''] as $statutChoisi) {
                    echo '<input type="hidden" name="status[]" class="prefa-statut-choisi" value="' . prefaEscape($statutChoisi) . '">';
                }

                // Les urgentes n'ont plus d'écran à elles : c'est ce filtre qui les isole, et
                // la tuile « Urgentes » de l'accueil arrive ici avec lui déjà posé.
                $barre .= filtreRendu('priority', 'Priorité', [
                    ['id' => 1, 'libelle' => 'Urgentes'],
                    ['id' => 0, 'libelle' => 'Normales'],
                ], $priority);

                echo filtresBarre($barre, $userId || $priority);
                ?>

            </form>

        <?php endif; ?>


        <!-- Résultats -->
        <div id="prefa-results">

            <?php if ($canViewAllPrefa): ?>
                <span
                    id="prefa-urgent-total"
                    data-count="<?= (int) $urgentPending ?>"
                    hidden></span>
            <?php endif; ?>

            <?php // Les onglets remplacent la phrase « 1 en attente · 0 urgente · 2 affichées » :
                  // chaque compte est posé sur ce qu'il compte, et un clic suffit à n'afficher
                  // que lui. Ils vivent dans #prefa-results pour que leurs comptes suivent la
                  // recherche à chaque rafraîchissement. ?>
            <div class="prefa-onglets-barre">

                <div class="prefa-onglets" role="group" aria-label="Statut des demandes">
                    <?php foreach ($ongletsStatut as $onglet): ?>
                        <button type="button"
                                class="prefa-onglet"
                                data-statut="<?= prefaEscape($onglet['valeur']) ?>"
                                <?= $onglet['aide'] !== '' ? 'title="' . prefaEscape($onglet['aide']) . '"' : '' ?>
                                aria-pressed="<?= $onglet['actif'] ? 'true' : 'false' ?>">
                            <?php // Le point du statut : les onglets sont la légende de ceux qui
                                  // ouvrent les lignes. Muet ici, le mot est juste à côté. ?>
                            <?php if ($onglet['valeur'] !== ''): ?>
                                <span class="prefa-point status-<?= (int) $onglet['valeur'] ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?= prefaEscape($onglet['libelle']) ?>
                            <span class="prefa-onglet-compte"><?= (int) $onglet['compte'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php // Ce sur quoi on agit le jour même : les urgentes en attente, les
                      // échéances dépassées. Un nombre à zéro reste sourd ; il ne prend la
                      // teinte de l'alerte que s'il y a quelque chose à faire. ?>
                <p class="prefa-list-summary">
                    <?php if ($canViewAllPrefa): ?>
                        <span class="<?= $urgentPending > 0 ? 'est-a-traiter' : '' ?>">
                            <strong class="prefa-list-summary-urgent"><?= (int) $urgentPending ?></strong>
                            urgente<?= $urgentPending > 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                    <span class="<?= $enRetardTotal > 0 ? 'est-a-traiter' : '' ?>" title="Fin prévue dépassée sur une demande ni refusée ni fabriquée (PV « Fin de fabrication et conformité » pas encore à « Fait »).">
                        <strong><?= (int) $enRetardTotal ?></strong> en retard
                    </span>
                </p>

            </div>


            <!-- Tableau -->
            <div
                class="prefa-table-scroll"
                role="region"
                aria-label="Liste des demandes"
                tabindex="0">

                <?php // « prefa-table-points » : chaque ligne s'ouvre sur le point de son
                      // statut, et l'en-tête de la première colonne prend le même retrait.
                      // « prefa-table-demandes » : la ligne entière ouvre la fiche
                      // (prefa-interactions.js) — sur cet écran seulement. ?>
                <table class="prefa-table prefa-table-demandes<?= $vueParAffaire ? '' : ' prefa-table-points' ?>">

                    <thead>

                        <tr>

                            <th scope="col">
                                Demande n°
                            </th>

                            <?php if (!$vueParAffaire): ?>
                                <!-- Rangée sous le nom de son affaire, une demande n'a pas
                                     besoin de le répéter à chaque ligne.

                                     Cette colonne porte la largeur libre du tableau (voir la
                                     feuille) : c'est elle qui pousse les mesures contre le
                                     bord droit et rassemble l'identité à gauche. -->
                                <th scope="col" class="prefa-affaire">
                                    Affaire
                                </th>
                            <?php endif; ?>

                            <th scope="col">
                                Fin prévue
                            </th>

                            <?php // Plus de colonne « Priorité » : elle écrivait « Normale » trois
                                  // lignes sur quatre pour ne rien annoncer, et l'urgence —
                                  // la seule information de la colonne — ouvre maintenant la
                                  // ligne par un signe. Le filtre « Priorité » de la barre,
                                  // lui, reste : c'est là qu'on la cherche. ?>
                            <?php if (!$vueParAffaire): ?>
                                <th scope="col" class="prefa-nombre">
                                    Pouces
                                </th>

                                <th scope="col" class="prefa-nombre">
                                    Temps atelier
                                </th>

                                <th scope="col">
                                    Demandeur
                                </th>
                            <?php endif; ?>

                            <?php if ($vueParAffaire): ?>
                                <!-- Rangée par statut, la liste le dit par le point de couleur
                                     qui ouvre chaque ligne : la colonne ne servirait qu'à le
                                     redire. -->
                                <th scope="col">
                                    Statut
                                </th>
                            <?php endif; ?>

                            <th scope="col">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <?php
                    // Posé avant les deux branches : la ligne « aucune demande » et le volet
                    // de détail s'en servent pour leur colspan.
                    //
                    // Quatre colonnes sur ses propres demandes — n°, échéance, état, actions —
                    // contre neuf sur la liste générale, où l'affaire et le demandeur varient
                    // d'une ligne à l'autre et où l'atelier a besoin des pouces.
                    $colonnes = $vueParAffaire ? 4 : 7;
                    ?>

                    <?php if (!empty($requests)): ?>

                        <?php
                        // Chez le demandeur, les demandes arrivent rangées par affaire, et
                        // chaque affaire s'annonce une fois, avec son compte : les lignes ne
                        // répètent pas son nom.
                        //
                        // Ailleurs, elles sont rangées par statut, sans titre de groupe. Les
                        // titres — « Validées 1 ouvertes à l'atelier » — coupaient la liste en
                        // tranches pour redire ce que les onglets comptent déjà : le statut se
                        // lit au point de couleur qui ouvre la ligne, et les onglets en sont
                        // la légende.
                        $parAffaire = [];
                        if ($vueParAffaire) {
                            foreach ($requests as $ligne) {
                                $cle = trim((string) ($ligne['nom_affaire'] ?? ''));
                                $parAffaire[$cle] = ($parAffaire[$cle] ?? 0) + 1;
                            }
                        }
                        $groupeCourant = null;
                        ?>

                        <?php foreach ($requests as $row): ?>
                            <?php $groupe = trim((string) ($row['nom_affaire'] ?? '')); ?>
                            <?php if ($vueParAffaire && $groupe !== $groupeCourant): ?>
                                <?php $groupeCourant = $groupe; ?>
                                <tbody class="prefa-groupe">
                                    <tr>
                                        <th scope="colgroup" colspan="<?= $colonnes ?>">
                                            <span class="prefa-groupe-nom"><?= prefaEscape($groupe !== '' ? $groupe : 'Sans affaire') ?></span>
                                            <span class="prefa-groupe-compte"><?= (int) $parAffaire[$groupe] ?></span>
                                        </th>
                                    </tr>
                                </tbody>
                            <?php endif; ?>
                            <?php
                            require __DIR__
                                . '/../partials/prefa_list_row.php';
                            ?>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tbody>

                            <tr>

                                <td
                                    class="prefa-table-empty"
                                    colspan="<?= $colonnes ?>">
                                    Aucune demande trouvée.
                                </td>

                            </tr>

                        </tbody>

                    <?php endif; ?>

                </table>

            </div>

        </div>


        <?php if (!$partial): ?>

        </div>

    </section>

<?php endif; ?>
