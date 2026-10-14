<?php

/**
 * Le matériel commandé pour une demande, en lecture, dans la fiche de la demande.
 *
 * Les articles sont relevés dans le PV « Réception matériel » : le chef d'atelier dépose
 * le bon du fournisseur, le fait lire, corrige ce que la reconnaissance a mal compris,
 * puis enregistre. Ils ne vivaient que là. Le chargé de production, lui, ouvre la fiche
 * d'une demande — il avait à passer par la page des PV pour savoir ce qui avait été
 * commandé, demande par demande.
 *
 * Rien ne s'écrit ici : ce qui s'affiche est ce que l'atelier a validé.
 *
 * @var list<array<string, mixed>> $commandes les commandes de la demande, articles compris
 */
require_once __DIR__ . '/../includes/commandes.php';

$etats = commandeEtats();
$articlesEnTout = 0;
foreach ($commandes as $commande) $articlesEnTout += count($commande['articles'] ?? []);
?>
<details class="prefa-document-group fiche-famille prefa-materiel" open>

    <?php // « Réception matériel », et non « Matériel commandé » : le bloc du dessus porte
          // ce que le bon commande, celui-ci ce que l'atelier a vu arriver. Deux noms
          // voisins pour deux choses différentes, c'''était une question par semaine. ?>
    <summary class="fiche-famille-titre">
        <span class="fiche-famille-nom">Réception matériel</span>
        <span class="prefa-document-compte"><?= $articlesEnTout ?></span>
    </summary>

    <div class="prefa-materiel-corps">

        <?php foreach ($commandes as $commande): $articles = $commande['articles'] ?? []; ?>

            <article class="prefa-materiel-bon">

                <h4>
                    <span class="commande-numero"><?= prefaEscape($commande['numero']) ?></span>
                    <span class="prefa-materiel-fournisseur"><?= prefaEscape($commande['fournisseur']) ?></span>
                    <span class="commande-etat commande-etat-<?= prefaEscape($commande['statut']) ?>">
                        <?= prefaEscape($etats[$commande['statut']] ?? $commande['statut']) ?>
                    </span>
                </h4>

                <?php if ($articles): ?>

                    <div class="commande-articles-scroll">
                        <table class="commande-articles-table commande-articles-lecture">
                            <thead>
                                <tr>
                                    <th scope="col">Référence</th>
                                    <th scope="col">Désignation</th>
                                    <th scope="col">Unité</th>
                                    <th scope="col">Attendu</th>
                                    <th scope="col">Reçu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($articles as $article): ?>
                                    <tr>
                                        <td><?= $article['reference_article'] !== null && $article['reference_article'] !== ''
                                                ? prefaEscape($article['reference_article'])
                                                : '<span class="est-vide">—</span>' ?></td>
                                        <td title="<?= prefaEscape($article['designation']) ?>"><?= prefaEscape($article['designation']) ?></td>
                                        <td><?= prefaEscape($article['unite']) ?></td>
                                        <td class="commande-article-nombre"><?= prefaEscape(commandeQuantite($article['quantite_attendue'])) ?></td>
                                        <?php // Ce qui est arrivé ne se lit pas sur le bon : l'atelier le saisit à la
                                              // réception. Vide ne veut pas dire zéro, et le tiret le dit mieux. ?>
                                        <td class="commande-article-nombre<?= $article['quantite_recue'] === null ? ' est-vide' : '' ?>">
                                            <?= prefaEscape(commandeQuantite($article['quantite_recue'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <p class="prefa-materiel-vide">
                        Les articles de ce bon n’ont pas encore été relevés.
                    </p>

                <?php endif; ?>

            </article>

        <?php endforeach; ?>

    </div>

</details>
