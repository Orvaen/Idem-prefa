<?php

/** @var PDO $db */
/** @var int $requestId */
/** @var array<string, mixed> $row */
/** @var array<int, array<int, array<string, mixed>>> $attachmentsByRequest */
/** @var array<int, array<int, array<string, mixed>>> $qmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $dmosByRequest */
/** @var bool $canManageWorkshop */
/** @var array<int, array<string, mixed>> $workshopUsers */

require_once __DIR__ . '/../includes/atelier.php';
$elements = atelierElements($db, $requestId);
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
$taken = !empty($row['prise_en_charge_atelier']);
$documentOptions = [];
foreach ($attachmentsByRequest[$requestId] ?? [] as $file) $documentOptions['plan:' . $file['key']] = $file['name'];
foreach (['qmos' => $qmosByRequest[$requestId] ?? [], 'dmos' => $dmosByRequest[$requestId] ?? []] as $kind => $files) {
    foreach ($files as $file) $documentOptions[$kind . ':' . $file['id']] = strtoupper($kind) . ' — ' . $file['nom'];
}
$activeCount = 0;
foreach ($elements as $element) foreach ($element['affectations'] as $assignment) if ($assignment['actif']) $activeCount++;
?>
<section class="atelier-section" aria-label="Travaux à affecter pour la demande <?= prefaEscape(prefaReferenceComplete($requestId)) ?>">
    <?php if ($canManageWorkshop && $taken): ?>
        <?php if (empty($isPlansIsoPage)): ?><button type="button" class="prefa-toggle atelier-open-plan-create" data-dialog="atelier-create-dialog-<?= $requestId ?>">Ajouter un plan</button><?php endif; ?>
        <dialog id="atelier-create-dialog-<?= $requestId ?>" class="atelier-plan-dialog" aria-labelledby="atelier-create-title-<?= $requestId ?>">
            <form class="atelier-action prefa-form" data-endpoint="save_atelier.php">
                <div class="atelier-plan-dialog-heading">
                    <h2 id="atelier-create-title-<?= $requestId ?>">Ajouter un plan</h2>
                    <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                </div>
                <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="element">
                <div><label for="atelier-ref-<?= $requestId ?>">Nom du plan</label><input id="atelier-ref-<?= $requestId ?>" name="reference" maxlength="80" required></div>
                <div><label for="atelier-label-<?= $requestId ?>">Description</label><input id="atelier-label-<?= $requestId ?>" name="libelle" maxlength="180" required></div>
                <?php if ($documentOptions): ?>
                    <fieldset class="atelier-document-picker">
                        <legend>Documents à associer</legend>
                        <?php foreach ($documentOptions as $token => $label): ?><label class="atelier-checkbox"><input type="checkbox" name="documents[]" value="<?= prefaEscape($token) ?>" <?= str_starts_with($token, 'plan:') && count($attachmentsByRequest[$requestId] ?? []) === 1 ? 'checked' : '' ?>> <span><?= prefaEscape($label) ?></span></label><?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
                <span class="atelier-message" role="status" aria-live="polite"></span>
                <div class="atelier-plan-dialog-footer"><button type="button" class="atelier-close-plan-edit">Annuler</button><button type="submit">Ajouter</button></div>
            </form>
        </dialog>
    <?php elseif (!$elements && !$canManageWorkshop): ?>
        <p class="atelier-muted atelier-no-plans">Le chef d’atelier n’a pas encore préparé les plans et les tâches.</p>
    <?php endif; ?>

    <?php if ($elements): ?>
        <?php $plusieursPlans = count($elements) > 1; ?>
        <div class="atelier-plan-list">
            <?php if ($plusieursPlans): ?>
                <div class="atelier-tablist" role="tablist" aria-label="Plans de la demande">
                    <?php foreach ($elements as $index => $element): ?>
                        <button type="button" class="atelier-tab" role="tab"
                            id="plan-tab-<?= (int) $element['id'] ?>"
                            aria-controls="plan-pane-<?= (int) $element['id'] ?>"
                            aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                            <?= $index === 0 ? '' : 'tabindex="-1"' ?>><?= prefaEscape($element['reference']) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

                    <?php foreach ($elements as $index => $element):
                        $active = [];
                        $history = [];
                        foreach ($element['affectations'] as $assignment) {
                            if ($assignment['actif']) $active[$assignment['type_affectation']] = $assignment;
                            else $history[] = $assignment;
                        }
                        $formId = 'atelier-manage-' . (int) $element['id'];
                        $availableDocuments = [];
                        foreach ($element['documents'] as $doc) {
                            $name = atelierDocumentName($db, $requestId, $doc['type_document'], $doc['cle_document']);
                            if ($name !== null) {
                                $endpoint = $doc['type_document'] === 'plan' ? 'download_prefa_attachment.php' : 'download_' . $doc['type_document'] . '.php';
                                $availableDocuments[] = ['name' => $name, 'url' => 'actions/' . $endpoint . '?id=' . $requestId . '&file=' . rawurlencode($doc['cle_document'])];
                            }
                        }
                    ?>
                        <article class="atelier-plan-block" <?php if ($plusieursPlans): ?>role="tabpanel" id="plan-pane-<?= (int) $element['id'] ?>" aria-labelledby="plan-tab-<?= (int) $element['id'] ?>"<?= $index === 0 ? '' : ' hidden' ?><?php else: ?>aria-label="Plan <?= prefaEscape($element['reference']) ?>"<?php endif; ?>>
                            <div class="atelier-plan-identity"><h3><?= prefaEscape($element['reference']) ?></h3><span class="atelier-row-description"><?= prefaEscape($element['libelle']) ?></span>
                                <?php if (!$availableDocuments): ?><span class="atelier-missing-file"><?= !empty($attachmentsByRequest[$requestId]) ? 'Le fichier de la demande reste à rattacher à ce plan.' : 'Aucun fichier rattaché à ce plan.' ?></span><?php endif; ?>
                                <?php if ($availableDocuments): ?>
                                    <div class="atelier-plan-files atelier-plan-files-inline">
                                        <?php foreach ($availableDocuments as $doc): ?>
                                            <a class="prefa-document-link<?= strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION)) === 'pdf' ? ' prefa-pdf-link' : '' ?>" data-name="<?= prefaEscape($doc['name']) ?>" href="<?= prefaEscape($doc['url']) ?>"><?= prefaEscape($doc['name']) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($canManageWorkshop && $taken): ?>
                                    <details class="atelier-plan-information atelier-file-management">
                                        <summary>Gérer les fichiers</summary>
                                            <form class="atelier-action prefa-form atelier-document-edit" data-endpoint="save_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="documents"><input type="hidden" name="element" value="<?= (int) $element['id'] ?>">
                                                <p class="atelier-muted">Fichiers associés à ce plan (au moins un plan / ISO) :</p>
                                                <?php $selectedDocuments = array_map(fn($doc) => $doc['type_document'] . ':' . $doc['cle_document'], $element['documents']); ?>
                                                <?php foreach ($documentOptions as $token => $label): ?>
                                                    <label class="atelier-checkbox"><input type="checkbox" name="documents[]" value="<?= prefaEscape($token) ?>" <?= in_array($token, $selectedDocuments, true) ? 'checked' : '' ?>><span><?= prefaEscape($label) ?></span></label>
                                                <?php endforeach; ?>
                                                <?php if (!$documentOptions): ?><p class="atelier-muted">Ajoutez d’abord un fichier à la demande.</p><?php endif; ?>
                                                <div class="atelier-form-actions"><button type="submit" <?= !$documentOptions ? 'disabled' : '' ?>>Enregistrer</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                            </form>
                                    </details>
                                <?php endif; ?>

                            </div>
                            <div class="atelier-plan-work-grid">
                            <?php foreach ($types as $type => $label): $assignment = $active[$type] ?? null; ?>
                                <section class="atelier-plan-work"><h4><?= prefaEscape($label) ?></h4>
                                    <?php if ($canManageWorkshop && $taken): ?>
                                        <button type="button" class="atelier-edit-assignment atelier-person-button <?= $assignment ? 'is-assigned' : '' ?>" data-form="<?= $formId ?>" data-type="<?= $type ?>" data-label="<?= prefaEscape($element['reference'] . ' · ' . $label) ?>" data-user="<?= $assignment ? (int) $assignment['id_utilisateur'] : '' ?>" aria-controls="<?= $formId ?>" aria-expanded="false" title="<?= $assignment ? 'Changer la personne affectée' : 'Affecter une personne' ?> — <?= prefaEscape($label) ?>">
                                            <?= $assignment ? prefaEscape($assignment['utilisateur_nom']) : 'Affecter' ?>
                                        </button>
                                    <?php else: ?><span><?= $assignment ? prefaEscape($assignment['utilisateur_nom']) : 'Non affecté' ?></span><?php endif; ?>

                                    <?php if ($assignment):
                                        $pct = (int) $assignment['avancement'];
                                        $state = $pct === 100 ? 'done' : ($pct > 0 ? 'progress' : 'todo');
                                        $stateLabel = ['done' => 'Terminé', 'progress' => 'En cours', 'todo' => 'À commencer'][$state];
                                    ?>
                                        <span class="atelier-work-status is-<?= $state ?>"><?= $stateLabel ?> · <?= $pct ?> %</span>
                                        <?php if ($canManageWorkshop && $taken): ?>
                                            <button type="button" class="atelier-edit-progress" data-dialog="atelier-progress-dialog-<?= $requestId ?>" data-affectation="<?= (int) $assignment['id'] ?>" data-revision="<?= (int) $assignment['revision'] ?>" data-avancement="<?= $pct ?>" data-commentaire="<?= prefaEscape((string) ($assignment['commentaire'] ?? '')) ?>" data-label="<?= prefaEscape($element['reference'] . ' · ' . $label . ' · ' . $assignment['utilisateur_nom']) ?>">Saisir l’avancement</button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($assignment && $assignment['commentaire']): ?><details class="atelier-work-report">
                                            <summary>Compte rendu</summary>
                                            <p class="atelier-note"><?= prefaEscape($assignment['commentaire']) ?></p>
                                        </details>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                            </div>
                            <?php if ($canManageWorkshop && $taken): ?>
                                <div class="atelier-plan-actions-area">
                                    <div class="atelier-plan-actions">
                                        <button type="button" class="prefa-toggle atelier-open-plan-edit" data-dialog="atelier-plan-dialog-<?= $requestId ?>" data-element="<?= (int) $element['id'] ?>" data-reference="<?= prefaEscape($element['reference']) ?>" data-description="<?= prefaEscape($element['libelle']) ?>">Modifier</button>
                                        <form class="atelier-action atelier-delete-plan" data-endpoint="save_atelier.php" data-plan-name="<?= prefaEscape($element['reference']) ?>">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                                            <input type="hidden" name="id" value="<?= $requestId ?>">
                                            <input type="hidden" name="element" value="<?= (int) $element['id'] ?>">
                                            <input type="hidden" name="operation" value="delete_element">
                                            <button type="submit" class="prefa-toggle atelier-plan-delete-button">Supprimer</button>
                                            <span class="atelier-message" role="status" aria-live="polite"></span>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php if ($canManageWorkshop && $taken): ?>
                            <div class="atelier-editor-row" hidden>

                                    <div id="<?= $formId ?>" class="atelier-manage-panel" hidden>
                                        <h5 class="atelier-manage-title">Affecter une personne</h5>
                                        <form class="atelier-action prefa-form atelier-manage-form" data-endpoint="save_atelier.php">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="assign"><input type="hidden" name="element" value="<?= (int) $element['id'] ?>"><input type="hidden" name="type" value="plan">
                                            <div><label for="atelier-user-<?= $element['id'] ?>">Personnel atelier</label><select id="atelier-user-<?= $element['id'] ?>" name="utilisateur" required>
                                                    <option value="">Sélectionner une personne</option><?php foreach ($workshopUsers as $user): ?><option value="<?= (int) $user['id'] ?>"><?= prefaEscape($user['nom']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="atelier-form-actions">
                                                <button type="submit" <?= !$workshopUsers ? 'disabled' : '' ?>>Enregistrer</button>
                                                <button type="submit" value="revoke" formnovalidate class="atelier-remove-assignment" hidden>Retirer l’affectation</button>
                                                <button type="button" class="atelier-cancel-assignment">Annuler</button>
                                                <span class="atelier-message" role="status" aria-live="polite"></span>
                                            </div>
                                        </form>
                                    </div>
                            </div>
                        <?php endif; ?>
                                <?php if ($history): ?>
                                    <details class="atelier-plan-information atelier-assignment-history">
                                        <summary>Historique des affectations (<?= count($history) ?>)</summary>
                                        <ol class="atelier-history-timeline">
                                            <?php foreach ($history as $assignment): ?>
                                                <li>
                                                    <div class="atelier-history-entry"><strong><?= prefaEscape($assignment['utilisateur_nom']) ?></strong><span class="atelier-history-kind"><?= $types[$assignment['type_affectation']] ?></span><span class="atelier-history-period">Du <?= date('d/m/Y à H:i', strtotime($assignment['date_affectation'])) ?><?php if ($assignment['date_fin_affectation']): ?> au <?= date('d/m/Y à H:i', strtotime($assignment['date_fin_affectation'])) ?><?php endif; ?></span><span class="atelier-history-ended">Affectation terminée <span class="atelier-history-accessible">(ancienne affectation)</span></span><?php if ($assignment['commentaire']): ?><p class="atelier-note"><?= prefaEscape($assignment['commentaire']) ?></p><?php endif; ?></div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    </details>
                                <?php endif; ?>
                        </article>
                    <?php endforeach; ?>


        </div>
        <?php if ($canManageWorkshop && $taken): ?>
            <dialog id="atelier-plan-dialog-<?= $requestId ?>" class="atelier-plan-dialog" aria-labelledby="atelier-plan-dialog-title-<?= $requestId ?>">
                <form class="atelier-action prefa-form" data-endpoint="save_atelier.php">
                    <div class="atelier-plan-dialog-heading">
                        <h2 id="atelier-plan-dialog-title-<?= $requestId ?>">Modifier le plan</h2>
                        <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                    </div>
                    <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="element">
                    <input type="hidden" name="operation" value="update_element">
                    <div class="form-group"><label for="atelier-dialog-name-<?= $requestId ?>">Nom du plan</label><input id="atelier-dialog-name-<?= $requestId ?>" name="reference" maxlength="80" required></div>
                    <div class="form-group"><label for="atelier-dialog-description-<?= $requestId ?>">Description</label><textarea id="atelier-dialog-description-<?= $requestId ?>" name="libelle" maxlength="180" rows="3" required></textarea></div>
                    <span class="atelier-message" role="status" aria-live="polite"></span>
                    <div class="atelier-plan-dialog-footer">
                        <button type="button" class="atelier-close-plan-edit">Annuler</button>
                        <button type="submit">Enregistrer</button>
                    </div>
                </form>
            </dialog>

            <dialog id="atelier-progress-dialog-<?= $requestId ?>" class="atelier-plan-dialog" aria-labelledby="atelier-progress-dialog-title-<?= $requestId ?>">
                <form class="atelier-action prefa-form" data-endpoint="update_atelier.php">
                    <div class="atelier-plan-dialog-heading">
                        <h2 id="atelier-progress-dialog-title-<?= $requestId ?>">Saisir l’avancement</h2>
                        <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                    </div>
                    <p class="atelier-muted atelier-progress-target"></p>
                    <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="operation" value="progress">
                    <input type="hidden" name="affectation">
                    <input type="hidden" name="revision">
                    <div class="form-group"><label for="atelier-progress-value-<?= $requestId ?>">Avancement (%)</label><input id="atelier-progress-value-<?= $requestId ?>" name="avancement" type="number" min="0" max="100" step="1" required></div>
                    <div class="form-group"><label for="atelier-progress-note-<?= $requestId ?>">Compte rendu</label><textarea id="atelier-progress-note-<?= $requestId ?>" name="commentaire" maxlength="2000" rows="3"></textarea></div>
                    <span class="atelier-message" role="status" aria-live="polite"></span>
                    <div class="atelier-plan-dialog-footer">
                        <button type="button" class="atelier-close-plan-edit">Annuler</button>
                        <button type="submit">Enregistrer</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($row['id_personnel_atelier'])): ?>
        <details class="atelier-legacy">
            <summary>Ancienne affectation de la demande</summary>
            <p class="atelier-muted"><?= prefaEscape($row['personnel_atelier_nom'] ?: 'Non renseignée') ?> était affecté à la demande entière. Cette information est conservée ; les accès actuels dépendent des plans et des tâches affectés ci-dessus.</p>
        </details>
    <?php endif; ?>
</section>
