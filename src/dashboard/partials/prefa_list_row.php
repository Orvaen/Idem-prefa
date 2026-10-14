<?php
$requestId = (int) $row['id'];
$isPending = (int) $row['id_statut'] === 1;
$isUrgentPending = !empty($row['urgent']) && $isPending;
// Une demande validée que l'atelier n'a pas encore reprise : l'action de prise en
// charge s'offre ici comme sur la page Plans / ISO.

$ownerName = trim($row['prenom'] . ' ' . $row['name']);
?>
<tbody class="prefa-request<?= $isUrgentPending ? ' is-urgent-pending' : '' ?>">
    <tr>
        <?php // Le numéro seul : le chantier est dans la colonne d'à côté quand la liste n'est
              // pas groupée, et dans le titre du groupe quand elle l'est. Le répéter ici
              // allongeait la colonne de deux cents pixels pour redire la voisine. ?>
        <td class="prefa-reference"><?= prefaEscape(prefaReference($requestId)) ?></td>
        <?php if (!$vueParAffaire): ?>
            <td><?= prefaEscape($row['nom_affaire'] ?: '—') ?></td>
        <?php endif; ?>
        <td><?= $row['date_creation'] ? prefaEscape(prefaFormatDate($row['date_creation'])) : '—' ?></td>
        <td><?= $row['date_fin_prevue'] ? prefaEscape(prefaFormatDate($row['date_fin_prevue'])) : '—' ?></td>
        <td><?= $row['urgent'] ? '<span class="prefa-urgent">Urgente</span>' : 'Normale' ?></td>
        <td><?= prefaEscape($row['pouces_total_iso']) ?></td>
        <td><?= prefaEscape(prefaFormatDays((float) $row['heures_chiffrees'] / 24)) ?></td>
        <td><?= prefaEscape($ownerName) ?></td>
        <td><span class="prefa-status status-<?= (int) $row['id_statut'] ?>"><?= prefaEscape($row['libelle']) ?></span></td>
        <td>
            <button type="button" class="prefa-toggle" aria-expanded="false" aria-controls="prefa-detail-<?= $requestId ?>">
                <?= $isAdmin && $isPending ? 'Examiner' : 'Détails' ?>
            </button>
            <?php if ($canEditPrefa): ?><button type="button" class="edit-prefa" data-id="<?= $requestId ?>">Modifier</button><?php endif; ?>

            <?php if ($canEditPrefa && ($isAdmin || ($isPending && (int) $row['idUsers'] === (int) $actor['id']))): ?>
                <button type="button" class="delete-prefa" data-id="<?= $requestId ?>" data-csrf="<?= prefaEscape(Session::jeton()) ?>">Supprimer</button>
            <?php endif; ?>



        </td>
    </tr>
    <tr id="prefa-detail-<?= $requestId ?>" class="prefa-detail-row" hidden>
        <td colspan="<?= $vueParAffaire ? 9 : 10 ?>">
            <div class="prefa-table-detail">
                <?php require __DIR__ . '/prefa_detail.php'; ?>
                <?php if ($isAdmin && $isPending): ?>
                    <form class="prefa-review prefa-form">
                        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                        <input type="hidden" name="id" value="<?= $requestId ?>">

                        <div class="prefa-review-barre">
                            <div class="prefa-review-motif">
                                <label for="comment-<?= $requestId ?>">Commentaire <span>— obligatoire pour un refus</span></label>
                                <textarea id="comment-<?= $requestId ?>" name="commentaire" maxlength="1000" rows="1"
                                          placeholder="Motif, réserve, consigne pour l’atelier…"></textarea>
                            </div>
                            <div class="prefa-review-actions">
                                <p class="prefa-message" role="status" aria-live="polite"></p>
                                <div class="prefa-buttons">
                                    <button type="submit" value="3" class="reject bouton-discret">Refuser</button>
                                    <button type="submit" value="2">Valider</button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</tbody>
