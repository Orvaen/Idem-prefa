<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/nomenclature.php';
require_once __DIR__ . '/../includes/plan_viewer.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$stmt = $db->prepare('SELECT a.id, a.id_element, a.type_affectation, e.reference, e.libelle, e.id_demande
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY e.id_demande DESC, e.reference, a.type_affectation');
$stmt->execute([$actor['id']]);
$assignments = $stmt->fetchAll();
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
// La feuille ISO voyage avec le pointage : sans elle, l'historique ne dit pas sur quel
// plan le temps a été passé — et c'est la première question qu'on se pose en le relisant.
$historique = $db->prepare("SELECT p.*, doc.cle_document, doc.pages
    FROM pointage_atelier p
    LEFT JOIN element_atelier_document doc ON doc.id = p.id_document
    WHERE p.id_affectation = ? AND p.id_utilisateur = ?
    ORDER BY p.date_travail DESC, p.id DESC");

// Les feuilles ISO de chaque élément. Un élément en porte plusieurs : le pointage dit sur
// laquelle le travail a été fait, et on peut l'ouvrir pour la lire avant de pointer.
$isoParElement = [];
foreach ($assignments as $assignment) {
    $elementId = (int) $assignment['id_element'];
    if (isset($isoParElement[$elementId])) continue;
    $isoParElement[$elementId] = planDocuments($db, $elementId);
}
?>
<!-- Suggestions de Type de soudures : une aide à la saisie, pas une contrainte.
     Les ateliers n'emploient pas tous le même vocabulaire. -->
<datalist id="pointage-procedes">
    <option value="TIG"></option><option value="MIG"></option><option value="MAG"></option>
    <option value="111"></option><option value="135"></option><option value="141"></option>
</datalist>
<section class="page prefa-page prefa-list atelier-page" id="pointage-page" aria-labelledby="pointage-page-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="pointage-page-title">Pointage</h1>
                <p>Déclarez le temps passé sur chacune de vos affectations en cours.</p>
            </div>
            <button type="button" class="atelier-refresh prefa-toggle">Actualiser</button>
        </header>

        <p class="prefa-list-summary"><strong><?= count($assignments) ?></strong> <?= count($assignments) === 1 ? 'affectation à pointer' : 'affectations à pointer' ?></p>

        <div class="prefa-table-scroll" role="region" aria-label="Pointage de mes affectations" tabindex="0">
            <table class="prefa-table pointage-table">
                <thead><tr>
                    <th scope="col">Demande n°</th>
                    <th scope="col">Plan / élément</th>
                    <th scope="col">Travail</th>
                    <th scope="col">Date du travail</th>
                    <th scope="col">Durée (min)</th>
                    <th scope="col">Type de soudure</th>
                    <th scope="col">Commentaire</th>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$assignments): ?>
                    <tbody><tr><td colspan="8" class="prefa-table-empty">Aucune affectation en cours : il n’y a rien à pointer.</td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($assignments as $assignment):
                    $id = (int) $assignment['id'];
                    $historique->execute([$id, $actor['id']]);
                    $pointages = $historique->fetchAll();
                    $minutes = array_sum(array_column($pointages, 'duree_minutes'));
                    $cible = $assignment['reference'] . ' · ' . $types[$assignment['type_affectation']];
                ?>
                    <tbody class="prefa-request">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?></td>
                            <?php $iso = $isoParElement[(int) $assignment['id_element']] ?? []; ?>
                            <td>
                                <span class="pointage-plan-nom">
                                    <strong><?= prefaEscape($assignment['reference']) ?></strong>
                                    <span class="atelier-row-description"><?= prefaEscape($assignment['libelle']) ?></span>
                                </span>
                                <?php if ($iso): ?>
                                    <!-- La feuille ISO travaillée, sous le nom de l'élément : elle
                                         lui appartient, et une colonne de plus faisait déborder
                                         le tableau. -->
                                    <!-- Un seul choix, et c'est celui que le soudeur se pose :
                                         sur quel isométrique ai-je travaillé ? Le fichier et sa
                                         page ne font donc qu'une ligne de liste. Deux contrôles
                                         côte à côte — la feuille, puis un numéro de page —
                                         obligeaient à traduire soi-même « page 3 du cahier ». -->
                                    <span class="pointage-iso-cell">
                                        <select class="pointage-input pointage-iso" form="pointage-<?= $id ?>" name="document"
                                                aria-label="Isométrique travaillé — <?= prefaEscape($cible) ?>">
                                            <?php $premier = true; ?>
                                            <?php foreach ($iso as $feuille): ?>
                                                <?php for ($page = 1; $page <= (int) $feuille['pages']; $page++): ?>
                                                    <option value="<?= (int) $feuille['id'] ?>:<?= $page ?>" <?= $premier ? 'selected' : '' ?>>
<?php // Sans l'extension et avec « p. » : le nom du fichier mange la largeur, et
                                                          // c'est la page qu'on doit lire d'un coup d'œil. ?>
                                                        <?= prefaEscape(preg_replace('/\.pdf$/i', '', $feuille['nom'])) ?><?= (int) $feuille['pages'] > 1 ? ' — p. ' . $page : '' ?>
                                                    </option>
                                                    <?php $premier = false; ?>
                                                <?php endfor; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="pointage-iso-ouvrir">Ouvrir</button>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= prefaEscape($types[$assignment['type_affectation']]) ?></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="date" type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required aria-label="Date du travail — <?= prefaEscape($cible) ?>"></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="minutes" type="number" min="1" max="1440" step="1" required aria-label="Durée en minutes — <?= prefaEscape($cible) ?>"></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="type_soudure" maxlength="30" list="pointage-procedes" placeholder="TIG…" aria-label="Type de soudure — <?= prefaEscape($cible) ?>"></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="commentaire" maxlength="500" aria-label="Commentaire du pointage — <?= prefaEscape($cible) ?>"></td>
                            <td class="pointage-actions-cell">
                                <!-- Les commandes s'alignent dans une boîte à elles, et non dans
                                     la cellule : une cellule mise en « flex » quitte la grille du
                                     tableau, cesse de compter comme colonne, et la bordure de la
                                     ligne s'arrête avec elle au lieu d'aller jusqu'au bord. -->
                                <div class="pointage-actions">
                                <form id="pointage-<?= $id ?>" class="atelier-action prefa-form pointage-row-form" data-endpoint="update_atelier.php">
                                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                    <input type="hidden" name="affectation" value="<?= $id ?>">
                                    <input type="hidden" name="operation" value="time">
                                    <input type="hidden" name="cle_saisie" value="<?= bin2hex(random_bytes(16)) ?>">
                                    <button type="submit">Enregistrer</button>
                                    <span class="atelier-message" role="status" aria-live="polite"></span>
                                </form>
                                <button type="button" class="prefa-toggle" aria-controls="pointage-detail-<?= $id ?>" aria-expanded="false">Historique (<?= count($pointages) ?>)</button>
                                </div>

                                <?php if ($iso): ?>
                                    <!-- Les fenêtres de lecture des feuilles, une par ISO. Leur
                                         liste n'est pas affichée ici : la colonne « Plan ISO »
                                         sert déjà à choisir, et le bouton ouvre la bonne. -->
                                    <div class="pointage-iso-lecteurs">
                                        <?= planViewerRendu(
                                            (int) $assignment['id_element'],
                                            array_map(static function (array $doc) use ($db, $assignment): array {
                                                // Une nomenclature par page du cahier : le soudeur
                                                // tourne les pages, les coulées suivent.
                                                $reperes = [];
                                                for ($page = 1; $page <= $doc['pages']; $page++) {
                                                    $reperes[$page] = nomenclatureLignes($db, (int) $assignment['id_element'], $doc['id'], $page);
                                                }
                                                return $doc + ['reperes' => $reperes, 'empreintes' => []];
                                            }, $iso),
                                            [],
                                            false,
                                            false
                                        ) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr id="pointage-detail-<?= $id ?>" class="prefa-detail-row" hidden>
                            <td colspan="8">
                                <div class="atelier-detail-content">
                                    <div class="prefa-detail-heading">
                                        <div>
                                            <h2>Historique des pointages <span><?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?></span></h2>
                                            <p><?= prefaEscape($cible) ?><?php if ($minutes): ?> · total <?= intdiv($minutes, 60) ?> h <?= sprintf('%02d', $minutes % 60) ?><?php endif; ?></p>
                                        </div>
                                        <button type="button" class="prefa-detail-close" data-close-target="pointage-detail-<?= $id ?>" aria-label="Fermer le volet">✕</button>
                                    </div>

                                    <?php if (!$pointages): ?>
                                        <p class="atelier-muted">Aucun pointage enregistré pour ce travail.</p>
                                    <?php else: ?>
                                        <?php
                                        // Les journées, du plus récent au plus ancien. Un pointage se
                                        // relit par jour — « combien ai-je fait lundi » — bien plus
                                        // souvent que ligne à ligne.
                                        $journees = [];
                                        foreach ($pointages as $entry) {
                                            $journees[(string) $entry['date_travail']][] = $entry;
                                        }
                                        ?>
                                        <table class="pointage-histo">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Durée</th>
                                                    <th scope="col">Plan ISO</th>
                                                    <th scope="col">Type de soudure</th>
                                                    <th scope="col">Commentaire</th>
                                                </tr>
                                            </thead>
                                            <?php foreach ($journees as $jour => $lignes): ?>
                                                <?php $totalJour = array_sum(array_column($lignes, 'duree_minutes')); ?>
                                                <tbody>
                                                    <tr class="pointage-histo-jour">
                                                        <th colspan="3" scope="colgroup">
                                                            <?= prefaEscape(date('d/m/Y', strtotime($jour))) ?>
                                                            <span><?= count($lignes) ?> pointage<?= count($lignes) > 1 ? 's' : '' ?></span>
                                                        </th>
                                                        <td class="pointage-histo-total">
                                                            <?= intdiv($totalJour, 60) ?> h <?= sprintf('%02d', $totalJour % 60) ?>
                                                        </td>
                                                    </tr>
                                                    <?php foreach ($lignes as $entry): ?>
                                                        <tr>
                                                            <td class="pointage-histo-duree"><?= (int) $entry['duree_minutes'] ?> min</td>
                                                            <td><?= $entry['cle_document']
                                                                ? prefaEscape(preg_replace('/^[a-f0-9]{32}-/', '', (string) $entry['cle_document'])
                                                                    . ((int) $entry['pages'] > 1 ? ' — page ' . (int) $entry['page'] : ''))
                                                                : '<span class="atelier-muted">non précisé</span>' ?></td>
                                                            <td><?= !empty($entry['type_soudure'])
                                                                ? prefaEscape($entry['type_soudure'])
                                                                : '<span class="atelier-muted">—</span>' ?></td>
                                                            <td><?= $entry['commentaire']
                                                                ? prefaEscape($entry['commentaire'])
                                                                : '<span class="atelier-muted">—</span>' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
