<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');
$stmt = $db->prepare('SELECT a.*, e.reference, e.libelle, e.id_demande FROM affectation_atelier a JOIN element_atelier e ON e.id = a.id_element JOIN demande_prefabrication d ON d.id = e.id_demande WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2 ORDER BY e.id_demande DESC, e.reference, a.type_affectation');
$stmt->execute([$actor['id']]);
$assignments = $stmt->fetchAll();
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
$documentsByElement = [];
foreach ($assignments as $assignment) {
    $elementId = (int) $assignment['id_element'];
    if (isset($documentsByElement[$elementId])) continue;
    $stmt = $db->prepare('SELECT * FROM element_atelier_document WHERE id_element = ? ORDER BY id');
    $stmt->execute([$elementId]);
    $documentsByElement[$elementId] = [];
    foreach ($stmt->fetchAll() as $doc) {
        $name = atelierDocumentName($db, (int) $assignment['id_demande'], $doc['type_document'], $doc['cle_document']);
        if (!$name) continue;
        $endpoint = $doc['type_document'] === 'plan' ? 'download_prefa_attachment.php' : 'download_' . $doc['type_document'] . '.php';
        // La référence voyage dans l'adresse, pas l'identifiant technique : les cinq points
        // d'entrée de téléchargement ne lisent que « ref ». Cet écran était resté sur
        // « id » — tous ses documents répondaient « Document invalide ».
        $documentsByElement[$elementId][] = ['name' => $name, 'url' => 'actions/' . $endpoint
            . '?ref=' . rawurlencode(prefaReference((int) $assignment['id_demande']))
            . '&file=' . rawurlencode($doc['cle_document'])];
    }
}
$stmt = $db->prepare('SELECT p.*, e.reference, e.id_demande, a.type_affectation FROM pointage_atelier p JOIN affectation_atelier a ON a.id = p.id_affectation JOIN element_atelier e ON e.id = a.id_element JOIN demande_prefabrication d ON d.id = e.id_demande WHERE p.id_utilisateur = ? AND NOT (COALESCE(a.actif, 0) = 1 AND d.id_statut = 2) ORDER BY p.date_travail DESC, p.id DESC LIMIT 100');
$stmt->execute([$actor['id']]);
$times = $stmt->fetchAll();
$stmt = $db->prepare('SELECT h.*, e.reference, e.id_demande, a.type_affectation FROM avancement_atelier h
    JOIN affectation_atelier a ON a.id = h.id_affectation JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande WHERE h.id_utilisateur = ? AND NOT (COALESCE(a.actif, 0) = 1 AND d.id_statut = 2) ORDER BY h.date_saisie DESC, h.id DESC LIMIT 100');
$stmt->execute([$actor['id']]);
$recentProgress = $stmt->fetchAll();
?>
<section class="page prefa-page prefa-list atelier-page" aria-labelledby="atelier-page-title">
    <div class="">
        <header class="prefa-heading prefa-list-heading">
            <div><h1 id="atelier-page-title">Mes affectations</h1><p>Consultez vos plans et mettez à jour l’avancement de vos travaux.</p></div>
            <button type="button" class="atelier-refresh prefa-toggle">Actualiser</button>
        </header>
        <p class="prefa-list-summary"><strong><?= count($assignments) ?></strong> <?= count($assignments) === 1 ? 'affectation' : 'affectations' ?></p>
        <div class="prefa-table-scroll" role="region" aria-label="Mes affectations" tabindex="0">
            <table class="prefa-table atelier-table">
                <thead><tr><th scope="col">Demande n°</th><th scope="col">Plan / élément</th><th scope="col">Travail</th><th scope="col">Documents</th><th scope="col">Avancement</th><th scope="col">Statut</th><th scope="col">Actions</th></tr></thead>
                <?php if (!$assignments): ?><tbody><tr><td colspan="7" class="prefa-table-empty">Aucun élément ne vous est actuellement affecté.</td></tr></tbody><?php endif; ?>
                <?php foreach ($assignments as $assignment):
                    $id = (int) $assignment['id'];
                    $progress = (int) $assignment['avancement'];
                    $status = $progress === 100 ? 'Terminé' : ($progress ? 'En cours' : 'À commencer');
                    $statusClass = $progress === 100 ? 'status-2' : ($progress ? 'atelier-status-progress' : 'status-1');
                    $docs = $documentsByElement[(int) $assignment['id_element']];
                    $historyStmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
                        FROM avancement_atelier h JOIN Utilisateur u ON u.id = h.id_utilisateur
                        WHERE h.id_affectation = ? ORDER BY h.date_saisie DESC, h.id DESC");
                    $historyStmt->execute([$id]);
                    $progressHistory = $historyStmt->fetchAll();
                    $historyStmt = $db->prepare('SELECT * FROM pointage_atelier WHERE id_affectation = ? AND id_utilisateur = ? ORDER BY date_travail DESC, id DESC');
                    $historyStmt->execute([$id, $actor['id']]);
                    $timeHistory = $historyStmt->fetchAll();
                    $historyStmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
                        FROM element_atelier_etapes_historique h JOIN Utilisateur u ON u.id = h.modifie_par
                        WHERE h.id_element = ? ORDER BY h.revision DESC");
                    $historyStmt->execute([$assignment['id_element']]);
                    $stepsHistory = $historyStmt->fetchAll();
                ?>
                    <tbody class="atelier-assignment-group">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?></td>
                            <td><strong><?= prefaEscape($assignment['reference']) ?></strong><span class="atelier-row-description"><?= prefaEscape($assignment['libelle']) ?></span></td>
                            <td><?= $types[$assignment['type_affectation']] ?></td>
                            <td><?php if ($docs): ?><?= count($docs) ?> <?= count($docs) === 1 ? 'fichier' : 'fichiers' ?><?php else: ?><span class="atelier-missing-file">Fichier à associer</span><?php endif; ?></td>
                            <td><?= $progress ?> %</td>
                            <td><span class="prefa-status <?= $statusClass ?>"><?= $status ?></span></td>
                            <td><button type="button" class="prefa-toggle" aria-controls="atelier-detail-<?= $id ?>" aria-expanded="false">Mettre à jour</button></td>
                        </tr>
                        <tr id="atelier-detail-<?= $id ?>" class="prefa-detail-row" hidden>
                            <td colspan="7">
                                <div class="atelier-detail-content">
                                    <?php if ($docs): ?>
                                        <section class="atelier-files"><h2>Documents du plan</h2><div class="atelier-file-links">
                                            <?php foreach ($docs as $doc): ?><a class="prefa-document-link<?= strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION)) === 'pdf' ? ' prefa-pdf-link' : '' ?>" data-name="<?= prefaEscape($doc['name']) ?>" href="<?= prefaEscape($doc['url']) ?>"><?= prefaEscape($doc['name']) ?></a><?php endforeach; ?>
                                        </div></section>
                                    <?php else: ?>
                                        <p class="atelier-missing-file">Le chef d’atelier ou l’administrateur doit associer le fichier à ce plan pour que vous puissiez le consulter.</p>
                                    <?php endif; ?>
                                    <div class="atelier-tabs">
                                        <div class="atelier-tablist" role="tablist" aria-label="Suivi de l’affectation">
                                            <button type="button" class="atelier-tab" role="tab" id="atelier-tab-etapes-<?= $id ?>" aria-controls="atelier-pane-etapes-<?= $id ?>" aria-selected="true">Étapes</button>
                                            <button type="button" class="atelier-tab" role="tab" id="atelier-tab-avancement-<?= $id ?>" aria-controls="atelier-pane-avancement-<?= $id ?>" aria-selected="false" tabindex="-1">Avancement</button>
                                        </div>

                                        <section class="atelier-detail-panel" role="tabpanel" id="atelier-pane-etapes-<?= $id ?>" aria-labelledby="atelier-tab-etapes-<?= $id ?>">
                                            <p class="atelier-muted">Ces statuts sont partagés par les personnels affectés à cet élément et affichés dans le planning.</p>
                                            <?php $stepState = atelierElementSteps($db, (int) $assignment['id_element']); ?>
                                            <form class="atelier-action prefa-form atelier-grid" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                <input type="hidden" name="affectation" value="<?= $id ?>">
                                                <input type="hidden" name="operation" value="steps">
                                                <input type="hidden" name="etapes_revision" value="<?= $stepState['revision'] ?>">
                                                <?php foreach (atelierSteps() as $stepKey => $stepLabel): ?>
                                                    <div><label for="atelier-step-<?= $id ?>-<?= $stepKey ?>"><?= prefaEscape($stepLabel) ?></label>
                                                        <select id="atelier-step-<?= $id ?>-<?= $stepKey ?>" name="etapes[<?= $stepKey ?>]">
                                                            <?php foreach (['' => 'Non renseigné', 'OK' => 'OK', 'Non OK' => 'Non OK', 'RAS' => 'RAS'] as $value => $label): ?>
                                                                <option value="<?= prefaEscape($value) ?>" <?= ($stepState['statuts'][$stepKey] ?? ($stepKey === 'autre' ? 'RAS' : '')) === $value ? 'selected' : '' ?>><?= prefaEscape($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="atelier-form-actions"><button type="submit">Enregistrer les étapes</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                            </form>
                                            <details class="atelier-saved-history">
                                                <summary>Historique des étapes (<?= count($stepsHistory) ?>)</summary>
                                                <?php if (!$stepsHistory): ?><p class="atelier-muted">Aucune saisie enregistrée.</p><?php endif; ?>
                                                <ol class="atelier-saved-history-list">
                                                    <?php foreach ($stepsHistory as $entry): $statuses = json_decode($entry['statuts'], true); ?>
                                                        <li><span class="atelier-muted"><?= prefaEscape(atelierLocalDateTime($entry['date_saisie'])) ?> · <?= prefaEscape($entry['auteur']) ?></span>
                                                            <dl class="atelier-step-snapshot"><?php foreach (atelierSteps() as $key => $label): ?><div><dt><?= prefaEscape($label) ?></dt><dd><?= prefaEscape(($statuses[$key] ?? '') ?: 'Non renseigné') ?></dd></div><?php endforeach; ?></dl>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            </details>
                                        </section>
                                        <section class="atelier-detail-panel" role="tabpanel" id="atelier-pane-avancement-<?= $id ?>" aria-labelledby="atelier-tab-avancement-<?= $id ?>" hidden>
                                            <form class="atelier-action prefa-form atelier-grid" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="affectation" value="<?= $id ?>"><input type="hidden" name="operation" value="progress"><input type="hidden" name="revision" value="<?= (int) $assignment['revision'] ?>">
                                                <div><label for="atelier-progress-<?= $id ?>">Avancement (%)</label><input id="atelier-progress-<?= $id ?>" type="number" name="avancement" min="0" max="100" step="1" value="<?= $progress ?>" required></div>
                                                <div class="atelier-wide"><label for="atelier-note-<?= $id ?>"><?= $assignment['type_affectation'] === 'soudage' ? 'Compte rendu du suivi soudage' : 'Compte rendu d’avancement' ?></label><textarea id="atelier-note-<?= $id ?>" name="commentaire" maxlength="2000"><?= prefaEscape($assignment['commentaire'] ?? '') ?></textarea></div>
                                                <div class="atelier-form-actions"><button type="submit">Enregistrer l’avancement</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                            </form>
                                            <details class="atelier-saved-history">
                                                <summary>Historique de l’avancement (<?= count($progressHistory) ?>)</summary>
                                                <p class="atelier-history-context">Demande <?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?> · <?= prefaEscape($assignment['reference']) ?> · <?= prefaEscape($types[$assignment['type_affectation']]) ?></p>
                                                <?php if (!$progressHistory): ?><p class="atelier-muted">Aucun avancement enregistré.</p><?php endif; ?>
                                                <ol class="atelier-saved-history-list">
                                                    <?php foreach ($progressHistory as $entry): ?>
                                                        <li><strong><?= (int) $entry['avancement'] ?> %</strong> <span class="atelier-muted">· <?= prefaEscape(atelierLocalDateTime($entry['date_saisie'])) ?> · <?= prefaEscape($entry['auteur']) ?></span>
                                                            <?php if ($entry['commentaire']): ?><p class="atelier-note"><?= prefaEscape($entry['commentaire']) ?></p><?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            </details>
                                        </section>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <?php if ($recentProgress || $times): ?>
            <details class="atelier-archive-history">
                <summary>Historique des anciennes affectations</summary>
                <p class="atelier-muted">Vos dernières saisies sur les travaux qui ne figurent plus dans vos affectations en cours (100 par catégorie).</p>
                <div class="atelier-detail-columns">
                    <?php if ($recentProgress): ?>
                        <section class="atelier-detail-panel">
                            <h2>Avancements</h2>
                            <ol class="atelier-saved-history-list">
                                <?php foreach ($recentProgress as $entry): ?>
                                    <li><strong><?= (int) $entry['avancement'] ?> %</strong> <span class="atelier-muted">· <?= prefaEscape(atelierLocalDateTime($entry['date_saisie'])) ?></span>
                                        <p class="atelier-history-context">Demande <?= prefaEscape(prefaReferenceComplete((int) $entry['id_demande'])) ?> · <?= prefaEscape($entry['reference']) ?> · <?= prefaEscape($types[$entry['type_affectation']]) ?></p>
                                        <?php if ($entry['commentaire']): ?><p class="atelier-note"><?= prefaEscape($entry['commentaire']) ?></p><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </section>
                    <?php endif; ?>
                    <?php if ($times): ?>
                        <section class="atelier-detail-panel">
                            <h2>Pointages</h2>
                            <ol class="atelier-saved-history-list">
                                <?php foreach ($times as $entry): ?>
                                    <li><strong><?= (int) $entry['duree_minutes'] ?> min</strong> <span class="atelier-muted">· Travail du <?= prefaEscape(date('d/m/Y', strtotime($entry['date_travail']))) ?></span>
                                        <p class="atelier-history-context">Demande <?= prefaEscape(prefaReferenceComplete((int) $entry['id_demande'])) ?> · <?= prefaEscape($entry['reference']) ?> · <?= prefaEscape($types[$entry['type_affectation']]) ?></p>
                                        <?php if ($entry['commentaire']): ?><p class="atelier-note"><?= prefaEscape($entry['commentaire']) ?></p><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </section>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</section>
