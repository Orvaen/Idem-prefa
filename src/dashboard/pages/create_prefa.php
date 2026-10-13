<?php
require_once __DIR__ . '/../includes/prefa_context.php';
if (!$canEditPrefa) prefaError(403, 'Création et modification réservées aux demandeurs.');
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/prefa_hours.php';

$editing = isset($_GET['id']);
$request = [];
if ($editing) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) prefaError(400, 'Demande invalide.');
    $stmt = $db->prepare('SELECT * FROM demande_prefabrication WHERE id = ?' . ($isAdmin ? '' : ' AND idUsers = ?'));
    $stmt->execute($isAdmin ? [$id] : [$id, $actor['id']]);
    $request = $stmt->fetch();
    if (!$request) prefaError(404, 'Demande introuvable ou inaccessible.');
} elseif ($isAdmin) {
    prefaError(403, 'Un administrateur ne peut pas créer de demande de préfabrication.');
}
$field = static fn(string $key) => prefaEscape($request[$key] ?? '');
$hoursSettings = prefaHoursSettings($db);
$hoursPerInch = $hoursSettings['rate'];
$qmosFiles = [];
$dmosFiles = [];
$materielFiles = [];
$ccpuFiles = [];
$attachments = $editing ? prefaAttachments((int) $request['id']) : [];
if ($editing) {
    $pdf = $db->prepare('SELECT id, nom FROM demande_qmos_documents WHERE id_demande = ? ORDER BY id');
    $pdf->execute([$request['id']]);
    $qmosFiles = $pdf->fetchAll();
    $pdf = $db->prepare('SELECT id, nom FROM demande_dmos_documents WHERE id_demande = ? ORDER BY id');
    $pdf->execute([$request['id']]);
    $dmosFiles = $pdf->fetchAll();
    $pdf = $db->prepare('SELECT id, nom FROM demande_materiel_documents WHERE id_demande = ? ORDER BY id');
    $pdf->execute([$request['id']]);
    $materielFiles = $pdf->fetchAll();
    $pdf = $db->prepare('SELECT id, nom FROM demande_ccpu_documents WHERE id_demande = ? ORDER BY id');
    $pdf->execute([$request['id']]);
    $ccpuFiles = $pdf->fetchAll();
}
$passivations = $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll();
$passivationLabel = '';
foreach ($passivations as $type) {
    if ((int) $type['id'] === (int) ($request['id_passivation'] ?? 0)) $passivationLabel = $type['libelle'];
}

$matieres = $db->query('SELECT id, libelle FROM type_matiere ORDER BY libelle')->fetchAll();
$matiereLabel = '';
foreach ($matieres as $type) {
    if ((int) $type['id'] === (int) ($request['id_matiere'] ?? 0)) $matiereLabel = $type['libelle'];
}



$currentRevetementType = 'aucun';
$currentRevetementPrecision = '';
if ($editing && !empty($request['revetement'])) {
    $comm = (string) ($request['commentaire_revetement'] ?? '');
    if (stripos($comm, 'intérieur et extérieur') !== false || stripos($comm, 'les_deux') !== false) {
        $currentRevetementType = 'les_deux';
    } elseif (stripos($comm, 'extérieur') !== false) {
        $currentRevetementType = 'exterieur';
    } elseif (stripos($comm, 'intérieur') !== false) {
        $currentRevetementType = 'interieur';
    } else {
        $currentRevetementType = 'exterieur';
    }
    $parts = explode(' — ', $comm, 2);
    if (isset($parts[1])) {
        $currentRevetementPrecision = $parts[1];
    } elseif (!in_array($comm, ['Revêtement extérieur', 'Revêtement intérieur', 'Revêtement intérieur et extérieur', 'Oui'], true)) {
        $currentRevetementPrecision = $comm;
    }
}
?>

<section class="page prefa-page prefa-simple">
    <div class="prefa-shell">
        <div class="prefa-heading">
            <h1><?= $editing ? 'Modifier la demande ' . prefaEscape(prefaReferenceComplete((int) $request['id'])) : 'Nouvelle Affaire' ?></h1>
            <p><?= $editing ? 'Après modification, la demande repassera en attente de validation.' : 'Renseignez votre affaire, puis transmettez-la pour validation.' ?></p>
        </div>
        <form id="prefa-form" class="prefa-form" novalidate enctype="multipart/form-data" data-hours-per-inch="<?= prefaEscape($hoursPerInch) ?>">
            <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><?php endif; ?>
            <div class="prefa-section">
                <div class="prefa-grid">
                    <?php
                    // Ce champ ne nomme pas la demande : il nomme l'AFFAIRE à laquelle elle
                    // se rattache, et une affaire en porte plusieurs. L'appeler « nom de la
                    // demande » alors que le menu annonce « Mes affaires » faisait croire
                    // qu'on créait une affaire à chaque fois.
                    //
                    // Les affaires déjà ouvertes sont proposées : c'est ainsi qu'on en
                    // rejoint une au lieu d'en créer une jumelle à une faute de frappe près.
                    $affairesConnues = $db->query('SELECT nom FROM affaire ORDER BY nom')->fetchAll(PDO::FETCH_COLUMN);
                    ?>
                    <div class="form-group prefa-full">
                        <label for="nom-demande">Affaire <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="nom-demande" name="nom_affaire" type="text" value="<?= $field('nom_affaire') ?>" maxlength="255" required
                            list="affaires-ouvertes" autocomplete="off" placeholder="Ex. : Réseau vapeur — bâtiment A">
                        <datalist id="affaires-ouvertes">
                            <?php foreach ($affairesConnues as $nomAffaire): ?>
                                <option value="<?= prefaEscape($nomAffaire) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="prefa-aide">Choisissez un chantier déjà ouvert pour y ajouter cette demande, ou saisissez-en un nouveau.</small>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="plan-documents">Plans BPE / ISO et pièces jointes<?php if (!$editing): ?> <span class="required-mark">*</span><?php endif; ?></label>
                        <label class="prefa-dropzone" for="plan-documents" tabindex="0"><strong>Déposer des documents ici</strong><span>ou cliquez pour les choisir · PDF, images, Word, Excel · 50 Mo maximum par fichier</span></label>
                        <input id="plan-documents" name="plan_documents[]" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx" multiple hidden>
                        <div id="plan-selected-files" class="prefa-file-list"></div>
                        <?php if ($attachments): ?><div id="plan-saved-files" class="prefa-file-list"><?php foreach ($attachments as $attachment): ?><div class="prefa-file-row"><a href="actions/download_prefa_attachment.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= rawurlencode($attachment['key']) ?>"><?= prefaEscape($attachment['name']) ?></a><button type="button" class="prefa-remove-saved" data-key="<?= prefaEscape($attachment['key']) ?>" aria-label="Retirer <?= prefaEscape($attachment['name']) ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="qmos">Documents QMOS <small>(facultatif)</small></label>
                        <label class="prefa-dropzone" for="qmos" tabindex="0"><strong>Déposer vos PDF QMOS</strong><span>Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="qmos" name="qmos_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="qmos-help" multiple hidden>
                        <div id="qmos-selected-files" class="prefa-file-list"></div>
                        <?php if ($qmosFiles): ?><div id="qmos-saved-files" class="prefa-file-list"><?php foreach ($qmosFiles as $qmosFile): ?><div class="prefa-file-row"><a href="actions/download_qmos.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $qmosFile['id'] ?>"><?= prefaEscape($qmosFile['nom']) ?></a><button type="button" class="prefa-remove-qmos-saved" data-id="<?= (int) $qmosFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php elseif (!empty($request[' QMOS'])): ?><small>Ancienne référence : <?= $field(' QMOS') ?></small><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="dmos">Documents DMOS <small>(facultatif)</small></label>
                        <label class="prefa-dropzone" for="dmos" tabindex="0"><strong>Déposer vos PDF DMOS</strong><span>Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="dmos" name="dmos_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="dmos-help" multiple hidden>
                        <div id="dmos-selected-files" class="prefa-file-list"></div>
                        <?php if ($dmosFiles): ?><div class="prefa-file-list"><?php foreach ($dmosFiles as $dmosFile): ?><div class="prefa-file-row"><a href="actions/download_dmos.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $dmosFile['id'] ?>"><?= prefaEscape($dmosFile['nom']) ?></a><button type="button" class="prefa-remove-dmos-saved" data-id="<?= (int) $dmosFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="materiel">Matériel <small>(facultatif)</small></label>
                        <label class="prefa-dropzone" for="materiel" tabindex="0"><strong>Déposer votre fiche matériel</strong><span>PDF · Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="materiel" name="materiel_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="materiel-help" multiple hidden>
                        <div id="materiel-selected-files" class="prefa-file-list"></div>
                        <?php if ($materielFiles): ?><div class="prefa-file-list"><?php foreach ($materielFiles as $materielFile): ?><div class="prefa-file-row"><a href="actions/download_materiel.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $materielFile['id'] ?>"><?= prefaEscape($materielFile['nom']) ?></a><button type="button" class="prefa-remove-materiel-saved" data-id="<?= (int) $materielFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="ccpu">CCPU <small>(certificats matière, facultatif)</small></label>
                        <label class="prefa-dropzone" for="ccpu" tabindex="0"><strong>Déposer vos certificats matière</strong><span>PDF · Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="ccpu" name="ccpu_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="ccpu-help" multiple hidden>
                        <div id="ccpu-selected-files" class="prefa-file-list"></div>
                        <?php if ($ccpuFiles): ?><div class="prefa-file-list"><?php foreach ($ccpuFiles as $ccpuFile): ?><div class="prefa-file-row"><a href="actions/download_ccpu.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $ccpuFile['id'] ?>"><?= prefaEscape($ccpuFile['nom']) ?></a><button type="button" class="prefa-remove-ccpu-saved" data-id="<?= (int) $ccpuFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="prefa-section">
                <div class="prefa-grid">

                    <!-- quand l’atelier doit avoir fini la préfa. -->
                    <div class="form-group">
                        <label for="date-fin">
                            Date prévue de fin de fabrication
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>

                        <input
                            id="date-fin"
                            name="date_fin_prevue"
                            type="date"
                            value="<?= $field('date_fin_prevue') ?>"
                            required>
                    </div>

                    <!-- quand elle doit au plus tard être livrée. -->
                    <div class="form-group">
                        <label for="date-livraison">
                            Date limite de livraison au chantier
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>

                        <input
                            id="date-livraison"
                            name="date_livraison_prevue"
                            type="date"
                            value="<?= $field('date_livraison_prevue') ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="pouces">Total des pouces ISO <span class="required-mark" aria-hidden="true">*</span></label><input id="pouces" name="pouces_total_iso" value="<?= $field('pouces_total_iso') ?>" type="number" min="0" max="2147483647" step="1" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label for="heures">Durée estimée (jours)</label><input id="heures" name="heures_chiffrees" value="<?= $editing ? prefaEscape(number_format((float) prefaCalculatedHours((int) $request['pouces_total_iso'], $hoursPerInch) / 24, 2, '.', '')) : '' ?>" type="number" step="0.01" readonly placeholder="0" title="Calcul automatique">
                    </div>
                    <div class="form-group">
                        <label for="passivation-search">Type de passivation</label>
                        <div class="passivation-select">
                            <input id="passivation-search" type="text" value="<?= prefaEscape($passivationLabel) ?>" placeholder="Rechercher ou sélectionner…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="passivation-options">
                            <button type="button" id="passivation-clear" aria-label="Effacer le type de passivation" title="Effacer">×</button>
                            <input type="hidden" name="id_passivation" value="<?= $field('id_passivation') ?>">
                            <div id="passivation-options" role="listbox" hidden style="display: none;">
                                <div id="passivation-none" role="option" aria-selected="<?= empty($request['id_passivation']) ? 'true' : 'false' ?>" data-value="">Aucune passivation</div>
                                <?php foreach ($passivations as $type): ?>
                                    <div id="passivation-option-<?= (int) $type['id'] ?>" role="option" aria-selected="<?= (int) $type['id'] === (int) ($request['id_passivation'] ?? 0) ? 'true' : 'false' ?>" data-value="<?= (int) $type['id'] ?>"><?= prefaEscape($type['libelle']) ?></div>
                                <?php endforeach; ?>
                                <div id="passivation-option-autre" role="option" aria-selected="false" data-value="autre" class="passivation-option-autre">Autre (préciser)...</div>
                                <p class="passivation-no-results" hidden style="display: none;">Aucun type trouvé.</p>
                            </div>
                        </div>
                    </div>
                    <div class="form-group prefa-full" id="passivation-autre-field" hidden style="display: none;">
                        <label for="passivation-autre">Préciser le type de passivation <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="passivation-autre" name="passivation_autre" type="text" maxlength="100" placeholder="Ex. Passivation électrolytique…" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="matiere-search">Matière</label>
                        <div class="matiere-select">
                            <input id="matiere-search" type="text" value="<?= prefaEscape($matiereLabel) ?>" placeholder="Rechercher ou sélectionner…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="matiere-options">
                            <button type="button" id="matiere-clear" aria-label="Effacer la matière" title="Effacer">×</button>
                            <input type="hidden" name="id_matiere" value="<?= $field('id_matiere') ?>">
                            <div id="matiere-options" role="listbox" hidden style="display: none;">
                                <div id="matiere-none" role="option" aria-selected="<?= empty($request['id_matiere']) ? 'true' : 'false' ?>" data-value="">Matière non précisée</div>
                                <?php foreach ($matieres as $type): ?>
                                    <div id="matiere-option-<?= (int) $type['id'] ?>" role="option" aria-selected="<?= (int) $type['id'] === (int) ($request['id_matiere'] ?? 0) ? 'true' : 'false' ?>" data-value="<?= (int) $type['id'] ?>"><?= prefaEscape($type['libelle']) ?></div>
                                <?php endforeach; ?>
                                <div id="matiere-option-autre" role="option" aria-selected="false" data-value="autre" class="matiere-option-autre">Autre (préciser)...</div>
                                <p class="matiere-no-results" hidden style="display: none;">Aucun type trouvé.</p>
                            </div>
                        </div>
                    </div>
                    <div class="form-group prefa-full" id="matiere-autre-field" hidden style="display: none;">
                        <label for="matiere-autre">Préciser la matière <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="matiere-autre" name="matiere_autre" type="text" maxlength="100" placeholder="Ex. Inox 316L…" autocomplete="off">
                    </div>

                    <div class="form-group" id="matiere-disponibilite-field" <?= empty($request['id_matiere']) ? 'hidden' : '' ?>>
                        <label for="matiere-disponibilite">Disponibilité de la matière <span id="matiere-disponibilite-required" class="required-mark" aria-hidden="true" <?= empty($request['id_matiere']) ? 'hidden' : '' ?>>*</span></label>
                        <select id="matiere-disponibilite" name="matiere_disponibilite" class="prefa-select" <?= empty($request['id_matiere']) ? '' : 'required' ?>>
                            <option value="">À préciser</option>
                            <option value="stock" <?= ($request['matiere_disponibilite'] ?? '') === 'stock' ? 'selected' : '' ?>>En stock</option>
                            <option value="commande" <?= ($request['matiere_disponibilite'] ?? '') === 'commande' ? 'selected' : '' ?>>À commander</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="revetement-type">Revêtement des tubes</label>
                        <select id="revetement-type" name="revetement_type" class="prefa-select">
                            <option value="aucun" <?= $currentRevetementType === 'aucun' ? 'selected' : '' ?>>Sans revêtement</option>
                            <option value="exterieur" <?= $currentRevetementType === 'exterieur' ? 'selected' : '' ?>>Revêtement extérieur</option>
                            <option value="interieur" <?= $currentRevetementType === 'interieur' ? 'selected' : '' ?>>Revêtement intérieur</option>
                            <option value="les_deux" <?= $currentRevetementType === 'les_deux' ? 'selected' : '' ?>>Revêtement intérieur et extérieur</option>
                        </select>
                    </div>
                    <div class="form-group" id="revetement-details" <?= $currentRevetementType === 'aucun' ? 'hidden style="display: none;"' : '' ?>>
                        <label for="revetement-precision">Précision du revêtement <small>(facultatif : peinture anticorrosion, époxy, polyéthylène...)</small></label>
                        <input id="revetement-precision" name="revetement_precision" type="text" maxlength="255" value="<?= prefaEscape($currentRevetementPrecision) ?>" placeholder="Ex. Peinture anticorrosion, époxy...">
                    </div>

                </div>
            </div>
            <div class="prefa-section">
                <?php
                $controlMethods = [
                    ['name' => 'CDN', 'code' => 'CND', 'label' => 'Contrôles CND', 'key' => 'cdn', 'input' => 'controles'],
                    ['name' => 'RT', 'code' => 'RT', 'label' => 'Radiographie RT', 'key' => 'rt', 'input' => 'controles-rt'],
                    ['name' => 'PT', 'code' => 'PT', 'label' => 'Ressuage PT', 'key' => 'pt', 'input' => 'controles-pt'],
                ];
                foreach ($controlMethods as $control):
                    $enabled = !empty($request[$control['name']]);
                    $percent = filter_var($request['controles_' . $control['key']] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 1.5;
                ?>
                    <label class="prefa-option"><input type="checkbox" name="<?= $control['name'] ?>" value="1" <?= $enabled ? 'checked' : '' ?>><span><strong><?= $control['label'] ?></strong><small>Indiquez le pourcentage de contrôle souhaité.</small></span></label>
                    <fieldset class="form-group prefa-percent-field" id="<?= $control['key'] ?>-details" <?= $enabled ? '' : 'hidden' ?>>
                        <legend>Pourcentage <?= $control['code'] ?> (%)</legend>
                        <div class="prefa-range-row">
                            <input id="<?= $control['input'] ?>" name="controles_<?= $control['key'] ?>" class="prefa-control-range" type="range" min="1" max="100" step="0.5" value="<?= $percent ?>" aria-label="Pourcentage <?= $control['code'] ?>" <?= $enabled ? 'required' : 'disabled' ?>>
                            <input id="<?= $control['input'] ?>-value" class="prefa-control-value" type="text" inputmode="decimal" value="<?= $percent ?>" aria-label="Pourcentage <?= $control['code'] ?>" <?= $enabled ? 'required' : 'disabled' ?>>
                            <span class="cnd-unit" aria-hidden="true">%</span>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <label class="prefa-option"><input type="checkbox" name="urgent" value="1" <?= !empty($request['urgent']) ? 'checked' : '' ?>><span><strong>Demande urgente</strong><small>Signalez une priorité de traitement à l’administrateur.</small></span></label>
            </div>
            <p class="prefa-message" role="status" aria-live="polite"></p>
            <div class="prefa-form-footer prefa-actions"><?php if ($editing): ?><button type="button" class="cancel-prefa-edit">Annuler</button><?php endif; ?><button type="submit"><?= $editing ? 'Enregistrer' : 'Envoyer la demande' ?></button></div>
        </form>
    </div>
</section>