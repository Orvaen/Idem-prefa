<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

$stmt = $db->prepare('SELECT a.id, a.type_affectation, e.reference, e.libelle, e.id_demande
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY e.id_demande DESC, e.reference, a.type_affectation');
$stmt->execute([$actor['id']]);
$assignments = $stmt->fetchAll();
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
$historique = $db->prepare('SELECT * FROM pointage_atelier WHERE id_affectation = ? AND id_utilisateur = ? ORDER BY date_travail DESC, id DESC');
?>
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
                    <th scope="col">Commentaire</th>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$assignments): ?>
                    <tbody><tr><td colspan="7" class="prefa-table-empty">Aucune affectation en cours : il n’y a rien à pointer.</td></tr></tbody>
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
                            <td><strong><?= prefaEscape($assignment['reference']) ?></strong><span class="atelier-row-description"><?= prefaEscape($assignment['libelle']) ?></span></td>
                            <td><?= prefaEscape($types[$assignment['type_affectation']]) ?></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="date" type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required aria-label="Date du travail — <?= prefaEscape($cible) ?>"></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="minutes" type="number" min="1" max="1440" step="1" required aria-label="Durée en minutes — <?= prefaEscape($cible) ?>"></td>
                            <td><input class="pointage-input" form="pointage-<?= $id ?>" name="commentaire" maxlength="500" aria-label="Commentaire du pointage — <?= prefaEscape($cible) ?>"></td>
                            <td class="pointage-actions-cell">
                                <form id="pointage-<?= $id ?>" class="atelier-action prefa-form pointage-row-form" data-endpoint="update_atelier.php">
                                    <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                                    <input type="hidden" name="affectation" value="<?= $id ?>">
                                    <input type="hidden" name="operation" value="time">
                                    <input type="hidden" name="cle_saisie" value="<?= bin2hex(random_bytes(16)) ?>">
                                    <button type="submit">Enregistrer</button>
                                    <span class="atelier-message" role="status" aria-live="polite"></span>
                                </form>
                                <button type="button" class="prefa-toggle" aria-controls="pointage-detail-<?= $id ?>" aria-expanded="false">Historique (<?= count($pointages) ?>)</button>
                            </td>
                        </tr>

                        <tr id="pointage-detail-<?= $id ?>" class="prefa-detail-row" hidden>
                            <td colspan="7">
                                <div class="atelier-detail-content">
                                    <div class="prefa-detail-heading">
                                        <div>
                                            <h2>Historique des pointages <span><?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?></span></h2>
                                            <p><?= prefaEscape($cible) ?><?php if ($minutes): ?> · total <?= intdiv($minutes, 60) ?> h <?= sprintf('%02d', $minutes % 60) ?><?php endif; ?></p>
                                        </div>
                                        <button type="button" class="prefa-detail-close" data-close-target="pointage-detail-<?= $id ?>" aria-label="Fermer le volet">✕</button>
                                    </div>

                                    <?php if (!$pointages): ?><p class="atelier-muted">Aucun pointage enregistré pour ce travail.</p><?php endif; ?>
                                    <ol class="atelier-saved-history-list">
                                        <?php foreach ($pointages as $entry): ?>
                                            <li><strong><?= (int) $entry['duree_minutes'] ?> min</strong> <span class="atelier-muted">· Travail du <?= prefaEscape(date('d/m/Y', strtotime($entry['date_travail']))) ?></span>
                                                <?php if ($entry['commentaire']): ?><p class="atelier-note"><?= prefaEscape($entry['commentaire']) ?></p><?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
