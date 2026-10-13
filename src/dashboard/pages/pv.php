<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/pv.php';
require_once __DIR__ . '/../includes/commandes.php';
if (!$canViewPv) prefaError(403, 'Cette page est réservée à l’atelier et aux demandeurs.');
// Le chargé d'affaire suit l'avancement de ses PV sans les renseigner : la saisie
// appartient au chef d'atelier.
$pvLectureSeule = !$canFillPv;

// Le demandeur ne voit que ses propres affaires ; l'atelier voit tout le parc.
$sql = 'SELECT d.id, d.nom_affaire, u.prenom AS demandeur_prenom, u.name AS demandeur_nom
    FROM demande_prefabrication d
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    WHERE d.id_statut = 2';
$parametres = [];
if ($pvLimiteAuDemandeur) {
    $sql .= ' AND d.idUsers = ?';
    $parametres[] = $actor['id'];
}
$stmt = $db->prepare($sql . ' ORDER BY d.id DESC');
$stmt->execute($parametres);
$demandes = $stmt->fetchAll();

$types = pvTypes();
$statuts = pvStatuts();

// Un seul passage par demande : la lecture groupée ramène les quatre PV et leurs documents.
$pvParDemande = [];
$resteAFaire = 0;
$demandeurs = [];
foreach ($demandes as $demande) {
    $pvs = pvParDemande($db, (int) $demande['id']);
    $pvParDemande[(int) $demande['id']] = $pvs;
    $resteAFaire += pvAvancement($pvs)['restants'];
    $nom = trim(($demande['demandeur_prenom'] ?? '') . ' ' . ($demande['demandeur_nom'] ?? ''));
    if ($nom !== '') $demandeurs[$nom] = $nom;
}
ksort($demandeurs);
?>
<section class="page prefa-page prefa-list atelier-page" id="pv-page" aria-labelledby="pv-page-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="pv-page-title">Avancement préfabrication</h1>
                <p><?= $pvLimiteAuDemandeur ? 'Réception matériel, fin de fabrication, conformité et passivation, pour vos affaires.' : 'Réception matériel, fin de fabrication, conformité et passivation, demande par demande.' ?></p>
            </div>
            <button type="button" class="pv-refresh prefa-toggle">Actualiser</button>
        </header>

        <div class="prefa-filters" role="search">
            <div class="floating-field">
                <label for="pv-filtre-recherche">Rechercher</label>
                <input id="pv-filtre-recherche" class="floating-control" type="search" placeholder="N° de demande, affaire ou demandeur">
            </div>
            <div class="floating-field floating-always">
                <label for="pv-filtre-avancement">Avancement</label>
                <select id="pv-filtre-avancement" class="floating-control">
                    <option value="">Tous</option>
                    <option value="restants">PV à renseigner</option>
                    <option value="complets">Tout est renseigné</option>
                </select>
            </div>
            <?php if (!$pvLimiteAuDemandeur && count($demandeurs) > 1): ?>
                <div class="floating-field floating-always">
                    <label for="pv-filtre-demandeur">Demandeur</label>
                    <select id="pv-filtre-demandeur" class="floating-control">
                        <option value="">Tous les demandeurs</option>
                        <?php foreach ($demandeurs as $nom): ?><option value="<?= prefaEscape($nom) ?>"><?= prefaEscape($nom) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <p class="prefa-list-summary"><strong id="pv-compte"><?= count($demandes) ?></strong> <span id="pv-compte-libelle"><?= count($demandes) === 1 ? 'demande validée' : 'demandes validées' ?></span><?= $resteAFaire ? ' · ' . $resteAFaire . ' PV à faire' : '' ?></p>

        <div class="prefa-table-scroll" role="region" aria-label="PV par demande" tabindex="0">
            <table class="prefa-table pv-table">
                <thead><tr>
                    <th scope="col">Demande n°</th>
                    <th scope="col">Affaire</th>
                    <?php foreach ($types as $libelle): ?><th scope="col"><?= prefaEscape($libelle) ?></th><?php endforeach; ?>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$demandes): ?>
                    <tbody><tr><td colspan="7" class="prefa-table-empty"><?= $pvLimiteAuDemandeur ? 'Aucune de vos demandes n’est validée : il n’y a pas encore de PV à renseigner.' : 'Aucune demande validée : il n’y a pas encore de PV à renseigner.' ?></td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($demandes as $demande):
                    $id = (int) $demande['id'];
                    $pvs = $pvParDemande[$id];
                    $avancement = pvAvancement($pvs);
                    $demandeurNom = trim(($demande['demandeur_prenom'] ?? '') . ' ' . ($demande['demandeur_nom'] ?? ''));
                    $recherche = mb_strtolower(prefaReference($id) . ' ' . $id . ' ' . ($demande['nom_affaire'] ?? '') . ' ' . $demandeurNom);
                ?>
                    <tbody class="prefa-request pv-ligne"
                        data-recherche="<?= prefaEscape($recherche) ?>"
                        data-demandeur="<?= prefaEscape($demandeurNom) ?>"
                        data-restants="<?= $avancement['restants'] ?>">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete($id)) ?></td>
                            <td>
                                <strong><?= prefaEscape($demande['nom_affaire']) ?></strong>
                                <span class="atelier-row-description"><?= prefaEscape(trim(($demande['demandeur_prenom'] ?? '') . ' ' . ($demande['demandeur_nom'] ?? ''))) ?></span>
                            </td>
                            <?php foreach ($types as $type => $libelle): $pv = $pvs[$type]; ?>
                                <td><span class="pv-etat pv-etat-<?= prefaEscape($pv['statut']) ?>" title="<?= prefaEscape($libelle . ' — ' . $statuts[$pv['statut']]) ?>">
                                    <?= prefaEscape($statuts[$pv['statut']]) ?>
                                    <?php if ($pv['documents']): ?><span class="pv-compte-doc"><?= count($pv['documents']) ?></span><?php endif; ?>
                                </span></td>
                            <?php endforeach; ?>
                            <td>
                                <button type="button" class="prefa-toggle" aria-expanded="false" aria-controls="pv-detail-<?= $id ?>">
                                    <?= $avancement['restants'] ? 'Renseigner' : 'Revoir' ?>
                                </button>
                            </td>
                        </tr>

                        <tr id="pv-detail-<?= $id ?>" class="prefa-detail-row" hidden>
                            <td colspan="7">
                                <div class="pv-volet">
                                    <div class="prefa-detail-heading">
                                        <div>
                                            <h2>PV <span><?= prefaEscape(prefaReferenceComplete($id)) ?></span></h2>
                                            <p><?= prefaEscape($demande['nom_affaire']) ?> · <?= $avancement['faits'] ?>/<?= $avancement['total'] ?> renseignés</p>
                                        </div>
                                        <button type="button" class="prefa-detail-close" data-close-target="pv-detail-<?= $id ?>" aria-label="Fermer le volet">✕</button>
                                    </div>

                                    <?php foreach ($types as $type => $libelle): $pv = $pvs[$type]; $cle = $id . '-' . $type; ?>
                                        <details class="pv-bloc">
                                            <summary>
                                                <span class="pv-bloc-titre"><?= prefaEscape($libelle) ?></span>
                                                <span class="pv-etat pv-etat-<?= prefaEscape($pv['statut']) ?>"><?= prefaEscape($statuts[$pv['statut']]) ?></span>
                                                <?php if ($pv['date_pv']): ?><span class="pv-bloc-date"><?= prefaEscape(prefaFormatDate($pv['date_pv'])) ?></span><?php endif; ?>
                                                <?php if ($pv['documents']): ?><span class="pv-bloc-docs"><?= count($pv['documents']) ?> <?= count($pv['documents']) === 1 ? 'document' : 'documents' ?></span><?php endif; ?>
                                            </summary>

                                            <?php if ($pvLectureSeule): ?>
                                                <div class="pv-lecture">
                                                    <dl class="pv-lecture-champs">
                                                        <div><dt>État</dt><dd><?= prefaEscape($statuts[$pv['statut']]) ?></dd></div>
                                                        <div><dt>Date du PV</dt><dd><?= prefaEscape(prefaFormatDate($pv['date_pv'])) ?></dd></div>
                                                    </dl>

                                                    <?php if ($pv['commentaire']): ?>
                                                        <div class="pv-lecture-bloc">
                                                            <h3>Commentaire</h3>
                                                            <p><?= nl2br(prefaEscape($pv['commentaire'])) ?></p>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($type === 'reception_materiel'):
                                                        $commandes = commandesDeLaDemande($db, $id); ?>
                                                        <div class="pv-lecture-bloc">
                                                            <h3>Commandes fournisseur</h3>
                                                            <?php if ($commandes): ?>
                                                                <?php require __DIR__ . '/../partials/commandes_table.php'; ?>
                                                            <?php else: ?>
                                                                <p class="pv-lecture-vide">Aucune commande saisie.</p>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="pv-lecture-bloc">
                                                        <h3>Documents</h3>
                                                        <?php if ($pv['documents']): ?>
                                                            <?php $modifiable = false; require __DIR__ . '/../partials/pv_documents.php'; ?>
                                                        <?php else: ?>
                                                            <p class="pv-lecture-vide">Aucun document déposé.</p>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($pv['date_maj']): ?>
                                                        <span class="pv-signature">Dernière saisie : <?= prefaEscape(trim(($pv['auteur_prenom'] ?? '') . ' ' . ($pv['auteur_nom'] ?? ''))) ?> le <?= prefaEscape(prefaFormatDate($pv['date_maj'], true)) ?></span>
                                                    <?php else: ?>
                                                        <span class="pv-signature">Ce PV n’a pas encore été renseigné par l’atelier.</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                            <form class="prefa-form pv-form" data-cle="pv-form-<?= prefaEscape($cle) ?>" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                                                <input type="hidden" name="demande" value="<?= $id ?>">
                                                <input type="hidden" name="type" value="<?= prefaEscape($type) ?>">
                                                <input type="hidden" name="operation" value="save">
                                                <input type="hidden" name="revision" value="<?= (int) $pv['revision'] ?>">

                                                <div class="pv-champs">
                                                    <fieldset class="pv-statut">
                                                        <legend>État</legend>
                                                        <?php foreach ($statuts as $valeur => $texte): ?>
                                                            <label class="pv-radio">
                                                                <input type="radio" name="statut" value="<?= prefaEscape($valeur) ?>" <?= $pv['statut'] === $valeur ? 'checked' : '' ?>>
                                                                <span><?= prefaEscape($texte) ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </fieldset>

                                                    <div class="form-group">
                                                        <label for="pv-date-<?= prefaEscape($cle) ?>">Date du PV</label>
                                                        <input id="pv-date-<?= prefaEscape($cle) ?>" name="date_pv" type="date" value="<?= prefaEscape((string) ($pv['date_pv'] ?? '')) ?>">
                                                    </div>
                                                </div>

                                                <?php if ($type === 'reception_materiel'):
                                                    $commandes = commandesDeLaDemande($db, $id); ?>
                                                    <?php require __DIR__ . '/../partials/commandes_form.php'; ?>
                                                <?php endif; ?>

                                                <div class="form-group">
                                                    <label for="pv-commentaire-<?= prefaEscape($cle) ?>">Commentaire</label>
                                                    <textarea id="pv-commentaire-<?= prefaEscape($cle) ?>" name="commentaire" maxlength="1000" rows="2"><?= prefaEscape((string) ($pv['commentaire'] ?? '')) ?></textarea>
                                                </div>

                                                <?php if ($pv['documents']): ?>
                                                    <?php $modifiable = true; require __DIR__ . '/../partials/pv_documents.php'; ?>
                                                <?php endif; ?>

                                                <div class="pv-depot">
                                                    <div class="pv-fichiers">
                                                        <input id="pv-fichiers-<?= prefaEscape($cle) ?>" class="pv-fichiers-input" name="documents[]" type="file" accept=".pdf,.png,.jpg,.jpeg" multiple>
                                                        <label class="pv-fichiers-zone" for="pv-fichiers-<?= prefaEscape($cle) ?>">
                                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M12 16V4" /><path d="m7 9 5-5 5 5" /><path d="M20 16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2" />
                                                            </svg>
                                                            <span class="pv-fichiers-texte">Ajouter des documents</span>
                                                            <span class="pv-fichiers-aide">PDF, PNG ou JPEG — 50 Mo maximum</span>
                                                        </label>
                                                    </div>
                                                    <button type="submit">Enregistrer</button>
                                                </div>

                                                <div class="atelier-form-actions">
                                                    <span class="atelier-message" role="status" aria-live="polite"></span>
                                                    <?php if ($pv['date_maj']): ?>
                                                        <span class="pv-signature">Dernière saisie : <?= prefaEscape(trim(($pv['auteur_prenom'] ?? '') . ' ' . ($pv['auteur_nom'] ?? ''))) ?> le <?= prefaEscape(prefaFormatDate($pv['date_maj'], true)) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                            <?php endif; ?>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
