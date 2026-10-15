<?php
require_once __DIR__ . '/../includes/prefa_context.php';
if (!$canEditPrefa) prefaError(403, 'Création et modification réservées aux demandeurs.');
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/prefa_hours.php';
require_once __DIR__ . '/../includes/liste_filtrable.php';

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
// Les catalogues. Le composant retrouve seul le libellé de l'option retenue : il n'y a
// plus à le chercher ici pour l'écrire dans le champ.
$passivations = $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll();
$matieres = $db->query('SELECT id, libelle FROM type_matiere ORDER BY libelle')->fetchAll();



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
        <?php // La sortie, en tête de page. Le formulaire tient sur deux écrans et son
              // « Annuler » est tout en bas : une fois descendu dans les champs, revenir à
              // la liste demandait de refaire tout le chemin. La touche Échap fait la même
              // chose, et les deux préviennent avant de jeter une saisie en cours. ?>
        <button type="button" class="prefa-retour">
            <span aria-hidden="true">&larr;</span> Retour à la liste
        </button>
        <div class="prefa-heading">
            <!-- « Nouvelle demande » et non « Nouvelle affaire » : c'est le chargé de
                 production qui ouvre l'affaire, et on en dépose plusieurs sur la même. -->
            <h1><?= $editing ? 'Modifier la demande ' . prefaEscape(prefaReferenceComplete((int) $request['id'])) : 'Nouvelle demande' ?></h1>
            <p><?= $editing ? 'Après modification, la demande repassera en attente de validation.' : 'Rattachez cette demande à l’une de vos affaires, puis transmettez-la pour validation.' ?></p>
        </div>
        <form id="prefa-form" class="prefa-form" novalidate enctype="multipart/form-data" data-hours-per-inch="<?= prefaEscape($hoursPerInch) ?>">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><?php endif; ?>
            <div class="prefa-section">
                <div class="prefa-grid">
                    <?php
                    // La demande se rattache à une affaire ouverte, elle n'en crée pas une :
                    // c'est le chargé de production qui ouvre le chantier et désigne qui le
                    // suit. On ne choisit donc que parmi les siennes, et sans issue « Autre ».
                    $affairesOuvertes = Affaire::pour($db, $utilisateur);
                    ?>
                    <?php if ($affairesOuvertes): ?>
                        <div class="prefa-full">
                            <?= listeFiltrableRendu('affaire', 'Affaire',
                                array_map(static fn (Affaire $a): array => ['id' => $a->id, 'libelle' => $a->nom], $affairesOuvertes),
                                $request['id_affaire'] ?? null,
                                ['vide' => 'Choisissez l’affaire…', 'aucun' => 'Aucune affaire de ce nom.',
                                 'obligatoire' => true, 'chercher' => 'Rechercher parmi vos affaires…',
                                 'aide' => 'Une affaire porte plusieurs demandes : déposez-en autant que le chantier en demande.']) ?>
                        </div>
                    <?php else: ?>
                        <!-- Sans affaire, rien à déposer : on le dit plutôt que de montrer une
                             liste vide dont on ne saurait pas quoi faire. -->
                        <div class="form-group prefa-full">
                            <label for="affaire-search">Affaire <span class="required-mark" aria-hidden="true">*</span></label>
                            <p class="prefa-aide">Aucune affaire ne vous est confiée pour l’instant.
                                Demandez au chargé de production d’en ouvrir une à votre nom.</p>
                        </div>
                    <?php endif; ?>
                    <div class="form-group prefa-full">
                        <label for="plan-documents">Plans BPE / ISO et pièces jointes<?php if (!$editing): ?> <span class="required-mark">*</span><?php endif; ?></label>
                        <label class="prefa-dropzone" for="plan-documents" tabindex="0"><strong>Déposer des documents ici</strong><span>ou cliquez pour les choisir · PDF, images, Word, Excel · 50 Mo maximum par fichier</span></label>
                        <input id="plan-documents" name="plan_documents[]" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx" multiple hidden>
                        <div id="plan-selected-files" class="prefa-file-list"></div>
                        <?php if ($attachments): ?><div id="plan-saved-files" class="prefa-file-list"><?php foreach ($attachments as $attachment): ?><div class="prefa-file-row"><a href="actions/download_prefa_attachment.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= rawurlencode($attachment['key']) ?>"><?= prefaEscape($attachment['name']) ?></a><button type="button" class="prefa-remove-saved" data-key="<?= prefaEscape($attachment['key']) ?>" aria-label="Retirer <?= prefaEscape($attachment['name']) ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="qmos">Documents QMOS <span class="required-mark" aria-hidden="true">*</span></label>
                        <label class="prefa-dropzone" for="qmos" tabindex="0"><strong>Déposer vos PDF QMOS</strong><span>Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="qmos" name="qmos_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="qmos-help" multiple hidden>
                        <div id="qmos-selected-files" class="prefa-file-list"></div>
                        <?php if ($qmosFiles): ?><div id="qmos-saved-files" class="prefa-file-list"><?php foreach ($qmosFiles as $qmosFile): ?><div class="prefa-file-row"><a href="actions/download_qmos.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $qmosFile['id'] ?>"><?= prefaEscape($qmosFile['nom']) ?></a><button type="button" class="prefa-remove-qmos-saved" data-id="<?= (int) $qmosFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php elseif (!empty($request[' QMOS'])): ?><small>Ancienne référence : <?= $field(' QMOS') ?></small><?php endif; ?>
                    </div>
                    <div class="form-group prefa-full">
                        <label for="dmos">Documents DMOS <span class="required-mark" aria-hidden="true">*</span></label>
                        <label class="prefa-dropzone" for="dmos" tabindex="0"><strong>Déposer vos PDF DMOS</strong><span>Nombre de fichiers illimité · 50 Mo maximum par fichier</span></label>
                        <input id="dmos" name="dmos_pdf[]" type="file" accept=".pdf,application/pdf" aria-describedby="dmos-help" multiple hidden>
                        <div id="dmos-selected-files" class="prefa-file-list"></div>
                        <?php if ($dmosFiles): ?><div id="dmos-saved-files" class="prefa-file-list"><?php foreach ($dmosFiles as $dmosFile): ?><div class="prefa-file-row"><a href="actions/download_dmos.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $dmosFile['id'] ?>"><?= prefaEscape($dmosFile['nom']) ?></a><button type="button" class="prefa-remove-dmos-saved" data-id="<?= (int) $dmosFile['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
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
                        <label for="pouces">
                                Total des pouces ISO 
                                <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input id="pouces" name="pouces_total_iso" value="<?= $field('pouces_total_iso') ?>" type="number" min="0" max="2147483647" step="1" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label for="heures">Durée estimée (jours)</label><input id="heures" name="heures_chiffrees" value="<?= $editing ? prefaEscape(number_format((float) prefaCalculatedHours((int) $request['pouces_total_iso'], $hoursPerInch) / 24, 2, '.', '')) : '' ?>" type="number" step="0.01" readonly placeholder="0" title="Calcul automatique">
                    </div>
                    <?= listeFiltrableRendu('passivation', 'Type de passivation', $passivations,
                        $request['id_passivation'] ?? null,
                        ['vide' => 'Aucune passivation', 'autre' => 'Autre (préciser)...',
                         'aucun' => 'Aucun type trouvé.']) ?>
                    <?= listeFiltrableAutreRendu('passivation', 'Préciser le type de passivation',
                        'Ex. Passivation électrolytique…', 'passivation_autre') ?>

                    <?= listeFiltrableRendu('matiere', 'Matière', $matieres,
                        $request['id_matiere'] ?? null,
                        ['vide' => 'Matière non précisée', 'autre' => 'Autre (préciser)...',
                         'aucun' => 'Aucun type trouvé.']) ?>
                    <?= listeFiltrableAutreRendu('matiere', 'Préciser la matière',
                        'Ex. Inox 316L…', 'matiere_autre') ?>

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