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
require_once __DIR__ . '/../includes/avancement.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/nomenclature.php';
require_once __DIR__ . '/../includes/soudure.php';
require_once __DIR__ . '/../includes/plan_viewer.php';
$elements = atelierElements($db, $requestId);
// Les coulées des certificats de la demande : une seule lecture pour tous ses plans.
$couleesDisponibles = nomenclatureCouleesDisponibles($db, $requestId);
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
// Une demande validée est ouverte au travail de l'atelier : il n'y a plus d'étape de
// prise en charge à franchir avant d'y préparer des plans.
$taken = true;
$documentOptions = [];
foreach ($attachmentsByRequest[$requestId] ?? [] as $file) $documentOptions['plan:' . $file['key']] = $file['name'];
foreach (['qmos' => $qmosByRequest[$requestId] ?? [], 'dmos' => $dmosByRequest[$requestId] ?? []] as $kind => $files) {
    foreach ($files as $file) $documentOptions[$kind . ':' . $file['id']] = strtoupper($kind) . ' — ' . $file['nom'];
}
$activeCount = 0;
$affectationsActives = [];
foreach ($elements as $element) foreach ($element['affectations'] as $assignment) if ($assignment['actif']) {
    $activeCount++;
    $affectationsActives[] = (int) $assignment['id'];
}
// Les avancements déclarés par l'atelier et pas encore tranchés, toute la demande en une
// lecture : un plan porte trois travaux, et les interroger un par un ferait une requête
// par case.
$avancementsAValider = avancementEnAttentePlusieurs($db, $affectationsActives);
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
                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="element">
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
                        // Les feuilles ISO se montrent dans leur propre section, avec de quoi
                        // les ouvrir et les travailler : on ne garde ici que les autres
                        // documents — QMOS, DMOS, matériel — qui n'ont qu'à se télécharger.
                        $availableDocuments = [];
                        $documentsAnnexes = [];
                        foreach ($element['documents'] as $doc) {
                            $name = atelierDocumentName($db, $requestId, $doc['type_document'], $doc['cle_document']);
                            if ($name === null) continue;
                            $endpoint = $doc['type_document'] === 'plan' ? 'download_prefa_attachment.php' : 'download_' . $doc['type_document'] . '.php';
                            // La référence voyage dans l'adresse, pas l'identifiant technique.
                            $lien = ['name' => $name, 'type' => $doc['type_document'],
                                'url' => 'actions/' . $endpoint . '?ref=' . rawurlencode(prefaReference($requestId)) . '&file=' . rawurlencode($doc['cle_document'])];
                            $availableDocuments[] = $lien;
                            if ($doc['type_document'] !== 'plan') $documentsAnnexes[] = $lien;
                        }
                        // Le dépliant du bas ne propose que ce qu'il gère : les plans se
                        // déposent et se retirent feuille par feuille, plus bas dans le volet.
                        $optionsAnnexes = array_filter($documentOptions,
                            static fn (string $token): bool => !str_starts_with($token, 'plan:'), ARRAY_FILTER_USE_KEY);
                    ?>
                        <article class="atelier-plan-block" <?php if ($plusieursPlans): ?>role="tabpanel" id="plan-pane-<?= (int) $element['id'] ?>" aria-labelledby="plan-tab-<?= (int) $element['id'] ?>"<?= $index === 0 ? '' : ' hidden' ?><?php else: ?>aria-label="Plan <?= prefaEscape($element['reference']) ?>"<?php endif; ?>>
                            <div class="atelier-plan-identity"><h3><?= prefaEscape($element['reference']) ?></h3><span class="atelier-row-description"><?= prefaEscape($element['libelle']) ?></span>
                                <?php if (!$availableDocuments): ?><span class="atelier-missing-file"><?= !empty($attachmentsByRequest[$requestId]) ? 'Le fichier de la demande reste à rattacher à ce plan.' : 'Aucun fichier rattaché à ce plan.' ?></span><?php endif; ?>
                            </div>

                            <?php if ($canManageWorkshop && $taken): ?>
                                <!-- Ces deux commandes portent sur le plan lui-même — son nom,
                                     sa description, son existence — et non sur ses feuilles.
                                     Elles se tiennent donc contre son titre, et le disent :
                                     « Modifier » et « Supprimer » tout court laissaient croire
                                     qu'on allait toucher aux fichiers. -->
                                <div class="atelier-plan-actions-area">
                                    <div class="atelier-plan-actions">
                                        <button type="button" class="prefa-toggle atelier-open-plan-edit" data-dialog="atelier-plan-dialog-<?= $requestId ?>" data-element="<?= (int) $element['id'] ?>" data-reference="<?= prefaEscape($element['reference']) ?>" data-description="<?= prefaEscape($element['libelle']) ?>">Renommer</button>
                                        <form class="atelier-action atelier-delete-plan" data-endpoint="save_atelier.php" data-plan-name="<?= prefaEscape($element['reference']) ?>">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                            <input type="hidden" name="id" value="<?= $requestId ?>">
                                            <input type="hidden" name="element" value="<?= (int) $element['id'] ?>">
                                            <input type="hidden" name="operation" value="delete_element">
                                            <button type="submit" class="prefa-toggle atelier-plan-delete-button">Supprimer le plan</button>
                                            <span class="atelier-message" role="status" aria-live="polite"></span>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <h4 class="atelier-plan-titre-section">Affectations</h4>
                            <?php
                            // Les soudures enregistrées sur ce plan : le dénominateur de
                            // l'avancement, quand il y en a. Lu une fois pour les trois
                            // travaux, qui portent tous sur le même plan.
                            //
                            // La phrase qui dit d'où sort ce total voyage avec le bouton : un
                            // cahier de quinze pages réunit les soudures de ses quinze feuilles,
                            // et « 105 » sans cette précision ne se comprend pas.
                            $prevu = avancementSouduresPrevues($db, (int) $element['id']);
                            $soudureTotal = $prevu['prevues'];
                            $soudureOrigine = $soudureTotal > 0 ? avancementDenominateurPhrase($prevu) : '';
                            ?>
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
                                        // Ce que la personne affectée annonce, et que personne n'a encore reconnu.
                                        $declaration = $avancementsAValider[(int) $assignment['id']] ?? null;
                                    ?>
                                        <span class="atelier-work-status is-<?= $state ?>"><?= $stateLabel ?> · <?= $pct ?> %</span>
                                        <?php if ($canManageWorkshop && $taken): ?>
                                            <button type="button" class="atelier-edit-progress" data-dialog="atelier-progress-dialog-<?= $requestId ?>" data-affectation="<?= (int) $assignment['id'] ?>" data-revision="<?= (int) $assignment['revision'] ?>" data-avancement="<?= $pct ?>" data-soudures="<?= $soudureTotal ?>" data-origine="<?= prefaEscape($soudureOrigine) ?>" data-commentaire="<?= prefaEscape((string) ($assignment['commentaire'] ?? '')) ?>" data-label="<?= prefaEscape($element['reference'] . ' · ' . $label . ' · ' . $assignment['utilisateur_nom']) ?>">Saisir l’avancement</button>
                                        <?php endif; ?>
                                        <?php // La bande vient après le bouton : elle occupe toute la largeur, et
                                              // placée avant elle, elle aurait renvoyé le bouton sur la ligne suivante.
                                        ?>
                                        <?php if ($declaration && $canManageWorkshop && $taken): ?>
                                            <!-- Le chiffre annoncé se tient à part du chiffre reconnu, et ne
                                                 se confond pas avec lui : c'est tout l'objet de la validation. -->
                                            <div class="atelier-declaration">
                                                <p class="atelier-declaration-titre">Avancement déclaré, à valider</p>
                                                <p class="atelier-declaration-chiffre"><?= prefaEscape(avancementPhrase($declaration)) ?></p>
                                                <p class="atelier-muted"><?= prefaEscape($declaration['auteur']) ?> · <?= prefaEscape(atelierLocalDateTime($declaration['date_saisie'])) ?></p>
                                                <?php if ($declaration['commentaire']): ?><p class="atelier-note"><?= prefaEscape($declaration['commentaire']) ?></p><?php endif; ?>
                                                <form class="atelier-action atelier-declaration-actions" data-endpoint="update_atelier.php">
                                                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                    <input type="hidden" name="id" value="<?= $requestId ?>">
                                                    <input type="hidden" name="operation" value="valider">
                                                    <input type="hidden" name="affectation" value="<?= (int) $assignment['id'] ?>">
                                                    <input type="hidden" name="declaration" value="<?= (int) $declaration['id'] ?>">
                                                    <button type="submit" name="decision" value="valider" title="L’avancement du plan prendra ce chiffre.">Valider</button>
                                                    <button type="submit" name="decision" value="refuser" class="bouton-discret" title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>
                                                    <span class="atelier-message" role="status" aria-live="polite"></span>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                        <?php // Un chiffre déclaré n'est pas un fait : il ne sort pas d'entre la
                                              // personne affectée et son chef avant d'être reconnu. Le reste de
                                              // l'application ne voit donc que l'avancement de l'affectation. ?>
                                    <?php endif; ?>

                                    <?php if ($assignment && $assignment['commentaire']): ?><details class="atelier-work-report">
                                            <summary>Compte rendu</summary>
                                            <p class="atelier-note"><?= prefaEscape($assignment['commentaire']) ?></p>
                                        </details>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                            </div>

                            <?php
                            // Ce que l'atelier a soudé sur ce plan, en une ligne. La liste
                            // entière vit dans l'onglet « Soudures » : elle y est avec celles
                            // des autres plans, et c'est là qu'on valide. La répéter ici
                            // ferait deux endroits pour le même chiffre.
                            $faitesIso = avancementSouduresFaites($db, (int) $element['id']);
                            ?>
                            <?php if ($faitesIso || $soudureTotal): ?>
                                <p class="atelier-soudures-renvoi">
                                    <span class="atelier-plan-titre-section">Soudures</span>
                                    <strong><?= $faitesIso ?><?= $soudureTotal ? ' / ' . $soudureTotal : '' ?></strong>
                                    <span class="atelier-muted">cordon<?= $faitesIso > 1 ? 's' : '' ?> renseigné<?= $faitesIso > 1 ? 's' : '' ?><?= $soudureTotal ? ' sur ' . $soudureTotal . ' prévu' . ($soudureTotal > 1 ? 's' : '') . ' au plan' : '' ?></span>
                                    <button type="button" class="atelier-vers-suivi-soudures" title="Ouvre l’écran « Soudures », où l’on voit les cordons et où l’on valide">Voir les cordons ↗</button>
                                </p>
                            <?php endif; ?>

                            <?php
                            // Un élément porte plusieurs ISO, et un cahier déposé d'un bloc
                            // porte plusieurs pages. Chaque page a sa liste soudure imprimée :
                            // on la prépare ici, et elle voyage avec sa feuille jusqu'à la
                            // fenêtre du plan, où elle se remplit en regardant le dessin.
                            $elementIso = (int) $element['id'];
                            $modifiableIso = $canManageWorkshop && $taken;
                            $soudeursIso = soudureSoudeursDisponibles($db, $elementIso);
                            $plansIso = array_map(static function (array $doc) use ($db, $elementIso, $soudeursIso, $modifiableIso): array {
                                $listes = [];
                                $reperes = [];
                                $empreintes = [];
                                for ($page = 1; $page <= $doc['pages']; $page++) {
                                    $listes[$page] = soudureRendu(
                                        soudureLignes($db, $elementIso, $doc['id'], $page),
                                        $soudeursIso, $elementIso, $doc['id'], $modifiableIso,
                                        soudureEmpreinte($db, $elementIso, $doc['id'], $page), $page);
                                    $reperes[$page] = nomenclatureLignes($db, $elementIso, $doc['id'], $page);
                                    $empreintes[$page] = nomenclatureEmpreinte($db, $elementIso, $doc['id'], $page);
                                }
                                return $doc + [
                                    'reperes' => $reperes,
                                    'empreintes' => $empreintes,
                                    'soudures' => $listes,
                                    'cordons' => soudureCompte($db, $elementIso, $doc['id']),
                                ];
                            }, planDocuments($db, $elementIso));
                            ?>

                            <?= planViewerRendu($elementIso, $plansIso, $couleesDisponibles, $modifiableIso) ?>

                            <?php if ($documentsAnnexes || ($canManageWorkshop && $taken && $optionsAnnexes)): ?>
                                <!-- Les pièces qui accompagnent le plan sans être des feuilles :
                                     on les télécharge, on ne travaille pas dessus. Repliées,
                                     elles ne prennent pas la place du vrai travail. -->
                                <details class="atelier-plan-information atelier-file-management">
                                    <summary>Autres documents de la demande</summary>
                                    <?php if ($documentsAnnexes): ?>
                                        <div class="atelier-plan-files atelier-plan-files-inline">
                                            <?php foreach ($documentsAnnexes as $doc): ?>
                                                <a class="prefa-document-link<?= strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION)) === 'pdf' ? ' prefa-pdf-link' : '' ?>" data-name="<?= prefaEscape($doc['name']) ?>" href="<?= prefaEscape($doc['url']) ?>"><?= prefaEscape($doc['name']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canManageWorkshop && $taken && $optionsAnnexes): ?>
                                        <form class="atelier-action prefa-form atelier-document-edit" data-endpoint="save_atelier.php">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="documents"><input type="hidden" name="element" value="<?= (int) $element['id'] ?>">
                                            <p class="atelier-muted">QMOS et DMOS de la demande à rattacher à ce plan :</p>
                                            <?php $selectedDocuments = array_map(fn($doc) => $doc['type_document'] . ':' . $doc['cle_document'], $element['documents']); ?>
                                            <?php foreach ($optionsAnnexes as $token => $label): ?>
                                                <label class="atelier-checkbox"><input type="checkbox" name="documents[]" value="<?= prefaEscape($token) ?>" <?= in_array($token, $selectedDocuments, true) ? 'checked' : '' ?>><span><?= prefaEscape($label) ?></span></label>
                                            <?php endforeach; ?>
                                            <div class="atelier-form-actions"><button type="submit">Enregistrer</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                        </form>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>

                        <?php if ($canManageWorkshop && $taken): ?>
                            <div class="atelier-editor-row" hidden>

                                    <div id="<?= $formId ?>" class="atelier-manage-panel" hidden>
                                        <h5 class="atelier-manage-title">Affecter une personne</h5>
                                        <form class="atelier-action prefa-form atelier-manage-form" data-endpoint="save_atelier.php">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="assign"><input type="hidden" name="element" value="<?= (int) $element['id'] ?>"><input type="hidden" name="type" value="plan">
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
                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
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
                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="operation" value="progress">
                    <input type="hidden" name="affectation">
                    <input type="hidden" name="revision">
                    <!-- Deux façons de dire la même chose, et une seule sert à la fois : le plan
                         porte une liste de soudures, on compte des cordons ; sinon des pour cent.
                         Le champ écarté est désactivé, pas seulement masqué — masqué, il partirait
                         quand même avec le formulaire. -->
                    <div class="form-group atelier-champ-soudures" hidden>
                        <label for="atelier-progress-soudures-<?= $requestId ?>">Soudures faites</label>
                        <input id="atelier-progress-soudures-<?= $requestId ?>" name="soudures" type="number" min="0" step="1" disabled>
                        <span class="atelier-champ-aide"><span class="atelier-soudures-origine"></span> · <output class="atelier-quotient"></output></span>
                    </div>
                    <div class="form-group atelier-champ-pourcentage"><label for="atelier-progress-value-<?= $requestId ?>">Avancement (%)</label><input id="atelier-progress-value-<?= $requestId ?>" name="avancement" type="number" min="0" max="100" step="1" required></div>
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
