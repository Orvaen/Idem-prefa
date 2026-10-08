<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/approvisionnement.php';
if (!$canManageWorkshop) prefaError(403, 'Cette page est réservée au chef d’atelier.');

$signalements = $db->query('SELECT a.*, d.nom_affaire,
        u.prenom AS auteur_prenom, u.name AS auteur_nom,
        t.prenom AS traitant_prenom, t.name AS traitant_nom
    FROM demande_approvisionnement a
    JOIN demande_prefabrication d ON d.id = a.id_demande
    JOIN Utilisateur u ON u.id = a.demande_par
    LEFT JOIN Utilisateur t ON t.id = a.traite_par
    ORDER BY FIELD(a.statut, \'nouvelle\', \'vue\', \'traitee\'), a.date_creation DESC')->fetchAll();

$motifs = appoMotifs();
$statuts = appoStatuts();
$aFaire = count(array_filter($signalements, static fn ($s) => $s['statut'] === 'nouvelle'));
$vues = count(array_filter($signalements, static fn ($s) => $s['statut'] === 'vue'));
?>
<section class="page prefa-page prefa-list atelier-page" id="signalements-page" aria-labelledby="signalements-page-title">
    <div class="prefa-shell">
        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="signalements-page-title">Signalements matériel</h1>
                <p>Les manques de matériel remontés par l’atelier sur les demandes en cours.</p>
            </div>
            <button type="button" class="appro-refresh prefa-toggle">Actualiser</button>
        </header>

        <p class="prefa-list-summary"><strong><?= count($signalements) ?></strong> <?= count($signalements) === 1 ? 'signalement' : 'signalements' ?><?= $aFaire ? ' · ' . $aFaire . ' à faire' : '' ?><?= $vues ? ' · ' . $vues . ($vues === 1 ? ' vue' : ' vues') : '' ?></p>

        <div class="prefa-table-scroll" role="region" aria-label="Signalements de matériel manquant" tabindex="0">
            <table class="prefa-table appro-table">
                <thead><tr>
                    <th scope="col">Demande n°</th>
                    <th scope="col">Matériel manquant</th>
                    <th scope="col">Quantité</th>
                    <th scope="col">Motif</th>
                    <th scope="col">Signalé par</th>
                    <th scope="col">État</th>
                    <th scope="col">Actions</th>
                </tr></thead>

                <?php if (!$signalements): ?>
                    <tbody><tr><td colspan="7" class="prefa-table-empty">Aucun matériel manquant signalé par l’atelier.</td></tr></tbody>
                <?php endif; ?>

                <?php foreach ($signalements as $signalement):
                    $id = (int) $signalement['id'];
                    $statut = $signalement['statut'];
                ?>
                    <tbody class="prefa-request">
                        <tr>
                            <td><?= prefaEscape(prefaReferenceComplete((int) $signalement['id_demande'])) ?></td>
                            <td>
                                <strong><?= prefaEscape($signalement['designation']) ?></strong>
                                <?php if ($signalement['commentaire']): ?><span class="atelier-row-description"><?= prefaEscape($signalement['commentaire']) ?></span><?php endif; ?>
                            </td>
                            <td><?= prefaEscape(appoQuantite($signalement['quantite']) . ' ' . $signalement['unite']) ?></td>
                            <td><?= prefaEscape($motifs[$signalement['motif']]) ?></td>
                            <td>
                                <?= prefaEscape(trim($signalement['auteur_prenom'] . ' ' . $signalement['auteur_nom'])) ?>
                                <span class="atelier-row-description"><?= prefaEscape(prefaFormatDate($signalement['date_creation'], true)) ?></span>
                            </td>
                            <td><span class="appro-etat appro-etat-<?= prefaEscape($signalement['statut']) ?>"><?= prefaEscape($statuts[$signalement['statut']]) ?></span></td>
                            <td class="appro-actions-cell">
                                <form id="appro-<?= $id ?>" class="appro-form appro-statut-form"></form>
                                <input type="hidden" form="appro-<?= $id ?>" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>">
                                <input type="hidden" form="appro-<?= $id ?>" name="operation" value="statut">
                                <input type="hidden" form="appro-<?= $id ?>" name="id" value="<?= $id ?>">
                                <div class="appro-actions">
                                    <?php if ($statut === 'nouvelle'): ?>
                                        <button type="submit" form="appro-<?= $id ?>" name="statut" value="vue">Marquer vue</button>
                                        <button type="submit" form="appro-<?= $id ?>" name="statut" value="traitee">Marquer traité</button>
                                    <?php elseif ($statut === 'vue'): ?>
                                        <button type="submit" form="appro-<?= $id ?>" name="statut" value="traitee">Marquer traité</button>
                                        <button type="submit" form="appro-<?= $id ?>" name="statut" value="nouvelle" class="appro-rouvrir">Rouvrir</button>
                                    <?php else: ?>
                                        <button type="submit" form="appro-<?= $id ?>" name="statut" value="nouvelle" class="appro-rouvrir">Rouvrir</button>
                                    <?php endif; ?>
                                    <span class="atelier-message" role="status" aria-live="polite" data-message-for="appro-<?= $id ?>"></span>
                                </div>
                                <?php if ($signalement['date_traitement']): ?>
                                    <span class="atelier-row-description"><?= $statut === 'vue' ? 'Vue par' : 'Traité par' ?> <?= prefaEscape(trim(($signalement['traitant_prenom'] ?? '') . ' ' . ($signalement['traitant_nom'] ?? ''))) ?> le <?= prefaEscape(prefaFormatDate($signalement['date_traitement'], true)) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</section>
