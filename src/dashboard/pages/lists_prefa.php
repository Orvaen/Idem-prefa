<?php

require_once __DIR__ . '/../includes/prefa_context.php';
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
$ongletsStatut = [[
    'valeur' => '',
    'libelle' => 'Toutes',
    'compte' => array_sum($comptesParStatut),
    'actif' => !$status,
]];
foreach ($statuses as $s) {
    $id = (int) $s['id'];
    $ongletsStatut[] = [
        'valeur' => (string) $id,
        'libelle' => $libelleOnglet[$id] ?? (string) $s['libelle'],
        'compte' => $comptesParStatut[$id] ?? 0,
        'actif' => $status === [(string) $id],
    ];
}

/** Le signe d'un statut, façon Linear : un cercle pointillé qui attend, plein quand c'est tranché. */
$iconeStatut = static function (int $statut): string {
    return match ($statut) {
        1 => '<svg class="prefa-statut-icone status-1" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.1" /></svg>',
        2 => '<svg class="prefa-statut-icone status-2" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7" fill="currentColor" />'
            . '<path d="m5 8.2 2 2 4-4.2" fill="none" stroke="var(--surface)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>',
        3 => '<svg class="prefa-statut-icone status-3" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7" fill="currentColor" />'
            . '<path d="m5.6 5.6 4.8 4.8M10.4 5.6l-4.8 4.8" stroke="var(--surface)" stroke-width="1.8" stroke-linecap="round" /></svg>',
        default => '',
    };
};

/*
|--------------------------------------------------------------------------
| Titre et description
|--------------------------------------------------------------------------
*/

if ($urgentView) {
    $pageTitle = 'Demandes urgentes';
    $pageDescription = 'Traitez en priorité les demandes signalées comme urgentes.';
} elseif ($canViewAllPrefa) {
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

                    <div class="prefa-densite" role="group" aria-label="Densité du tableau">
                        <button type="button" class="prefa-densite-choix" data-densite="confortable"
                                aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
                            <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                                <rect x="2" y="3" width="12" height="2.6" rx="1" />
                                <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
                                <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
                            </svg>
                        </button>
                        <button type="button" class="prefa-densite-choix" data-densite="compact"
                                aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
                            <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                                <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
                                <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
                                <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
                                <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
                            </svg>
                        </button>
                    </div>

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
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>

                        Actualiser

                    </button>

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

                // L'écran des demandes urgentes est un filtre figé : on ne propose pas de
                // le défaire, sinon l'entrée du menu ne voudrait plus rien dire.
                if ($urgentView) {
                    echo '<input type="hidden" name="view" value="urgent">';
                    echo '<input type="hidden" name="priority[]" value="1">';
                } else {
                    $barre .= filtreRendu('priority', 'Priorité', [
                        ['id' => 1, 'libelle' => 'Urgentes'],
                        ['id' => 0, 'libelle' => 'Normales'],
                    ], $priority);
                }

                $pose = $userId || (!$urgentView && $priority);
                echo filtresBarre($barre, (bool) $pose);
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
                                aria-pressed="<?= $onglet['actif'] ? 'true' : 'false' ?>">
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

                <table class="prefa-table">

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
                                <!-- Rangée par statut, la liste l'annonce dans le titre de
                                     chaque groupe : la colonne ne servirait qu'à le redire. -->
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
                        // Les demandes arrivent rangées — par affaire chez le demandeur, par
                        // statut ailleurs — et chaque groupe s'annonce une fois, avec son
                        // compte. Sans ce titre, on lirait une liste à plat où rien ne dit où
                        // commence le groupe suivant.
                        $cleGroupe = static fn (array $ligne): string => $vueParAffaire
                            ? trim((string) ($ligne['nom_affaire'] ?? ''))
                            : (string) (int) $ligne['id_statut'];
                        $parGroupe = [];
                        foreach ($requests as $ligne) {
                            $cle = $cleGroupe($ligne);
                            $parGroupe[$cle] = ($parGroupe[$cle] ?? 0) + 1;
                        }
                        // Une phrase sous chaque statut, pour qui découvre l'écran : ce que
                        // le groupe attend, ou ce qu'il est devenu.
                        $aideStatut = [
                            1 => $isAdmin ? 'à examiner, puis valider ou refuser' : 'en attente de validation',
                            2 => 'ouvertes à l’atelier',
                            3 => 'non retenues',
                        ];
                        $groupeCourant = null;
                        ?>

                        <?php foreach ($requests as $row): ?>
                            <?php $groupe = $cleGroupe($row); ?>
                            <?php if ($groupe !== $groupeCourant): ?>
                                <?php $groupeCourant = $groupe; ?>
                                <tbody class="prefa-groupe">
                                    <tr>
                                        <th scope="colgroup" colspan="<?= $colonnes ?>">
                                            <?php if ($vueParAffaire): ?>
                                                <span class="prefa-groupe-nom"><?= prefaEscape($groupe !== '' ? $groupe : 'Sans affaire') ?></span>
                                            <?php else: ?>
                                                <span class="prefa-groupe-nom prefa-groupe-statut status-<?= (int) $row['id_statut'] ?>"><?= $iconeStatut((int) $row['id_statut']) ?><?= prefaEscape($libelleOnglet[(int) $row['id_statut']] ?? $row['libelle']) ?></span>
                                            <?php endif; ?>
                                            <span class="prefa-groupe-compte"><?= (int) $parGroupe[$groupe] ?></span>
                                            <?php if (!$vueParAffaire && isset($aideStatut[(int) $row['id_statut']])): ?>
                                                <span class="prefa-groupe-aide"><?= prefaEscape($aideStatut[(int) $row['id_statut']]) ?></span>
                                            <?php endif; ?>
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
