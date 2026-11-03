<?php

/**
 * Les commandes d'une demande, en lecture.
 *
 * @var list<array<string, mixed>> $commandes
 */
$etats = commandeEtats();
?>
<div class="commandes-scroll">
    <table class="commandes-table">
        <thead>
            <tr>
                <th scope="col">N° de commande</th>
                <th scope="col">Fournisseur</th>
                <th scope="col">Livraison</th>
                <th scope="col">Lieu</th>
                <th scope="col">État</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commandes as $commande): ?>
                <tr>
                    <td class="commande-numero"><?= prefaEscape($commande['numero']) ?></td>
                    <td><?= prefaEscape($commande['fournisseur']) ?></td>
                    <td class="commande-dates">
                        <?php if ($commande['date_bl']): ?>
                            <?= prefaEscape(prefaFormatDate($commande['date_bl'])) ?>
                            <?php if ($commande['date_livraison_2']): ?>
                                <!-- Deux échéances : la commande arrive en deux fois, et
                                     l'atelier doit voir la seconde sans ouvrir le bon. -->
                                <span class="commande-date-2">puis <?= prefaEscape(prefaFormatDate($commande['date_livraison_2'])) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="commande-vide">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $commande['lieu_livraison'] ? prefaEscape($commande['lieu_livraison']) : '<span class="commande-vide">—</span>' ?></td>
                    <td>
                        <span class="commande-etat commande-etat-<?= prefaEscape($commande['statut']) ?>">
                            <?= prefaEscape($etats[$commande['statut']] ?? $commande['statut']) ?>
                        </span>
                        <?php if ($commande['statut'] === 'complete' && $commande['receptionnaire']): ?>
                            <span class="commande-recu-par"><?= prefaEscape($commande['receptionnaire']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
