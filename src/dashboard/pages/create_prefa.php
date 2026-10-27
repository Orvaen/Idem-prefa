<?php
require_once __DIR__ . '/../includes/prefa_context.php';
if (!$canEditPrefa) prefaError(403, 'Création et modification réservées aux demandeurs.');
require_once __DIR__ . '/../includes/demande/prefa_attachments.php';
require_once __DIR__ . '/../includes/demande/prefa_hours.php';
require_once __DIR__ . '/../includes/vue/liste_filtrable.php';

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
$controles = [
    ['name' => 'CDN', 'code' => 'CND', 'libelle' => 'Contrôles non destructifs', 'key' => 'cdn', 'input' => 'controles'],
    ['name' => 'RT', 'code' => 'RT', 'libelle' => 'Radiographie', 'key' => 'rt', 'input' => 'controles-rt'],
    ['name' => 'PT', 'code' => 'PT', 'libelle' => 'Ressuage', 'key' => 'pt', 'input' => 'controles-pt'],
];
// Les documents complémentaires : une ligne chacun, que l'on peut cliquer ou sur laquelle
// on peut déposer. Le plan, le QMOS et le DMOS sont obligatoires — on ne soude pas sans
// le mode opératoire ni sa qualification, et actions/create_prefa.php refuse la demande
// qui s'en passe ; la fiche matériel et les CCPU s'ajoutent plus tard s'ils manquent.
// Aucun ne mérite pour autant cinq grandes zones de dépôt qui se ressemblent toutes.
$complements = [
    ['id' => 'qmos', 'nom' => 'qmos_pdf[]', 'titre' => 'QMOS', 'aide' => 'Qualification du mode opératoire de soudage',
     'obligatoire' => true,
     'fichiers' => $qmosFiles, 'liste' => 'qmos-saved-files', 'retirer' => 'prefa-remove-qmos-saved', 'telecharger' => 'download_qmos.php'],
    // La liste des DMOS déjà en base porte un identifiant comme celle des QMOS : c'est là que
    // la vérification d'envoi compte ce qui reste, et sans lui une modification se refusait
    // alors que le document était bien là.
    ['id' => 'dmos', 'nom' => 'dmos_pdf[]', 'titre' => 'DMOS', 'aide' => 'Descriptif du mode opératoire de soudage',
     'obligatoire' => true,
     'fichiers' => $dmosFiles, 'liste' => 'dmos-saved-files', 'retirer' => 'prefa-remove-dmos-saved', 'telecharger' => 'download_dmos.php'],
    ['id' => 'materiel', 'nom' => 'materiel_pdf[]', 'titre' => 'Fiche matériel', 'aide' => 'Liste du matériel à prévoir',
     'fichiers' => $materielFiles, 'liste' => null, 'retirer' => 'prefa-remove-materiel-saved', 'telecharger' => 'download_materiel.php'],
    ['id' => 'ccpu', 'nom' => 'ccpu_pdf[]', 'titre' => 'CCPU', 'aide' => 'Certificats matière',
     'fichiers' => $ccpuFiles, 'liste' => null, 'retirer' => 'prefa-remove-ccpu-saved', 'telecharger' => 'download_ccpu.php'],
];
?>

<?php // La page a sa propre apparence — ivoire en thème clair, brun chaud en sombre — et ses
      // propres polices : voir css/dashboard/nouvelle-demande.css. data-static-fields
      // tient floating-fields.js à l'écart : ici l'étiquette se pose au-dessus du champ. ?>
<?php
// La demande se rattache à une affaire ouverte, elle n'en crée pas une : c'est le chargé de
// production qui ouvre le chantier et désigne qui le suit. On ne choisit donc que parmi les
// siennes, et sans issue « Autre ».
//
// Le calcul est remonté ici parce que l'écran entier en dépend, et non la seule liste
// déroulante : sans affaire, il n'y a pas de demande à faire.
$affairesOuvertes = Affaire::pour($db, $utilisateur);
?>

<?php if (!$editing && !$affairesOuvertes): ?>
    <?php // Rien à rattacher, donc rien à remplir. On montrait le formulaire entier —
          // plans, documents, planning, matière, contrôles — et son bouton d'envoi, pour
          // une demande qui ne pouvait pas partir : la personne remplissait tout avant de
          // l'apprendre. L'écran se tient maintenant à ce qu'il y a à dire. ?>
    <section class="page prefa-page prefa-nouvelle">
        <header class="nd-entete">
            <div class="nd-titre">
                <button type="button" class="prefa-retour nd-retour bouton-discret"><span aria-hidden="true">&larr;</span> Demandes</button>
                <h1>Nouvelle demande</h1>
                <p>Une demande se rattache à une affaire ouverte à votre nom.</p>
            </div>
        </header>
        <div class="nd-sans-affaire">
            <p>Aucune affaire ne vous est confiée pour l’instant.</p>
            <p class="nd-discret">Demandez au chargé de production d’en ouvrir une à votre nom :
                vous pourrez alors y déposer vos demandes.</p>
        </div>
    </section>
<?php else: ?>

<section class="page prefa-page prefa-nouvelle">
    <?php // Tant qu'aucune affaire n'est choisie, le reste du formulaire n'a pas lieu d'être :
      // une demande se rattache à une affaire, et on remplissait plans, documents, planning
      // et contrôles sans savoir à quoi ils se rapportaient. Le marqueur est posé ici, le
      // script le retire dès que le choix est fait. En modification, l'affaire est connue. ?>
<form id="prefa-form" class="prefa-form nd-form<?= (!$editing && empty($request['id_affaire'])) ? ' nd-attend-affaire' : '' ?>" novalidate enctype="multipart/form-data" data-static-fields data-hours-per-inch="<?= prefaEscape($hoursPerInch) ?>">
        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><?php endif; ?>

        <header class="nd-entete">
            <div class="nd-titre">
                <button type="button" class="prefa-retour nd-retour bouton-discret"><span aria-hidden="true">&larr;</span> Demandes</button>
                <!-- « Nouvelle demande » et non « Nouvelle affaire » : c'est le chargé de
                     production qui ouvre l'affaire, et on en dépose plusieurs sur la même. -->
                <h1><?= $editing ? 'Modifier la demande ' . prefaEscape(prefaReferenceComplete((int) $request['id'])) : 'Nouvelle demande' ?></h1>
                <p><?= $editing ? 'Après modification, la demande repassera en attente de validation.' : 'Une affaire peut porter plusieurs demandes.' ?></p>
            </div>
        </header>

        <p class="prefa-message" role="status" aria-live="polite"></p>

        <div class="nd-colonnes">

            <!-- À gauche : de quoi il s'agit — l'affaire et ses documents. -->
            <div class="nd-colonne">
                <?php if ($affairesOuvertes): ?>
                    <?= listeFiltrableRendu('affaire', 'Affaire',
                        array_map(static fn (Affaire $a): array => ['id' => $a->id, 'libelle' => $a->nom], $affairesOuvertes),
                        $request['id_affaire'] ?? null,
                        ['vide' => 'Choisissez l’affaire…', 'aucun' => 'Aucune affaire de ce nom.',
                         'obligatoire' => true, 'chercher' => 'Rechercher parmi vos affaires…',
                         'aide' => '']) ?>
                <?php else: ?>
                    <!-- Sans affaire, rien à déposer : on le dit plutôt que de montrer une
                         liste vide dont on ne saurait pas quoi faire. -->
                    <div class="form-group">
                        <label for="affaire-search">Affaire <span class="required-mark" aria-hidden="true">*</span></label>
                        <p class="prefa-aide">Aucune affaire ne vous est confiée pour l’instant.
                            Demandez au chargé de production d’en ouvrir une à votre nom.</p>
                    </div>
                <?php endif; ?>

                <div class="form-group nd-plans">
                    <label for="plan-documents">Plans BPE / ISO<?php if (!$editing): ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?></label>
                    <label class="prefa-dropzone nd-depot" for="plan-documents" tabindex="0">
                        <span class="nd-depot-titre">Déposez vos plans ici, ou <u>choisissez des fichiers</u></span>
                        <span class="nd-depot-aide">PDF, images, Word, Excel · 50 Mo par fichier</span>
                    </label>
                    <input id="plan-documents" name="plan_documents[]" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx" multiple hidden>
                    <div id="plan-selected-files" class="prefa-file-list"></div>
                    <?php if ($attachments): ?><div id="plan-saved-files" class="prefa-file-list"><?php foreach ($attachments as $attachment): ?><div class="prefa-file-row"><a href="actions/download_prefa_attachment.php?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= rawurlencode($attachment['key']) ?>"><?= prefaEscape($attachment['name']) ?></a><button type="button" class="prefa-remove-saved" data-key="<?= prefaEscape($attachment['key']) ?>" aria-label="Retirer <?= prefaEscape($attachment['name']) ?>">Retirer</button></div><?php endforeach; ?></div><?php endif; ?>
                </div>

                <div class="nd-complements">
                    <div class="nd-complements-entete">
                        <span>Documents complémentaires</span>
                        <span class="nd-discret">PDF · facultatifs sauf <span class="required-mark" aria-hidden="true">*</span></span>
                    </div>
                    <?php foreach ($complements as $doc): ?>
                        <div class="nd-complement">
                            <label class="prefa-dropzone nd-ligne" for="<?= $doc['id'] ?>" tabindex="0">
                                <span class="nd-ligne-texte">
                                    <span class="nd-ligne-titre"><?= prefaEscape($doc['titre']) ?><?php if (!empty($doc['obligatoire'])): ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?></span>
                                    <span class="nd-discret"><?= prefaEscape($doc['aide']) ?></span>
                                </span>
                                <span class="nd-ligne-action"><span id="<?= $doc['id'] ?>-help" class="nd-ligne-etat"></span>Ajouter un PDF</span>
                            </label>
                            <input id="<?= $doc['id'] ?>" name="<?= $doc['nom'] ?>" type="file" accept=".pdf,application/pdf" aria-describedby="<?= $doc['id'] ?>-help" multiple hidden>
                            <div id="<?= $doc['id'] ?>-selected-files" class="prefa-file-list"></div>
                            <?php if ($doc['fichiers']): ?><div<?= $doc['liste'] ? ' id="' . $doc['liste'] . '"' : '' ?> class="prefa-file-list"><?php foreach ($doc['fichiers'] as $fichier): ?><div class="prefa-file-row"><a href="actions/<?= $doc['telecharger'] ?>?ref=<?= rawurlencode(prefaReference((int) $request['id'])) ?>&amp;file=<?= (int) $fichier['id'] ?>"><?= prefaEscape($fichier['nom']) ?></a><button type="button" class="<?= $doc['retirer'] ?>" data-id="<?= (int) $fichier['id'] ?>">Retirer</button></div><?php endforeach; ?></div><?php elseif ($doc['id'] === 'qmos' && !empty($request[' QMOS'])): ?><small class="nd-discret">Ancienne référence : <?= $field(' QMOS') ?></small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- À droite : ce que l'atelier doit faire. -->
            <div class="nd-colonne">
                <fieldset class="nd-groupe">
                    <legend>Planning</legend>
                    <div class="nd-grille">
                        <div class="form-group">
                            <label for="date-fin">Fin de fabrication <span class="required-mark" aria-hidden="true">*</span></label>
                            <input id="date-fin" name="date_fin_prevue" type="date" value="<?= $field('date_fin_prevue') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="date-livraison">Livraison au plus tard <span class="required-mark" aria-hidden="true">*</span></label>
                            <input id="date-livraison" name="date_livraison_prevue" type="date" value="<?= $field('date_livraison_prevue') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="pouces">Pouces ISO <span class="required-mark" aria-hidden="true">*</span></label>
                            <input id="pouces" name="pouces_total_iso" value="<?= $field('pouces_total_iso') ?>" type="number" min="0" max="2147483647" step="1" placeholder="0" required>
                        </div>
                        <?php // La durée se calcule d'après les pouces : elle se lit, elle ne se saisit
                              // pas. Le champ reste (le script l'écrit, le serveur le reçoit) mais
                              // n'a plus l'air d'une case à remplir. ?>
                        <div class="form-group nd-duree">
                            <label for="heures">Durée estimée</label>
                            <span class="nd-duree-valeur"><input id="heures" name="heures_chiffrees" value="<?= $editing ? prefaEscape(number_format((float) prefaCalculatedHours((int) $request['pouces_total_iso'], $hoursPerInch) / 24, 2, '.', '')) : '' ?>" type="number" step="0.01" readonly placeholder="—" tabindex="-1"> jours</span>
                            <span class="nd-discret">Calculée d’après les pouces</span>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="nd-groupe">
                    <legend>Matière</legend>
                    <div class="nd-grille">
                        <?= listeFiltrableRendu('matiere', 'Matière', $matieres,
                            $request['id_matiere'] ?? null,
                            ['vide' => 'Matière non précisée', 'autre' => 'Autre (préciser)...',
                             'aucun' => 'Aucun type trouvé.', 'chercher' => 'Non précisée']) ?>
                        <?= listeFiltrableRendu('passivation', 'Passivation', $passivations,
                            $request['id_passivation'] ?? null,
                            ['vide' => 'Aucune passivation', 'autre' => 'Autre (préciser)...',
                             'aucun' => 'Aucun type trouvé.', 'chercher' => 'Aucune']) ?>
                        <?= listeFiltrableAutreRendu('matiere', 'Préciser la matière',
                            'Ex. Inox 316L…', 'matiere_autre') ?>
                        <?= listeFiltrableAutreRendu('passivation', 'Préciser le type de passivation',
                            'Ex. Passivation électrolytique…', 'passivation_autre') ?>
                        <div class="form-group prefa-full" id="matiere-disponibilite-field" <?= empty($request['id_matiere']) ? 'hidden' : '' ?>>
                            <label for="matiere-disponibilite">Disponibilité de la matière <span id="matiere-disponibilite-required" class="required-mark" aria-hidden="true" <?= empty($request['id_matiere']) ? 'hidden' : '' ?>>*</span></label>
                            <select id="matiere-disponibilite" name="matiere_disponibilite" class="prefa-select" <?= empty($request['id_matiere']) ? '' : 'required' ?>>
                                <option value="">À préciser</option>
                                <option value="stock" <?= ($request['matiere_disponibilite'] ?? '') === 'stock' ? 'selected' : '' ?>>En stock</option>
                                <option value="commande" <?= ($request['matiere_disponibilite'] ?? '') === 'commande' ? 'selected' : '' ?>>À commander</option>
                            </select>
                        </div>
                        <div class="form-group prefa-full">
                            <label for="revetement-type">Revêtement des tubes</label>
                            <select id="revetement-type" name="revetement_type" class="prefa-select">
                                <option value="aucun" <?= $currentRevetementType === 'aucun' ? 'selected' : '' ?>>Sans revêtement</option>
                                <option value="exterieur" <?= $currentRevetementType === 'exterieur' ? 'selected' : '' ?>>Revêtement extérieur</option>
                                <option value="interieur" <?= $currentRevetementType === 'interieur' ? 'selected' : '' ?>>Revêtement intérieur</option>
                                <option value="les_deux" <?= $currentRevetementType === 'les_deux' ? 'selected' : '' ?>>Revêtement intérieur et extérieur</option>
                            </select>
                        </div>
                        <div class="form-group prefa-full" id="revetement-details" <?= $currentRevetementType === 'aucun' ? 'hidden style="display: none;"' : '' ?>>
                            <label for="revetement-precision">Précision du revêtement <span class="nd-discret">· facultatif</span></label>
                            <input id="revetement-precision" name="revetement_precision" type="text" maxlength="255" value="<?= prefaEscape($currentRevetementPrecision) ?>" placeholder="Ex. Peinture anticorrosion, époxy…">
                        </div>
                    </div>
                </fieldset>

                <?php // Une ligne par contrôle : on coche, et le pourcentage apparaît au bout de
                      // la ligne. Le curseur reste dans la page — c'est lui qui porte le nom
                      // envoyé au serveur, et le script le tient à jour depuis la case — mais
                      // il n'est plus montré : on tape un nombre, on ne vise pas une graduation. ?>
                <fieldset class="nd-groupe nd-controles">
                    <legend>Contrôles</legend>
                    <?php foreach ($controles as $control):
                        $enabled = !empty($request[$control['name']]);
                        $percent = filter_var($request['controles_' . $control['key']] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 1.5;
                    ?>
                        <div class="nd-controle">
                            <label class="nd-controle-nom"><input type="checkbox" name="<?= $control['name'] ?>" value="1" <?= $enabled ? 'checked' : '' ?>> <?= $control['libelle'] ?> <span class="nd-discret"><?= $control['code'] ?></span></label>
                            <fieldset class="form-group prefa-percent-field nd-pourcentage" id="<?= $control['key'] ?>-details" <?= $enabled ? '' : 'hidden' ?>>
                                <legend class="nd-sr">Pourcentage <?= $control['code'] ?></legend>
                                <input id="<?= $control['input'] ?>" name="controles_<?= $control['key'] ?>" class="prefa-control-range" type="range" min="1" max="100" step="0.5" value="<?= $percent ?>" aria-hidden="true" tabindex="-1" <?= $enabled ? 'required' : 'disabled' ?>>
                                <input id="<?= $control['input'] ?>-value" class="prefa-control-value" type="text" inputmode="decimal" value="<?= $percent ?>" aria-label="Pourcentage <?= $control['code'] ?>" <?= $enabled ? 'required' : 'disabled' ?>>
                                <span class="cnd-unit" aria-hidden="true">%</span>
                            </fieldset>
                            <span class="nd-non-demande">Non demandé</span>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            </div>

        </div>

        <?php // L'envoi est en bas, collé au bord de la fenêtre. Il était en haut, à côté du
              // titre, du temps où le formulaire tenait sur un écran : en une seule colonne
              // il n'y tient plus, et il fallait tout remonter pour trouver le bouton. La
              // barre reste donc lisible où qu'on en soit — même principe que la décision
              // d'une fiche de demande.
              ?>
        <div class="nd-barre">
            <div class="nd-envoi">
                <label class="nd-urgent"><input type="checkbox" name="urgent" value="1" <?= !empty($request['urgent']) ? 'checked' : '' ?>> Demande urgente</label>
                <div class="nd-boutons prefa-actions">
                    <button type="button" class="cancel-prefa-edit nd-annuler bouton-discret">Annuler</button>
                    <button type="submit" class="nd-envoyer"><?= $editing ? 'Enregistrer' : 'Envoyer la demande' ?></button>
                </div>
            </div>
        </div>

        <?php // « Haut de page » se tient sur le côté, au bord droit et à mi-hauteur : posé
              // dans la barre, puis juste au-dessus d'elle, il suivait la barre et restait
              // donc en bas. Il ne paraît qu'une fois qu'on a défilé — en haut de page, il
              // n'aurait rien à proposer. ?>
        <button type="button" class="nd-haut bouton-discret" aria-label="Revenir en haut de la page" title="Haut de page" hidden>
            <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 12.5v-9M3.5 8 8 3.5 12.5 8"/></svg>
        </button>
    </form>
</section>

<?php endif; ?>
