<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
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
$stmt = $db->prepare("SELECT h.*, e.reference, e.id_demande, a.type_affectation,
        TRIM(CONCAT(v.prenom, ' ', v.name)) AS validateur
    FROM avancement_atelier h
    JOIN affectation_atelier a ON a.id = h.id_affectation JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    LEFT JOIN Utilisateur v ON v.id = h.valide_par
    WHERE h.id_utilisateur = ? AND NOT (COALESCE(a.actif, 0) = 1 AND d.id_statut = 2) ORDER BY h.date_saisie DESC, h.id DESC LIMIT 100");
$stmt->execute([$actor['id']]);
$recentProgress = $stmt->fetchAll();
?>
<section class="page prefa-page prefa-list atelier-page" aria-labelledby="atelier-page-title">
    <div class="">
        <header class="prefa-heading prefa-list-heading">
            <div><h1 id="atelier-page-title">Mes plans</h1></div>
            <button type="button" class="atelier-refresh prefa-toggle">Actualiser</button>
        </header>
        <p class="prefa-list-summary"><strong><?= count($assignments) ?></strong> <?= count($assignments) === 1 ? 'affectation' : 'affectations' ?></p>
        <div class="prefa-table-scroll" role="region" aria-label="Mes plans" tabindex="0">
            <table class="prefa-table atelier-table">
                <?php // Le plan d'abord : c'est l'écran de ses plans, pas celui des demandes. Le
                      // statut a rejoint l'avancement — « 0 % » et « À commencer » disaient la même
                      // chose dans deux colonnes — et les documents ne se comptent plus, ils
                      // s'ouvrent : on lisait « 1 fichier » et il fallait déplier pour savoir
                      // lequel. ?>
                <thead><tr><th scope="col">Plan</th><th scope="col">Demande</th><th scope="col">Travail</th><th scope="col">Documents</th><th scope="col">Avancement</th><th scope="col">Actions</th></tr></thead>
                <?php if (!$assignments): ?><tbody><tr><td colspan="6" class="prefa-table-empty">Aucun élément ne vous est actuellement affecté.</td></tr></tbody><?php endif; ?>
                <?php foreach ($assignments as $assignment):
                    $id = (int) $assignment['id'];
                    $progress = (int) $assignment['avancement'];
                    $status = $progress === 100 ? 'Terminé' : ($progress ? 'En cours' : 'À commencer');
                    $statusClass = $progress === 100 ? 'status-2' : ($progress ? 'atelier-status-progress' : 'status-1');
                    $docs = $documentsByElement[(int) $assignment['id_element']];
                    $historyStmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur,
                            TRIM(CONCAT(v.prenom, ' ', v.name)) AS validateur
                        FROM avancement_atelier h JOIN Utilisateur u ON u.id = h.id_utilisateur
                        LEFT JOIN Utilisateur v ON v.id = h.valide_par
                        WHERE h.id_affectation = ? ORDER BY h.date_saisie DESC, h.id DESC");
                    $historyStmt->execute([$id]);
                    $progressHistory = $historyStmt->fetchAll();
                    // Le plan porte-t-il une liste de soudures ? Si oui, l'avancement se
                    // déclare en cordons — « j'en ai fait sept » — et le pourcentage en
                    // découle. Sinon il se déclare en pour cent, comme avant.
                    $prevu = avancementSouduresPrevues($db, (int) $assignment['id_element']);
                    $soudureTotal = $prevu['prevues'];
                    $feuillesDuPlan = $prevu['pages'];
                    $soudureOrigine = avancementDenominateurPhrase($prevu);
                    $soudureFaites = avancementSouduresFaites($db, (int) $assignment['id_element']);
                    $attente = avancementEnAttente($db, $id);
                    $historyStmt = $db->prepare("SELECT h.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
                        FROM element_atelier_etapes_historique h JOIN Utilisateur u ON u.id = h.modifie_par
                        WHERE h.id_element = ? ORDER BY h.revision DESC");
                    $historyStmt->execute([$assignment['id_element']]);
                    $stepsHistory = $historyStmt->fetchAll();
                ?>
                    <tbody class="atelier-assignment-group">
                        <tr>
                            <td><strong><?= prefaEscape($assignment['reference']) ?></strong><span class="atelier-row-description"><?= prefaEscape($assignment['libelle']) ?></span></td>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $assignment['id_demande'])) ?></td>
                            <td><?= $types[$assignment['type_affectation']] ?></td>
                            <td>
                                <?php if ($docs): ?>
                                    <span class="atelier-row-documents">
                                        <?php foreach ($docs as $doc): ?>
                                            <a class="atelier-row-document<?= strtolower(pathinfo($doc['name'], PATHINFO_EXTENSION)) === 'pdf' ? ' prefa-pdf-link' : '' ?>"
                                               data-name="<?= prefaEscape($doc['name']) ?>" href="<?= prefaEscape($doc['url']) ?>"><?= prefaEscape($doc['name']) ?></a>
                                        <?php endforeach; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="atelier-missing-file">Pas encore de fichier</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="prefa-status <?= $statusClass ?>"><?= $progress ?> % · <?= $status ?></span><?php if ($attente): ?><span class="atelier-row-description">déclaré : <?= prefaEscape(avancementPhrase($attente)) ?>, à valider</span><?php endif; ?></td>
                            <td><button type="button" class="prefa-toggle" aria-controls="atelier-detail-<?= $id ?>" aria-expanded="false">Mettre à jour</button></td>
                        </tr>
                        <tr id="atelier-detail-<?= $id ?>" class="prefa-detail-row" hidden>
                            <td colspan="6">
                                <div class="atelier-detail-content">
                                    <?php // Les documents ne sont plus redits ici : ils s'ouvrent déjà depuis
                                          // la ligne du plan, juste au-dessus, et « Pas encore de fichier »
                                          // y dit aussi leur absence. ?>
                                    <div class="atelier-tabs">
                                        <div class="atelier-tablist" role="tablist" aria-label="Suivi de l’affectation">
                                            <button type="button" class="atelier-tab" role="tab" id="atelier-tab-etapes-<?= $id ?>" aria-controls="atelier-pane-etapes-<?= $id ?>" aria-selected="true">Étapes</button>
                                            <button type="button" class="atelier-tab" role="tab" id="atelier-tab-avancement-<?= $id ?>" aria-controls="atelier-pane-avancement-<?= $id ?>" aria-selected="false" tabindex="-1">Avancement</button>
                                        </div>

                                        <section class="atelier-detail-panel" role="tabpanel" id="atelier-pane-etapes-<?= $id ?>" aria-labelledby="atelier-tab-etapes-<?= $id ?>">
                                            <p class="atelier-muted">Partagé avec les autres personnes sur ce plan.</p>
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
                                            <?php if ($attente): ?>
                                                <p class="atelier-attente">
                                                    <strong><?= prefaEscape(avancementPhrase($attente)) ?></strong>
                                                    en attente du chef d’atelier
                                                    <span class="atelier-muted">· déclaré le <?= prefaEscape(atelierLocalDateTime($attente['date_saisie'])) ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($soudureTotal > 0): ?>
                                                <?php // Le plan porte une liste de soudures : l'avancement ne se tape
                                                      // pas, il se compte sur les cordons renseignés. La saisie est donc
                                                      // là où l'on renseigne les cordons, et nulle part ailleurs — deux
                                                      // endroits pour le même chiffre, c'est un chiffre de trop. ?>
                                                <p class="atelier-compte-soudures">
                                                    <strong><?= $soudureFaites ?> soudure<?= $soudureFaites > 1 ? 's' : '' ?> sur <?= $soudureTotal ?></strong>
                                                    <span class="atelier-muted"><?= prefaEscape($soudureOrigine) ?> · soit <?= avancementPourcentage($soudureFaites, $soudureTotal) ?> %</span>
                                                </p>
                                                <button type="button" class="atelier-vers-soudures" data-page="pages/soudures.php">Renseigner mes soudures ↗</button>
                                            <?php else: ?>
                                            <form class="atelier-action prefa-form atelier-grid" data-endpoint="update_atelier.php">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="affectation" value="<?= $id ?>"><input type="hidden" name="operation" value="progress"><input type="hidden" name="revision" value="<?= (int) $assignment['revision'] ?>">
                                                <div><label for="atelier-progress-<?= $id ?>">Avancement (%)</label><input id="atelier-progress-<?= $id ?>" type="number" name="avancement" min="0" max="100" step="1" value="<?= $progress ?>" required>
                                                    <?php // Sans liste de soudures reconnue, l'avancement reste en pour cent.
                                                          // Le dire : autrement on cherche le compte de cordons dont on a
                                                          // entendu parler, et on ne comprend pas pourquoi il n'est pas là. ?>
                                                    <span class="atelier-champ-aide">
                                                        <?php if ($feuillesDuPlan > 0): ?>
                                                            Pour compter en soudures, ouvrez une feuille dans « Mes soudures ».
                                                        <?php else: ?>
                                                            Pas encore de feuille pour ce plan.
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="atelier-wide"><label for="atelier-note-<?= $id ?>"><?= $assignment['type_affectation'] === 'soudage' ? 'Compte rendu du suivi soudage' : 'Compte rendu d’avancement' ?></label><textarea id="atelier-note-<?= $id ?>" name="commentaire" maxlength="2000"><?= prefaEscape($assignment['commentaire'] ?? '') ?></textarea></div>
                                                <p class="atelier-wide atelier-muted">Le chef d’atelier valide avant que le pourcentage change.</p>
                                                <div class="atelier-form-actions"><button type="submit">Déclarer l’avancement</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                            </form>
                                            <?php endif; ?>
                                            <details class="atelier-saved-history">
                                                <summary>Historique de l’avancement (<?= count($progressHistory) ?>)</summary>
                                                <?php if (!$progressHistory): ?><p class="atelier-muted">Aucun avancement enregistré.</p><?php endif; ?>
                                                <ol class="atelier-saved-history-list">
                                                    <?php foreach ($progressHistory as $entry): ?>
                                                        <li class="atelier-saisie-<?= prefaEscape($entry['etat']) ?>"><strong><?= prefaEscape(avancementPhrase($entry)) ?></strong> <span class="atelier-muted">· <?= prefaEscape(atelierLocalDateTime($entry['date_saisie'])) ?> · <?= prefaEscape($entry['auteur']) ?></span>
                                                            <span class="atelier-saisie-etat"><?= prefaEscape(avancementEtatPhrase($entry)) ?></span>
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
        <?php if ($recentProgress): ?>
            <details class="atelier-archive-history">
                <summary>Historique des anciennes affectations</summary>
                <p class="atelier-muted">Vos saisies sur les plans qui ne vous sont plus confiés.</p>
                <div class="atelier-detail-columns">
                    <?php if ($recentProgress): ?>
                        <section class="atelier-detail-panel">
                            <h2>Avancements</h2>
                            <ol class="atelier-saved-history-list">
                                <?php foreach ($recentProgress as $entry): ?>
                                    <li class="atelier-saisie-<?= prefaEscape($entry['etat']) ?>"><strong><?= prefaEscape(avancementPhrase($entry)) ?></strong> <span class="atelier-muted">· <?= prefaEscape(atelierLocalDateTime($entry['date_saisie'])) ?></span>
                                        <span class="atelier-saisie-etat"><?= prefaEscape(avancementEtatPhrase($entry)) ?></span>
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
