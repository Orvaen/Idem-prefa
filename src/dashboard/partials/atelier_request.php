<?php

/** @var PDO $db */
/** @var int $requestId */
/** @var array<string, mixed> $row */
/** @var array<int, array<int, array<string, mixed>>> $attachmentsByRequest */
/** @var array<int, array<int, array<string, mixed>>> $qmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $dmosByRequest */
/** @var bool $canManageWorkshop */
/** @var array<int, array<string, mixed>> $workshopUsers */

require_once __DIR__ . '/../includes/atelier/atelier.php';
require_once __DIR__ . '/../includes/atelier/avancement.php';
require_once __DIR__ . '/../includes/atelier/plan_document.php';
require_once __DIR__ . '/../includes/atelier/nomenclature.php';
require_once __DIR__ . '/../includes/atelier/soudure.php';
require_once __DIR__ . '/../includes/vue/plan_viewer.php';
$elements = atelierElements($db, $requestId);
// Les coulées des certificats de la demande : une seule lecture pour tous ses plans.
$couleesDisponibles = nomenclatureCouleesDisponibles($db, $requestId);
$types = ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
// Une icône par poste, comme les lignes de propriétés des volets (PV, fiche) : le libellé
// se repère avant de se lire.
$iconesPoste = [
    'plan' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" /></svg>',
    'montage' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5l3 3 5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z" /></svg>',
    'soudage' => '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c1.5 3 4 4.5 4 8a4 4 0 0 1-8 0c0-2 1-3 2-4 .2 1.5 1 2 2 2 0-2-1-3.5 0-6Z" /><path d="M5 21h14" /></svg>',
];
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
        <dialog id="atelier-create-dialog-<?= $requestId ?>" class="atelier-plan-dialog fenetre" aria-labelledby="atelier-create-title-<?= $requestId ?>">
            <form class="atelier-action" data-endpoint="save_atelier.php">
                <div class="fenetre-entete">
                    <h2 id="atelier-create-title-<?= $requestId ?>">Ajouter un plan</h2>
                    <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                </div>
                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="element">
                <div class="fenetre-corps">
                    <input class="fenetre-champ" name="reference" maxlength="80" required autocomplete="off" aria-label="Nom du plan" placeholder="Nom du plan">
                    <input class="fenetre-champ" name="libelle" maxlength="180" required autocomplete="off" aria-label="Description" placeholder="Description">
                    <?php if ($documentOptions): ?>
                        <fieldset class="fenetre-bloc">
                            <legend>Documents à associer</legend>
                            <div class="fenetre-cases">
                                <?php foreach ($documentOptions as $token => $label): ?><label class="fenetre-case"><input type="checkbox" name="documents[]" value="<?= prefaEscape($token) ?>" <?= str_starts_with($token, 'plan:') && count($attachmentsByRequest[$requestId] ?? []) === 1 ? 'checked' : '' ?>> <span><?= prefaEscape($label) ?></span></label><?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>
                    <span class="atelier-message fenetre-message" role="status" aria-live="polite"></span>
                </div>
                <div class="fenetre-pied"><button type="button" class="atelier-close-plan-edit fenetre-annuler">Annuler</button><button type="submit" class="fenetre-valider">Ajouter le plan</button></div>
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
                            <!-- L'en-tête du plan, sur le modèle de celui d'un volet : son nom en titre,
                                 sa description dessous, et ses deux commandes groupées dans un cadre —
                                 elles portent sur le plan lui-même, pas sur ses feuilles. -->
                            <div class="plan-volet-tete">
                                <div class="atelier-plan-identity"><h3><?= prefaEscape($element['reference']) ?></h3><span class="atelier-row-description"><?= prefaEscape($element['libelle']) ?></span>
                                    <?php if (!$availableDocuments): ?><span class="atelier-missing-file"><?= !empty($attachmentsByRequest[$requestId]) ? 'Le fichier de la demande reste à rattacher à ce plan.' : 'Aucun fichier rattaché à ce plan.' ?></span><?php endif; ?>
                                </div>
                                <?php if ($canManageWorkshop && $taken): ?>
                                    <div class="pv-volet-outils plan-volet-outils">
                                        <button type="button" class="prefa-toggle atelier-open-plan-edit" data-dialog="atelier-plan-dialog-<?= $requestId ?>" data-element="<?= (int) $element['id'] ?>" data-reference="<?= prefaEscape($element['reference']) ?>" data-description="<?= prefaEscape($element['libelle']) ?>" aria-label="Renommer le plan" title="Renommer le plan"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z" /><path d="m13.5 6.5 4 4" /></svg></button>
                                        <form class="atelier-action atelier-delete-plan" data-endpoint="save_atelier.php" data-plan-name="<?= prefaEscape($element['reference']) ?>">
                                            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                            <input type="hidden" name="id" value="<?= $requestId ?>">
                                            <input type="hidden" name="element" value="<?= (int) $element['id'] ?>">
                                            <input type="hidden" name="operation" value="delete_element">
                                            <button type="submit" class="atelier-plan-delete-button" aria-label="Supprimer le plan" title="Supprimer le plan"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" /></svg></button>
                                            <span class="atelier-message" role="status" aria-live="polite"></span>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <section class="plan-volet-section">
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
                                <section class="atelier-plan-work"><h4><?= $iconesPoste[$type] ?><?= prefaEscape($label) ?></h4>
                                    <?php if ($canManageWorkshop && $taken): ?>
                                        <button type="button" class="atelier-edit-assignment atelier-person-button <?= $assignment ? 'is-assigned' : '' ?>" data-form="<?= $formId ?>" data-type="<?= $type ?>" data-label="<?= prefaEscape($element['reference'] . ' · ' . $label) ?>" data-user="<?= $assignment ? (int) $assignment['id_utilisateur'] : '' ?>" aria-haspopup="menu" aria-expanded="false" title="<?= $assignment ? 'Changer la personne affectée' : 'Affecter une personne' ?> — <?= prefaEscape($label) ?>">
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
                                            <button type="button" class="atelier-edit-progress" data-dialog="atelier-progress-dialog-<?= $requestId ?>" data-affectation="<?= (int) $assignment['id'] ?>" data-revision="<?= (int) $assignment['revision'] ?>" data-avancement="<?= $pct ?>" data-soudures="<?= $soudureTotal ?>" data-origine="<?= prefaEscape($soudureOrigine) ?>" data-commentaire="<?= prefaEscape((string) ($assignment['commentaire'] ?? '')) ?>" data-label="<?= prefaEscape($element['reference'] . ' · ' . $label . ' · ' . $assignment['utilisateur_nom']) ?>" title="Saisir l’avancement">Saisir</button>
                                        <?php endif; ?>
                                        <?php // La bande vient après le bouton : elle occupe toute la largeur, et
                                              // placée avant elle, elle aurait renvoyé le bouton sur la ligne suivante.
                                        ?>
                                        <?php if ($declaration && $canManageWorkshop && $taken): ?>
                                            <!-- Le chiffre annoncé se tient à part du chiffre reconnu, et ne
                                                 se confond pas avec lui : c'est tout l'objet de la validation. -->
                                            <div class="atelier-declaration">
                                                <p class="atelier-declaration-titre">Avancement calculé, à valider</p>
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
                            </section>

                            <?php
                            // Ce que l'atelier a soudé sur ce plan, en une ligne. La liste
                            // entière vit dans l'onglet « Soudures » : elle y est avec celles
                            // des autres plans, et c'est là qu'on valide. La répéter ici
                            // ferait deux endroits pour le même chiffre.
                            $faitesIso = avancementSouduresFaites($db, (int) $element['id']);
                            ?>
                            <?php if ($faitesIso || $soudureTotal): ?>
                                <p class="atelier-soudures-renvoi plan-volet-section">
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

                            <section class="plan-volet-section">
                                <h4 class="atelier-plan-titre-section">Feuilles ISO</h4>
                                <?= planViewerRendu($elementIso, $plansIso, $couleesDisponibles, $modifiableIso) ?>
                            </section>

                            <?php if ($documentsAnnexes || ($canManageWorkshop && $taken && $optionsAnnexes)): ?>
                                <!-- Les pièces qui accompagnent le plan sans être des feuilles :
                                     on les télécharge, on ne travaille pas dessus. Repliées,
                                     elles ne prennent pas la place du vrai travail. -->
                                <section class="plan-volet-section atelier-file-management">
                                    <h4 class="atelier-plan-titre-section">Documents</h4>
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
                                            <p class="atelier-muted">QMOS et DMOS de la demande rattachés à ce plan :</p>
                                            <?php $selectedDocuments = array_map(fn($doc) => $doc['type_document'] . ':' . $doc['cle_document'], $element['documents']); ?>
                                            <?php foreach ($optionsAnnexes as $token => $label): ?>
                                                <label class="atelier-checkbox"><input type="checkbox" name="documents[]" value="<?= prefaEscape($token) ?>" <?= in_array($token, $selectedDocuments, true) ? 'checked' : '' ?>><span><?= prefaEscape($label) ?></span></label>
                                            <?php endforeach; ?>
                                            <div class="atelier-form-actions"><button type="submit">Enregistrer</button><span class="atelier-message" role="status" aria-live="polite"></span></div>
                                        </form>
                                    <?php endif; ?>
                                </section>
                            <?php endif; ?>

                        <?php if ($canManageWorkshop && $taken): ?>
                            <?php // Ce formulaire ne se montre plus : il ouvrait un panneau tout en bas
                                  // du plan, sous les feuilles et les documents, loin du poste qu'on
                                  // venait de cliquer. Le choix se fait maintenant dans un menu posé
                                  // sous le bouton de la personne (atelier.js), qui remplit ce
                                  // formulaire et l'envoie : un clic sur un nom suffit. Il reste dans
                                  // la page pour son jeton, la liste du personnel et l'envoi. ?>
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
                                <?php
                                // L'historique du plan, en frise comme celui des PV : chaque
                                // affectation, la plus récente en tête — qui, à quel poste, depuis
                                // quand, et quand elle a pris fin. Il était replié sous un
                                // « Historique des affectations (1) » qu'on n'ouvrait pas.
                                $frise = [];
                                foreach ($element['affectations'] as $affectation) {
                                    $frise[] = [
                                        'genre' => $affectation['actif'] ? 'fait' : 'a_faire',
                                        'titre' => $affectation['utilisateur_nom'] . ' · ' . ($types[$affectation['type_affectation']] ?? $affectation['type_affectation']),
                                        'texte' => $affectation['actif']
                                            ? 'Affecté' . ((int) $affectation['avancement'] > 0 ? ' · ' . (int) $affectation['avancement'] . ' %' : '')
                                            : 'Affectation terminée' . (!empty($affectation['date_fin_affectation']) ? ' le ' . date('d/m/Y à H:i', strtotime($affectation['date_fin_affectation'])) : ''),
                                        'quand' => (string) $affectation['date_affectation'],
                                        'commentaire' => (string) ($affectation['commentaire'] ?? ''),
                                    ];
                                }
                                usort($frise, static fn ($a, $b) => strcmp($b['quand'], $a['quand']));
                                ?>
                                <section class="plan-volet-section">
                                    <h4 class="atelier-plan-titre-section">Historique</h4>
                                    <?php if ($frise): ?>
                                        <ol class="pv-historique">
                                            <?php foreach ($frise as $evenement): ?>
                                                <li class="pv-historique-<?= $evenement['genre'] ?>">
                                                    <span class="pv-historique-signe" aria-hidden="true">
                                                        <?php if ($evenement['genre'] === 'fait'): ?>
                                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5" /><path d="M5 20c.7-3.6 3.5-5.5 7-5.5s6.3 1.9 7 5.5" /></svg>
                                                        <?php else: ?>
                                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8.5" stroke-dasharray="3 2.6" /></svg>
                                                        <?php endif; ?>
                                                    </span>
                                                    <div>
                                                        <strong><?= prefaEscape($evenement['titre']) ?></strong>
                                                        <p><?= prefaEscape($evenement['texte']) ?></p>
                                                        <?php if ($evenement['commentaire'] !== ''): ?><p class="plan-historique-note"><?= prefaEscape($evenement['commentaire']) ?></p><?php endif; ?>
                                                        <time datetime="<?= prefaEscape($evenement['quand']) ?>"><?= prefaEscape(date('d/m/Y à H:i', strtotime($evenement['quand']))) ?></time>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php else: ?>
                                        <p class="pv-volet-note">Personne n’est encore affecté à ce plan.</p>
                                    <?php endif; ?>
                                </section>
                        </article>
                    <?php endforeach; ?>


        </div>
        <?php if ($canManageWorkshop && $taken): ?>
            <dialog id="atelier-plan-dialog-<?= $requestId ?>" class="atelier-plan-dialog fenetre" aria-labelledby="atelier-plan-dialog-title-<?= $requestId ?>">
                <form class="atelier-action" data-endpoint="save_atelier.php">
                    <div class="fenetre-entete">
                        <h2 id="atelier-plan-dialog-title-<?= $requestId ?>">Renommer le plan</h2>
                        <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                    </div>
                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="element">
                    <input type="hidden" name="operation" value="update_element">
                    <div class="fenetre-corps">
                        <input class="fenetre-champ" name="reference" maxlength="80" required autocomplete="off" aria-label="Nom du plan" placeholder="Nom du plan">
                        <textarea class="fenetre-champ" name="libelle" maxlength="180" rows="3" required aria-label="Description" placeholder="Description"></textarea>
                        <span class="atelier-message fenetre-message" role="status" aria-live="polite"></span>
                    </div>
                    <div class="fenetre-pied">
                        <button type="button" class="atelier-close-plan-edit fenetre-annuler">Annuler</button>
                        <button type="submit" class="fenetre-valider">Enregistrer</button>
                    </div>
                </form>
            </dialog>

            <dialog id="atelier-progress-dialog-<?= $requestId ?>" class="atelier-plan-dialog fenetre" aria-labelledby="atelier-progress-dialog-title-<?= $requestId ?>">
                <form class="atelier-action" data-endpoint="update_atelier.php">
                    <div class="fenetre-entete">
                        <h2 id="atelier-progress-dialog-title-<?= $requestId ?>">Saisir l’avancement</h2>
                        <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                    </div>
                    <p class="atelier-progress-target fenetre-sous-titre"></p>
                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="operation" value="progress">
                    <input type="hidden" name="affectation">
                    <input type="hidden" name="revision">
                    <div class="fenetre-corps">
                        <!-- Deux façons de dire la même chose, et une seule sert à la fois : le plan
                             porte une liste de soudures, on compte des cordons ; sinon des pour cent.
                             Le champ écarté est désactivé, pas seulement masqué — masqué, il partirait
                             quand même avec le formulaire. -->
                        <div class="fenetre-bloc atelier-champ-soudures" hidden>
                            <label class="fenetre-etiquette" for="atelier-progress-soudures-<?= $requestId ?>">Soudures faites</label>
                            <input class="fenetre-champ" id="atelier-progress-soudures-<?= $requestId ?>" name="soudures" type="number" min="0" step="1" disabled>
                            <span class="fenetre-aide"><span class="atelier-soudures-origine"></span> · <output class="atelier-quotient"></output></span>
                        </div>
                        <div class="fenetre-bloc atelier-champ-pourcentage">
                            <label class="fenetre-etiquette" for="atelier-progress-value-<?= $requestId ?>">Avancement (%)</label>
                            <input class="fenetre-champ" id="atelier-progress-value-<?= $requestId ?>" name="avancement" type="number" min="0" max="100" step="1" required>
                        </div>
                        <textarea class="fenetre-champ" id="atelier-progress-note-<?= $requestId ?>" name="commentaire" maxlength="2000" rows="3" aria-label="Compte rendu" placeholder="Compte rendu (facultatif)"></textarea>
                        <span class="atelier-message fenetre-message" role="status" aria-live="polite"></span>
                    </div>
                    <div class="fenetre-pied">
                        <button type="button" class="atelier-close-plan-edit fenetre-annuler">Annuler</button>
                        <button type="submit" class="fenetre-valider">Enregistrer</button>
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
