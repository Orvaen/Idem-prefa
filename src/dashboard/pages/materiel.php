<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/approvisionnement.php';
if (!$isWorkshopPersonnel) prefaError(403, 'Cette page est réservée au personnel atelier.');

// Les demandes sur lesquelles la personne travaille : ce sont les seules où elle
// peut signaler un manque.
$stmt = $db->prepare('SELECT DISTINCT d.id, d.nom_affaire
    FROM affectation_atelier a
    JOIN element_atelier e ON e.id = a.id_element
    JOIN demande_prefabrication d ON d.id = e.id_demande
    WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
    ORDER BY d.id DESC');
$stmt->execute([$actor['id']]);
$demandes = $stmt->fetchAll();

$stmt = $db->prepare('SELECT a.*, d.nom_affaire, t.prenom AS traitant_prenom, t.name AS traitant_nom
    FROM demande_approvisionnement a
    JOIN demande_prefabrication d ON d.id = a.id_demande
    LEFT JOIN Utilisateur t ON t.id = a.traite_par
    WHERE a.demande_par = ?
    ORDER BY a.date_creation DESC, a.id DESC');
$stmt->execute([$actor['id']]);
$signalements = $stmt->fetchAll();

$motifs = appoMotifs();
$statuts = appoStatuts();
?>
<section class="page prefa-page prefa-list atelier-page" id="materiel-page" aria-labelledby="materiel-page-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="materiel-page-title">Matériel</h1>
                <p>Signalez un matériel manquant sur l’une de vos affectations : le signalement part au chef d’atelier.</p>
            </div>
            <button type="button" class="appro-refresh prefa-toggle">Actualiser</button>
        </header>

        <?php if (!$demandes): ?>
            <p class="prefa-table-empty">Aucune affectation en cours : il n’y a pas de demande sur laquelle signaler un manque.</p>
        <?php else: ?>
            <form class="prefa-form appro-form appro-create-form" aria-label="Signaler un matériel manquant">
                <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                <input type="hidden" name="operation" value="create">

                <div class="appro-create-grid">
                    <div class="form-group">
                        <label for="materiel-demande">Demande concernée</label>
                        <select id="materiel-demande" name="demande" required>
                            <?php foreach ($demandes as $demande): ?>
                                <option value="<?= (int) $demande['id'] ?>"><?= prefaEscape(prefaReferenceComplete((int) $demande['id'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="materiel-designation">Matériel manquant</label>
                        <input id="materiel-designation" name="designation" maxlength="180" required placeholder="Ex. : coude 90° à souder DN50">
                    </div>

                    <div class="form-group">
                        <label for="materiel-quantite">Quantité</label>
                        <input id="materiel-quantite" name="quantite" type="number" min="0.001" step="0.001" required>
                    </div>

                    <div class="form-group">
                        <label for="materiel-unite">Unité</label>
                        <select id="materiel-unite" name="unite" required>
                            <?php foreach (appoUnites() as $cle => $libelle): ?>
                                <option value="<?= prefaEscape($cle) ?>"><?= prefaEscape($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="materiel-motif">Motif</label>
                        <select id="materiel-motif" name="motif" required>
                            <?php foreach ($motifs as $cle => $libelle): ?>
                                <option value="<?= prefaEscape($cle) ?>"><?= prefaEscape($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group appro-create-note">
                        <label for="materiel-commentaire">Précisions</label>
                        <textarea id="materiel-commentaire" name="commentaire" maxlength="1000" rows="2" placeholder="Où le manque a été constaté, contrainte de délai…"></textarea>
                    </div>
                </div>

                <div class="atelier-form-actions">
                    <button type="submit">Signaler au chef d’atelier</button>
                    <span class="atelier-message" role="status" aria-live="polite"></span>
                </div>
            </form>
        <?php endif; ?>

        <h2 class="appro-sous-titre">Mes signalements</h2>
        <p class="prefa-list-summary"><strong><?= count($signalements) ?></strong> <?= count($signalements) === 1 ? 'signalement' : 'signalements' ?></p>

        <div class="prefa-table-scroll" role="region" aria-label="Mes signalements de matériel" tabindex="0">
            <table class="prefa-table appro-table">
                <thead><tr>
                    <th scope="col">Demande n°</th>
                    <th scope="col">Matériel</th>
                    <th scope="col">Quantité</th>
                    <th scope="col">Motif</th>
                    <th scope="col">Signalé le</th>
                    <th scope="col">État</th>
                </tr></thead>

                <?php if (!$signalements): ?>
                    <tbody><tr><td colspan="6" class="prefa-table-empty">Vous n’avez signalé aucun manque.</td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($signalements as $signalement): ?>
                    <tbody class="prefa-request">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $signalement['id_demande'])) ?></td>
                            <td>
                                <strong><?= prefaEscape($signalement['designation']) ?></strong>
                                <?php if ($signalement['commentaire']): ?><span class="atelier-row-description"><?= prefaEscape($signalement['commentaire']) ?></span><?php endif; ?>
                            </td>
                            <td><?= prefaEscape(appoQuantite($signalement['quantite']) . ' ' . $signalement['unite']) ?></td>
                            <td><?= prefaEscape($motifs[$signalement['motif']]) ?></td>
                            <td><?= prefaEscape(prefaFormatDate($signalement['date_creation'], true)) ?></td>
                            <td>
                                <span class="appro-etat appro-etat-<?= prefaEscape($signalement['statut']) ?>"><?= prefaEscape($statuts[$signalement['statut']]) ?></span>
                                <?php if ($signalement['date_traitement']): ?>
                                    <span class="atelier-row-description"><?= prefaEscape(trim(($signalement['traitant_prenom'] ?? '') . ' ' . ($signalement['traitant_nom'] ?? ''))) ?> · <?= prefaEscape(prefaFormatDate($signalement['date_traitement'], true)) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
